<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Cron;

defined('ABSPATH') || exit;

/**
 * PIX Payment Timeout Cron Adapter (EFI Bank)
 *
 * RESPONSABILIDADE:
 * - Detectar cobranças PIX pendentes (status 'pending') há mais de TIMEOUT_HOURS
 * - Consultar API EFI Bank para status real da cobrança
 * - Se CONCLUIDA → marcar como pago (webhook perdido)
 * - Se REMOVIDA* → marcar como expirado
 * - Se ATIVA mas expirada → marcar como expirado
 *
 * FREQUÊNCIA: A cada 1 hora
 *
 * CONTEXTO PIX:
 * PIX não tem "pre-authorization". Uma cobrança PIX (cob) é:
 * - ATIVA     → QR Code gerado, aguardando pagamento
 * - CONCLUIDA → Pagamento recebido
 * - REMOVIDA_PELO_USUARIO_RECEBEDOR → Cancelada pelo recebedor
 * - REMOVIDA_PELO_PSP → Expirada/removida pelo banco
 *
 * CENÁRIO CRÍTICO QUE RESOLVE:
 * - Webhook PIX não chegou (falha de rede, servidor fora do ar)
 * - Cobrança PIX expirou sem o sistema saber
 * - Cliente pagou mas status ficou "pending" localmente
 *
 * @package LimpVix\Infrastructure\Cron
 * @since 0.10.0
 */
final class PaymentAuthorizationTimeoutCronAdapter
{
    private const HOOK_NAME = 'limpvix_payment_authorization_timeout';
    private const RECURRENCE = 'hourly';
    private const TIMEOUT_HOURS = 24;

    /**
     * Registrar cron schedule + hook
     */
    public static function register(): void
    {
        if (!wp_next_scheduled(self::HOOK_NAME)) {
            wp_schedule_event(time(), self::RECURRENCE, self::HOOK_NAME);
        }
        add_action(self::HOOK_NAME, [self::class, 'execute']);
    }

