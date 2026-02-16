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
use LimpVix\Infrastructure\Auth\JwtAuthMiddleware;
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

        // POST /executions/{id}/evidence - Upload evidence
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/evidence', [
            'methods' => 'POST',
            'callback' => [$this, 'addEvidence'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['photo', 'video'],
                    'description' => 'Evidence type (photo or video)'
                ],
                'url' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'uri',
                    'description' => 'Evidence URL'
                ],
                'category' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['epi_checkin', 'epi_checkout', 'location', 'issue'],
                    'default' => 'location',
                    'description' => 'Evidence category (GAP #2)'
                ],
                'stage' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['check_in', 'execution', 'check_out'],
                    'description' => 'Execution stage when evidence was captured'
                ],
                'uploaded_by' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'User ID who uploaded (default: current user)'
                ]
            ]
        ]);

        // GET /executions/{id}/evidence - List evidences
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/evidence', [
            'methods' => 'GET',
            'callback' => [$this, 'listEvidences'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);

        // DELETE /executions/{id}/evidence/{index} - Delete evidence
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/evidence/(?P<index>\d+)', [
            'methods' => 'DELETE',
            'callback' => [$this, 'removeEvidence'],
            'permission_callback' => [$this, 'checkAdminPermissions']
        ]);
        register_rest_route($this->namespace, '/executions/(?P<id>\d+)/reschedule', [
            'methods' => 'POST',
            'callback' => [$this, 'rescheduleExecution'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);

        // POST /executions/{uuid}/issues - Report issue (GAP #4)
        register_rest_route($this->namespace, '/executions/(?P<uuid>[a-f0-9-]+)/issues', [
            'methods' => 'POST',
            'callback' => [$this, 'reportIssue'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'type' => 'string',
                    'validate_callback' => function($param) {
                        return !empty($param);
                    }
                ],
                'type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['quality', 'damage', 'missing_items', 'access', 'equipment', 'other'],
                    'description' => 'Issue type'
                ],
                'description' => [
                    'required' => true,
                    'type' => 'string',
                    'description' => 'Problem description'
                ],
                'evidence_urls' => [
                    'required' => false,
                    'type' => 'array',
                    'items' => ['type' => 'string'],
                    'description' => 'URLs of photos/videos showing the problem'
                ]
            ]
        ]);

        // GET /executions/{uuid}/issues - List issues
        register_rest_route($this->namespace, '/executions/(?P<uuid>[a-f0-9-]+)/issues', [
            'methods' => 'GET',
            'callback' => [$this, 'listIssues'],
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
    // EVIDENCE MANAGEMENT (GAP #5)
    // ========================================

    /**
     * POST /limpvix/v1/executions/{id}/evidence
     * Add evidence (photo or video) to execution
     * Supports categorization (GAP #2)
     */
    public function addEvidence(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $executionId = (int) $request->get_param('id');
            $type = $request->get_param('type');
            $url = $request->get_param('url');
            $category = $request->get_param('category') ?? 'location';
            $stage = $request->get_param('stage');
            $uploadedBy = $request->get_param('uploaded_by');

            // Use Case
            $addEvidence = new \LimpVix\Application\UseCases\Execution\AddEvidence();
            $result = $addEvidence->execute(
                $executionId,
                $type,
                $url,
                $category,
                $uploadedBy,
                $stage
            );

            if (!$result->isOk()) {
                return ApiResponse::validationError([$result->error()]);
            }

            return ApiResponse::success(
                $result->value(),
                'Evidence added successfully'
            );

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * GET /limpvix/v1/executions/{id}/evidence
     * List all evidences for an execution
     */
    public function listEvidences(WP_REST_Request $request): WP_REST_Response
    {
        try {
            global $wpdb;
            $executionId = (int) $request->get_param('id');
            $table = $wpdb->prefix . 'limpvix_executions';

            $execution = $wpdb->get_row($wpdb->prepare(
                "SELECT id, evidence, evidence_status FROM {$table} WHERE id = %d",
                $executionId
            ), ARRAY_A);

            if (!$execution) {
                return ApiResponse::notFound('Execution not found');
            }

            $evidences = !empty($execution['evidence'])
                ? json_decode($execution['evidence'], true)
                : [];

            return ApiResponse::success([
                'execution_id' => $executionId,
                'evidence_status' => $execution['evidence_status'] ?? null,
                'evidences' => $evidences,
                'count' => count($evidences)
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * DELETE /limpvix/v1/executions/{id}/evidence/{index}
     * Remove specific evidence by index (admin only)
     */
    public function removeEvidence(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $executionId = (int) $request->get_param('id');
            $evidenceIndex = (int) $request->get_param('index');
            $adminUserId = get_current_user_id();

            // Use Case
            $removeEvidence = new \LimpVix\Application\UseCases\Execution\RemoveEvidence();
            $result = $removeEvidence->execute($executionId, $evidenceIndex, $adminUserId);

            if (!$result->isOk()) {
                return ApiResponse::validationError([$result->error()]);
            }

            return ApiResponse::success(
                $result->value(),
                'Evidence removed successfully'
            );

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    // ========================================
    // PERMISSION CALLBACKS

    // ========================================
    // EVIDENCE MANAGEMENT (GAP #5)
    // ========================================

    /**
     * GET /limpvix/v1/executions/{id}/evidence
     * List all evidences for an execution
     */

    // ========================================
    // ISSUE REPORTING (GAP #4)
    // ========================================

    /**
     * POST /limpvix/v1/executions/{uuid}/issues
     * Report an issue during execution
     *
     * GAP #4: Customer or Professional can report problems
     */
    public function reportIssue(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $executionUuid = $request->get_param('uuid');
            $type = $request->get_param('type');
            $description = $request->get_param('description');
            $evidenceUrls = $request->get_param('evidence_urls') ?? [];
            $currentUserId = get_current_user_id();

            // Determine who is reporting (customer or professional)
            // TODO: Improve this logic to properly detect user role
            $reportedBy = current_user_can('manage_options') ? 'admin' : 'customer';

            // Use Case
            $reportIssue = new \LimpVix\Application\UseCases\Execution\ReportIssue(
                $GLOBALS['limpvix_execution_repository'] ?? new \LimpVix\Infrastructure\Persistence\WpExecutionRepository()
            );

            $result = $reportIssue->execute(
                $executionUuid,
                $type,
                $description,
                $reportedBy,
                $currentUserId,
                $evidenceUrls
            );

            if (!$result->isOk()) {
                return ApiResponse::validationError([$result->error()]);
            }

            return ApiResponse::success(
                $result->value(),
                'Issue reported successfully'
            );

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

    /**
     * GET /limpvix/v1/executions/{uuid}/issues
     * List all issues for an execution
     */
    public function listIssues(WP_REST_Request $request): WP_REST_Response
    {
        try {
            $executionUuid = $request->get_param('uuid');

            // Get execution
            $executionRepo = $GLOBALS['limpvix_execution_repository']
                ?? new \LimpVix\Infrastructure\Persistence\WpExecutionRepository();

            $execution = $executionRepo->findByUuid($executionUuid);

            if (!$execution) {
                return ApiResponse::notFound('Execution not found');
            }

            // Get issues
            $issues = $execution->getIssues();

            return ApiResponse::success([
                'execution_uuid' => $executionUuid,
                'issues_count' => $issues->count(),
                'has_open_issues' => $issues->hasOpenIssues(),
                'issues' => $issues->toArray(),
            ]);

        } catch (\Exception $e) {
            return ApiResponse::error($e->getMessage());
        }
    }

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

