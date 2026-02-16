<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Cron;

use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;

defined('ABSPATH') || exit;

/**
 * Payout Reconciliation Cron Adapter
 *
 * RESPONSABILIDADE:
 * - Sincronizar payouts em processamento com MercadoPago
 * - Detectar divergências entre estado local vs gateway
 * - Notificar admin se houver inconsistências
 *
 * FREQUÊNCIA: A cada 6 horas
 *
 * WORKFLOW:
 * 1. Buscar payouts com status = 'processing'
 * 2. Para cada payout: verificar status no MercadoPago
 * 3. Se divergir: atualizar local + notificar admin
 * 4. Registrar estatísticas de execução
 *
 * CENÁRIO CRÍTICO QUE RESOLVE:
 * - Local = "completed", MP = "failed" → Corrige para "failed"
 * - Local = "processing", MP = "completed" → Corrige para "completed"
 * - Detecta payouts stuck em "processing" por >24h
 *
 * @package LimpVix\Infrastructure\Cron
 * @since 0.10.0
 */
final class PayoutReconciliationCronAdapter
{
    private const HOOK_NAME = 'limpvix_reconcile_payouts';
    private const RECURRENCE = 'limpvix_6hours';
    private const STUCK_THRESHOLD_HOURS = 24;

    private MercadoPagoPayoutProvider $payoutProvider;
    private WpPayoutRepository $payoutRepository;

    public function __construct(
        MercadoPagoPayoutProvider $payoutProvider,
        WpPayoutRepository $payoutRepository
    ) {
        $this->payoutProvider = $payoutProvider;
        $this->payoutRepository = $payoutRepository;
    }

