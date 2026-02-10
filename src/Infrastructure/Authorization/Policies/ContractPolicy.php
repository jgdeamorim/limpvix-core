<?php
/**
 * ContractPolicy - Authorization policy for Contract resources
 *
 * REGRAS:
 * - Admin (manage_options) pode tudo
 * - Cliente pode criar apenas para si mesmo
 * - Cliente pode ver apenas seus próprios contratos
 * - Apenas admin pode update/delete
 *
 * @package LimpVix\Infrastructure\Authorization\Policies
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\Authorization\Policies;

use LimpVix\Infrastructure\Authorization\AuthorizationPolicyInterface;
use LimpVix\Domain\Contract\Contract;

defined('ABSPATH') || exit;

final class ContractPolicy implements AuthorizationPolicyInterface
{
    public function canView(int $userId, mixed $resource): bool
    {
        // Admin pode ver tudo
        if (user_can($userId, 'manage_options')) {
            return true;
        }

        // Cliente pode ver apenas seus contratos
        if ($resource instanceof Contract) {
            return $resource->getClientUserId() === $userId;
        }

        // Se resource é array com client_user_id
        if (is_array($resource) && isset($resource['client_user_id'])) {
            return (int) $resource['client_user_id'] === $userId;
        }

        return false;
    }

    public function canCreate(int $userId, mixed $data): bool
    {
        // Admin pode criar qualquer contrato
        if (user_can($userId, 'manage_options')) {
            return true;
        }

        // User pode criar apenas para si mesmo
        if (is_array($data) && isset($data['client_user_id'])) {
            return (int) $data['client_user_id'] === $userId;
        }

        // Se data é objeto com client_user_id
        if (is_object($data) && isset($data->client_user_id)) {
            return (int) $data->client_user_id === $userId;
        }

        return false;
    }

    public function canUpdate(int $userId, mixed $resource): bool
    {
        // Apenas admin pode atualizar contratos
        return user_can($userId, 'manage_options');
    }

    public function canDelete(int $userId, mixed $resource): bool
    {
        // Apenas admin pode deletar
        return user_can($userId, 'manage_options');
    }
}
