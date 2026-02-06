<?php
/**
 * FinanceCapabilities - Permissões do Módulo Financeiro
 *
 * RESPONSABILIDADE:
 * - Definir capabilities customizadas para finanças
 * - Registrar no WordPress
 * - Gate de acesso
 *
 * PRINCÍPIOS:
 * - Least Privilege (mínimo necessário)
 * - Role-based Access Control
 * - Auditável
 *
 * CAPABILITIES:
 * - limpvix_finance_view: Ver dados financeiros
 * - limpvix_finance_manage: Gerenciar (bloquear, liberar)
 * - limpvix_finance_payout: Executar/autorizar payouts
 *
 * IMPORTANTE:
 * - Capabilities são adicionadas aos roles existentes
 * - Nunca criar roles customizados
 * - Usar roles do WordPress (administrator, shop_manager)
 *
 * PASSO 5.6.1 - Infraestrutura Admin
 *
 * @package LimpVix\Admin\Capabilities
 */

namespace LimpVix\Admin\Capabilities;

defined('ABSPATH') || exit;

class FinanceCapabilities
{
    /**
     * Capabilities do módulo
     */
    private const CAPABILITIES = [
        'limpvix_finance_view',    // Ver dados financeiros
        'limpvix_finance_manage',  // Gerenciar (bloquear, liberar)
        'limpvix_finance_payout'   // Autorizar/executar payouts
    ];

    /**
     * Registrar capabilities
     *
     * @return void
     */
    public function register(): void
    {
        // Adicionar capabilities ao role administrator
        $this->addCapabilitiesToRole('administrator', self::CAPABILITIES);

        // Adicionar apenas view ao shop_manager (se WooCommerce ativo)
        if ($this->isWooCommerceActive()) {
            $this->addCapabilitiesToRole('shop_manager', [
                'limpvix_finance_view'
            ]);
        }
    }

    /**
     * Remover capabilities (cleanup)
     *
     * @return void
     */
    public function unregister(): void
    {
        $this->removeCapabilitiesFromRole('administrator', self::CAPABILITIES);

        if ($this->isWooCommerceActive()) {
            $this->removeCapabilitiesFromRole('shop_manager', [
                'limpvix_finance_view'
            ]);
        }
    }

    /**
     * Adicionar capabilities a um role
     *
     * @param string $roleName Nome do role
     * @param array $capabilities Array de capabilities
     * @return void
     */
    private function addCapabilitiesToRole(string $roleName, array $capabilities): void
    {
        $role = get_role($roleName);

        if ($role === null) {
            return;
        }

        foreach ($capabilities as $cap) {
            $role->add_cap($cap);
        }
    }

    /**
     * Remover capabilities de um role
     *
     * @param string $roleName Nome do role
     * @param array $capabilities Array de capabilities
     * @return void
     */
    private function removeCapabilitiesFromRole(string $roleName, array $capabilities): void
    {
        $role = get_role($roleName);

        if ($role === null) {
            return;
        }

        foreach ($capabilities as $cap) {
            $role->remove_cap($cap);
        }
    }

    /**
     * Verificar se WooCommerce está ativo
     *
     * @return bool
     */
    private function isWooCommerceActive(): bool
    {
        return class_exists('WooCommerce');
    }

    /**
     * Verificar se usuário tem permissão de view
     *
     * @param int|null $userId User ID (null = current user)
     * @return bool
     */
    public static function canView(?int $userId = null): bool
    {
        if ($userId === null) {
            return current_user_can('limpvix_finance_view');
        }

        $user = get_user_by('id', $userId);
        return $user && $user->has_cap('limpvix_finance_view');
    }

    /**
     * Verificar se usuário tem permissão de manage
     *
     * @param int|null $userId User ID (null = current user)
     * @return bool
     */
    public static function canManage(?int $userId = null): bool
    {
        if ($userId === null) {
            return current_user_can('limpvix_finance_manage');
        }

        $user = get_user_by('id', $userId);
        return $user && $user->has_cap('limpvix_finance_manage');
    }

    /**
     * Verificar se usuário tem permissão de payout
     *
     * @param int|null $userId User ID (null = current user)
     * @return bool
     */
    public static function canPayout(?int $userId = null): bool
    {
        if ($userId === null) {
            return current_user_can('limpvix_finance_payout');
        }

        $user = get_user_by('id', $userId);
        return $user && $user->has_cap('limpvix_finance_payout');
    }

    /**
     * Obter todas as capabilities
     *
     * @return array
     */
    public static function getAll(): array
    {
        return self::CAPABILITIES;
    }
}
