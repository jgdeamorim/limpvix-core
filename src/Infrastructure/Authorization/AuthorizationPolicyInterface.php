<?php
/**
 * AuthorizationPolicyInterface - Interface for authorization policies
 *
 * RESPONSABILIDADE:
 * - Definir contrato para policies de autorização
 * - Padronizar verificações de permissão por resource
 * - CRUD permissions: view, create, update, delete
 *
 * IMPLEMENTAÇÃO:
 * - Cada recurso tem sua própria Policy
 * - Policies verificam WordPress capabilities e ownership
 * - Retorna boolean (autorizado/não autorizado)
 *
 * @package LimpVix\Infrastructure\Authorization
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\Authorization;

defined('ABSPATH') || exit;

interface AuthorizationPolicyInterface
{
    /**
     * Check if user can view resource
     *
     * @param int $userId User ID requesting access
     * @param mixed $resource Resource being accessed (object, array, or null)
     * @return bool True if authorized
     */
    public function canView(int $userId, mixed $resource): bool;

    /**
     * Check if user can create resource
     *
     * @param int $userId User ID requesting access
     * @param mixed $data Creation data (usually array)
     * @return bool True if authorized
     */
    public function canCreate(int $userId, mixed $data): bool;

    /**
     * Check if user can update resource
     *
     * @param int $userId User ID requesting access
     * @param mixed $resource Resource being updated
     * @return bool True if authorized
     */
    public function canUpdate(int $userId, mixed $resource): bool;

    /**
     * Check if user can delete resource
     *
     * @param int $userId User ID requesting access
     * @param mixed $resource Resource being deleted
     * @return bool True if authorized
     */
    public function canDelete(int $userId, mixed $resource): bool;
}
