<?php
/**
 * AuthorizationService - Central authorization service
 *
 * RESPONSABILIDADE:
 * - Gerenciar policies de autorização por resource type
 * - Delegar verificações para policies específicas
 * - Fornecer interface única para controllers
 *
 * PADRÃO:
 * - Policy Registry (armazena policies por tipo de recurso)
 * - Delegation Pattern (delega para policy específica)
 *
 * @package LimpVix\Infrastructure\Authorization
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\Authorization;

defined('ABSPATH') || exit;

final class AuthorizationService
{
    /**
     * @var array<string, AuthorizationPolicyInterface>
     */
    private array $policies = [];

    /**
     * Register policy for resource type
     *
     * @param string $resourceType Resource type identifier (e.g., 'contract', 'execution')
     * @param AuthorizationPolicyInterface $policy Policy instance
     * @return void
     */
    public function registerPolicy(string $resourceType, AuthorizationPolicyInterface $policy): void
    {
        $this->policies[$resourceType] = $policy;
    }

    /**
     * Authorize action on resource
     *
     * @param string $action Action to authorize ('view', 'create', 'update', 'delete')
     * @param int $userId User ID requesting access
     * @param string $resourceType Resource type
     * @param mixed $resource Resource data or object (null for create)
     * @return bool True if authorized
     * @throws \RuntimeException If no policy registered for resource type
     */
    public function authorize(string $action, int $userId, string $resourceType, mixed $resource = null): bool
    {
        if (!isset($this->policies[$resourceType])) {
            error_log("[AuthorizationService] No policy registered for resource type: {$resourceType}");
            throw new \RuntimeException("No policy registered for resource type: {$resourceType}");
        }

        $policy = $this->policies[$resourceType];

        return match($action) {
            'view' => $policy->canView($userId, $resource),
            'create' => $policy->canCreate($userId, $resource),
            'update' => $policy->canUpdate($userId, $resource),
            'delete' => $policy->canDelete($userId, $resource),
            default => false
        };
    }

    /**
     * Check if policy is registered for resource type
     *
     * @param string $resourceType Resource type
     * @return bool True if registered
     */
    public function hasPolicy(string $resourceType): bool
    {
        return isset($this->policies[$resourceType]);
    }

    /**
     * Get all registered policies
     *
     * @return array<string, AuthorizationPolicyInterface>
     */
    public function getPolicies(): array
    {
        return $this->policies;
    }
}
