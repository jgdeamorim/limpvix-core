<?php
/**
 * BriefingBootstrap - Inicialização do Módulo Briefing
 *
 * RESPONSABILIDADE:
 * - Inicializar todo o módulo Briefing
 * - Registrar repositories, use cases, controllers
 * - Registrar event listeners
 * - Registrar páginas administrativas
 * - Registrar REST API endpoints
 * - Dependency Injection manual
 *
 * DEVE SER CHAMADO:
 * - Pelo Kernel.php no hook 'init'
 * - Apenas se feature flag 'briefing_enabled' estiver ativa
 *
 * @package LimpVix\Core
 * @since 0.2.0
 */

namespace LimpVix\Core;

use LimpVix\Infrastructure\Persistence\WpBriefingRepository;
use LimpVix\Infrastructure\Adapters\FirebaseAuthAdapter;
use LimpVix\Infrastructure\Adapters\BriefingPaymentAdapter;
use LimpVix\Infrastructure\API\BriefingApiBootstrap;
use LimpVix\Infrastructure\Admin\Pages\BriefingManagementPage;
use LimpVix\Infrastructure\Admin\Pages\BriefingDetailPage;
use LimpVix\Infrastructure\Admin\Pages\BriefingSettings;
use LimpVix\Infrastructure\Events\BriefingEventDispatcher;
use LimpVix\Infrastructure\Integration\ContractActivationListener;
use LimpVix\Infrastructure\Integration\ScheduleCreationListener;
use LimpVix\Application\Services\BriefingMetricsCalculator;
use LimpVix\Application\UseCases\Briefing\LockBriefing;
use LimpVix\Domain\Briefing\BriefingPolicy;

defined('ABSPATH') || exit;

class BriefingBootstrap
{
    /**
     * @var bool Flag para evitar inicialização dupla
     */
    private static $initialized = false;

    /**
     * Inicializar módulo Briefing
     *
     * @return void
     */
    public static function init(): void
    {
        if (self::$initialized) {
            return;
        }

        // Verificar feature flag
        $featureFlags = new FeatureFlags();
        if (!$featureFlags->isEnabled('briefing_enabled')) {
            return;
        }

        // 1. Inicializar dependências
        self::registerDependencies();

        // 2. Registrar REST API
        self::registerRestApi();

        // 3. Registrar Admin Pages
        self::registerAdminPages();

        // 4. Registrar Event Listeners
        self::registerEventListeners();

        // 5. Registrar Payment Adapter (webhooks WooCommerce)
        self::registerPaymentAdapter();

        self::$initialized = true;

        // Log
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log('[LimpVix] Módulo Briefing inicializado com sucesso!');
        }
    }

    /**
     * Registrar dependências compartilhadas
     *
     * @return array Retorna instâncias para reuso
     */
    private static function registerDependencies(): array
    {
        // Repositories
        $briefingRepository = new WpBriefingRepository();

        // Services
        $policy = new BriefingPolicy();
        $calculator = new BriefingMetricsCalculator();
        $firebaseAdapter = new FirebaseAuthAdapter();

        // Use Cases
        $lockBriefingUseCase = new LockBriefing($briefingRepository, $policy);

        // Event Dispatcher
        $eventDispatcher = new BriefingEventDispatcher();

        return [
            'briefingRepository' => $briefingRepository,
            'policy' => $policy,
            'calculator' => $calculator,
            'firebaseAdapter' => $firebaseAdapter,
            'lockBriefingUseCase' => $lockBriefingUseCase,
            'eventDispatcher' => $eventDispatcher
        ];
    }

    /**
     * Registrar REST API endpoints
     *
     * @return void
     */
    private static function registerRestApi(): void
    {
        BriefingApiBootstrap::init();
    }

    /**
     * Registrar páginas administrativas
     *
     * @return void
     */
    private static function registerAdminPages(): void
    {
        if (!is_admin()) {
            return;
        }

        $briefingRepository = new WpBriefingRepository();

        // Página de gerenciamento
        $managementPage = new BriefingManagementPage($briefingRepository);
        $managementPage->register();

        // Página de detalhes
        $detailPage = new BriefingDetailPage($briefingRepository);
        $detailPage->register();

        // Página de configurações
        $settingsPage = new BriefingSettings();
        $settingsPage->register();
    }

    /**
     * Registrar Event Listeners
     *
     * @return void
     */
    private static function registerEventListeners(): void
    {
        $briefingRepository = new WpBriefingRepository();

        // Listener: Ativação de Contratos
        $contractListener = new ContractActivationListener($briefingRepository);
        $contractListener->register();

        // Listener: Criação de Agendas
        $scheduleListener = new ScheduleCreationListener($briefingRepository);
        $scheduleListener->register();
    }

    /**
     * Registrar Payment Adapter (webhooks WooCommerce)
     *
     * @return void
     */
    private static function registerPaymentAdapter(): void
    {
        $briefingRepository = new WpBriefingRepository();
        $policy = new BriefingPolicy();
        $lockBriefingUseCase = new LockBriefing($briefingRepository, $policy);

        $paymentAdapter = new BriefingPaymentAdapter($briefingRepository, $lockBriefingUseCase);
        $paymentAdapter->register();
    }

    /**
     * Verificar se módulo está inicializado
     *
     * @return bool
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Resetar inicialização (útil para testes)
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$initialized = false;
    }
}
