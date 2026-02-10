<?php
/**
 * CreateExecution - Use Case para criar execução
 *
 * @package LimpVix\Application\UseCase\Execution
 * @since 0.9.0
 */

namespace LimpVix\Application\UseCase\Execution;

use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Execution\ContractExecution;
use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;

defined('ABSPATH') || exit;

final class CreateExecution
{
    private ContractExecutionRepositoryInterface $repository;

    public function __construct(ContractExecutionRepositoryInterface $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Criar nova execução vinculada a contrato
     *
     * @param int $contractId
     * @param int $professionalUserId
     * @param \DateTimeImmutable $scheduledDate
     * @return ContractExecution
     */
    public function execute(
        int $contractId,
        int $professionalUserId,
        \DateTimeImmutable $scheduledDate
    ): ContractExecution {
        $execution = ContractExecution::create(
            ContractId::fromInt($contractId),
            $professionalUserId,
            $scheduledDate
        );

        $this->repository->save($execution);

        return $execution;
    }
}
