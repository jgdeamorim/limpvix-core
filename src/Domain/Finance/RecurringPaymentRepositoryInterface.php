<?php
/**
 * RecurringPaymentRepositoryInterface - Repository Contract for RecurringPayment
 *
 * RESPONSABILIDADE:
 * - Definir contrato para persistência de RecurringPayment aggregate
 * - Abstrair detalhes de implementação (WordPress, MySQL, etc)
 * - Garantir que todas implementações seguem o mesmo contrato
 *
 * PATTERN: Repository Pattern (DDD)
 * - Domain layer define interface
 * - Infrastructure layer implementa (WpRecurringPaymentRepository)
 *
 * @package LimpVix\Domain\Finance
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

interface RecurringPaymentRepositoryInterface
{
    /**
     * Save recurring payment (insert or update)
     *
     * @param RecurringPayment $payment
     * @return void
     * @throws \RuntimeException if save fails
     */
    public function save(RecurringPayment $payment): void;

    /**
     * Find recurring payment by UUID
     *
     * @param string $paymentUuid
     * @return RecurringPayment|null
     */
    public function findByUuid(string $paymentUuid): ?RecurringPayment;

    /**
     * Find recurring payment by gateway transaction ID
     * Used in webhook processing to identify payment from MercadoPago callback
     *
     * @param string $gatewayTransactionId MercadoPago payment ID
     * @return RecurringPayment|null
     */
    public function findByGatewayTransactionId(string $gatewayTransactionId): ?RecurringPayment;

    /**
     * Find recurring payment by contract and billing cycle
     * Ensures only ONE payment per cycle (idempotency)
     *
     * @param int $contractId
     * @param int $billingCycleNumber
     * @return RecurringPayment|null
     */
    public function findByContractAndCycle(int $contractId, int $billingCycleNumber): ?RecurringPayment;

    /**
     * Find all payments for a contract
     * Useful for displaying payment history
     *
     * @param int $contractId
     * @return array<RecurringPayment>
     */
    public function findByContract(int $contractId): array;

    /**
     * Find payments by status
     * Used by cron to find pending/failed payments for processing
     *
     * @param string $status Status value (pending, processing, failed, etc)
     * @param int|null $limit Optional limit
     * @return array<RecurringPayment>
     */
    public function findByStatus(string $status, ?int $limit = null): array;

    /**
     * Find failed payments that can be retried
     * Status = failed AND attempt_count < 3
     *
     * @return array<RecurringPayment>
     */
    public function findRetryablePayments(): array;

    /**
     * Find payments due for processing
     * due_date <= today AND status = pending
     *
     * @param \DateTimeImmutable $date
     * @return array<RecurringPayment>
     */
    public function findDuePayments(\DateTimeImmutable $date): array;

    /**
     * Delete recurring payment by ID
     * Should rarely be used - prefer status transitions
     *
     * @param int $id
     * @return bool
     */
    public function deleteById(int $id): bool;
}
