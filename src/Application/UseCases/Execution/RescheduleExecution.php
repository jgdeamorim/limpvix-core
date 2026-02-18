<?php
/**
 * RescheduleExecution - Use Case para reagendar execução
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;

defined('ABSPATH') || exit;

final class RescheduleExecution
{
    private ContractExecutionRepositoryInterface $repository;

    public function __construct(ContractExecutionRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Reagendar execução (scheduled → scheduled com nova data)
     *
     * @param int $executionId
     * @param \DateTimeImmutable $newScheduledDate
     * @return void
     * @throws \DomainException
     */
    public function execute(int $executionId, \DateTimeImmutable $newScheduledDate): void
    {
        $execution = $this->repository->findById(ExecutionId::fromInt($executionId));

        if (!$execution) {
            throw new \DomainException("Execution ID {$executionId} not found");
        }

        $execution->rescheduleExecution($newScheduledDate);
        $this->repository->save($execution);
    }
}
