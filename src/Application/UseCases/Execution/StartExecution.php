<?php
/**
 * StartExecution - Use Case para iniciar execução
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;

defined('ABSPATH') || exit;

final class StartExecution
{
    private ContractExecutionRepositoryInterface $repository;

    public function __construct(ContractExecutionRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Iniciar execução (scheduled → in_progress)
     *
     * @param int $executionId
     * @return void
     * @throws \DomainException
     */
    public function execute(int $executionId): void
    {
        $execution = $this->repository->findById(ExecutionId::fromInt($executionId));

        if (!$execution) {
            throw new \DomainException("Execution ID {$executionId} not found");
        }

        $execution->startExecution();
        $this->repository->save($execution);
    }
}
