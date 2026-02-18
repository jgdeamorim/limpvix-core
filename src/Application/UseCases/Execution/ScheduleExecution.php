<?php
/**
 * ScheduleExecution - Use Case para agendar execução
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;

defined('ABSPATH') || exit;

final class ScheduleExecution
{
    private ContractExecutionRepositoryInterface $repository;

    public function __construct(ContractExecutionRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Agendar execução (draft → scheduled)
     *
     * @param int $executionId
     * @param \DateTimeImmutable $scheduledDate
     * @return void
     * @throws \DomainException
     */
    public function execute(int $executionId, \DateTimeImmutable $scheduledDate): void
    {
        $execution = $this->repository->findById(ExecutionId::fromInt($executionId));

        if (!$execution) {
            throw new \DomainException("Execution ID {$executionId} not found");
        }

        $execution->scheduleExecution($scheduledDate);
        $this->repository->save($execution);
    }
}
