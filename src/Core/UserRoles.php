<?php

declare(strict_types=1);

namespace LimpVix\Core;

defined("ABSPATH") || exit;

/**
 * User Roles Management
 *
 * Registra e gerencia custom user roles para LimpVix:
 * - limpvix_customer:          Clientes que solicitam serviços
 * - limpvix_professional:      Profissionais que executam serviços
 * - limpvix_gerente_nacional:  Equipe LimpVix — escopo Brasil todo
 * - limpvix_gerente_estadual:  Equipe LimpVix — escopo UF
 * - limpvix_gerente_regional:  Equipe LimpVix — escopo UF + Zona
 * - limpvix_financeiro:        Equipe LimpVix — autorização e processamento de pagamentos
 *
 * Escopo geográfico armazenado em user meta:
 * - limpvix_user_uf   (ex: "SP")
 * - limpvix_user_zona (ex: "Zona Sul")
 */
final class UserRoles
{
    /** Meta keys para escopo geográfico */
    public const META_UF         = 'limpvix_user_uf';
    public const META_ZONA       = 'limpvix_user_zona';
    public const META_MUNICIPIO  = 'limpvix_user_municipio';

    /**
     * Registrar custom roles no WordPress
     * Chamado no activation hook do plugin
     */
    public static function register(): void
    {
        self::registerCustomerRole();
        self::registerProfessionalRole();
        self::registerGerenteNacionalRole();
        self::registerGerenteEstadualRole();
        self::registerGerenteRegionalRole();
        self::registerGerenteMunicipalRole();
        self::registerFinanceiroRole();
    }

