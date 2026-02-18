<?php
/**
 * ExecutionBootstrap - Inicialização do módulo Execution
 *
 * RESPONSABILIDADES:
 * - Registrar Repository (WpContractExecutionRepository)
 * - Registrar Use Cases (7 Use Cases)
 * - Registrar Event Listeners (6 Domain Events)
 *
 * @package LimpVix\Core
 * @since 0.9.0
 */

namespace LimpVix\Core;

use LimpVix\Infrastructure\Persistence\Execution\WpContractExecutionRepository;
use LimpVix\Application\UseCases\Execution\CreateExecution;
use LimpVix\Application\UseCases\Execution\ScheduleExecution;
use LimpVix\Application\UseCases\Execution\StartExecution;
use LimpVix\Application\UseCases\Execution\CompleteExecution;
use LimpVix\Application\UseCases\Execution\CancelExecution;
use LimpVix\Application\UseCases\Execution\MarkNoShow;
use LimpVix\Application\UseCases\Execution\RescheduleExecution;

defined('ABSPATH') || exit;

final class ExecutionBootstrap
{
    /**
     * Inicializa o módulo Execution
     *
     * @return void
     */
    public static function init(): void
    {
        // 1. Registrar Repository
        add_action('init', [self::class, 'registerRepository'], 5);

        // 2. Registrar Use Cases
        add_action('init', [self::class, 'registerUseCases'], 10);

        // 3. Registrar REST API
        add_action('rest_api_init', [self::class, 'registerRestApi']);

        // 4. Registrar Event Listeners
        self::registerEventListeners();

        self::logInfo('Execution Module Bootstrap initialized');
    }

    /**
     * Registra o Repository no container global
     *
     * @return void
     */
    public static function registerRepository(): void
    {
        if (!isset($GLOBALS['limpvix_execution_repository'])) {
            $GLOBALS['limpvix_execution_repository'] = new WpContractExecutionRepository();

            self::logInfo('ExecutionRepository registered');
        }
    }

    /**
     * Registra Use Cases no container global
     *
     * @return void
     */
    public static function registerUseCases(): void
    {
        $repository = $GLOBALS['limpvix_execution_repository'] ?? null;

        if (!$repository) {
            self::logError('Execution Repository not found - cannot register Use Cases');
            return;
        }

        $GLOBALS['limpvix_execution_use_cases'] = [
            'create' => new CreateExecution($repository),
            'list' => new \LimpVix\Application\UseCases\Execution\ListExecutions($repository),
            'get' => new \LimpVix\Application\UseCases\Execution\GetExecution($repository),
            'schedule' => new ScheduleExecution($repository),
            'start' => new StartExecution($repository),
            'complete' => new CompleteExecution($repository),
            'cancel' => new CancelExecution($repository),
            'mark_no_show' => new MarkNoShow($repository),
            'reschedule' => new RescheduleExecution($repository),
        ];

        self::logInfo('9 Execution Use Cases registered');
    }

    /**
     * Registra REST API endpoints
     *
     * CRIADO (ONDA 2): ExecutionController com 9 endpoints
     *
     * @return void
     */
    public static function registerRestApi(): void
    {
        if (class_exists('LimpVix\\Infrastructure\\API\\ExecutionController')) {
            $useCases = $GLOBALS['limpvix_execution_use_cases'] ?? [];
            $authService = $GLOBALS['limpvix_authorization_service'] ?? null;

            if (empty($useCases)) {
                self::logInfo('Execution Use Cases not found - controller will use fallback');
            }

            if (!$authService) {
                self::logInfo('AuthorizationService not found - controller will use fallback');
            }

            $controller = new \LimpVix\Infrastructure\API\ExecutionController($useCases, $authService);
            $controller->register_routes();

            self::logInfo('ExecutionController REST API registered with ' . count($useCases) . ' Use Cases');
        }
    }

    /**
     * Registra Event Listeners para eventos de domínio
     *
     * @return void
     */
    private static function registerEventListeners(): void
    {
        // Evento: Execution Scheduled
        add_action('limpvix_execution_scheduled', [self::class, 'onExecutionScheduled'], 10, 1);

        // Evento: Execution Started
        add_action('limpvix_execution_started', [self::class, 'onExecutionStarted'], 10, 1);

        // Evento: Execution Completed
        add_action('limpvix_execution_completed', [self::class, 'onExecutionCompleted'], 10, 1);

        // Evento: Execution Cancelled
        add_action('limpvix_execution_cancelled', [self::class, 'onExecutionCancelled'], 10, 1);

        // Evento: Execution NoShow
        add_action('limpvix_execution_no_show', [self::class, 'onExecutionNoShow'], 10, 1);

        // Evento: Execution Rescheduled
        add_action('limpvix_execution_rescheduled', [self::class, 'onExecutionRescheduled'], 10, 1);

        self::logInfo('Execution Event Listeners registered');
    }

