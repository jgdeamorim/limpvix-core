<?php
/**
 * GetExecution - Use Case for getting single execution
 *
 * RESPONSABILIDADE:
 * - Buscar execução por ID
 * - Lançar exceção se não encontrado
 * - Retornar aggregate completo
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;
use LimpVix\Domain\Execution\ExecutionId;
use LimpVix\Domain\Execution\ContractExecution;

defined('ABSPATH') || exit;

final class GetExecution
{
    public function __construct(
        private ContractExecutionRepositoryInterface $repository
    ) {}

    /**
     * Execute Use Case
     *
     * @param int $executionId Execution ID
     * @return ContractExecution
     * @throws \RuntimeException If execution not found
     */
    public function execute(int $executionId): ContractExecution
    {
        $execution = $this->repository->findById(ExecutionId::fromInt($executionId));

        if (!$execution) {
            throw new \RuntimeException("Execution #{$executionId} not found");
        }

        return $execution;
    }
}
