<?php
/**
 * ProfessionalBootstrap - Inicialização do módulo Professional
 *
 * RESPONSABILIDADES:
 * - Registrar Repository (WpMarketplaceProfessionalRepository)
 * - Registrar Admin Pages (ProfessionalManagementPage)
 * - Registrar REST API (ProfessionalController)
 *
 * MUDANÇA (SPRINT 8.5 - FASE 2):
 * - Agora registra repository e use cases no ServiceContainer
 * - Elimina duplicação de instâncias WpMarketplaceProfessionalRepository
 * - Melhora performance e testabilidade
 *
 * @package LimpVix\Core
 * @since 0.8.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Core;

use LimpVix\Infrastructure\Admin\Pages\ProfessionalManagementPage;
use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

defined('ABSPATH') || exit;

/**
 * Professional Module Bootstrap
 *
 * Registra repository, admin pages e REST API do módulo Profissionais
 */
final class ProfessionalBootstrap
{
    /**
     * Inicializa o módulo Professional
     *
     * ORDEM DE EXECUÇÃO:
     * 1. Registrar Repository (prioridade 5 - antes de tudo)
     * 2. Registrar Admin Pages (apenas no admin)
     * 3. Registrar REST API (rest_api_init)
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
            self::registerAjaxHandlers();
        }

        // 3. Registrar REST API
        add_action('rest_api_init', [self::class, 'registerRestApi']);

        self::logInfo('Professional Module Bootstrap initialized');
    }

    /**
     * Registra o Repository no ServiceContainer
     *
     * MUDANÇA (SPRINT 8.5 - FASE 2): Usa ServiceContainer ao invés de $GLOBALS
     * - Elimina duplicação de instâncias
     * - Melhora performance (singleton lazy loading)
     *
     * @return void
     */
    public static function registerRepository(): void
    {
        $container = ServiceContainer::getInstance();

        // Register using factory (lazy loading)
        $container->factory('professional_repository', function() {
            return new WpMarketplaceProfessionalRepository();
        });

        // BACKWARD COMPATIBILITY: Manter $GLOBALS temporariamente
        // TODO: Remover após refatorar todos os consumidores
        if (!isset($GLOBALS['limpvix_professional_repository'])) {
            $GLOBALS['limpvix_professional_repository'] = $container->get('professional_repository');
        }

        self::logInfo('ProfessionalRepository registered in ServiceContainer');
    }

    /**
     * Registra páginas do Admin
     *
     * @return void
     */
    public static function registerAdminPages(): void
    {
        // Register Professional Management Page (with KYC tab integrated)
        $professionalPage = new ProfessionalManagementPage();
        $professionalPage->register();

        self::logInfo('ProfessionalManagementPage registered');
    }

    /**
     * Registra REST API endpoints
     *
     * MUDANÇA (SPRINT 8.5 - FASE 2): Usa ServiceContainer ao invés de $GLOBALS
     *
     * @return void
     */
    public static function registerRestApi(): void
    {
        $container = ServiceContainer::getInstance();

        // Register ProfessionalController
        if (class_exists('LimpVix\\Infrastructure\\API\\ProfessionalController')) {
            $repository = $container->has('professional_repository')
                ? $container->get('professional_repository')
                : ($GLOBALS['limpvix_professional_repository'] ?? null);

            if ($repository) {
                $controller = new \LimpVix\Infrastructure\API\ProfessionalController();
                $controller->register();

                self::logInfo('ProfessionalController REST API registered');
            } else {
                self::logError('ProfessionalRepository not found - cannot register ProfessionalController');
            }
        }

        // Register ProfessionalOAuthController (MercadoPago OAuth endpoints)
        if (class_exists('LimpVix\\Infrastructure\\API\\ProfessionalOAuthController')) {
            $oauthController = new \LimpVix\Infrastructure\API\ProfessionalOAuthController();
            $oauthController->register_routes();

            self::logInfo('ProfessionalOAuthController REST API registered');
        }

        // Register ProfessionalDocumentController (Document KYC endpoints - GAP A)
        if (class_exists('LimpVix\\Infrastructure\\API\\ProfessionalDocumentController')) {
            // Create use cases
            $documentRepository = new \LimpVix\Infrastructure\Persistence\WpProfessionalDocumentRepository();
            $professionalRepository = $container->has('professional_repository')
                ? $container->get('professional_repository')
                : ($GLOBALS['limpvix_professional_repository'] ?? null);

            if ($professionalRepository) {
                $uploadDocument = new \LimpVix\Application\UseCases\Professional\UploadDocument(
                    $documentRepository,
                    $professionalRepository
                );
                $reviewDocument = new \LimpVix\Application\UseCases\Professional\ReviewDocument($documentRepository);
                $listDocuments = new \LimpVix\Application\UseCases\Professional\ListDocuments($documentRepository);

                $documentController = new \LimpVix\Infrastructure\API\ProfessionalDocumentController(
                    $uploadDocument,
                    $reviewDocument,
                    $listDocuments
                );
                $documentController->register_routes();

                self::logInfo('ProfessionalDocumentController REST API registered');
            }
        }
    }

    /**
     * Registra AJAX handlers
     *
     * @return void
     */
    public static function registerAjaxHandlers(): void
    {
        // Register Document Review AJAX Handler (GAP A)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Ajax\\DocumentReviewAjaxHandler')) {
            $ajaxHandler = new \LimpVix\Infrastructure\Admin\Ajax\DocumentReviewAjaxHandler();
            $ajaxHandler->register();

            self::logInfo('DocumentReviewAjaxHandler registered');
        }
    }

    /**
     * Log info message
     *
     * @param string $message
     * @return void
     */
    private static function logInfo(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[LimpVix][ProfessionalBootstrap] %s', $message));
        }
    }

    /**
     * Log error message
     *
     * @param string $message
     * @return void
     */
    private static function logError(string $message): void
    {
        error_log(sprintf('[LimpVix][ProfessionalBootstrap][ERROR] %s', $message));
    }
}
