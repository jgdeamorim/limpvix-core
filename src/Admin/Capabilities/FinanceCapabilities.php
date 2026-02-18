<?php
/**
 * FinanceCapabilities - Permissões do Módulo Financeiro e Operacional
 *
 * RESPONSABILIDADE:
 * - Definir capabilities customizadas para finanças e operação
 * - Registrar nos roles WordPress e nos roles LimpVix internos
 * - Gate de acesso para todas as ações administrativas
 *
 * PRINCÍPIOS:
 * - Least Privilege (mínimo necessário)
 * - Role-based Access Control
 * - Auditável
 *
 * CAPABILITIES LEGADAS:
 * - limpvix_finance_view:      Ver dados financeiros
 * - limpvix_finance_manage:    Gerenciar (bloquear, liberar)
 * - limpvix_finance_payout:    Executar/autorizar payouts
 *
 * CAPABILITIES NOVAS (Hierarquia de Equipe):
 * - limpvix_view_payouts:      Ver lista de payouts
 * - limpvix_authorize_payout:  Autorizar payout (pré-aprovação)
 * - limpvix_process_payout:    Processar/executar payout via EFI Bank
 * - limpvix_view_feedback:     Ver feedbacks e resoluções
 * - limpvix_resolve_feedback:  Resolver feedback bloqueante
 * - limpvix_review_evidence:   Revisar evidências de execução
 * - limpvix_manage_settings:   Alterar configurações do sistema
 * - limpvix_manage_users:      Criar/editar usuários da equipe LimpVix
 *
 * @package LimpVix\Admin\Capabilities
 */

namespace LimpVix\Admin\Capabilities;

defined('ABSPATH') || exit;

class FinanceCapabilities
{
    /**
     * Capabilities legadas do módulo financeiro
     */
    private const CAPABILITIES = [
        'limpvix_finance_view',
        'limpvix_finance_manage',
        'limpvix_finance_payout',
    ];

    /**
     * Novas capabilities para hierarquia de equipe
     */
    private const STAFF_CAPABILITIES = [
        'limpvix_view_payouts',
        'limpvix_authorize_payout',
        'limpvix_process_payout',
        'limpvix_view_feedback',
        'limpvix_resolve_feedback',
        'limpvix_review_evidence',
        'limpvix_manage_settings',
        'limpvix_manage_users',
    ];

    /**
     * Registrar capabilities nos roles WordPress e LimpVix
     *
     * @return void
     */
    public function register(): void
    {
        $allCaps = array_merge(self::CAPABILITIES, self::STAFF_CAPABILITIES);

        // administrator: tudo
        $this->addCapabilitiesToRole('administrator', $allCaps);

        // shop_manager: apenas view (legado WooCommerce)
        if ($this->isWooCommerceActive()) {
            $this->addCapabilitiesToRole('shop_manager', ['limpvix_finance_view', 'limpvix_view_payouts']);
        }

        // Roles LimpVix internos já têm capabilities declaradas no registro do role (UserRoles.php).
        // Aqui garantimos idempotência adicionando novamente caso o role já exista.
        $this->addCapabilitiesToRole('limpvix_gerente_nacional', [
            'limpvix_finance_view', 'limpvix_finance_manage',
            'limpvix_view_payouts', 'limpvix_authorize_payout',
            'limpvix_view_feedback', 'limpvix_resolve_feedback',
            'limpvix_review_evidence', 'limpvix_manage_settings', 'limpvix_manage_users',
        ]);
        $this->addCapabilitiesToRole('limpvix_gerente_estadual', [
            'limpvix_finance_view',
            'limpvix_view_payouts', 'limpvix_authorize_payout',
            'limpvix_view_feedback', 'limpvix_resolve_feedback', 'limpvix_review_evidence',
        ]);
        $this->addCapabilitiesToRole('limpvix_gerente_regional', [
            'limpvix_finance_view',
            'limpvix_view_payouts',
            'limpvix_view_feedback', 'limpvix_resolve_feedback', 'limpvix_review_evidence',
        ]);
        $this->addCapabilitiesToRole('limpvix_financeiro', [
            'limpvix_finance_view', 'limpvix_finance_manage', 'limpvix_finance_payout',
            'limpvix_view_payouts', 'limpvix_authorize_payout', 'limpvix_process_payout',
        ]);
    }

    /**
     * Remover capabilities (cleanup no deactivation)
     *
     * @return void
     */
    public function unregister(): void
    {
        $allCaps = array_merge(self::CAPABILITIES, self::STAFF_CAPABILITIES);

        $this->removeCapabilitiesFromRole('administrator', $allCaps);

        if ($this->isWooCommerceActive()) {
            $this->removeCapabilitiesFromRole('shop_manager', ['limpvix_finance_view', 'limpvix_view_payouts']);
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

    // ──────────────────────────────────────────────────────────────────────────
    // Novos gates de acesso
    // ──────────────────────────────────────────────────────────────────────────

    public static function canViewPayouts(?int $userId = null): bool
    {
        return $userId === null
            ? current_user_can('limpvix_view_payouts')
            : (bool) (get_user_by('id', $userId)?->has_cap('limpvix_view_payouts'));
    }

    public static function canAuthorizePayout(?int $userId = null): bool
    {
        return $userId === null
            ? current_user_can('limpvix_authorize_payout')
            : (bool) (get_user_by('id', $userId)?->has_cap('limpvix_authorize_payout'));
    }

    public static function canProcessPayout(?int $userId = null): bool
    {
        // Admin e financeiro com limpvix_process_payout OU manage_options
        if ($userId === null) {
            return current_user_can('limpvix_process_payout') || current_user_can('manage_options');
        }
        $user = get_user_by('id', $userId);
        return $user && ($user->has_cap('limpvix_process_payout') || $user->has_cap('manage_options'));
    }

    public static function canViewFeedback(?int $userId = null): bool
    {
        return $userId === null
            ? (current_user_can('limpvix_view_feedback') || current_user_can('manage_options'))
            : (bool) (get_user_by('id', $userId)?->has_cap('limpvix_view_feedback'));
    }

    public static function canResolveFeedback(?int $userId = null): bool
    {
        return $userId === null
            ? (current_user_can('limpvix_resolve_feedback') || current_user_can('manage_options'))
            : (bool) (get_user_by('id', $userId)?->has_cap('limpvix_resolve_feedback'));
    }

    public static function canReviewEvidence(?int $userId = null): bool
    {
        return $userId === null
            ? (current_user_can('limpvix_review_evidence') || current_user_can('manage_options'))
            : (bool) (get_user_by('id', $userId)?->has_cap('limpvix_review_evidence'));
    }

    public static function canManageSettings(?int $userId = null): bool
    {
        return $userId === null
            ? (current_user_can('limpvix_manage_settings') || current_user_can('manage_options'))
            : (bool) (get_user_by('id', $userId)?->has_cap('limpvix_manage_settings'));
    }

    public static function canManageUsers(?int $userId = null): bool
    {
        return $userId === null
            ? (current_user_can('limpvix_manage_users') || current_user_can('manage_options'))
            : (bool) (get_user_by('id', $userId)?->has_cap('limpvix_manage_users'));
    }

    /**
     * Obter todas as capabilities (legadas + novas)
     *
     * @return array
     */
    public static function getAll(): array
    {
        return array_merge(self::CAPABILITIES, self::STAFF_CAPABILITIES);
    }
}
