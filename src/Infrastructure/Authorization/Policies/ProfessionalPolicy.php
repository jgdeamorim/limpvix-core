<?php
/**
 * ProfessionalPolicy - Authorization policy for Professional resources
 *
 * REGRAS:
 * - Admin pode tudo
 * - Professional pode ver/atualizar apenas seu próprio perfil
 * - Professional pode aceitar/rejeitar suas próprias ofertas
 *
 * @package LimpVix\Infrastructure\Authorization\Policies
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\Authorization\Policies;

use LimpVix\Infrastructure\Authorization\AuthorizationPolicyInterface;

defined('ABSPATH') || exit;

final class ProfessionalPolicy implements AuthorizationPolicyInterface
{
    public function canView(int $userId, mixed $resource): bool
    {
        // Admin pode ver tudo
        if (user_can($userId, 'manage_options')) {
            return true;
        }

        // Professional pode ver apenas seu próprio perfil
        if (is_int($resource)) {
            // Resource é o professional_id
            return $this->isProfessionalOwner($userId, $resource);
        }

        if (is_array($resource) && isset($resource['user_id'])) {
            return (int) $resource['user_id'] === $userId;
        }

        if (is_object($resource) && method_exists($resource, 'getUserId')) {
            return $resource->getUserId() === $userId;
        }

        return false;
    }

    public function canCreate(int $userId, mixed $data): bool
    {
        // Apenas admin pode registrar novos profissionais
        return user_can($userId, 'manage_options');
    }

    public function canUpdate(int $userId, mixed $resource): bool
    {
        // Admin pode atualizar qualquer professional
        if (user_can($userId, 'manage_options')) {
            return true;
        }

        // Professional pode atualizar apenas seu próprio perfil
        if (is_int($resource)) {
            return $this->isProfessionalOwner($userId, $resource);
        }

        if (is_object($resource) && method_exists($resource, 'getUserId')) {
            return $resource->getUserId() === $userId;
        }

        return false;
    }

    public function canDelete(int $userId, mixed $resource): bool
    {
        // Apenas admin pode deletar
        return user_can($userId, 'manage_options');
    }

    /**
     * Check if user is owner of professional profile
     *
     * @param int $userId User ID
     * @param int $professionalId Professional ID
     * @return bool True if owner
     */
    private function isProfessionalOwner(int $userId, int $professionalId): bool
    {
        $repository = $GLOBALS['limpvix_professional_repository'] ?? null;

        if (!$repository) {
            return false;
        }

        $professional = $repository->findById($professionalId);

        if (!$professional) {
            return false;
        }

        return $professional->getUserId() === $userId;
    }
}
