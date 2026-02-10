<?php
/**
 * ExecutionController - REST API para Contract Executions
 *
 * ARQUITETURA DDD - Segue padrões estabelecidos:
 * - DTOs para validação de input
 * - AuthorizationService para controle de acesso
 * - Use Cases para toda lógica de negócio
 * - ApiResponse para respostas padronizadas
 *
 * Endpoints:
 * - GET    /limpvix/v1/executions              - Listar execuções
 * - GET    /limpvix/v1/executions/{id}         - Obter execução
 * - POST   /limpvix/v1/executions              - Criar execução
 * - POST   /limpvix/v1/executions/{id}/schedule   - Agendar
 * - POST   /limpvix/v1/executions/{id}/start      - Iniciar
 * - POST   /limpvix/v1/executions/{id}/complete   - Completar
 * - POST   /limpvix/v1/executions/{id}/cancel     - Cancelar
 * - POST   /limpvix/v1/executions/{id}/no-show    - Marcar no-show
 * - POST   /limpvix/v1/executions/{id}/reschedule - Reagendar
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.10.0
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Request;
use WP_REST_Response;
use LimpVix\Infrastructure\Authorization\AuthorizationService;
use LimpVix\Application\DTO\Request\CreateExecutionRequest;
use LimpVix\Application\DTO\Request\ScheduleExecutionRequest;
use LimpVix\Application\DTO\Request\CompleteExecutionRequest;
use LimpVix\Application\DTO\Request\CancelExecutionRequest;
use LimpVix\Application\DTO\Request\RescheduleExecutionRequest;
use LimpVix\Application\DTO\Response\ExecutionResponse;
use LimpVix\Application\DTO\Response\ExecutionListResponse;

defined('ABSPATH') || exit;

class ExecutionController
{
    private string $namespace = 'limpvix/v1';
    private array $useCases;
    private AuthorizationService $authService;

    /**
     * Construtor com Dependency Injection
     *
     * @param array $useCases Array com Use Cases injetados
     * @param AuthorizationService $authService Authorization service
     */
    public function __construct(array $useCases, AuthorizationService $authService)
    {
        $this->useCases = $useCases;
        $this->authService = $authService;
    }

    /**
     * Registrar rotas REST API
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registrar todas as rotas
     */
    public function register_routes(): void
    {
        // GET /executions - Listar
        register_rest_route($this->namespace, '/executions', [
            'methods' => 'GET',
            'callback' => [$this, 'listExecutions'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'contract_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'Filtrar por contrato'
                ],
                'professional_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'Filtrar por profissional'
                ]
            ]
        ]);

        // GET /executions/{id} - Obter
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getExecution'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);

        // POST /executions - Criar
        register_rest_route($this->namespace, '/executions', [
            'methods' => 'POST',
            'callback' => [$this, 'createExecution'],
            'permission_callback' => [$this, 'checkAdminPermissions']
        ]);

        // POST /executions/{id}/schedule - Agendar
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/schedule', [
            'methods' => 'POST',
            'callback' => [$this, 'scheduleExecution'],
            'permission_callback' => [$this, 'checkAdminPermissions']
        ]);

        // POST /executions/{id}/start - Iniciar
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/start', [
            'methods' => 'POST',
            'callback' => [$this, 'startExecution'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);

        // POST /executions/{id}/complete - Completar
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/complete', [
            'methods' => 'POST',
            'callback' => [$this, 'completeExecution'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);

        // POST /executions/{id}/cancel - Cancelar
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancelExecution'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);

        // POST /executions/{id}/no-show - Marcar no-show
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/no-show', [
            'methods' => 'POST',
            'callback' => [$this, 'markNoShow'],
            'permission_callback' => [$this, 'checkAdminPermissions']
        ]);

        // POST /executions/{id}/reschedule - Reagendar
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/reschedule', [
            'methods' => 'POST',
            'callback' => [$this, 'rescheduleExecution'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);
    }

    /**
     * GET /limpvix/v1/executions
     * Listar execuções com filtros
     */
    public function listExecutions(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $contractId = $request->get_param('contract_id');
            $professionalId = $request->get_param('professional_id');
            $currentUserId = get_current_user_id();

            // Use Case
            $executions = $this->useCases['list']->execute($contractId, $professionalId);

            // Filtrar por autorização (user vê apenas suas execuções)
            if (!current_user_can('manage_options')) {
                $executions = array_filter($executions, function($execution) use ($currentUserId) {
                    return $this->authService->authorize('view', $currentUserId, 'execution', $execution);
                });
            }

            $response = new ExecutionListResponse($executions, count($executions));
            return new WP_REST_Response($response->toArray(), 200);

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * GET /limpvix/v1/executions/{id}
     * Obter execução específica
     */
    public function getExecution(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $executionId = (int) $request->get_param('id');
            $currentUserId = get_current_user_id();

            // Use Case
            $execution = $this->useCases['get']->execute($executionId);

            // Authorization
            if (!$this->authService->authorize('view', $currentUserId, 'execution', $execution)) {
                return ApiResponse::unauthorized();
            }

            return ApiResponse::success(
                ExecutionResponse::fromAggregate($execution)->toArray()
            );

        } catch (\RuntimeException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/executions
     * Criar nova execução
     */
    public function createExecution(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $dto = CreateExecutionRequest::fromArray($request->get_params());

            // Use Case
            $execution = $this->useCases['create']->execute(
                $dto->contract_id,
                $dto->professional_user_id,
                $dto->getScheduledDateImmutable()
            );

            return ApiResponse::success(
                ExecutionResponse::fromAggregate($execution)->toArray(),
                'Execution created successfully',
                201
            );

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/executions/{id}/schedule
     * Agendar execução
     */
    public function scheduleExecution(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $params = array_merge(
                $request->get_params(),
                ['id' => $request->get_param('id')]
            );
            $dto = ScheduleExecutionRequest::fromArray($params);

            // Use Case
            $this->useCases['schedule']->execute(
                $dto->execution_id,
                $dto->getScheduledDateImmutable()
            );

            return ApiResponse::success(null, 'Execution scheduled successfully');

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/executions/{id}/start
     * Iniciar execução
     */
    public function startExecution(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $executionId = (int) $request->get_param('id');
            $currentUserId = get_current_user_id();

            // Buscar execução para authorization
            $execution = $this->useCases['get']->execute($executionId);

            // Authorization
            if (!$this->authService->authorize('update', $currentUserId, 'execution', $execution)) {
                return ApiResponse::unauthorized();
            }

            // Use Case
            $this->useCases['start']->execute($executionId);

            return ApiResponse::success(null, 'Execution started successfully');

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/executions/{id}/complete
     * Completar execução
     */
    public function completeExecution(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $params = array_merge(
                $request->get_params(),
                ['id' => $request->get_param('id')]
            );
            $dto = CompleteExecutionRequest::fromArray($params);
            $currentUserId = get_current_user_id();

            // Buscar execução para authorization
            $execution = $this->useCases['get']->execute($dto->execution_id);

            // Authorization
            if (!$this->authService->authorize('update', $currentUserId, 'execution', $execution)) {
                return ApiResponse::unauthorized();
            }

            // Use Case
            $this->useCases['complete']->execute(
                $dto->execution_id,
                $dto->notes,
                $dto->photos
            );

            return ApiResponse::success(null, 'Execution completed successfully');

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/executions/{id}/cancel
     * Cancelar execução
     */
    public function cancelExecution(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $params = array_merge(
                $request->get_params(),
                ['id' => $request->get_param('id')]
            );
            $dto = CancelExecutionRequest::fromArray($params);
            $currentUserId = get_current_user_id();

            // Buscar execução para authorization
            $execution = $this->useCases['get']->execute($dto->execution_id);

            // Authorization
            if (!$this->authService->authorize('update', $currentUserId, 'execution', $execution)) {
                return ApiResponse::unauthorized();
            }

            // Use Case
            $this->useCases['cancel']->execute(
                $dto->execution_id,
                $dto->reason
            );

            return ApiResponse::success(null, 'Execution cancelled successfully');

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/executions/{id}/no-show
     * Marcar como no-show
     */
    public function markNoShow(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $executionId = (int) $request->get_param('id');

            // Use Case
            $this->useCases['mark_no_show']->execute($executionId);

            return ApiResponse::success(null, 'Execution marked as no-show');

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/executions/{id}/reschedule
     * Reagendar execução
     */
    public function rescheduleExecution(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $params = array_merge(
                $request->get_params(),
                ['id' => $request->get_param('id')]
            );
            $dto = RescheduleExecutionRequest::fromArray($params);
            $currentUserId = get_current_user_id();

            // Buscar execução para authorization
            $execution = $this->useCases['get']->execute($dto->execution_id);

            // Authorization
            if (!$this->authService->authorize('update', $currentUserId, 'execution', $execution)) {
                return ApiResponse::unauthorized();
            }

            // Use Case
            $this->useCases['reschedule']->execute(
                $dto->execution_id,
                $dto->getNewScheduledDateImmutable()
            );

            return ApiResponse::success(null, 'Execution rescheduled successfully');

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    // ========================================
    // PERMISSION CALLBACKS
    // ========================================

    public function checkPermissions(): bool
    {
        return is_user_logged_in();
    }

    public function checkAdminPermissions(): bool
    {
        return current_user_can('manage_options');
    }
}