    /**
     * Handler: Execution Scheduled
     *
     * @param array $eventData
     * @return void
     */
    /**
     * Handler: Execution Scheduled
     *
     * Quando uma ContractExecution é agendada, cria um Execution
     * para rastreamento em tempo real (check-in GPS, evidências, SLA).
     *
     * ContractExecution (agendamento) → Execution (tempo real)
     *
     * @param array $eventData {execution_id, contract_id, professional_user_id, scheduled_date}
     * @return void
     */
    public static function onExecutionScheduled($eventData): void
    {
        try {
            $contractId = $eventData['contract_id'] ?? null;
            $professionalUserId = $eventData['professional_user_id'] ?? null;
            $scheduledDate = $eventData['scheduled_date'] ?? null;
            $contractExecutionId = $eventData['execution_id'] ?? null;

            if (!$contractId || !$professionalUserId || !$scheduledDate) {
                self::logError('Missing required data in execution_scheduled event');
                return;
            }

            global $wpdb;

            // 1. Buscar contrato para endereço do serviço
            $contract = $wpdb->get_row($wpdb->prepare(
                "SELECT id, service_address FROM {$wpdb->prefix}limpvix_contracts WHERE id = %d",
                $contractId
            ), ARRAY_A);

            if (!$contract) {
                self::logError("Contract #{$contractId} not found for execution creation");
                return;
            }

            // 2. Buscar order_uuid via ContractExecution → Briefing → Order
            $orderUuid = null;

            if ($contractExecutionId) {
                $briefingUuid = $wpdb->get_var($wpdb->prepare(
                    "SELECT briefing_uuid FROM {$wpdb->prefix}limpvix_contract_executions WHERE id = %d",
                    $contractExecutionId
                ));

                if ($briefingUuid) {
                    $orderId = $wpdb->get_var($wpdb->prepare(
                        "SELECT order_id FROM {$wpdb->prefix}limpvix_briefings WHERE uuid = %s",
                        $briefingUuid
                    ));

                    if ($orderId) {
                        $orderUuid = $wpdb->get_var($wpdb->prepare(
                            "SELECT uuid FROM {$wpdb->prefix}limpvix_orders WHERE id = %d",
                            $orderId
                        ));
                    }
                }
            }

            // Fallback: buscar última order do mesmo customer/professional
            if (!$orderUuid) {
                $clientId = $wpdb->get_var($wpdb->prepare(
                    "SELECT client_user_id FROM {$wpdb->prefix}limpvix_contracts WHERE id = %d",
                    $contractId
                ));

                if ($clientId) {
                    $orderUuid = $wpdb->get_var($wpdb->prepare(
                        "SELECT uuid FROM {$wpdb->prefix}limpvix_orders
                         WHERE customer_id = %d
                         ORDER BY created_at DESC LIMIT 1",
                        $clientId
                    ));
                }
            }

            if (!$orderUuid) {
                self::logError("No order found for contract #{$contractId} - generating placeholder");
                $orderUuid = wp_generate_uuid4();
            }

            // 3. Verificar se Execution já existe para esta data/ordem
            $existingExecution = $wpdb->get_var($wpdb->prepare(
                "SELECT execution_uuid FROM {$wpdb->prefix}limpvix_executions
                 WHERE order_uuid = %s AND DATE(scheduled_start_time) = DATE(%s)",
                $orderUuid,
                $scheduledDate
            ));

            if ($existingExecution) {
                self::logInfo("Execution already exists for order {$orderUuid}: {$existingExecution}");
                return;
            }

            // 4. Extrair coordenadas do service_address (JSON)
            $serviceAddress = json_decode($contract['service_address'] ?? '{}', true);
            $lat = (float) ($serviceAddress['coordinates']['latitude'] ?? 0);
            $lon = (float) ($serviceAddress['coordinates']['longitude'] ?? 0);

            // Validar coordenadas (0,0 = não disponível)
            if ($lat == 0 && $lon == 0) {
                self::logInfo("No coordinates available for contract #{$contractId} - using default");
                $lat = -20.31;  // Default: Vitória/ES (sede LimpVix)
                $lon = -40.315;
            }

            $serviceLocation = new \LimpVix\Domain\Execution\ValueObjects\GeoLocation($lat, $lon);

            // 5. Criar Execution para rastreamento em tempo real
            $executionUuid = wp_generate_uuid4();

            $execution = \LimpVix\Domain\Execution\Execution::create(
                $executionUuid,
                $orderUuid,
                $professionalUserId,
                new \DateTimeImmutable($scheduledDate),
                $serviceLocation
            );

            $repository = new \LimpVix\Infrastructure\Persistence\WpExecutionRepository();
            $repository->save($execution);

            // 6. Vincular Execution à ContractExecution via schedule_uuid
            if ($contractExecutionId) {
                $wpdb->update(
                    $wpdb->prefix . 'limpvix_contract_executions',
                    ['schedule_uuid' => $executionUuid],
                    ['id' => $contractExecutionId]
                );
            }

            self::logInfo("Execution created: UUID={$executionUuid}, Order={$orderUuid}, Professional={$professionalUserId}, Date={$scheduledDate}");

        } catch (\Exception $e) {
            self::logError('Failed to create Execution from scheduled event: ' . $e->getMessage());
        }
    }

