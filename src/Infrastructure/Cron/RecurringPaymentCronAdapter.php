<?php
/**
 * RecurringPaymentCronAdapter - Cron Job for Automatic Payment Charging
 *
 * RESPONSABILIDADE:
 * - Executar diariamente às 00:00
 * - Encontrar contratos próximos ao vencimento (end_date)
 * - Criar cobranças via ChargeRecurringPayment use case
 * - Retentar payments falhados via RetryFailedPayment use case
 * - Registrar logs de execução
 *
 * WORKFLOW:
 * 1. Buscar contratos com end_date <= hoje + 3 days AND auto_renew=true
 * 2. Para cada contrato, chamar ChargeRecurringPayment
 * 3. Buscar payments com status=failed AND attempt_count < 3
 * 4. Para cada payment, chamar RetryFailedPayment
 * 5. Registrar estatísticas de execução
 *
 * CRON SCHEDULE:
 * - Hook: limpvix_charge_recurring_payments
 * - Frequency: daily (00:00)
 * - Recurrence: 'daily'
 *
 * RETRY LOGIC:
 * - Failed payments são retentados após 2 dias (handled by repository query)
 * - Max 3 attempts total
 * - Após 3 falhas, contrato marcado como payment_failed
 *
 * @package LimpVix\Infrastructure\Cron
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Infrastructure\Cron;

use LimpVix\Application\UseCases\Finance\ChargeRecurringPayment;
use LimpVix\Application\UseCases\Finance\RetryFailedPayment;
use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

final class RecurringPaymentCronAdapter
{
    private ChargeRecurringPayment $chargeRecurringPayment;
    private RetryFailedPayment $retryFailedPayment;
    private ContractRepositoryInterface $contractRepository;

    public function __construct(
        ChargeRecurringPayment $chargeRecurringPayment,
        RetryFailedPayment $retryFailedPayment,
        ContractRepositoryInterface $contractRepository
    ) {
        $this->chargeRecurringPayment = $chargeRecurringPayment;
        $this->retryFailedPayment = $retryFailedPayment;
        $this->contractRepository = $contractRepository;
    }

    /**
     * Execute cron job
     * Called by WordPress cron: limpvix_charge_recurring_payments
     *
     * @return array Execution statistics
     */
    public function execute(): array
    {
        $startTime = microtime(true);

        error_log('[LimpVix] RecurringPaymentCronAdapter: Starting execution...');

        $stats = [
            'contracts_found' => 0,
            'charges_created' => 0,
            'charges_failed' => 0,
            'retries_attempted' => 0,
            'retries_succeeded' => 0,
            'retries_failed' => 0,
            'execution_time' => 0,
        ];

        try {
            // Step 1: Charge expiring contracts
            $chargeStats = $this->chargeExpiringContracts();
            $stats['contracts_found'] = $chargeStats['found'];
            $stats['charges_created'] = $chargeStats['succeeded'];
            $stats['charges_failed'] = $chargeStats['failed'];

            // Step 2: Retry failed payments
            $retryStats = $this->retryFailedPayments();
            $stats['retries_attempted'] = $retryStats['attempted'];
            $stats['retries_succeeded'] = $retryStats['succeeded'];
            $stats['retries_failed'] = $retryStats['failed'];

        } catch (\Exception $e) {
            error_log(sprintf(
                '[LimpVix] RecurringPaymentCronAdapter error: %s',
                $e->getMessage()
            ));
        }

        $stats['execution_time'] = round(microtime(true) - $startTime, 2);

        // Log execution summary
        error_log(sprintf(
            '[LimpVix] RecurringPaymentCronAdapter completed: ' .
            'Contracts: %d found, %d charged, %d failed | ' .
            'Retries: %d attempted, %d succeeded | ' .
            'Time: %.2fs',
            $stats['contracts_found'],
            $stats['charges_created'],
            $stats['charges_failed'],
            $stats['retries_attempted'],
            $stats['retries_succeeded'],
            $stats['execution_time']
        ));

        return $stats;
    }

    /**
     * Charge expiring contracts
     * Find contracts with end_date approaching and auto_renew=true
     *
     * @return array{found: int, succeeded: int, failed: int}
     */
    private function chargeExpiringContracts(): array
    {
        $stats = ['found' => 0, 'succeeded' => 0, 'failed' => 0];

        // Find contracts expiring in next 3 days
        $expiringContracts = $this->findExpiringContracts();
        $stats['found'] = count($expiringContracts);

        if (empty($expiringContracts)) {
            error_log('[LimpVix] No expiring contracts found for charging');
            return $stats;
        }

        error_log(sprintf(
            '[LimpVix] Found %d expiring contracts to charge',
            count($expiringContracts)
        ));

        foreach ($expiringContracts as $contract) {
            try {
                // Call ChargeRecurringPayment use case
                $result = $this->chargeRecurringPayment->execute(
                    $contract->getId()->toInt()
                );

                if ($result->isOk()) {
                    $stats['succeeded']++;
                    error_log(sprintf(
                        '[LimpVix] Successfully charged contract %d: %s',
                        $contract->getId()->toInt(),
                        json_encode($result->value())
                    ));
                } else {
                    $stats['failed']++;
                    error_log(sprintf(
                        '[LimpVix] Failed to charge contract %d: %s',
                        $contract->getId()->toInt(),
                        $result->error()
                    ));
                }

            } catch (\Exception $e) {
                $stats['failed']++;
                error_log(sprintf(
                    '[LimpVix] Exception charging contract %d: %s',
                    $contract->getId()->toInt(),
                    $e->getMessage()
                ));
            }
        }

        return $stats;
    }

    /**
     * Retry failed payments
     * Calls RetryFailedPayment use case batch method
     *
     * @return array{attempted: int, succeeded: int, failed: int}
     */
    private function retryFailedPayments(): array
    {
        try {
            // Call batch retry method
            $result = $this->retryFailedPayment->retryAllPendingPayments();

            if ($result->isOk()) {
                $batchStats = $result->value();
                return [
                    'attempted' => $batchStats['total'],
                    'succeeded' => $batchStats['succeeded'],
                    'failed' => $batchStats['failed'],
                ];
            } else {
                error_log(sprintf(
                    '[LimpVix] Failed to retry payments: %s',
                    $result->error()
                ));
                return ['attempted' => 0, 'succeeded' => 0, 'failed' => 0];
            }

        } catch (\Exception $e) {
            error_log(sprintf(
                '[LimpVix] Exception retrying payments: %s',
                $e->getMessage()
            ));
            return ['attempted' => 0, 'succeeded' => 0, 'failed' => 0];
        }
    }

    /**
     * Find contracts expiring in next 3 days with auto_renew enabled
     *
     * @return array<\LimpVix\Domain\Contract\Contract>
     */
    private function findExpiringContracts(): array
    {
        // Calculate target date (today + 3 days window)
        $targetDate = new \DateTimeImmutable('+3 days');

        // Find all active contracts
        $activeContracts = $this->contractRepository->findActiveContracts('active');

        // Filter: auto_renew=true AND end_date <= targetDate
        $expiringContracts = array_filter($activeContracts, function ($contract) use ($targetDate) {
            // Must have auto_renew enabled
            if (!$contract->isAutoRenew()) {
                return false;
            }

            // Must have end_date set
            $endDate = $contract->getEndDate();
            if ($endDate === null) {
                return false;
            }

            // End date must be within 3 days
            return $endDate <= $targetDate;
        });

        return array_values($expiringContracts);
    }

    /**
     * Register cron schedule and hook
     * Called during plugin initialization
     *
     * @return void
     */
    public static function register(): void
    {
        // Add custom cron schedule (daily at 00:00)
        add_filter('cron_schedules', function ($schedules) {
            if (!isset($schedules['limpvix_daily'])) {
                $schedules['limpvix_daily'] = [
                    'interval' => DAY_IN_SECONDS,
                    'display' => __('Once Daily (LimpVix)', 'limpvix'),
                ];
            }
            return $schedules;
        });

        // Schedule event if not already scheduled
        if (!wp_next_scheduled('limpvix_charge_recurring_payments')) {
            // Schedule for midnight
            $midnight = strtotime('tomorrow midnight');
            wp_schedule_event(
                $midnight,
                'limpvix_daily',
                'limpvix_charge_recurring_payments'
            );

            error_log(sprintf(
                '[LimpVix] Scheduled recurring payment cron for %s',
                date('Y-m-d H:i:s', $midnight)
            ));
        }
    }

    /**
     * Unregister cron schedule
     * Called during plugin deactivation
     *
     * @return void
     */
    public static function unregister(): void
    {
        $timestamp = wp_next_scheduled('limpvix_charge_recurring_payments');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'limpvix_charge_recurring_payments');
            error_log('[LimpVix] Unscheduled recurring payment cron');
        }
    }
}
