<?php
/**
 * RecurringPaymentFailed - Domain Event
 *
 * RESPONSABILIDADE:
 * - Notificar sistema que pagamento recorrente falhou
 * - Trigger: RecurringPayment::markAsFailed()
 * - Listeners: RetryScheduler, AdminNotifier
 *
 * LISTENERS ESPERADOS:
 * - Schedule retry if attempt_count < 3
 * - Send notification to admin (if max attempts reached)
 * - Send notification to customer (payment failed, update method)
 * - Log failure for analytics
 *
 * @package LimpVix\Domain\Finance\Events
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Domain\Finance\Events;

use LimpVix\Domain\Finance\RecurringPayment;

defined('ABSPATH') || exit;

final class RecurringPaymentFailed
{
    private RecurringPayment $payment;
    private string $reason;
    private bool $canRetry;
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        RecurringPayment $payment,
        string $reason,
        bool $canRetry
    ) {
        $this->payment = $payment;
        $this->reason = $reason;
        $this->canRetry = $canRetry;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getPayment(): RecurringPayment
    {
        return $this->payment;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function canRetry(): bool
    {
        return $this->canRetry;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    /**
     * Serialize to array (for WordPress action hooks)
     */
    public function toArray(): array
    {
        return [
            'event' => 'recurring_payment.failed',
            'payment_uuid' => $this->payment->getPaymentUuid(),
            'contract_id' => $this->payment->getContractId(),
            'billing_cycle' => $this->payment->getBillingCycleNumber(),
            'amount' => $this->payment->getAmount(),
            'attempt_count' => $this->payment->getAttemptCount(),
            'can_retry' => $this->canRetry,
            'failure_reason' => $this->reason,
            'gateway_transaction_id' => $this->payment->getGatewayTransactionId(),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
