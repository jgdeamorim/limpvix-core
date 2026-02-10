<?php
/**
 * ExecutionScheduled - Evento disparado quando execução é agendada
 *
 * @package LimpVix\Domain\Execution\Events
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution\Events;

use LimpVix\Domain\Execution\ContractExecution;

defined('ABSPATH') || exit;

final class ExecutionScheduled
{
    private ContractExecution $execution;
    private \DateTimeImmutable $occurredAt;

    public function __construct(ContractExecution $execution)
    {
        $this->execution = $execution;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getExecution(): ContractExecution
    {
        return $this->execution;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event' => 'execution.scheduled',
            'execution_id' => $this->execution->getId()->toInt(),
            'contract_id' => $this->execution->getContractId()->toInt(),
            'professional_user_id' => $this->execution->getProfessionalUserId(),
            'scheduled_date' => $this->execution->getScheduledDate()->format('Y-m-d H:i:s'),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
