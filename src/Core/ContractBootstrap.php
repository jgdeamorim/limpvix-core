<?php
/**
 * ContractBootstrap - Inicialização do módulo Contract
 *
 * RESPONSABILIDADES:
 * - Registrar Repository (WpContractRepository)
 * - Registrar Use Cases (10 Use Cases)
 * - Registrar Admin Pages (ContractManagementPage)
 * - Registrar Admin Widgets (CronHealthWidget)
 * - Registrar REST API (ContractController, HealthController)
 * - Registrar Cron Jobs (ContractAutomation - expiração)
 * - Registrar Event Listeners (Domain Events)
 *
 * IMPORTANTE:
 * - Este Bootstrap habilita o Contract Module com DDD
 * - Substitui uso direto de $wpdb por Use Cases
 * - DEVE ser registrado no Kernel.php
 *
 * @package LimpVix\Core
 * @since 0.8.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Core;

use LimpVix\Infrastructure\Persistence\Contract\WpContractRepository;
use LimpVix\Application\UseCase\Contract\CreateContract;
use LimpVix\Application\UseCase\Contract\SubmitForAllocation;
use LimpVix\Application\UseCase\Contract\ActivateContract;
use LimpVix\Application\UseCase\Contract\PauseContract;
use LimpVix\Application\UseCase\Contract\ResumeContract;
use LimpVix\Application\UseCase\Contract\CancelContract;
use LimpVix\Application\UseCase\Contract\CompleteContract;
use LimpVix\Application\UseCase\Contract\ExpireContract;
use LimpVix\Application\UseCase\Contract\RenewContract;
use LimpVix\Application\UseCase\Contract\ScheduleNextExecution;
use LimpVix\Infrastructure\Events\WordPressEventDispatcher;

defined('ABSPATH') || exit;

final class ContractBootstrap
{
    /**
     * Inicializa o módulo Contract
     *
     * ORDEM DE EXECUÇÃO:
     * 1. Registrar Repository (prioridade 5 - antes de tudo)
     * 2. Registrar Use Cases (prioridade 10)
     * 3. Registrar Admin Pages (apenas no admin)
     * 4. Registrar Admin Assets (CSS/JS)
     * 5. Registrar Admin Widgets (Dashboard widgets)
     * 6. Registrar REST API (rest_api_init)
     * 7. Registrar Cron Jobs (expiração de contratos)
     * 8. Registrar Event Listeners
     *
     * @return void
     */
    public static function init(): void
    {
        // 1. Registrar Repository (prioridade alta)
        add_action('init', [self::class, 'registerRepository'], 5);

        // 2. Registrar Use Cases (depende do Repository)
        add_action('init', [self::class, 'registerUseCases'], 10);

        // 3. Registrar Admin Pages (apenas no admin)
        if (is_admin()) {
            add_action('admin_menu', [self::class, 'registerAdminPages']);
            add_action('admin_enqueue_scripts', [self::class, 'registerAdminAssets']);
            add_action('wp_dashboard_setup', [self::class, 'registerAdminWidgets']);
        }

        // 4. Registrar REST API
        add_action('rest_api_init', [self::class, 'registerRestApi']);

        // 5. Registrar Cron Jobs
        self::registerCronJobs();

        // 6. Registrar Event Listeners
        self::registerEventListeners();

        // Log de inicialização
        self::logInfo('Contract Module Bootstrap initialized');
    }

    /**
     * Registra o Repository no ServiceContainer
     *
     * MUDANÇA (SPRINT 8.5 - FASE 2): Usa ServiceContainer ao invés de $GLOBALS
     *
     * @return void
     */
    public static function registerRepository(): void
    {
        $container = ServiceContainer::getInstance();

        // Register using factory (lazy loading)
        $container->factory('contract_repository', function() {
            return new WpContractRepository();
        });

        // BACKWARD COMPATIBILITY: Manter $GLOBALS temporariamente
        // TODO: Remover após refatorar todos os consumidores
        if (!isset($GLOBALS['limpvix_contract_repository'])) {
            $GLOBALS['limpvix_contract_repository'] = $container->get('contract_repository');
        }

        self::logInfo('ContractRepository registered in ServiceContainer');
    }

    /**
     * Registra Use Cases no ServiceContainer
     *
     * MUDANÇA (SPRINT 8.5 - FASE 2): Usa ServiceContainer ao invés de $GLOBALS
     * - Repository é obtido do container
     * - Use Cases são registrados no container
     * - Mantém backward compatibility com $GLOBALS temporariamente
     *
     * @return void
     */
    public static function registerUseCases(): void
    {
        global $wpdb;

        $container = ServiceContainer::getInstance();

        // Get repository from container
        $repository = $container->has('contract_repository')
            ? $container->get('contract_repository')
            : ($GLOBALS['limpvix_contract_repository'] ?? null);

        if (!$repository) {
            self::logError('Contract Repository not found - cannot register Use Cases');
            return;
        }

        // Registrar ContractNumberGenerator (SPRINT 7 - Item 1.8)
        $contractNumberGenerator = new \LimpVix\Application\Services\ContractNumberGenerator($wpdb);

        // Registrar Event Dispatcher (ONDA 1 - Task #104)
        $eventDispatcher = new WordPressEventDispatcher();

        // Get additional repositories (try container first, fallback to $GLOBALS)
        $professionalRepository = $container->has('professional_repository')
            ? $container->get('professional_repository')
            : ($GLOBALS['limpvix_professional_repository'] ?? null);

        $executionRepository = new \LimpVix\Infrastructure\Persistence\WpExecutionRepository();

        // Build Use Cases array
        $useCases = [
            'create' => new CreateContract($repository, $contractNumberGenerator),
            'list' => new \LimpVix\Application\UseCase\Contract\ListContracts($repository),
            'get_statistics' => new \LimpVix\Application\UseCase\Contract\GetContractStatistics(),
            'submit_for_allocation' => new SubmitForAllocation($repository),
            'activate' => new ActivateContract($repository),
            'pause' => new PauseContract($repository),
            'resume' => new ResumeContract($repository, $eventDispatcher),
            'cancel' => new CancelContract($repository, $eventDispatcher),
            'complete' => new CompleteContract($repository, $eventDispatcher),
            'expire' => new ExpireContract($repository, $eventDispatcher),
            'renew' => new RenewContract($repository, $eventDispatcher),
            'schedule_next' => new ScheduleNextExecution($repository),
        ];

        // GAP #7: Add reallocation use cases (if dependencies available)
        if ($professionalRepository && $executionRepository) {
            $useCases['reallocate_professional'] = new \LimpVix\Application\UseCase\Contract\ReallocateProfessional(
                $repository,
                $professionalRepository,
                $executionRepository
            );

            $useCases['get_reallocation_options'] = new \LimpVix\Application\UseCase\Contract\GetReallocationOptions(
                $repository,
                $professionalRepository
            );

            // GAP #3: Add SendOffers use case
            $useCases['send_offers'] = new \LimpVix\Application\UseCase\Briefing\SendOffers(
                $professionalRepository,
                $repository
            );

            self::logInfo('15 Contract Use Cases registered (including GAP #7 reallocation + GAP #3 SendOffers)');
        } else {
            self::logInfo('12 Contract Use Cases registered');
            if (!$professionalRepository) {
                self::logInfo('GAP #7 reallocation + GAP #3 SendOffers use cases skipped - Professional Repository not available');
            }
        }

        // Register in ServiceContainer
        $container->set('contract_use_cases', $useCases);

        // BACKWARD COMPATIBILITY: Manter $GLOBALS temporariamente
        // TODO: Remover após refatorar todos os consumidores
        $GLOBALS['limpvix_contract_use_cases'] = $useCases;

        // GAP #2: Register Recurring Payment components
        self::registerRecurringPaymentComponents($repository);
    }

    /**
     * Register Recurring Payment components (GAP #2)
     * - RecurringPayment repository
     * - RecurringPayment use cases
     * - MercadoPago payment provider
     *
     * MUDANÇA (SPRINT 8.5 - FASE 2): Usa ServiceContainer ao invés de $GLOBALS
     *
     * @param \LimpVix\Domain\Contract\ContractRepositoryInterface $contractRepository
     * @return void
     */
    private static function registerRecurringPaymentComponents($contractRepository): void
    {
        $container = ServiceContainer::getInstance();

        // Register RecurringPaymentRepository
        $paymentRepository = new \LimpVix\Infrastructure\Persistence\Finance\WpRecurringPaymentRepository();

        // Payment provider: EFI Bank primário, MercadoPago como fallback
        $efiPaymentProvider = new \LimpVix\Infrastructure\Finance\Providers\EfiPaymentProvider();
        if ($efiPaymentProvider->isAvailable()) {
            $paymentProvider = $efiPaymentProvider;
            error_log('[LimpVix][Contract] PaymentProvider: EFI Bank (cash-in PIX)');
        } else {
            $paymentProvider = new \LimpVix\Infrastructure\Finance\Providers\MercadoPagoPaymentProvider();
            error_log('[LimpVix][Contract] PaymentProvider: MercadoPago (fallback)');
        }

        // Build Recurring Payment Use Cases array
        $recurringPaymentUseCases = [
            'charge' => new \LimpVix\Application\UseCases\Finance\ChargeRecurringPayment(
                $contractRepository,
                $paymentRepository,
                $paymentProvider
            ),
            'process_webhook' => new \LimpVix\Application\UseCases\Finance\ProcessPaymentWebhook(
                $paymentRepository,
                $contractRepository,
                $paymentProvider
            ),
            'retry' => new \LimpVix\Application\UseCases\Finance\RetryFailedPayment(
                $paymentRepository,
                $contractRepository,
                $paymentProvider
            ),
        ];

        // Register in ServiceContainer
        $container->set('recurring_payment_use_cases', $recurringPaymentUseCases);

        // BACKWARD COMPATIBILITY: Manter $GLOBALS temporariamente
        // TODO: Remover após refatorar todos os consumidores
        $GLOBALS['limpvix_recurring_payment_use_cases'] = $recurringPaymentUseCases;

        self::logInfo('GAP #2: RecurringPayment components registered (3 use cases)');
    }

    /**
     * Registra páginas do Admin
     *
     * NOTA: ContractManagementPage será refatorado para usar Use Cases
     *
     * @return void
     */
    public static function registerAdminPages(): void
    {
        // @future: Refatorar ContractManagementPage para usar Use Cases
        // Linha 2041: substituir $wpdb por $useCases

        if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ContractManagementPage')) {
            $managementPage = new \LimpVix\Infrastructure\Admin\Pages\ContractManagementPage();
            $managementPage->register();

            self::logInfo('ContractManagementPage registered');
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
        // Apenas nas páginas do Contract Module
        $contract_pages = [
            'limpvix_page_limpvix-contracts',
        ];

        if (!in_array($hook, $contract_pages, true)) {
            return;
        }

        // CSS
        wp_enqueue_style(
            'limpvix-contract-admin',
            LIMPVIX_PLUGIN_URL . 'assets/css/contract-admin.css',
            [],
            LIMPVIX_VERSION
        );

        // JS
        wp_enqueue_script(
            'limpvix-contract-admin',
            LIMPVIX_PLUGIN_URL . 'assets/js/contract-admin.js',
            ['jquery'],
            LIMPVIX_VERSION,
            true
        );

        // Localização (dados para JS)
        wp_localize_script('limpvix-contract-admin', 'limpvixContract', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('limpvix_contract_nonce'),
            'restUrl' => rest_url('limpvix/v1/'),
            'restNonce' => wp_create_nonce('wp_rest'),
        ]);
    }

    /**
     * Registra Admin Widgets (Dashboard)
     *
     * WIDGETS:
     * - CronHealthWidget: Status visual dos cron jobs (SPRINT 7 - Item 1.9)
     *
     * @return void
     */
    public static function registerAdminWidgets(): void
    {
        // Registrar CronHealthWidget (SPRINT 7 - Item 1.9)
        if (class_exists('LimpVix\\Infrastructure\\Admin\\Widgets\\CronHealthWidget')) {
            $cronHealthWidget = new \LimpVix\Infrastructure\Admin\Widgets\CronHealthWidget();
            $cronHealthWidget->register();

            self::logInfo('CronHealthWidget registered on dashboard');
        }
    }

    /**
     * Registra REST API endpoints
     *
     * NOTA: ContractController será refatorado para usar Use Cases
     *
     * @return void
     */
    /**
     * Registra REST API endpoints
     *
     * MUDANÇA (SPRINT 8.5 - FASE 2): Usa ServiceContainer ao invés de $GLOBALS
     * - Tenta obter serviços do container primeiro
     * - Fallback para $GLOBALS se não encontrado (backward compatibility)
     *
     * @return void
     */
    public static function registerRestApi(): void
    {
        $container = ServiceContainer::getInstance();

        // 1. Registrar ContractController
        if (class_exists('LimpVix\\Infrastructure\\API\\ContractController')) {
            $useCases = $container->has('contract_use_cases')
                ? $container->get('contract_use_cases')
                : ($GLOBALS['limpvix_contract_use_cases'] ?? []);

            $authService = $container->has('authorization_service')
                ? $container->get('authorization_service')
                : ($GLOBALS['limpvix_authorization_service'] ?? null);

            if (empty($useCases)) {
                self::logInfo('Contract Use Cases not found - controller will use fallback');
            }

            if (!$authService) {
                self::logInfo('AuthorizationService not found - controller will use fallback');
            }

            $controller = new \LimpVix\Infrastructure\API\ContractController($useCases, $authService);
            $controller->register_routes();

            self::logInfo('ContractController REST API registered with ' . count($useCases) . ' Use Cases');
        }

        // 2. Registrar HealthController (SPRINT 7 - Item 1.9)
        if (class_exists('LimpVix\\Infrastructure\\API\\HealthController')) {
            $healthController = new \LimpVix\Infrastructure\API\HealthController();
            $healthController->registerRoutes();

            self::logInfo('HealthController REST API registered (cron monitoring)');
        }

        // 3. Register MercadoPagoWebhookController (GAP #2)
        if (class_exists('LimpVix\\Infrastructure\\API\\Controllers\\MercadoPagoWebhookController')) {
            $paymentUseCases = $container->has('recurring_payment_use_cases')
                ? $container->get('recurring_payment_use_cases')
                : ($GLOBALS['limpvix_recurring_payment_use_cases'] ?? []);

            if (isset($paymentUseCases['process_webhook'])) {
                $webhookController = new \LimpVix\Infrastructure\API\Controllers\MercadoPagoWebhookController(
                    $paymentUseCases['process_webhook']
                );
                $webhookController->register();

                self::logInfo('GAP #2: MercadoPagoWebhookController registered at /webhooks/mercadopago');
            }
        }

        // 4. Register OfferController (GAP #3 - Sprint 8)
        if (class_exists('LimpVix\\Infrastructure\\API\\OfferController')) {
            $useCases = $container->has('contract_use_cases')
                ? $container->get('contract_use_cases')
                : ($GLOBALS['limpvix_contract_use_cases'] ?? []);

            $contractRepository = $container->has('contract_repository')
                ? $container->get('contract_repository')
                : ($GLOBALS['limpvix_contract_repository'] ?? null);

            $jwtMiddleware = $container->has('jwt_middleware')
                ? $container->get('jwt_middleware')
                : ($GLOBALS['limpvix_jwt_middleware'] ?? null);

            $offerController = new \LimpVix\Infrastructure\API\OfferController(
                $useCases,
                $contractRepository,
                $jwtMiddleware
            );
            $offerController->register_routes();

            self::logInfo('GAP #3: OfferController registered (6 REST endpoints for offer management)');
        }
    }

    /**
     * Registra Cron Jobs para automação de contratos
     *
     * CRON JOBS:
     * - limpvix_check_contract_expiration: Expirar contratos diariamente
     * - limpvix_charge_recurring_payments: Cobrar renovações automáticas (GAP #2)
     * - limpvix_fallback_send_offers: Auto-enviar offers (fallback) a cada hora (HYBRID)
     *
     * @return void
     */
    private static function registerCronJobs(): void
    {
        // Agendar evento se não existir
        if (!wp_next_scheduled('limpvix_check_contract_expiration')) {
            wp_schedule_event(time(), 'daily', 'limpvix_check_contract_expiration');
            self::logInfo('Cron job "limpvix_check_contract_expiration" scheduled');
        }

        // Registrar handler do cron
        add_action('limpvix_check_contract_expiration', [self::class, 'onCheckContractExpiration']);

        // GAP #2: Cobrança recorrente automática DESATIVADA
        // Modelo on-demand: cada visita = checkout separado pelo cliente.
        // Contratos recorrentes definem frequência de agendamento, não cobrança automática.
        // \LimpVix\Infrastructure\Cron\RecurringPaymentCronAdapter::register();
        // add_action('limpvix_charge_recurring_payments', [self::class, 'onChargeRecurringPayments']);

        // TIER 1: Register payout reconciliation cron (Payment critical fix)
        \LimpVix\Infrastructure\Cron\PayoutReconciliationCronAdapter::register();

        // TIER 1: Register reconciliation cron handler
        add_action('limpvix_reconcile_payouts', ['\LimpVix\Infrastructure\Cron\PayoutReconciliationCronAdapter', 'executeStatic']);

        // TIER 1: Register payment authorization timeout cron (Payment critical fix)
        \LimpVix\Infrastructure\Cron\PaymentAuthorizationTimeoutCronAdapter::register();

        // TIER 1: Register authorization timeout cron handler
        add_action('limpvix_payment_authorization_timeout', ['\LimpVix\Infrastructure\Cron\PaymentAuthorizationTimeoutCronAdapter', 'execute']);

        self::logInfo('GAP #2: Recurring payment cron registered');

        // HYBRID AUTOMATION: Register SendOffers fallback cron
        \LimpVix\Infrastructure\Cron\SendOffersCronAdapter::register();

        self::logInfo('HYBRID: SendOffers fallback cron registered (hourly)');
    }

    /**
     * Handler: Verificar e expirar contratos
     *
     * Executado diariamente via WP Cron
     *
     * REFATORADO (SPRINT 7 - Item 1.9):
     * - Adiciona CronMonitor para rastrear execuções
     * - Registra início/fim com status
     * - Permite health check via endpoint
     *
     * @return void
     */
    public static function onCheckContractExpiration(): void
    {
        // SPRINT 7 - Item 1.9: CronMonitor para health check
        global $wpdb;
        $monitor = new \LimpVix\Application\Services\CronMonitor();
        $jobName = 'check_contract_expiration';

        // Registrar início
        $monitor->recordStart($jobName);

        $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? null;

        if (!$useCases || !isset($useCases['expire'])) {
            self::logError('ExpireContract Use Case not available');
            $monitor->recordEnd($jobName, 'failure', 'ExpireContract Use Case not available');
            return;
        }

        /** @var ExpireContract $expireUseCase */
        $expireUseCase = $useCases['expire'];

        try {
            $expiredCount = $expireUseCase->execute();
            self::logInfo("Contract expiration check completed: {$expiredCount} contracts expired");

            // Registrar sucesso
            $monitor->recordEnd($jobName, 'success');

        } catch (\Exception $e) {
            self::logError('Contract expiration check failed: ' . $e->getMessage());

            // Registrar falha
            $monitor->recordEnd($jobName, 'failure', $e->getMessage());
        }
    }

    /**
     * Registra Event Listeners para eventos de domínio
     *
     * @return void
     */
    private static function registerEventListeners(): void
    {
        // Evento: Contract Created
        add_action('limpvix_contract_created', [self::class, 'onContractCreated'], 10, 1);

        // Evento: Contract Activated
        add_action('limpvix_contract_activated', [self::class, 'onContractActivated'], 10, 1);

        // Evento: Contract Paused
        add_action('limpvix_contract_paused', [self::class, 'onContractPaused'], 10, 1);

        // Evento: Contract Resumed
        add_action('limpvix_contract_resumed', [self::class, 'onContractResumed'], 10, 1);

        // Evento: Contract Cancelled
        add_action('limpvix_contract_cancelled', [self::class, 'onContractCancelled'], 10, 1);

        // Evento: Contract Completed
        add_action('limpvix_contract_completed', [self::class, 'onContractCompleted'], 10, 1);

        // Evento: Contract Expired
        add_action('limpvix_contract_expired', [self::class, 'onContractExpired'], 10, 1);

        // GAP #2: Contract Renewed (auto-renewal via payment)
        add_action('limpvix_contract_renewed', [self::class, 'onContractRenewed'], 10, 1);

        self::logInfo('Contract Event Listeners registered (including GAP #2 renewal)');
    }

    /**
     * Handler: Contract Created
     *
     * @param array $eventData ContractCreated event data
     * @return void
     */
    public static function onContractCreated($eventData): void
    {
        // @future: Enviar notificação ao cliente
        // @future: Criar entrada no audit log
        // @future: Iniciar processo de alocação

        self::logInfo('Contract created: ' . ($eventData['contract_id'] ?? 'unknown'));
    }

    /**
     * Handler: Contract Activated
     *
     * @param array $eventData ContractActivated event data
     * @return void
     */
    public static function onContractActivated($eventData): void
    {
        $contractId = $eventData['contract_id'] ?? null;
        $professionalId = $eventData['professional_id'] ?? null;

        // Sincronizar métricas do Professional
        if ($professionalId) {
            self::incrementProfessionalMetric(
                (int) $professionalId,
                'total_services'
            );
        }

        // AUTO-ENVIAR OFFERS (AUTOMAÇÃO HÍBRIDA - FASE 1)
        // Only auto-send if contract was activated WITHOUT allocated professional
        // (meaning it needs offers to be sent to find a professional)
        if ($contractId && !$professionalId) {
            self::autoSendOffers((int) $contractId);
        }

        self::logInfo('Contract activated: ' . ($contractId ?? 'unknown') . ' - Professional: ' . ($professionalId ?? 'unknown'));
    }

    /**
     * Handler: Contract Paused
     *
     * @param array $eventData ContractPaused event data
     * @return void
     */
    public static function onContractPaused($eventData): void
    {
        // @future: Notificar partes interessadas
        // @future: Cancelar execuções agendadas

        self::logInfo('Contract paused: ' . ($eventData['contract_id'] ?? 'unknown'));
    }

    /**
     * Handler: Contract Resumed
     *
     * @param array $eventData ContractResumed event data
     * @return void
     */
    public static function onContractResumed($eventData): void
    {
        // @future: Notificar partes interessadas
        // @future: Reagendar execuções

        self::logInfo('Contract resumed: ' . ($eventData['contract_id'] ?? 'unknown'));
    }

    /**
     * Handler: Contract Cancelled
     *
     * @param array $eventData ContractCancelled event data
     * @return void
     */
    public static function onContractCancelled($eventData): void
    {
        // Sincronizar métricas do Professional
        if (isset($eventData['professional_id'])) {
            self::incrementProfessionalMetric(
                (int) $eventData['professional_id'],
                'cancelled_services'
            );
            self::recalculateProfessionalAcceptanceRate((int) $eventData['professional_id']);
        }

        // @future: Notificar partes interessadas
        // @future: Processar cancelamento (reembolsos?)
        // @future: Liberar profissional alocado

        self::logInfo('Contract cancelled: ' . ($eventData['contract_id'] ?? 'unknown') . ' - Professional: ' . ($eventData['professional_id'] ?? 'unknown') . ' - Reason: ' . ($eventData['reason'] ?? 'N/A'));
    }

    /**
     * Handler: Contract Completed
     *
     * @param array $eventData ContractCompleted event data
     * @return void
     */
    public static function onContractCompleted($eventData): void
    {
        // Sincronizar métricas do Professional
        if (isset($eventData['professional_id'])) {
            self::incrementProfessionalMetric(
                (int) $eventData['professional_id'],
                'completed_services'
            );
            self::recalculateProfessionalAcceptanceRate((int) $eventData['professional_id']);
        }

        // @future: Solicitar avaliação do cliente
        // @future: Processar renovação automática (se auto_renew = true)
        // @future: Liberar profissional alocado

        self::logInfo('Contract completed: ' . ($eventData['contract_id'] ?? 'unknown') . ' - Professional: ' . ($eventData['professional_id'] ?? 'unknown'));
    }

    /**
     * Handler: Contract Expired
     *
     * @param array $eventData ContractExpired event data
     * @return void
     */
    public static function onContractExpired($eventData): void
    {
        // @future: Notificar partes interessadas
        // @future: Processar renovação automática (se auto_renew = true)

        self::logInfo('Contract expired: ' . ($eventData['contract_id'] ?? 'unknown'));
    }

    /**
     * Auto-send offers when contract activates (HYBRID AUTOMATION - PHASE 1)
     *
     * Automatically sends offers to matched professionals when a contract
     * is activated without an allocated professional.
     *
     * WORKFLOW:
     * 1. Check if feature flag 'auto_send_offers' is enabled
     * 2. Check if SendOffers use case is available
     * 3. Execute SendOffers use case
     * 4. Log success/failure (but don't fail contract activation)
     *
     * FALLBACK SAFETY:
     * - If auto-send fails, fallback cron will retry after 1 hour
     * - Admin can manually resend via "Enviar Offers" button
     * - Idempotency is guaranteed by SendOffers use case
     *
     * @param int $contractId Contract ID
     * @return void
     */
    private static function autoSendOffers(int $contractId): void
    {
        try {
            // Check feature flag
            $featureFlags = $GLOBALS['limpvix_feature_flags'] ?? null;
            if ($featureFlags && method_exists($featureFlags, 'isEnabled')) {
                if (!$featureFlags->isEnabled('auto_send_offers')) {
                    self::logInfo("Auto-SendOffers skipped for Contract #{$contractId}: feature flag disabled");
                    return;
                }
            }

            // Get SendOffers use case
            $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];

            if (!isset($useCases['send_offers'])) {
                self::logInfo("Auto-SendOffers skipped for Contract #{$contractId}: use case not available");
                return;
            }

            /** @var \LimpVix\Application\UseCase\Briefing\SendOffers $sendOffersUseCase */
            $sendOffersUseCase = $useCases['send_offers'];

            // Execute SendOffers
            $result = $sendOffersUseCase->execute($contractId);

            self::logInfo(sprintf(
                '[HYBRID-AUTO] SendOffers immediate: %d offers sent for Contract #%d to %d professionals',
                $result['offers_sent'],
                $contractId,
                $result['offers_sent']
            ));

        } catch (\RuntimeException $e) {
            // Expected errors (no coordinates, no professionals, already has offers)
            // These are NOT failures - they're expected situations
            self::logInfo(sprintf(
                '[HYBRID-AUTO] SendOffers expected condition for Contract #%d: %s',
                $contractId,
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            // Unexpected errors - log as error but don't fail contract activation
            self::logError(sprintf(
                '[HYBRID-AUTO] SendOffers unexpected error for Contract #%d: %s',
                $contractId,
                $e->getMessage()
            ));
        }
    }

    /**
     * Incrementa uma métrica do Professional
     *
     * MÉTRICAS SUPORTADAS:
     * - total_services: Total de serviços (contratos ativados)
     * - completed_services: Serviços completados com sucesso
     * - cancelled_services: Serviços cancelados
     *
     * @param int $professionalId ID do professional (allocated_professional_id)
     * @param string $metric Nome da métrica a incrementar
     * @return void
     */
    private static function incrementProfessionalMetric(int $professionalId, string $metric): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_professionals';

        // Validar métrica
        $validMetrics = ['total_services', 'completed_services', 'cancelled_services'];
        if (!in_array($metric, $validMetrics, true)) {
            self::logError("Invalid metric: {$metric}");
            return;
        }

        // Incrementar métrica
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET {$metric} = {$metric} + 1,
                 updated_at = %s
             WHERE id = %d",
            current_time('mysql'),
            $professionalId
        ));

        if ($updated === false) {
            self::logError("Failed to increment {$metric} for professional ID {$professionalId}: " . $wpdb->last_error);
        } else {
            self::logInfo("Professional {$professionalId}: {$metric} incremented");
        }
    }

    /**
     * Recalcula acceptance_rate do Professional
     *
     * FÓRMULA:
     * acceptance_rate = (completed_services / (completed_services + cancelled_services)) * 100
     *
     * Se não houver nenhum serviço, mantém 100.0 (taxa inicial)
     *
     * @param int $professionalId ID do professional (allocated_professional_id)
     * @return void
     */
    private static function recalculateProfessionalAcceptanceRate(int $professionalId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_professionals';

        // Buscar métricas atuais
        $professional = $wpdb->get_row($wpdb->prepare(
            "SELECT completed_services, cancelled_services
             FROM {$table}
             WHERE id = %d",
            $professionalId
        ));

        if (!$professional) {
            self::logError("Professional ID {$professionalId} not found for acceptance_rate calculation");
            return;
        }

        $completed = (int) $professional->completed_services;
        $cancelled = (int) $professional->cancelled_services;
        $total = $completed + $cancelled;

        // Calcular acceptance_rate
        $acceptanceRate = $total > 0 ? ($completed / $total) * 100 : 100.0;

        // Atualizar acceptance_rate
        $updated = $wpdb->query($wpdb->prepare(
            "UPDATE {$table}
             SET acceptance_rate = %f,
                 updated_at = %s
             WHERE id = %d",
            $acceptanceRate,
            current_time('mysql'),
            $professionalId
        ));

        if ($updated === false) {
            self::logError("Failed to update acceptance_rate for professional ID {$professionalId}: " . $wpdb->last_error);
        } else {
            self::logInfo("Professional {$professionalId}: acceptance_rate recalculated to {$acceptanceRate}%");
        }
    }

    /**
     * Handler: Charge Recurring Payments (GAP #2)
     *
     * Executado diariamente via WP Cron às 00:00
     * - Cobra contratos próximos ao vencimento
     * - Retenta payments falhados
     *
     * @return void
     */
    public static function onChargeRecurringPayments(): void
    {
        $useCases = $GLOBALS['limpvix_recurring_payment_use_cases'] ?? [];

        if (!isset($useCases['charge'], $useCases['retry'])) {
            self::logError('GAP #2: RecurringPayment use cases not available for cron');
            return;
        }

        $contractRepository = $GLOBALS['limpvix_contract_repository'] ?? null;

        if (!$contractRepository) {
            self::logError('GAP #2: ContractRepository not available for cron');
            return;
        }

        try {
            // Execute cron adapter
            $adapter = new \LimpVix\Infrastructure\Cron\RecurringPaymentCronAdapter(
                $useCases['charge'],
                $useCases['retry'],
                $contractRepository
            );

            $stats = $adapter->execute();

            self::logInfo(sprintf(
                'GAP #2: Recurring payment cron completed - Charged: %d/%d, Retries: %d (%.2fs)',
                $stats['charges_created'],
                $stats['contracts_found'],
                $stats['retries_succeeded'],
                $stats['execution_time']
            ));

        } catch (\Exception $e) {
            self::logError('GAP #2: Recurring payment cron failed: ' . $e->getMessage());
        }
    }

    /**
     * Handler: Contract Renewed (GAP #2)
     *
     * Triggered when contract is auto-renewed via payment
     * Delegates to ContractRenewedListener
     *
     * @param \LimpVix\Domain\Contract\Events\ContractRenewed $event
     * @return void
     */
    public static function onContractRenewed($event): void
    {
        try {
            \LimpVix\Infrastructure\Integration\ContractRenewedListener::handle($event);

            self::logInfo(sprintf(
                'GAP #2: Contract renewed - ID: %d, Payment: %s',
                $event->getContract()->getId()->toInt(),
                $event->getPayment()->getPaymentUuid()
            ));

        } catch (\Exception $e) {
            self::logError('GAP #2: ContractRenewed event handler failed: ' . $e->getMessage());
        }
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
                '[LimpVix Contract Bootstrap] [%s] %s',
                date('Y-m-d H:i:s'),
                $message
            ));
        }
    }

    /**
     * Log de erro
     *
     * @param string $message Mensagem de erro
     * @return void
     */
    private static function logError(string $message): void
    {
        error_log(sprintf(
            '[LimpVix Contract Bootstrap] [ERROR] [%s] %s',
            date('Y-m-d H:i:s'),
            $message
        ));
    }

    /**
     * Health check do módulo Contract
     *
     * @return array Status do módulo
     */
    public static function healthCheck(): array
    {
        return [
            'module' => 'Contract',
            'repository_loaded' => isset($GLOBALS['limpvix_contract_repository']),
            'use_cases_loaded' => isset($GLOBALS['limpvix_contract_use_cases']),
            'use_case_count' => count($GLOBALS['limpvix_contract_use_cases'] ?? []),
            'management_page_class_exists' => class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ContractManagementPage'),
            'controller_class_exists' => class_exists('LimpVix\\Infrastructure\\API\\ContractController'),
            'health_controller_class_exists' => class_exists('LimpVix\\Infrastructure\\API\\HealthController'),
            'cron_health_widget_class_exists' => class_exists('LimpVix\\Infrastructure\\Admin\\Widgets\\CronHealthWidget'),
            'domain_class_exists' => class_exists('LimpVix\\Domain\\Contract\\Contract'),
            'aggregate_root_exists' => class_exists('LimpVix\\Domain\\Contract\\Contract'),
            'cron_scheduled' => (bool) wp_next_scheduled('limpvix_check_contract_expiration'),
        ];
    }
}
