<?php
/**
 * ExecutionRescheduled - Evento disparado quando execução é reagendada
 *
 * @package LimpVix\Domain\Execution\Events
 * @since 0.9.0
 */

namespace LimpVix\Domain\Execution\Events;

use LimpVix\Domain\Execution\ContractExecution;

defined('ABSPATH') || exit;

final class ExecutionRescheduled
{
    private ContractExecution $execution;
    private \DateTimeImmutable $oldDate;
    private \DateTimeImmutable $newDate;
    private \DateTimeImmutable $occurredAt;

    public function __construct(
        ContractExecution $execution,
        \DateTimeImmutable $oldDate,
        \DateTimeImmutable $newDate
    ) {
        $this->execution = $execution;
        $this->oldDate = $oldDate;
        $this->newDate = $newDate;
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getExecution(): ContractExecution
    {
        return $this->execution;
    }

    public function getOldDate(): \DateTimeImmutable
    {
        return $this->oldDate;
    }

    public function getNewDate(): \DateTimeImmutable
    {
        return $this->newDate;
    }

    public function getOccurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function toArray(): array
    {
        return [
            'event' => 'execution.rescheduled',
            'execution_id' => $this->execution->getId()->toInt(),
            'contract_id' => $this->execution->getContractId()->toInt(),
            'professional_user_id' => $this->execution->getProfessionalUserId(),
            'old_scheduled_date' => $this->oldDate->format('Y-m-d H:i:s'),
            'new_scheduled_date' => $this->newDate->format('Y-m-d H:i:s'),
            'occurred_at' => $this->occurredAt->format('Y-m-d H:i:s'),
        ];
    }
}