    /**
     * Desregistrar cron
     */
    public static function unregister(): void
    {
        $timestamp = wp_next_scheduled(self::HOOK_NAME);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::HOOK_NAME);
        }
    }

    /**
     * Executar verificação de cobranças PIX pendentes
     *
     * @return array{
     *     pending_count: int,
     *     confirmed: int,
     *     expired: int,
     *     errors: int,
     *     execution_time: float
     * }
     */
    public static function execute(): array
    {
        global $wpdb;

        $start_time = microtime(true);

        $stats = [
            'pending_count' => 0,
            'confirmed' => 0,
            'expired' => 0,
            'errors' => 0,
            'execution_time' => 0.0,
        ];

        error_log('[PixPaymentTimeout] Starting PIX payment timeout check...');

        $timeout_threshold = date('Y-m-d H:i:s', strtotime('-' . self::TIMEOUT_HOURS . ' hours'));
        $orders_table = $wpdb->prefix . 'limpvix_orders';

        // Verificar se tabela existe
        if ($wpdb->get_var("SHOW TABLES LIKE '{$orders_table}'") !== $orders_table) {
            error_log('[PixPaymentTimeout] Orders table not found. Skipping.');
            $stats['execution_time'] = microtime(true) - $start_time;
            return $stats;
        }

        // Buscar cobranças PIX pendentes criadas há mais de TIMEOUT_HOURS
        $query = $wpdb->prepare(
            "SELECT id, order_uuid, payment_status, order_status,
                    gateway_transaction_id, payment_gateway, created_at
             FROM {$orders_table}
             WHERE payment_status IN ('pending', 'pre_authorized')
               AND created_at < %s
               AND gateway_transaction_id IS NOT NULL
               AND gateway_transaction_id != ''
             LIMIT 100",
            $timeout_threshold
        );

        $pending_payments = $wpdb->get_results($query, ARRAY_A);
        $stats['pending_count'] = count($pending_payments);

        error_log(sprintf(
            '[PixPaymentTimeout] Found %d pending PIX payments older than %dh.',
            $stats['pending_count'],
            self::TIMEOUT_HOURS
        ));

        if ($stats['pending_count'] === 0) {
            $stats['execution_time'] = microtime(true) - $start_time;
            return $stats;
        }

        // Inicializar EFI Bank provider
        $efiProvider = new \LimpVix\Infrastructure\Finance\Providers\EfiPaymentProvider();

        if (!$efiProvider->isAvailable()) {
            error_log('[PixPaymentTimeout] EFI Bank provider not available. Skipping.');
            $stats['execution_time'] = microtime(true) - $start_time;
            return $stats;
        }

        foreach ($pending_payments as $order) {
            try {
                $order_id = (int) $order['id'];
                $txid = $order['gateway_transaction_id'];

                // Consultar status real na API EFI Bank
                $chargeData = $efiProvider->getChargeStatus($txid);

                if ($chargeData === null) {
                    // Não conseguiu consultar — pode ser problema de rede
                    error_log(sprintf(
                        '[PixPaymentTimeout] Could not check charge %s for order #%d',
                        $txid,
                        $order_id
                    ));
                    $stats['errors']++;
                    continue;
                }

                $efiStatus = $chargeData['status'] ?? 'ATIVA';

                if ($efiStatus === 'CONCLUIDA') {
                    // Pagamento confirmado — webhook perdido!
                    $confirmed = self::confirmPayment($order_id, $txid, $chargeData);
                    if ($confirmed) {
                        $stats['confirmed']++;
                        error_log(sprintf(
                            '[PixPaymentTimeout] CONFIRMED missed payment for order #%d (txid: %s)',
                            $order_id,
                            $txid
                        ));
                    } else {
                        $stats['errors']++;
                    }
                } elseif (in_array($efiStatus, ['REMOVIDA_PELO_USUARIO_RECEBEDOR', 'REMOVIDA_PELO_PSP'], true)) {
                    // Cobrança removida/expirada
                    $expired = self::expireCharge($order_id, $txid, $efiStatus);
                    if ($expired) {
                        $stats['expired']++;
                        error_log(sprintf(
                            '[PixPaymentTimeout] EXPIRED charge for order #%d (txid: %s, reason: %s)',
                            $order_id,
                            $txid,
                            $efiStatus
                        ));
                    } else {
                        $stats['errors']++;
                    }
                } elseif ($efiStatus === 'ATIVA') {
                    // Ainda ativa mas passou do timeout — marcar como expirada
                    $expired = self::expireCharge($order_id, $txid, 'TIMEOUT_LOCAL');
                    if ($expired) {
                        $stats['expired']++;
                        error_log(sprintf(
                            '[PixPaymentTimeout] EXPIRED (timeout) charge for order #%d (txid: %s)',
                            $order_id,
                            $txid
                        ));
                    } else {
                        $stats['errors']++;
                    }
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                error_log(sprintf(
                    '[PixPaymentTimeout] ERROR processing order #%d: %s',
                    $order['id'],
                    $e->getMessage()
                ));
            }
        }

        $stats['execution_time'] = microtime(true) - $start_time;

        error_log(sprintf(
            '[PixPaymentTimeout] Job completed: %d confirmed, %d expired, %d errors (%.2fs)',
            $stats['confirmed'],
            $stats['expired'],
            $stats['errors'],
            $stats['execution_time']
        ));

        if ($stats['errors'] > 0) {
            self::notifyAdmin($stats);
        }

        return $stats;
    }

    /**
     * Confirmar pagamento que foi detectado via polling (webhook perdido)
     *
     * @param int    $order_id   Order ID
     * @param string $txid       EFI Bank txid
     * @param array  $chargeData Dados da cobrança do EFI Bank
     * @return bool Success
     */
    private static function confirmPayment(int $order_id, string $txid, array $chargeData): bool
    {
        global $wpdb;

        try {
            $orders_table = $wpdb->prefix . 'limpvix_orders';

            // Extrair data do pagamento PIX
            $paidAt = current_time('mysql');
            if (!empty($chargeData['pix'][0]['horario'])) {
                $paidAt = date('Y-m-d H:i:s', strtotime($chargeData['pix'][0]['horario']));
            }

            $endToEndId = $chargeData['pix'][0]['endToEndId'] ?? null;

            $updated = $wpdb->update(
                $orders_table,
                [
                    'payment_status' => 'paid',
                    'captured_at' => $paidAt,
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $order_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                return false;
            }

            // Disparar ação para outros listeners processarem (renovação contrato, etc.)
            do_action('limpvix_pix_payment_confirmed_via_polling', $order_id, $txid, $chargeData);

            return true;
        } catch (\Exception $e) {
            error_log(sprintf(
                '[PixPaymentTimeout] Confirm failed for order #%d: %s',
                $order_id,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Marcar cobrança PIX como expirada
     *
     * @param int    $order_id Order ID
     * @param string $txid     EFI Bank txid
     * @param string $reason   Razão da expiração (status EFI ou TIMEOUT_LOCAL)
     * @return bool Success
     */
    private static function expireCharge(int $order_id, string $txid, string $reason): bool
    {
        global $wpdb;

        try {
            $orders_table = $wpdb->prefix . 'limpvix_orders';

            $updated = $wpdb->update(
                $orders_table,
                [
                    'payment_status' => 'expired',
                    'cancelled_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $order_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            if ($updated === false) {
                return false;
            }

            do_action('limpvix_pix_charge_expired', $order_id, $txid, $reason);

            return true;
        } catch (\Exception $e) {
            error_log(sprintf(
                '[PixPaymentTimeout] Expire failed for order #%d: %s',
                $order_id,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Notificar admin sobre problemas
     */
    private static function notifyAdmin(array $stats): void
    {
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        $subject = sprintf(
            '[LimpVix] Alerta: %d erros em PIX Payment Timeout',
            $stats['errors']
        );

        $message = sprintf(
            "Olá,\n\n" .
            "O sistema detectou problemas ao processar cobranças PIX pendentes:\n\n" .
            "- Cobranças pendentes encontradas: %d\n" .
            "- Confirmadas (webhook perdido): %d\n" .
            "- Expiradas: %d\n" .
            "- Erros: %d\n\n" .
            "Por favor, verifique o painel administrativo.\n\n" .
            "Dashboard: %s\n\n" .
            "---\n" .
            "LimpVix Core\n" .
            "PIX Payment Timeout Cron (EFI Bank)",
            $stats['pending_count'],
            $stats['confirmed'],
            $stats['expired'],
            $stats['errors'],
            admin_url('admin.php?page=limpvix-orders')
        );

        wp_mail($admin_email, $subject, $message);

        error_log(sprintf(
            '[PixPaymentTimeout] Alert email sent to %s',
            $admin_email
        ));
    }
}