    /**
     * Remover custom roles no WordPress
     * Chamado no deactivation hook do plugin
     */
    public static function unregister(): void
    {
        remove_role("limpvix_customer");
        remove_role("limpvix_professional");
        remove_role("limpvix_gerente_nacional");
        remove_role("limpvix_gerente_estadual");
        remove_role("limpvix_gerente_regional");
        remove_role("limpvix_gerente_municipal");
        remove_role("limpvix_financeiro");
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Role registration methods
    // ──────────────────────────────────────────────────────────────────────────

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
     * Registrar role de Gerente Nacional
     * Escopo: Brasil todo | Pode: tudo exceto processar pagamentos
     */
    private static function registerGerenteNacionalRole(): void
    {
        add_role(
            "limpvix_gerente_nacional",
            __("Gerente Nacional LimpVix", "limpvix-core"),
            [
                "read"                       => true,
                "limpvix_finance_view"        => true,
                "limpvix_finance_manage"      => true,
                "limpvix_view_payouts"        => true,
                "limpvix_authorize_payout"    => true,
                "limpvix_view_feedback"       => true,
                "limpvix_resolve_feedback"    => true,
                "limpvix_review_evidence"     => true,
                "limpvix_manage_settings"     => true,
                "limpvix_manage_users"        => true,
            ]
        );
    }

    /**
     * Registrar role de Gerente Estadual
     * Escopo: UF (via meta limpvix_user_uf) | Pode: visualizar, resolver, autorizar pagamentos da UF
     */
    private static function registerGerenteEstadualRole(): void
    {
        add_role(
            "limpvix_gerente_estadual",
            __("Gerente Estadual LimpVix", "limpvix-core"),
            [
                "read"                       => true,
                "limpvix_finance_view"        => true,
                "limpvix_view_payouts"        => true,
                "limpvix_authorize_payout"    => true,
                "limpvix_view_feedback"       => true,
                "limpvix_resolve_feedback"    => true,
                "limpvix_review_evidence"     => true,
            ]
        );
    }

    /**
     * Registrar role de Gerente Regional
     * Escopo: UF + Zona (via meta) | Pode: visualizar, resolver feedbacks/evidências da zona
     */
    private static function registerGerenteRegionalRole(): void
    {
        add_role(
            "limpvix_gerente_regional",
            __("Gerente Regional LimpVix", "limpvix-core"),
            [
                "read"                       => true,
                "limpvix_finance_view"        => true,
                "limpvix_view_payouts"        => true,
                "limpvix_view_feedback"       => true,
                "limpvix_resolve_feedback"    => true,
                "limpvix_review_evidence"     => true,
            ]
        );
    }

    /**
     * Registrar role de Gerente Municipal (G-GERENTE-MUNICIPAL)
     * Escopo: Município (via meta limpvix_user_municipio) | Pode: visualizar, resolver feedbacks/evidências do município
     */
    private static function registerGerenteMunicipalRole(): void
    {
        add_role(
            "limpvix_gerente_municipal",
            __("Gerente Municipal LimpVix", "limpvix-core"),
            [
                "read"                       => true,
                "limpvix_finance_view"        => true,
                "limpvix_view_payouts"        => true,
                "limpvix_view_feedback"       => true,
                "limpvix_resolve_feedback"    => true,
                "limpvix_review_evidence"     => true,
            ]
        );
    }

    /**
     * Registrar role de Financeiro
     * Escopo: configurável | Pode: autorizar e processar pagamentos
     */
    private static function registerFinanceiroRole(): void
    {
        add_role(
            "limpvix_financeiro",
            __("Financeiro LimpVix", "limpvix-core"),
            [
                "read"                       => true,
                "limpvix_finance_view"        => true,
                "limpvix_finance_manage"      => true,
                "limpvix_finance_payout"      => true,
                "limpvix_view_payouts"        => true,
                "limpvix_authorize_payout"    => true,
                "limpvix_process_payout"      => true,
            ]
        );
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Role check methods
    // ──────────────────────────────────────────────────────────────────────────

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

    /**
     * Verificar se usuário é Gerente (qualquer nível)
     */
    public static function isManager(int $userId): bool
    {
        $user = get_user_by("id", $userId);
        if (!$user) {
            return false;
        }
        $managerRoles = ["limpvix_gerente_nacional", "limpvix_gerente_estadual", "limpvix_gerente_regional", "limpvix_gerente_municipal"];
        return !empty(array_intersect($managerRoles, (array) $user->roles));
    }

    /**
     * Verificar se usuário é Financeiro
     */
    public static function isFinanceiro(int $userId): bool
    {
        $user = get_user_by("id", $userId);
        return $user && in_array("limpvix_financeiro", (array) $user->roles, true);
    }

    /**
     * Verificar se usuário é equipe LimpVix (qualquer role interno)
     */
    public static function isStaff(int $userId): bool
    {
        return self::isAdmin($userId) || self::isManager($userId) || self::isFinanceiro($userId);
    }

    /**
     * Obter role do usuário (limpvix específico) — atualizado com novos roles
     */
    public static function getUserRole(int $userId): ?string
    {
        if (self::isAdmin($userId)) {
            return "admin";
        }
        if (in_array("limpvix_gerente_nacional", (array) (get_user_by("id", $userId)->roles ?? []), true)) {
            return "gerente_nacional";
        }
        if (in_array("limpvix_gerente_estadual", (array) (get_user_by("id", $userId)->roles ?? []), true)) {
            return "gerente_estadual";
        }
        if (in_array("limpvix_gerente_regional", (array) (get_user_by("id", $userId)->roles ?? []), true)) {
            return "gerente_regional";
        }
        if (in_array("limpvix_gerente_municipal", (array) (get_user_by("id", $userId)->roles ?? []), true)) {
            return "gerente_municipal";
        }
        if (in_array("limpvix_financeiro", (array) (get_user_by("id", $userId)->roles ?? []), true)) {
            return "financeiro";
        }
        if (self::isCustomer($userId)) {
            return "customer";
        }
        if (self::isProfessional($userId)) {
            return "professional";
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Geographic scope helpers (UF + Zona via user meta)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Obter UF do usuário (escopo geográfico)
     *
     * @return string|null Ex: "SP", "RJ"
     */
    public static function getUserUf(int $userId): ?string
    {
        $uf = get_user_meta($userId, self::META_UF, true);
        return !empty($uf) ? strtoupper(sanitize_text_field($uf)) : null;
    }

    /**
     * Obter Zona do usuário dentro da UF
     *
     * @return string|null Ex: "Zona Sul", "Zona Norte"
     */
    public static function getUserZona(int $userId): ?string
    {
        $zona = get_user_meta($userId, self::META_ZONA, true);
        return !empty($zona) ? sanitize_text_field($zona) : null;
    }

    /**
     * Obter Município do usuário (escopo geográfico — G-GERENTE-MUNICIPAL)
     *
     * @return string|null Ex: "Vitória", "Vila Velha"
     */
    public static function getUserMunicipio(int $userId): ?string
    {
        $municipio = get_user_meta($userId, self::META_MUNICIPIO, true);
        return !empty($municipio) ? sanitize_text_field($municipio) : null;
    }

    /**
     * Definir escopo geográfico do usuário
     *
     * @param string $uf        Ex: "SP"
     * @param string $zona      Ex: "Zona Sul" (vazio = toda a UF)
     * @param string $municipio Ex: "Vitória" (vazio = toda a zona/UF)
     */
    public static function setUserScope(int $userId, string $uf, string $zona = '', string $municipio = ''): void
    {
        update_user_meta($userId, self::META_UF, strtoupper(sanitize_text_field($uf)));
        update_user_meta($userId, self::META_ZONA, sanitize_text_field($zona));
        update_user_meta($userId, self::META_MUNICIPIO, sanitize_text_field($municipio));
    }

    /**
     * Verificar se usuário tem acesso ao município informado (G-GERENTE-MUNICIPAL)
     * Admin, Nacional e Estadual (mesma UF) têm escopo universal
     */
    public static function hasAccessToMunicipality(int $userId, string $municipio, string $uf = ''): bool
    {
        if (self::isAdmin($userId)) {
            return true;
        }

        $roles = (array) (get_user_by("id", $userId)->roles ?? []);

        if (in_array("limpvix_gerente_nacional", $roles, true)) {
            return true;
        }

        // Estadual: access to all municipalities in their UF
        if (in_array("limpvix_gerente_estadual", $roles, true)) {
            return empty($uf) || self::hasAccessToUf($userId, $uf);
        }

        // Regional: access to municipalities in their zone
        if (in_array("limpvix_gerente_regional", $roles, true)) {
            return empty($uf) || self::hasAccessToUf($userId, $uf);
        }

        // Municipal: only their specific municipality
        if (in_array("limpvix_gerente_municipal", $roles, true)) {
            $userMunicipio = self::getUserMunicipio($userId);
            if ($userMunicipio === null) {
                return false;
            }
            return mb_strtolower($municipio) === mb_strtolower($userMunicipio);
        }

        return false;
    }

    /**
     * Verificar se usuário tem escopo no estado informado
     * Admin e Gerente Nacional têm escopo universal
     */
    public static function hasAccessToUf(int $userId, string $uf): bool
    {
        if (self::isAdmin($userId)) {
            return true;
        }
        if (in_array("limpvix_gerente_nacional", (array) (get_user_by("id", $userId)->roles ?? []), true)) {
            return true;
        }
        $userUf = self::getUserUf($userId);
        return $userUf === null || strtoupper($uf) === $userUf;
    }

    /**
     * Labels legíveis para os roles da equipe LimpVix
     */
    public static function getStaffRoleLabels(): array
    {
        return [
            'limpvix_gerente_nacional'  => 'Gerente Nacional',
            'limpvix_gerente_estadual'  => 'Gerente Estadual',
            'limpvix_gerente_regional'  => 'Gerente Regional',
            'limpvix_gerente_municipal' => 'Gerente Municipal',
            'limpvix_financeiro'        => 'Financeiro',
        ];
    }
}
