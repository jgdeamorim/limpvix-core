<?php
/**
 * MarkNoShow - Use Case para marcar profissional como não comparecido
 *
 * @package LimpVix\Application\UseCase\Execution
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCase\Execution;

use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;

defined('ABSPATH') || exit;

final class MarkNoShow
{
    private ContractExecutionRepositoryInterface $repository;

    public function __construct(ContractExecutionRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Marcar profissional como não comparecido (scheduled → no_show)
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

        $execution->markNoShow();
        $this->repository->save($execution);
    }
}
