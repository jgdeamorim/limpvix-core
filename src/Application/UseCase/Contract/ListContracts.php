<?php
/**
 * ListContracts - Use Case for listing contracts
 *
 * RESPONSABILIDADE:
 * - Listar contratos com filtros
 * - Suportar filtro por client_id ou status
 * - Retornar array de Contract aggregates
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCase\Contract;

use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

final class ListContracts
{
    public function __construct(
        private ContractRepositoryInterface $repository
    ) {}

    /**
     * Execute Use Case
     *
     * @param int|null $clientUserId Filter by client user ID
     * @param string|null $status Filter by status ('active', 'pending_allocation', etc.)
     * @return array Array of Contract aggregates
     */
    public function execute(?int $clientUserId = null, ?string $status = null): array
    {
        // Filtrar por cliente
        if ($clientUserId !== null) {
            return $this->repository->findByClientId($clientUserId);
        }

        // Filtrar por status ativo
        if ($status === 'active') {
            return $this->repository->findActiveContracts();
        }

        // Admin sem filtros: retornar contratos ativos (comportamento padrão)
        return $this->repository->findActiveContracts();
    }
}
