<?php
/**
 * ExecutionCancelled - Evento disparado quando execução é cancelada
 *
 * @package LimpVix\Domain\Execution\Events
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution\Events;

use LimpVix\Domain\Execution\ContractExecution;

defined('ABSPATH') || exit;

final class ExecutionCancelled
{
    private ContractExecution $execution;
    private string $reason;
    private \DateTimeImmutable $occurredAt;

    public function __construct(ContractExecution $execution, string $reason)
    {
        $this->execution = $execution;
        $this->reason = $reason;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getExecution(): ContractExecution
    {
        return $this->execution;
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
            'event' => 'execution.cancelled',
            'execution_id' => $this->execution->getId()->toInt(),
            'contract_id' => $this->execution->getContractId()->toInt(),
            'professional_user_id' => $this->execution->getProfessionalUserId(),
            'reason' => $this->reason,
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
