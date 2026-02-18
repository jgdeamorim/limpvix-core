<?php
/**
 * WpRecurringPaymentRepository - WordPress Implementation of RecurringPaymentRepositoryInterface
 *
 * RESPONSABILIDADE:
 * - Persistir RecurringPayment aggregate em wp_limpvix_recurring_payments
 * - Reconstituir aggregates do banco de dados
 * - Executar queries complexas para cron jobs e webhooks
 *
 * PATTERN: Repository Pattern (Infrastructure Layer)
 * - Implementa interface do Domain layer
 * - Usa $wpdb para acesso ao MySQL
 * - Converte entre domain objects e database rows
 *
 * @package LimpVix\Infrastructure\Persistence\Finance
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Infrastructure\Persistence\Finance;

use LimpVix\Domain\Finance\RecurringPayment;
use LimpVix\Domain\Finance\RecurringPaymentRepositoryInterface;

defined('ABSPATH') || exit;

final class WpRecurringPaymentRepository implements RecurringPaymentRepositoryInterface
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_recurring_payments';
    }

    /**
     * Save recurring payment (insert or update)
     *
     * @param RecurringPayment $payment
     * @return void
     * @throws \RuntimeException if save fails
     */
    public function save(RecurringPayment $payment): void
    {
        $data = [
            'payment_uuid'             => $payment->getPaymentUuid(),
            'contract_id'              => $payment->getContractId(),
            'billing_cycle_number'     => $payment->getBillingCycleNumber(),
            'amount'                   => $payment->getAmount(),
            'status'                   => $payment->getStatus()->getValue(),
            'due_date'                 => $payment->getDueDate()->format('Y-m-d'),
            'gateway_transaction_id'   => $payment->getGatewayTransactionId(),
            'attempt_count'            => $payment->getAttemptCount(),
            'paid_at'                  => $payment->getPaidAt()?->format('Y-m-d H:i:s'),
            'failure_reason'           => $payment->getFailureReason(),
            'updated_at'               => $payment->getUpdatedAt()->format('Y-m-d H:i:s'),
            // Migration 029: on-demand execution fields
            'execution_scheduled_date' => $payment->getExecutionScheduledDate()?->format('Y-m-d'),
            'pix_qrcode'               => $payment->getPixQrCode(),
            'pix_qrimage'              => $payment->getPixQrImage(),
            'payment_provider'         => $payment->getPaymentProvider(),
        ];

        $format = ['%s', '%d', '%d', '%f', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s'];

        if ($payment->getId() === null) {
            // Insert new payment
            $data['created_at'] = $payment->getCreatedAt()->format('Y-m-d H:i:s');
            $format[] = '%s';

            $result = $this->wpdb->insert($this->table, $data, $format);

            if ($result === false) {
                throw new \RuntimeException(
                    sprintf(
                        'Failed to insert RecurringPayment: %s',
                        $this->wpdb->last_error
                    )
                );
            }

            // Update payment with generated ID using reflection
            $reflection = new \ReflectionClass($payment);
            $idProperty = $reflection->getProperty('id');
            $idProperty->setAccessible(true);
            $idProperty->setValue($payment, $this->wpdb->insert_id);

        } else {
            // Update existing payment
            $result = $this->wpdb->update(
                $this->table,
                $data,
                ['id' => $payment->getId()],
                $format,
                ['%d']
            );

            if ($result === false) {
                throw new \RuntimeException(
                    sprintf(
                        'Failed to update RecurringPayment %d: %s',
                        $payment->getId(),
                        $this->wpdb->last_error
                    )
                );
            }
        }
    }

    /**
     * Find recurring payment by UUID
     *
     * @param string $paymentUuid
     * @return RecurringPayment|null
     */
    public function findByUuid(string $paymentUuid): ?RecurringPayment
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE payment_uuid = %s",
                $paymentUuid
            ),
            ARRAY_A
        );

        return $row ? $this->hydrateFromRow($row) : null;
    }

    /**
     * Find recurring payment by gateway transaction ID
     *
     * @param string $gatewayTransactionId
     * @return RecurringPayment|null
     */
    public function findByGatewayTransactionId(string $gatewayTransactionId): ?RecurringPayment
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE gateway_transaction_id = %s",
                $gatewayTransactionId
            ),
            ARRAY_A
        );

        return $row ? $this->hydrateFromRow($row) : null;
    }

    /**
     * Find recurring payment by contract and billing cycle
     *
     * @param int $contractId
     * @param int $billingCycleNumber
     * @return RecurringPayment|null
     */
    public function findByContractAndCycle(int $contractId, int $billingCycleNumber): ?RecurringPayment
    {
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE contract_id = %d AND billing_cycle_number = %d",
                $contractId,
                $billingCycleNumber
            ),
            ARRAY_A
        );

        return $row ? $this->hydrateFromRow($row) : null;
    }

    /**
     * Find all payments for a contract
     *
     * @param int $contractId
     * @return array<RecurringPayment>
     */
    public function findByContract(int $contractId): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE contract_id = %d
                 ORDER BY billing_cycle_number ASC",
                $contractId
            ),
            ARRAY_A
        );

        return array_map([$this, 'hydrateFromRow'], $rows);
    }

    /**
     * Find payments by status
     *
     * @param string $status
     * @param int|null $limit
     * @return array<RecurringPayment>
     */
    public function findByStatus(string $status, ?int $limit = null): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = %s
             ORDER BY due_date ASC, created_at ASC",
            $status
        );

        if ($limit !== null) {
            $sql .= $this->wpdb->prepare(' LIMIT %d', $limit);
        }

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        return array_map([$this, 'hydrateFromRow'], $rows);
    }

    /**
     * Find failed payments that can be retried
     * Status = failed AND attempt_count < 3
     *
     * @return array<RecurringPayment>
     */
    public function findRetryablePayments(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE status = 'failed'
             AND attempt_count < 3
             ORDER BY due_date ASC, attempt_count ASC",
            ARRAY_A
        );

        return array_map([$this, 'hydrateFromRow'], $rows);
    }

    /**
     * Find payments due for processing
     * due_date <= today AND status = pending
     *
     * @param \DateTimeImmutable $date
     * @return array<RecurringPayment>
     */
    public function findDuePayments(\DateTimeImmutable $date): array
    {
        $rows = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE due_date <= %s
                 AND status = 'pending'
                 ORDER BY due_date ASC",
                $date->format('Y-m-d')
            ),
            ARRAY_A
        );

        return array_map([$this, 'hydrateFromRow'], $rows);
    }

    /**
     * Delete recurring payment by ID
     *
     * @param int $id
     * @return bool
     */
    public function deleteById(int $id): bool
    {
        $result = $this->wpdb->delete(
            $this->table,
            ['id' => $id],
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Hydrate RecurringPayment from database row
     *
     * @param array $row
     * @return RecurringPayment
     */
    private function hydrateFromRow(array $row): RecurringPayment
    {
        return RecurringPayment::fromPersistence(
            (int) $row['id'],
            $row['payment_uuid'],
            (int) $row['contract_id'],
            (int) $row['billing_cycle_number'],
            (float) $row['amount'],
            $row['status'],
            $row['due_date'],
            $row['gateway_transaction_id'],
            (int) $row['attempt_count'],
            $row['paid_at'],
            $row['failure_reason'],
            $row['created_at'],
            $row['updated_at'],
            // Migration 029: on-demand execution fields
            $row['execution_scheduled_date'] ?? null,
            $row['pix_qrcode'] ?? null,
            $row['pix_qrimage'] ?? null,
            $row['payment_provider'] ?? 'efipay'
        );
    }

    /**
     * Get payment statistics (for admin dashboard)
     *
     * @return array
     */
    public function getStatistics(): array
    {
        $stats = $this->wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'completed' THEN amount ELSE 0 END) as total_revenue
             FROM {$this->table}",
            ARRAY_A
        );

        return [
            'total' => (int) $stats['total'],
            'pending' => (int) $stats['pending'],
            'processing' => (int) $stats['processing'],
            'completed' => (int) $stats['completed'],
            'failed' => (int) $stats['failed'],
            'cancelled' => (int) $stats['cancelled'],
            'total_revenue' => (float) $stats['total_revenue'],
        ];
    }

    /**
     * Find payments that need retry (for cron job)
     * Failed payments with last attempt > 2 days ago
     *
     * @return array<RecurringPayment>
     */
    public function findPaymentsNeedingRetry(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE status = 'failed'
             AND attempt_count < 3
             AND updated_at < DATE_SUB(NOW(), INTERVAL 2 DAY)
             ORDER BY attempt_count ASC, updated_at ASC
             LIMIT 50",
            ARRAY_A
        );

        return array_map([$this, 'hydrateFromRow'], $rows);
    }

    /**
     * Find stuck processing payments (for monitoring)
     * Processing for more than 24 hours
     *
     * @return array<RecurringPayment>
     */
    public function findStuckPayments(): array
    {
        $rows = $this->wpdb->get_results(
            "SELECT * FROM {$this->table}
             WHERE status = 'processing'
             AND updated_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
             ORDER BY updated_at ASC",
            ARRAY_A
        );

        return array_map([$this, 'hydrateFromRow'], $rows);
    }
}
