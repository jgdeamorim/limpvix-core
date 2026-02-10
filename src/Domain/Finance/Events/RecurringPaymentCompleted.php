<?php
/**
 * RecurringPaymentCompleted - Domain Event
 *
 * RESPONSABILIDADE:
 * - Notificar sistema que pagamento recorrente foi completado
 * - Trigger: RecurringPayment::markAsCompleted()
 * - Listeners: ContractRenewedListener (renova contrato)
 *
 * LISTENERS ESPERADOS:
 * - Renew contract with new end_date
 * - Send confirmation email to customer
 * - Update analytics/metrics
 * - Create financial ledger entry
 *
 * @package LimpVix\Domain\Finance\Events
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Domain\Finance\Events;

use LimpVix\Domain\Finance\RecurringPayment;

defined('ABSPATH') || exit;

final class RecurringPaymentCompleted
{
    private RecurringPayment $payment;
    private \DateTimeImmutable $occurredAt;

    public function __construct(RecurringPayment $payment)
    {
        $this->payment = $payment;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getPayment(): RecurringPayment
    {
        return $this->payment;
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
            'event' => 'recurring_payment.completed',
            'payment_uuid' => $this->payment->getPaymentUuid(),
            'contract_id' => $this->payment->getContractId(),
            'billing_cycle' => $this->payment->getBillingCycleNumber(),
            'amount' => $this->payment->getAmount(),
            'paid_at' => $this->payment->getPaidAt()->format('Y-m-d H:i:s'),
            'gateway_transaction_id' => $this->payment->getGatewayTransactionId(),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
