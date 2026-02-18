<?php
/**
 * CompleteExecution - Use Case para completar execução
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;

defined('ABSPATH') || exit;

final class CompleteExecution
{
    private ContractExecutionRepositoryInterface $repository;

    public function __construct(ContractExecutionRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Completar execução (in_progress → completed)
     *
     * @param int $executionId
     * @param string|null $notes
     * @param array $photos
     * @return void
     * @throws \DomainException
     */
    public function execute(int $executionId, ?string $notes = null, array $photos = []): void
    {
        $execution = $this->repository->findById(ExecutionId::fromInt($executionId));

        if (!$execution) {
            throw new \DomainException("Execution ID {$executionId} not found");
        }

        $execution->completeExecution($notes, $photos);
        $this->repository->save($execution);
    }
}
