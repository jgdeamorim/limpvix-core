<?php

declare(strict_types=1);

namespace LimpVix\Core;

defined("ABSPATH") || exit;

/**
 * User Roles Management
 * 
 * Registra e gerencia custom user roles para LimpVix:
 * - limpvix_customer: Clientes que solicitam serviços
 * - limpvix_professional: Profissionais que executam serviços
 */
final class UserRoles
{
    /**
     * Registrar custom roles no WordPress
     * Chamado no activation hook do plugin
     */
    public static function register(): void
    {
        self::registerCustomerRole();
        self::registerProfessionalRole();
    }

    /**
     * Remover custom roles no WordPress
     * Chamado no deactivation hook do plugin
     */
    public static function unregister(): void
    {
        remove_role("limpvix_customer");
        remove_role("limpvix_professional");
    }

    /**
     * Registrar role de Cliente
     */
    private static function registerCustomerRole(): void
    {
        add_role(
            "limpvix_customer",
            __("Cliente LimpVix", "limpvix-core"),
            [
                // Capabilities básicas
                "read" => true,
                
                // Briefing (Solicitações de Serviço)
                "create_limpvix_briefings" => true,
                "edit_own_limpvix_briefings" => true,
                "view_own_limpvix_briefings" => true,
                "delete_own_limpvix_briefings" => true,
                
                // Contratos
                "view_own_limpvix_contracts" => true,
                "request_contract_changes" => true,
                
                // Execuções
                "view_own_limpvix_executions" => true,
                "provide_feedback_limpvix_executions" => true,
                
                // Pagamentos
                "view_own_limpvix_invoices" => true,
                "make_limpvix_payments" => true,
                
                // Perfil
                "edit_limpvix_customer_profile" => true,
            ]
        );
    }

    /**
     * Registrar role de Profissional
     */
    private static function registerProfessionalRole(): void
    {
        add_role(
            "limpvix_professional",
            __("Profissional LimpVix", "limpvix-core"),
            [
                // Capabilities básicas
                "read" => true,
                
                // Ofertas (Propostas de Trabalho)
                "view_limpvix_offers" => true,
                "accept_limpvix_offers" => true,
                "reject_limpvix_offers" => true,
                
                // Contratos
                "view_assigned_limpvix_contracts" => true,
                "request_schedule_changes" => true,
                
                // Execuções
                "start_limpvix_executions" => true,
                "complete_limpvix_executions" => true,
                "upload_limpvix_evidence" => true,
                "view_assigned_limpvix_executions" => true,
                
                // Disponibilidade
                "edit_limpvix_professional_availability" => true,
                "edit_limpvix_professional_profile" => true,
                
                // Pagamentos (Payout)
                "view_own_limpvix_payouts" => true,
                "configure_limpvix_payout_method" => true,
            ]
        );
    }

    /**
     * Verificar se usuário é Cliente
     */
    public static function isCustomer(int $userId): bool
    {
        $user = get_user_by("id", $userId);
        return $user && in_array("limpvix_customer", (array) $user->roles, true);
    }

    /**
     * Verificar se usuário é Profissional
     */
    public static function isProfessional(int $userId): bool
    {
        $user = get_user_by("id", $userId);
        return $user && in_array("limpvix_professional", (array) $user->roles, true);
    }

    /**
     * Verificar se usuário é Admin
     */
    public static function isAdmin(int $userId): bool
    {
        $user = get_user_by("id", $userId);
        return $user && user_can($user, "manage_options");
    }

    /**
     * Obter role do usuário (limpvix específico)
     */
    public static function getUserRole(int $userId): ?string
    {
        if (self::isAdmin($userId)) {
            return "admin";
        }
        
        if (self::isCustomer($userId)) {
            return "customer";
        }
        
        if (self::isProfessional($userId)) {
            return "professional";
        }
        
        return null;
    }

    /**
     * Atribuir role de Cliente a um usuário
     */
    public static function assignCustomerRole(int $userId): bool
    {
        $user = get_user_by("id", $userId);
        if (!$user) {
            return false;
        }

        $user->set_role("limpvix_customer");
        return true;
    }

    /**
     * Atribuir role de Profissional a um usuário
     */
    public static function assignProfessionalRole(int $userId): bool
    {
        $user = get_user_by("id", $userId);
        if (!$user) {
            return false;
        }

        $user->set_role("limpvix_professional");
        return true;
    }
}
