<?php
/**
 * ContractCancelled - Evento disparado quando contrato é cancelado
 *
 * @package LimpVix\Domain\Contract\Events
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Events;

use LimpVix\Domain\Contract\Contract;

defined('ABSPATH') || exit;

final class ContractCancelled
{
    private Contract $contract;
    private string $reason;
    private \DateTimeImmutable $occurredAt;

    public function __construct(Contract $contract, string $reason = '')
    {
        $this->contract = $contract;
        $this->reason = $reason;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getContract(): Contract
    {
        return $this->contract;
    }

    public function getReason(): string
    {
        return $this->reason;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event' => 'contract.cancelled',
            'contract_id' => $this->contract->getId()->toInt(),
            'contract_number' => $this->contract->getContractNumber(),
            'professional_id' => $this->contract->getAllocatedProfessionalId(),
            'reason' => $this->reason,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
