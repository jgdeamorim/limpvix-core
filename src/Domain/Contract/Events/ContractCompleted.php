<?php
/**
 * ContractCompleted - Evento disparado quando contrato é completado
 *
 * @package LimpVix\Domain\Contract\Events
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Events;

use LimpVix\Domain\Contract\Contract;

defined('ABSPATH') || exit;

final class ContractCompleted
{
    private Contract $contract;
    private \DateTimeImmutable $occurredAt;

    public function __construct(Contract $contract)
    {
        $this->contract = $contract;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getContract(): Contract
    {
        return $this->contract;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event' => 'contract.completed',
            'contract_id' => $this->contract->getId()->toInt(),
            'contract_number' => $this->contract->getContractNumber(),
            'professional_id' => $this->contract->getAllocatedProfessionalId(),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