    /**
     * Handler: Execution Started
     *
     * @param array $eventData
     * @return void
     */
    public static function onExecutionStarted($eventData): void
    {
        // @future: Notificar cliente que serviço iniciou
        // @future: Iniciar timer de SLA

        self::logInfo('Execution started: ' . ($eventData['execution_id'] ?? 'unknown'));
    }

    /**
     * Handler: Execution Completed
     *
     * @param array $eventData
     * @return void
     */
    public static function onExecutionCompleted($eventData): void
    {
        // @future: Solicitar avaliação do cliente
        // @future: Atualizar score do profissional
        // @future: Gerar próxima execução (contratos recorrentes)

        self::logInfo('Execution completed: ' . ($eventData['execution_id'] ?? 'unknown'));
    }

    /**
     * Handler: Execution Cancelled
     *
     * @param array $eventData
     * @return void
     */
    public static function onExecutionCancelled($eventData): void
    {
        // @future: Notificar partes interessadas
        // @future: Reagendar automaticamente se aplicável

        self::logInfo('Execution cancelled: ' . ($eventData['execution_id'] ?? 'unknown') .
                     ' - Reason: ' . ($eventData['reason'] ?? 'N/A'));
    }

    /**
     * Handler: Execution NoShow
     *
     * @param array $eventData
     * @return void
     */
    public static function onExecutionNoShow($eventData): void
    {
        // @future: Penalizar profissional (incrementar no_show_count)
        // @future: Notificar gestor
        // @future: Reagendar automaticamente

        self::logInfo('Execution no-show: ' . ($eventData['execution_id'] ?? 'unknown') .
                     ' - Professional: ' . ($eventData['professional_user_id'] ?? 'N/A'));
    }

    /**
     * Handler: Execution Rescheduled
     *
     * @param array $eventData
     * @return void
     */
    public static function onExecutionRescheduled($eventData): void
    {
        // @future: Notificar profissional e cliente
        // @future: Atualizar calendário

        self::logInfo('Execution rescheduled: ' . ($eventData['execution_id'] ?? 'unknown') .
                     ' - From: ' . ($eventData['old_scheduled_date'] ?? 'N/A') .
                     ' - To: ' . ($eventData['new_scheduled_date'] ?? 'N/A'));
    }

    /**
     * Log informativo (apenas se WP_DEBUG ativo)
     *
     * @param string $message
     * @return void
     */
    private static function logInfo(string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix Execution Bootstrap] [%s] %s',
                date('Y-m-d H:i:s'),
                $message
            ));
        }
    }

    /**
     * Log de erro
     *
     * @param string $message
     * @return void
     */
    private static function logError(string $message): void
    {
        error_log(sprintf(
            '[LimpVix Execution Bootstrap] [ERROR] [%s] %s',
            date('Y-m-d H:i:s'),
            $message
        ));
    }

    /**
     * Health check do módulo Execution
     *
     * @return array
     */
    public static function healthCheck(): array
    {
        return [
            'module' => 'Execution',
            'repository_loaded' => isset($GLOBALS['limpvix_execution_repository']),
            'use_cases_loaded' => isset($GLOBALS['limpvix_execution_use_cases']),
            'use_case_count' => count($GLOBALS['limpvix_execution_use_cases'] ?? []),
        ];
    }
}
