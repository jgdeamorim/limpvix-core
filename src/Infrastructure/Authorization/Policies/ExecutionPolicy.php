<?php
/**
 * ExecutionPolicy - Authorization policy for Execution resources
 *
 * REGRAS:
 * - Admin pode tudo
 * - Professional pode ver/atualizar suas execuções
 * - Cliente pode ver execuções dos seus contratos
 * - Apenas admin e professional alocado podem atualizar
 *
 * @package LimpVix\Infrastructure\Authorization\Policies
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\Authorization\Policies;

use LimpVix\Infrastructure\Authorization\AuthorizationPolicyInterface;
use LimpVix\Domain\Execution\ContractExecution;
use LimpVix\Domain\Contract\ContractId;

defined('ABSPATH') || exit;

final class ExecutionPolicy implements AuthorizationPolicyInterface
{
    public function canView(int $userId, mixed $resource): bool
    {
        // Admin pode ver tudo
        if (user_can($userId, 'manage_options')) {
            return true;
        }

        if ($resource instanceof ContractExecution) {
            // Professional pode ver suas execuções
            if ($resource->getProfessionalUserId() === $userId) {
                return true;
            }

            // Cliente pode ver execuções do seu contrato
            $contract = $this->getContract($resource->getContractId());
            if ($contract && $contract->getClientUserId() === $userId) {
                return true;
            }
        }

        return false;
    }

    public function canCreate(int $userId, mixed $data): bool
    {
        // Admin pode criar qualquer execução
        if (user_can($userId, 'manage_options')) {
            return true;
        }

        // Por ora, apenas admin pode criar execuções
        // (pode ser expandido futuramente)
        return false;
    }

    public function canUpdate(int $userId, mixed $resource): bool
    {
        // Admin pode atualizar qualquer execução
        if (user_can($userId, 'manage_options')) {
            return true;
        }

        // Professional alocado pode atualizar sua execução
        if ($resource instanceof ContractExecution) {
            return $resource->getProfessionalUserId() === $userId;
        }

        return false;
    }

    public function canDelete(int $userId, mixed $resource): bool
    {
        // Apenas admin pode deletar
        return user_can($userId, 'manage_options');
    }

    /**
     * Get contract by ID (helper method)
     *
     * @param ContractId $contractId Contract ID
     * @return \LimpVix\Domain\Contract\Contract|null
     */
    private function getContract(ContractId $contractId): ?\LimpVix\Domain\Contract\Contract
    {
        $repository = $GLOBALS['limpvix_contract_repository'] ?? null;

        if (!$repository) {
            return null;
        }

        return $repository->findById($contractId);
    }
}
