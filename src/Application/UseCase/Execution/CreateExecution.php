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
     * IMPORTANTE - ID Semantics (DIFERENTE de Contract.allocated_professional_id):
     * @param int $contractId Contract ID (wp_limpvix_contracts.id)
     * @param int $professionalUserId WordPress User ID (wp_users.ID)
     *                                ⚠️ ATENÇÃO: Este campo armazena user_id, NÃO professional.id (PK)
     *                                Motivo: Domain Execution foi projetado para lookup direto em wp_users
     *                                Diferente de Contract que usa professional.id (PK)
     * @param \DateTimeImmutable $scheduledDate Data agendada para execução
     * @return ContractExecution Aggregate criado e persistido
     *
     * INCONSISTÊNCIA CONHECIDA:
     * - wp_limpvix_contracts.allocated_professional_id → professional.id (PK da tabela professionals)
     * - wp_limpvix_executions.professional_id → user_id (FK para wp_users.ID)
     * Considerar padronizar no futuro para usar sempre professional.id (PK).
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
