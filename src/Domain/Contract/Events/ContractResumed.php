<?php
/**
 * ContractResumed - Evento disparado quando contrato é retomado
 *
 * @package LimpVix\Domain\Contract\Events
 * @since 0.8.0
 */

namespace LimpVix\Domain\Contract\Events;

use LimpVix\Domain\Contract\Contract;

defined('ABSPATH') || exit;

final class ContractResumed
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
            'event' => 'contract.resumed',
            'contract_id' => $this->contract->getId()->toInt(),
            'contract_number' => $this->contract->getContractNumber(),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
