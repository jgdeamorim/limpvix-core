<?php
/**
 * ProfessionalBootstrap - Inicialização do módulo Professional
 *
 * RESPONSABILIDADES:
 * - Registrar Repository (WpMarketplaceProfessionalRepository)
 * - Registrar Admin Pages (ProfessionalManagementPage)
 * - Registrar REST API (ProfessionalController)
 * - Registrar Event Listeners
 * - Registrar Cron Jobs (se houver)
 *
 * IMPORTANTE:
 * - Este arquivo DESBLOQUEIA 3.770 linhas de código morto
 * - Sem este Bootstrap, Professional Module NÃO funciona
 * - DEVE ser registrado no Kernel.php
 *
 * @package LimpVix\Core
 * @since 0.6.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Core;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Infrastructure\Admin\Pages\ProfessionalManagementPage;
use LimpVix\Infrastructure\Admin\Pages\ProfessionalAvailabilityPage;
use LimpVix\Infrastructure\API\ProfessionalController;

defined('ABSPATH') || exit;

final class ProfessionalBootstrap
{
    /**
     * Inicializa o módulo Professional
     *
     * ORDEM DE EXECUÇÃO:
     * 1. Registrar Repository (prioridade 5 - antes de tudo)
     * 2. Registrar Admin Pages (apenas no admin)
     * 3. Registrar Admin Assets (CSS/JS)
     * 4. Registrar REST API (rest_api_init)
     * 5. Registrar Event Listeners
     * 6. Registrar Cron Jobs (se houver)
     *
     * @return void
     */
    public static function init(): void
    {
        // 1. Registrar Repository (prioridade alta)
        add_action('init', [self::class, 'registerRepository'], 5);

        // 2. Registrar Admin Pages (apenas no admin)
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'registerAdminPages']);
            add_action('admin_enqueue_scripts', [self::class, 'registerAdminAssets']);
        }

        // 3. Registrar REST API
        add_action('rest_api_init', [self::class, 'registerRestApi']);

        // 4. Registrar Event Listeners
        self::registerEventListeners();

        // Log de inicialização
        self::logInfo('Professional Module Bootstrap initialized');
    }

    /**
     * Registra o Repository no container global
     *
     * @return void
     */
    public static function registerRepository(): void
    {
        if (!isset($GLOBALS['limpvix_professional_repository'])) {
            $GLOBALS['limpvix_professional_repository'] = new WpMarketplaceProfessionalRepository();

            self::logInfo('ProfessionalRepository registered');
        }
    }

    /**
     * Registra páginas do Admin
     *
     * @return void
     */
    public static function registerAdminPages(): void
    {
        // Página principal de gerenciamento
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ProfessionalManagementPage')) {
            $managementPage = new ProfessionalManagementPage();
            $managementPage->register();

            self::logInfo('ProfessionalManagementPage registered');
        }

        // Página de disponibilidade
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ProfessionalAvailabilityPage')) {
            $availabilityPage = new ProfessionalAvailabilityPage();
            $availabilityPage->registerMenu(); // Método correto: registerMenu() não register()

            self::logInfo('ProfessionalAvailabilityPage registered');
        }
    }

    /**
     * Registra assets (CSS/JS) para páginas admin
     *
     * @param string $hook Identificador da página atual
     * @return void
     */
    public static function registerAdminAssets(string $hook): void
    {
        // Apenas nas páginas do Professional Module
        $professional_pages = [
            'toplevel_page_limpvix-professionals',
            'limpvix_page_limpvix-professional-availability',
        ];

        if (!in_array($hook, $professional_pages, true)) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'limpvix-professional-admin',
            LIMPVIX_PLUGIN_URL . 'assets/css/professional-admin.css',
            [],
            LIMPVIX_VERSION
        );

        // JS
        wp_enqueue_script(
            'limpvix-professional-admin',
            LIMPVIX_PLUGIN_URL . 'assets/js/professional-admin.js',
            ['jquery'],
            LIMPVIX_VERSION,
            true
        );

        // Localização (dados para JS)
        wp_localize_script('limpvix-professional-admin', 'limpvixProfessional', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('limpvix_professional_nonce'),
            'restUrl' => rest_url('limpvix/v1/'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Registra REST API endpoints
     *
     * @return void
     */
    public static function registerRestApi(): void
    {
        if (class_exists('LimpVix\\Infrastructure\\API\\ProfessionalController')) {
            $repository = $GLOBALS['limpvix_professional_repository'] ?? null;

            if ($repository) {
                $controller = new ProfessionalController($repository);
                $controller->register_routes();

                self::logInfo('ProfessionalController REST API registered');
            }
        }
    }

    /**
     * Registra Event Listeners para eventos de domínio
     *
     * @return void
     */
    private static function registerEventListeners(): void
    {
        // Evento: Professional Registrado
        add_action('limpvix_professional_registered', [self::class, 'onProfessionalRegistered'], 10, 1);

        // Evento: Professional Ativado
        add_action('limpvix_professional_activated', [self::class, 'onProfessionalActivated'], 10, 1);

        // Evento: Score Atualizado
        add_action('limpvix_professional_score_updated', [self::class, 'onProfessionalScoreUpdated'], 10, 1);

        // Evento: Professional Alocado para Contrato
        add_action('limpvix_contract_allocated', [self::class, 'onContractAllocated'], 10, 2);

        self::logInfo('Professional Event Listeners registered');
    }

    /**
     * Handler: Professional Registrado
     *
     * @param object $event ProfessionalRegistered event
     * @return void
     */
    public static function onProfessionalRegistered($event): void
    {
        // @future: Enviar email de boas-vindas
        // @future: Criar entrada no audit log
        // @future: Notificar admin

        self::logInfo('Professional registered: ' . ($event->professionalId ?? 'unknown'));
    }

    /**
     * Handler: Professional Ativado
     *
     * @param object $event ProfessionalActivated event
     * @return void
     */
    public static function onProfessionalActivated($event): void
    {
        // @future: Enviar notificação
        // @future: Liberar para receber ofertas

        self::logInfo('Professional activated: ' . ($event->professionalId ?? 'unknown'));
    }

    /**
     * Handler: Score Atualizado
     *
     * @param object $event ProfessionalScoreUpdated event
     * @return void
     */
    public static function onProfessionalScoreUpdated($event): void
    {
        // @future: Atualizar cache
        // @future: Recalcular ranking
        // @future: Notificar se score crítico

        self::logInfo('Professional score updated: ' . ($event->professionalId ?? 'unknown'));
    }

    /**
     * Handler: Contrato Alocado
     *
     * @param int $contractId ID do contrato
     * @param int $professionalId ID do profissional
     * @return void
     */
    public static function onContractAllocated($contractId, $professionalId): void
    {
        // @future: Atualizar histórico de alocações
        // @future: Enviar notificação para profissional

        self::logInfo("Contract $contractId allocated to professional $professionalId");
    }

    /**
     * Log informativo (apenas se WP_DEBUG ativo)
     *
     * @param string $message Mensagem de log
     * @return void
     */
    private static function logInfo(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Professional Bootstrap] [%s] %s',
                date('Y-m-d H:i:s'),
                $message
            ));
        }
    }

    /**
     * Health check do módulo Professional
     *
     * @return array Status do módulo
     */
    public static function healthCheck(): array
    {
        return [
            'module' => 'Professional',
            'repository_loaded' => isset($GLOBALS['limpvix_professional_repository']),
            'management_page_class_exists' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ProfessionalManagementPage'),
            'controller_class_exists' => class_exists('LimpVix\\Infrastructure\\API\\ProfessionalController'),
            'domain_class_exists' => class_exists('LimpVix\\Domain\\Professional\\Professional'),
            'use_case_exists' => class_exists('LimpVix\\Application\\UseCases\\Professional\\RegisterProfessional'),
        ];
    }
}