    /**
     * Registrar cron schedule + hook
     */
    public static function register(): void
    {
        // Registrar schedule customizado (6 horas)
        add_filter('cron_schedules', function (array $schedules): array {
            if (!isset($schedules[self::RECURRENCE])) {
                $schedules[self::RECURRENCE] = [
                    'interval' => 6 * HOUR_IN_SECONDS,
                    'display' => __('A cada 6 horas', 'limpvix'),
                ];
            }
            return $schedules;
        });

        // Agendar evento se não existir
        if (!wp_next_scheduled(self::HOOK_NAME)) {
            wp_schedule_event(time(), self::RECURRENCE, self::HOOK_NAME);
        }

        // Registrar handler
        add_action(self::HOOK_NAME, [self::class, 'executeStatic']);
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
     * Execute wrapper estático (WordPress compatibility)
     */
    public static function executeStatic(): array
    {
        $provider = new MercadoPagoPayoutProvider();
        $repository = new WpPayoutRepository();
        $adapter = new self($provider, $repository);

        return $adapter->execute();
    }

    /**
     * Executar reconciliation
     *
     * @return array{
     *     processing_count: int,
     *     synced: int,
     *     divergences_found: int,
     *     stuck_payouts: int,
     *     errors: int,
     *     execution_time: float
     * }
     */
    public function execute(): array
    {
        $start_time = microtime(true);

        $stats = [
            'processing_count' => 0,
            'synced' => 0,
            'divergences_found' => 0,
            'stuck_payouts' => 0,
            'errors' => 0,
            'execution_time' => 0.0,
        ];

        // Log início
        error_log('[PayoutReconciliation] Starting reconciliation job...');

        // Verificar se provider está disponível
        if (!$this->payoutProvider->isAvailable()) {
            error_log('[PayoutReconciliation] MercadoPago provider not available. Skipping.');
            $stats['execution_time'] = microtime(true) - $start_time;
            return $stats;
        }

        // Buscar payouts em processamento
        $processing_payouts = $this->payoutRepository->getByStatus('processing', 500);
        $stats['processing_count'] = count($processing_payouts);

        error_log(sprintf(
            '[PayoutReconciliation] Found %d payouts in processing status.',
            $stats['processing_count']
        ));

        // Sincronizar cada payout
        foreach ($processing_payouts as $payout) {
            try {
                $divergence = $this->reconcilePayout($payout);

                if ($divergence) {
                    $stats['divergences_found']++;
                }

                $stats['synced']++;
            } catch (\Exception $e) {
                $stats['errors']++;
                error_log(sprintf(
                    '[PayoutReconciliation] ERROR reconciling payout #%d: %s',
                    $payout['id'],
                    $e->getMessage()
                ));
            }
        }

        // Detectar payouts stuck
        $stats['stuck_payouts'] = $this->detectStuckPayouts($processing_payouts);

        // Log final
        $stats['execution_time'] = microtime(true) - $start_time;

        error_log(sprintf(
            '[PayoutReconciliation] Job completed: %d synced, %d divergences, %d stuck, %d errors (%.2fs)',
            $stats['synced'],
            $stats['divergences_found'],
            $stats['stuck_payouts'],
            $stats['errors'],
            $stats['execution_time']
        ));

        // Notificar admin se houver problemas
        if ($stats['divergences_found'] > 0 || $stats['stuck_payouts'] > 0) {
            $this->notifyAdmin($stats);
        }

        return $stats;
    }

    /**
     * Reconciliar um payout específico
     *
     * @param array $payout Payout local
     * @return bool True se houve divergência
     */
    private function reconcilePayout(array $payout): bool
    {
        $payout_id = (int) $payout['id'];
        $transfer_id = $payout['gateway_transfer_id'];

        if (empty($transfer_id)) {
            error_log(sprintf(
                '[PayoutReconciliation] Payout #%d has no transfer_id, skipping.',
                $payout_id
            ));
            return false;
        }

        // Consultar status no MercadoPago
        $mp_status = $this->payoutProvider->getPayoutStatus($transfer_id);

        if ($mp_status === null) {
            error_log(sprintf(
                '[PayoutReconciliation] Could not fetch status for payout #%d (transfer_id: %s)',
                $payout_id,
                $transfer_id
            ));
            return false;
        }

        // Mapear status do MP para status local
        $mp_status_mapped = $this->mapMercadoPagoStatus($mp_status['status'] ?? 'unknown');
        $local_status = $payout['status'];

        // Verificar divergência
        if ($mp_status_mapped !== $local_status) {
            error_log(sprintf(
                '[PayoutReconciliation] DIVERGENCE FOUND - Payout #%d: Local=%s, MP=%s (mapped=%s)',
                $payout_id,
                $local_status,
                $mp_status['status'],
                $mp_status_mapped
            ));

            // Atualizar local com status do gateway
            $this->payoutRepository->updateStatus(
                $payout_id,
                $mp_status_mapped,
                json_encode($mp_status)
            );

            return true; // Divergência encontrada e corrigida
        }

        return false; // Sem divergência
    }

    /**
     * Mapear status do MercadoPago para status local
     *
     * @param string $mp_status Status do MP
     * @return string Status local
     */
    private function mapMercadoPagoStatus(string $mp_status): string
    {
        return match ($mp_status) {
            'approved', 'authorized' => 'completed',
            'in_process', 'pending' => 'processing',
            'rejected', 'cancelled' => 'failed',
            default => 'processing', // Default: manter em processamento
        };
    }

    /**
     * Detectar payouts stuck em processamento por >24h
     *
     * @param array[] $payouts Payouts em processamento
     * @return int Quantidade de payouts stuck
     */
    private function detectStuckPayouts(array $payouts): int
    {
        $stuck_count = 0;
        $threshold = time() - (self::STUCK_THRESHOLD_HOURS * HOUR_IN_SECONDS);

        foreach ($payouts as $payout) {
            $processed_at = strtotime($payout['processed_at'] ?? '');

            if ($processed_at && $processed_at < $threshold) {
                $stuck_count++;

                error_log(sprintf(
                    '[PayoutReconciliation] STUCK PAYOUT DETECTED - Payout #%d in processing for %d hours',
                    $payout['id'],
                    round((time() - $processed_at) / HOUR_IN_SECONDS)
                ));
            }
        }

        return $stuck_count;
    }

    /**
     * Notificar admin sobre problemas encontrados
     *
     * @param array $stats Estatísticas da execução
     */
    private function notifyAdmin(array $stats): void
    {
        $admin_email = get_option('admin_email');
        if (empty($admin_email)) {
            return;
        }

        $subject = sprintf(
            '[LimpVix] Alerta: %d divergências de payout detectadas',
            $stats['divergences_found']
        );

        $message = sprintf(
            "Olá,\n\n" .
            "O sistema detectou problemas na reconciliação de payouts:\n\n" .
            "- Payouts em processamento: %d\n" .
            "- Divergências encontradas: %d\n" .
            "- Payouts stuck (>24h): %d\n" .
            "- Erros: %d\n\n" .
            "Por favor, verifique o painel administrativo.\n\n" .
            "Dashboard: %s\n\n" .
            "---\n" .
            "LimpVix Core v0.10.0\n" .
            "Automated Payout Reconciliation System",
            $stats['processing_count'],
            $stats['divergences_found'],
            $stats['stuck_payouts'],
            $stats['errors'],
            admin_url('admin.php?page=limpvix-payouts')
        );

        wp_mail($admin_email, $subject, $message);

        error_log(sprintf(
            '[PayoutReconciliation] Alert email sent to %s',
            $admin_email
        ));
    }
}
