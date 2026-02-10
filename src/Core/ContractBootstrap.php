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
            // DISABLED: Dashboard widgets causing critical errors in admin
            // add_action('wp_dashboard_setup', [self::class, 'registerAdminWidgets']);
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
     * Registra o Repository no container global
     *
     * @return void
     */
    public static function registerRepository(): void
    {
        if (!isset($GLOBALS['limpvix_contract_repository'])) {
            $GLOBALS['limpvix_contract_repository'] = new WpContractRepository();

            self::logInfo('ContractRepository registered');
        }
    }

    /**
     * Registra Use Cases no container global
     *
     * IMPORTANTE: Dependency Injection manual via $GLOBALS
     * - Repository é injetado via construtor
     * - Use Cases ficam disponíveis para Controllers e Admin Pages
     *
     * @return void
     */
    public static function registerUseCases(): void
    {
        global $wpdb;

        $repository = $GLOBALS['limpvix_contract_repository'] ?? null;

        if (!$repository) {
            self::logError('Contract Repository not found - cannot register Use Cases');
            return;
        }

        // Registrar ContractNumberGenerator (SPRINT 7 - Item 1.8)
        $contractNumberGenerator = new \LimpVix\Application\Services\ContractNumberGenerator($wpdb);

        // Registrar Use Cases
        $GLOBALS['limpvix_contract_use_cases'] = [
            'create' => new CreateContract($repository, $contractNumberGenerator),
            'list' => new \LimpVix\Application\UseCase\Contract\ListContracts($repository),
            'get_statistics' => new \LimpVix\Application\UseCase\Contract\GetContractStatistics(),
            'submit_for_allocation' => new SubmitForAllocation($repository),
            'activate' => new ActivateContract($repository),
            'pause' => new PauseContract($repository),
            'resume' => new ResumeContract($repository),
            'cancel' => new CancelContract($repository),
            'complete' => new CompleteContract($repository),
            'expire' => new ExpireContract($repository),
            'renew' => new RenewContract($repository),
            'schedule_next' => new ScheduleNextExecution($repository),
        ];

        self::logInfo('12 Contract Use Cases registered');
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
     * REFATORADO (ONDA 2): Agora injeta AuthorizationService
     *
     * @return void
     */
    public static function registerRestApi(): void
    {
        // 1. Registrar ContractController
        if (class_exists('LimpVix\\Infrastructure\\API\\ContractController')) {
            $useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];
            $authService = $GLOBALS['limpvix_authorization_service'] ?? null;

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
    }

    /**
     * Registra Cron Jobs para automação de contratos
     *
     * CRON JOBS:
     * - limpvix_check_contract_expiration: Expirar contratos diariamente
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

        self::logInfo('Contract Event Listeners registered');
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
        // Sincronizar métricas do Professional
        if (isset($eventData['professional_id'])) {
            self::incrementProfessionalMetric(
                (int) $eventData['professional_id'],
                'total_services'
            );
        }

        // @future: Enviar notificação ao cliente e profissional
        // @future: Agendar primeira execução

        self::logInfo('Contract activated: ' . ($eventData['contract_id'] ?? 'unknown') . ' - Professional: ' . ($eventData['professional_id'] ?? 'unknown'));
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
