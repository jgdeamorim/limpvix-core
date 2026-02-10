<?php
/**
 * ListExecutions - Use Case for listing executions
 *
 * RESPONSABILIDADE:
 * - Listar execuções com filtros opcionais
 * - Suportar filtro por contract_id ou professional_id
 * - Retornar todas execuções para admin
 *
 * @package LimpVix\Application\UseCase\Execution
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCase\Execution;

use LimpVix\Domain\Execution\ContractExecutionRepositoryInterface;
use LimpVix\Domain\Contract\ContractId;

defined('ABSPATH') || exit;

final class ListExecutions
{
    public function __construct(
        private ContractExecutionRepositoryInterface $repository
    ) {}

    /**
     * Execute Use Case
     *
     * @param int|null $contractId Filter by contract ID
     * @param int|null $professionalId Filter by professional ID
     * @return array Array of ContractExecution aggregates
     */
    public function execute(?int $contractId = null, ?int $professionalId = null): array
    {
        // Filtrar por contrato
        if ($contractId !== null) {
            return $this->repository->findByContractId(ContractId::fromInt($contractId));
        }

        // Filtrar por profissional
        if ($professionalId !== null) {
            return $this->repository->findByProfessionalId($professionalId);
        }

        // Admin: listar todas (pode adicionar paginação futuramente)
        // Por ora, retorna vazio se não houver filtro (evita listar tudo sem controle)
        return [];
    }
}
