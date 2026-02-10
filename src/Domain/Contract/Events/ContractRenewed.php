<?php
/**
 * ContractRenewed - Domain Event
 *
 * RESPONSABILIDADE:
 * - Notificar sistema que contrato foi renovado automaticamente
 * - Trigger: Contract::renewWithPayment()
 * - Listeners: EmailNotifier, AnalyticsTracker
 *
 * LISTENERS ESPERADOS:
 * - Send renewal confirmation email to customer
 * - Send notification to professional (contract renewed)
 * - Update contract analytics (renewal rate)
 * - Schedule next execution
 *
 * @package LimpVix\Domain\Contract\Events
 * @since 0.9.0 (GAP #2)
 */

namespace LimpVix\Domain\Contract\Events;

use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Finance\RecurringPayment;

defined('ABSPATH') || exit;

final class ContractRenewed
{
    private Contract $contract;
    private RecurringPayment $payment;
    private \DateTimeImmutable $renewedAt;
    private \DateTimeImmutable $newEndDate;

    public function __construct(
        Contract $contract,
        RecurringPayment $payment,
        \DateTimeImmutable $renewedAt,
        \DateTimeImmutable $newEndDate
    ) {
        $this->contract = $contract;
        $this->payment = $payment;
        $this->renewedAt = $renewedAt;
        $this->newEndDate = $newEndDate;
    }

    public function getContract(): Contract
    {
        return $this->contract;
    }

    public function getPayment(): RecurringPayment
    {
        return $this->payment;
    }

    public function getRenewedAt(): \DateTimeImmutable
    {
        return $this->renewedAt;
    }

    public function getNewEndDate(): \DateTimeImmutable
    {
        return $this->newEndDate;
    }

    /**
     * Serialize to array (for WordPress action hooks)
     */
    public function toArray(): array
    {
        return [
            'event' => 'contract.renewed',
            'contract_id' => $this->contract->getId()->toInt(),
            'contract_number' => $this->contract->getContractNumber(),
            'client_user_id' => $this->contract->getClientUserId(),
            'professional_id' => $this->contract->getAllocatedProfessionalId(),
            'payment_uuid' => $this->payment->getPaymentUuid(),
            'payment_amount' => $this->payment->getAmount(),
            'billing_cycle' => $this->payment->getBillingCycleNumber(),
            'renewed_at' => $this->renewedAt->format('Y-m-d H:i:s'),
            'new_end_date' => $this->newEndDate->format('Y-m-d'),
            'contract_type' => $this->contract->getContractType(),
        ];
    }
}
