<?php
/**
 * ContractActivated - Evento disparado quando contrato é ativado
 *
 * @package LimpVix\Domain\Contract\Events
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Events;

use LimpVix\Domain\Contract\Contract;

defined('ABSPATH') || exit;

final class ContractActivated
{
    private Contract $contract;
    private int $professionalId;
    private \DateTimeImmutable $occurredAt;

    public function __construct(Contract $contract, int $professionalId)
    {
        $this->contract = $contract;
        $this->professionalId = $professionalId;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getContract(): Contract
    {
        return $this->contract;
    }

    public function getProfessionalId(): int
    {
        return $this->professionalId;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event' => 'contract.activated',
            'contract_id' => $this->contract->getId()->toInt(),
            'contract_number' => $this->contract->getContractNumber(),
            'professional_id' => $this->professionalId,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
