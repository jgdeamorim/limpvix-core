<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Cron;

defined('ABSPATH') || exit;

/**
 * Payment Authorization Timeout Cron Adapter
 *
 * RESPONSABILIDADE:
 * - Detectar payments PRE_AUTHORIZED há mais de 24h
 * - Capturar automaticamente (se order confirmada)
 * - Cancelar automaticamente (se order cancelada ou sem resposta)
 * - Prevenir expiração de authorization no gateway
 *
 * FREQUÊNCIA: A cada 1 hora
 *
 * WORKFLOW:
 * 1. Buscar orders com payment_status = 'pre_authorized'
 * 2. Filtrar: authorization_date < NOW() - 24h
 * 3. Para cada payment:
 *    a. Se order está confirmada (checkout completo) → CAPTURE
 *    b. Se order está pending/cancelled → CANCEL authorization
 * 4. Registrar estatísticas
 *
 * CENÁRIO CRÍTICO QUE RESOLVE:
 * - Payment pre-authorized no gateway
 * - Order aguarda validação/checkout por >30 dias
 * - Authorization expira no gateway (perdemos pagamento)
 * - Sistema tenta capturar payment expirado → FALHA
 *
 * GOLDEN RULE:
 * - Capture SÓ se order status permiteCapture PRE-AUTHORIZATION pode ser:
 * - CAPTURED (se order confirmada) → Dinheiro é transferido
 * - CANCELLED (se order não confirmada) → Dinheiro volta ao cliente
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
        // Schedule event se não existir
        if (!wp_next_scheduled(self::HOOK_NAME)) {
            wp_schedule_event(time(), self::RECURRENCE, self::HOOK_NAME);
        }

        // Registrar handler
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
     * Executar timeout check
     *
     * @return array{
     *     pre_authorized_count: int,
     *     captured: int,
     *     cancelled: int,
     *     errors: int,
     *     execution_time: float
     * }
     */
    public static function execute(): array
    {
        global $wpdb;

        $start_time = microtime(true);

        $stats = [
            'pre_authorized_count' => 0,
            'captured' => 0,
            'cancelled' => 0,
            'errors' => 0,
            'execution_time' => 0.0,
        ];

        error_log('[PaymentAuthorizationTimeout] Starting timeout check...');

        // Query: Orders com payment PRE_AUTHORIZED há >24h
        // Assumindo estrutura WordPress + WooCommerce ou custom orders table
        $timeout_threshold = date('Y-m-d H:i:s', strtotime('-' . self::TIMEOUT_HOURS . ' hours'));

        // NOTA: Adaptar query para estrutura real do LimpVix
        // Exemplo genérico:
        $orders_table = $wpdb->prefix . 'limpvix_orders';

        // Check if table exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$orders_table}'") === $orders_table;

        if (!$table_exists) {
            error_log('[PaymentAuthorizationTimeout] Orders table not found. Skipping.');
            $stats['execution_time'] = microtime(true) - $start_time;
            return $stats;
        }

        $query = $wpdb->prepare(
            "SELECT id, order_uuid, payment_status, order_status, authorization_date, payment_gateway
             FROM {$orders_table}
             WHERE payment_status = 'pre_authorized'
               AND authorization_date < %s
             LIMIT 100",
            $timeout_threshold
        );

        $expired_authorizations = $wpdb->get_results($query, ARRAY_A);
        $stats['pre_authorized_count'] = count($expired_authorizations);

        error_log(sprintf(
            '[PaymentAuthorizationTimeout] Found %d payments with expired authorization (>%dh).',
            $stats['pre_authorized_count'],
            self::TIMEOUT_HOURS
        ));

        // Process each payment
        foreach ($expired_authorizations as $order) {
            try {
                $order_id = (int) $order['id'];
                $order_status = $order['order_status'];
                $payment_gateway = $order['payment_gateway'] ?? 'mercadopago';

                // Decision: CAPTURE or CANCEL?
                if (in_array($order_status, ['confirmed', 'processing', 'completed'], true)) {
                    // Order confirmada → CAPTURE payment
                    $captured = self::capturePayment($order_id, $payment_gateway);

                    if ($captured) {
                        $stats['captured']++;
                        error_log(sprintf(
                            '[PaymentAuthorizationTimeout] CAPTURED payment for order #%d (status: %s)',
                            $order_id,
                            $order_status
                        ));
                    } else {
                        $stats['errors']++;
                    }
                } else {
                    // Order pending/cancelled → CANCEL authorization
                    $cancelled = self::cancelAuthorization($order_id, $payment_gateway);

                    if ($cancelled) {
                        $stats['cancelled']++;
                        error_log(sprintf(
                            '[PaymentAuthorizationTimeout] CANCELLED authorization for order #%d (status: %s)',
                            $order_id,
                            $order_status
                        ));
                    } else {
                        $stats['errors']++;
                    }
                }
            } catch (\Exception $e) {
                $stats['errors']++;
                error_log(sprintf(
                    '[PaymentAuthorizationTimeout] ERROR processing order #%d: %s',
                    $order['id'],
                    $e->getMessage()
                ));
            }
        }

        // Log final
        $stats['execution_time'] = microtime(true) - $start_time;

        error_log(sprintf(
            '[PaymentAuthorizationTimeout] Job completed: %d captured, %d cancelled, %d errors (%.2fs)',
            $stats['captured'],
            $stats['cancelled'],
            $stats['errors'],
            $stats['execution_time']
        ));

        // Notificar admin se houver problemas
        if ($stats['errors'] > 0) {
            self::notifyAdmin($stats);
        }

        return $stats;
    }

    /**
     * Capturar payment (finalize authorization)
     *
     * @param int $order_id Order ID
     * @param string $gateway Payment gateway
     * @return bool Success
     */
    private static function capturePayment(int $order_id, string $gateway): bool
    {
        global $wpdb;

        // TODO: Implement actual capture logic via gateway API
        // Para MercadoPago: usar API de capture
        // Para outros gateways: usar SDK apropriado

        try {
            // EXEMPLO SIMPLIFICADO - Implementar integração real
            if ($gateway === 'mercadopago') {
                // MercadoPago capture logic
                // $mpProvider = new MercadoPagoPaymentProvider();
                // $result = $mpProvider->capturePayment($order_id);
                // if (!$result) return false;
            }

            // Atualizar status local
            $orders_table = $wpdb->prefix . 'limpvix_orders';

            $updated = $wpdb->update(
                $orders_table,
                [
                    'payment_status' => 'captured',
                    'captured_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $order_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            return $updated !== false;
        } catch (\Exception $e) {
            error_log(sprintf(
                '[PaymentAuthorizationTimeout] Capture failed for order #%d: %s',
                $order_id,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Cancelar authorization (refund pre-authorization)
     *
     * @param int $order_id Order ID
     * @param string $gateway Payment gateway
     * @return bool Success
     */
    private static function cancelAuthorization(int $order_id, string $gateway): bool
    {
        global $wpdb;

        try {
            // TODO: Implement actual cancellation logic via gateway API
            if ($gateway === 'mercadopago') {
                // MercadoPago cancel logic
                // $mpProvider = new MercadoPagoPaymentProvider();
                // $result = $mpProvider->cancelAuthorization($order_id);
                // if (!$result) return false;
            }

            // Atualizar status local
            $orders_table = $wpdb->prefix . 'limpvix_orders';

            $updated = $wpdb->update(
                $orders_table,
                [
                    'payment_status' => 'authorization_cancelled',
                    'cancelled_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql'),
                ],
                ['id' => $order_id],
                ['%s', '%s', '%s'],
                ['%d']
            );

            return $updated !== false;
        } catch (\Exception $e) {
            error_log(sprintf(
                '[PaymentAuthorizationTimeout] Cancellation failed for order #%d: %s',
                $order_id,
                $e->getMessage()
            ));
            return false;
        }
    }

    /**
     * Notificar admin sobre problemas
     *
     * @param array $stats Estatísticas de execução
     */
    private static function notifyAdmin(array $stats): void
    {
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        $subject = sprintf(
            '[LimpVix] Alerta: %d erros em Payment Authorization Timeout',
            $stats['errors']
        );

        $message = sprintf(
            "Olá,\n\n" .
            "O sistema detectou problemas ao processar authorizations expiradas:\n\n" .
            "- Pre-authorizations encontradas: %d\n" .
            "- Capturadas com sucesso: %d\n" .
            "- Canceladas com sucesso: %d\n" .
            "- Erros: %d\n\n" .
            "Por favor, verifique o painel administrativo.\n\n" .
            "Dashboard: %s\n\n" .
            "---\n" .
            "LimpVix Core v0.10.0\n" .
            "Automated Payment Authorization Timeout System",
            $stats['pre_authorized_count'],
            $stats['captured'],
            $stats['cancelled'],
            $stats['errors'],
            admin_url('admin.php?page=limpvix-orders')
        );

        wp_mail($admin_email, $subject, $message);

        error_log(sprintf(
            '[PaymentAuthorizationTimeout] Alert email sent to %s',
            $admin_email
        ));
    }
}
