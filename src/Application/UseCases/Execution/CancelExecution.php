<?php
/**
 * CancelExecution - Use Case para cancelar execução
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;

defined('ABSPATH') || exit;

final class CancelExecution
{
    private ContractExecutionRepositoryInterface $repository;

    public function __construct(ContractExecutionRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Cancelar execução (* → cancelled)
     *
     * @param int $executionId
     * @param string $reason
     * @return void
     * @throws \DomainException
     */
    public function execute(int $executionId, string $reason): void
    {
        $execution = $this->repository->findById(ExecutionId::fromInt($executionId));

        if (!$execution) {
            throw new \DomainException("Execution ID {$executionId} not found");
        }

        $execution->cancelExecution($reason);
        $this->repository->save($execution);
    }
}
