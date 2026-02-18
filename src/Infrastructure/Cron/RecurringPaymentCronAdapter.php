<?php
/**
 * RecurringPaymentCronAdapter - Cron Job for Automatic Payment Charging
 *
 * RESPONSABILIDADE:
 * - Executar diariamente às 00:00
 * - Encontrar contratos com próxima execução em até 3 dias (modelo on-demand)
 * - Criar cobranças por execução via ChargeRecurringPayment use case
 * - Retentar payments falhados via RetryFailedPayment use case
 * - Registrar logs de execução
 *
 * MODELO ON-DEMAND:
 * - A cobrança é por execução, não por mês calendário
 * - nextExecutionDate = start_date + (ciclos × período da frequência)
 * - weekly:   cobrança a cada 7 dias (R$ monthlyValue / 4.33)
 * - biweekly: cobrança a cada 14 dias (R$ monthlyValue / 2.16)
 * - monthly:  cobrança a cada 30 dias (R$ monthlyValue)
 *
 * WORKFLOW:
 * 1. Buscar contratos ativos com auto_renew=true
 * 2. Filtrar: nextExecutionDate <= hoje + 3 dias (calculado da frequência)
 * 3. Para cada contrato, chamar ChargeRecurringPayment
 * 4. Buscar payments com status=failed AND attempt_count < 3
 * 5. Para cada payment, chamar RetryFailedPayment
 * 6. Registrar estatísticas de execução
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

        error_log('[LimpVix] RecurringPaymentCronAdapter: Starting execution (on-demand model)...');

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
     * Charge contracts whose next execution is within 3 days.
     * On-demand model: 1 charge per execution (not per calendar month).
     *
     * @return array{found: int, succeeded: int, failed: int}
     */
    private function chargeExpiringContracts(): array
    {
        $stats = ['found' => 0, 'succeeded' => 0, 'failed' => 0];

        // Find contracts due for execution charge
        $expiringContracts = $this->findExpiringContracts();
        $stats['found'] = count($expiringContracts);

        if (empty($expiringContracts)) {
            error_log('[LimpVix] No contracts due for execution charge in next 3 days');
            return $stats;
        }

        error_log(sprintf(
            '[LimpVix] Found %d contracts due for execution charge',
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
     * Find contracts due for execution charge in next 3 days.
     *
     * On-demand model: instead of checking end_date, we calculate the
     * next execution date from start_date and contract frequency.
     * This ensures weekly contracts are charged every 7 days, not monthly.
     *
     * @return array<\LimpVix\Domain\Contract\Contract>
     */
    private function findExpiringContracts(): array
    {
        $targetDate = new \DateTimeImmutable('+3 days');
        $today      = new \DateTimeImmutable('today');

        // Find all active contracts (note: no argument — interface takes none)
        $activeContracts = $this->contractRepository->findActiveContracts();

        $contractsDue = array_filter(
            $activeContracts,
            function ($contract) use ($targetDate, $today) {
                // Must have auto_renew enabled
                if (!$contract->isAutoRenew()) {
                    return false;
                }

                // Calculate next execution date from frequency
                $nextExecDate = $this->calculateNextExecutionDate($contract, $today);

                if ($nextExecDate === null) {
                    return false;
                }

                // Charge 3 days before the next execution date
                return $nextExecDate <= $targetDate;
            }
        );

        return array_values($contractsDue);
    }

    /**
     * Calculate the next upcoming execution date for a contract.
     *
     * Advances from start_date by the contract period until we reach
     * a date >= today. This gives us the next execution to be charged.
     *
     * Example — weekly contract started 2026-01-01, today is 2026-02-17:
     *   start + 7wks = 2026-02-19 >= today → next execution = 2026-02-19
     *
     * @param \LimpVix\Domain\Contract\Contract $contract
     * @param \DateTimeImmutable $today
     * @return \DateTimeImmutable|null
     */
    private function calculateNextExecutionDate($contract, \DateTimeImmutable $today): ?\DateTimeImmutable
    {
        $startDate = $contract->getStartDate();

        if ($startDate === null) {
            return null;
        }

        $periodDays = match ($contract->getContractType()) {
            'weekly'   => 7,
            'biweekly' => 14,
            default    => 30, // monthly
        };

        // Advance from start_date by period until we reach today or later
        $candidate = $startDate;
        $maxIterations = 500; // Safety guard (500 × 7 days ≈ 9.5 years)
        $iteration = 0;

        while ($candidate < $today && $iteration < $maxIterations) {
            $candidate = $candidate->modify("+{$periodDays} days");
            $iteration++;
        }

        return $candidate;
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
