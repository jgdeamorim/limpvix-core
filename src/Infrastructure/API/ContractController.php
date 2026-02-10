<?php
/**
 * ContractController - REST API para Contratos Recorrentes
 *
 * REFATORADO PARA DDD - Usa Use Cases em vez de $wpdb direto
 *
 * Endpoints:
 * - GET  /limpvix/v1/contracts - Listar contratos do cliente
 * - POST /limpvix/v1/contracts - Criar novo contrato
 * - POST /limpvix/v1/contracts/{id}/activate - Ativar contrato
 * - POST /limpvix/v1/contracts/{id}/pause - Pausar contrato
 * - POST /limpvix/v1/contracts/{id}/cancel - Cancelar contrato
 * - GET  /limpvix/v1/contracts/{id}/executions - Histórico de execuções
 * - POST /limpvix/v1/contracts/{id}/schedule-execution - Agendar próxima execução
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.8.0
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Application\UseCase\Contract\CreateContract;
use LimpVix\Application\UseCase\Contract\ActivateContract;
use LimpVix\Application\UseCase\Contract\PauseContract;
use LimpVix\Application\UseCase\Contract\CancelContract;
use LimpVix\Application\UseCase\Contract\ScheduleNextExecution;
use LimpVix\Infrastructure\Authorization\AuthorizationService;
use LimpVix\Application\DTO\Request\CreateContractRequest;
use LimpVix\Application\DTO\Response\ContractResponse;
use LimpVix\Application\DTO\Response\ContractListResponse;

defined('ABSPATH') || exit;

class ContractController
{
    private string $namespace = 'limpvix/v1';
    private ContractRepositoryInterface $repository;
    private array $useCases;
    private AuthorizationService $authService;

    /**
     * Construtor com Dependency Injection
     *
     * @param array $useCases Array com Use Cases injetados pelo Bootstrap
     * @param AuthorizationService|null $authService Authorization service
     */
    public function __construct(array $useCases = [], ?AuthorizationService $authService = null)
    {
        $this->useCases = $useCases;

        // Repository é acessado via global se não injetado
        $this->repository = $GLOBALS['limpvix_contract_repository'] ?? null;

        if (!$this->repository) {
            error_log('[ContractController] Repository not found - using global fallback');
        }

        // AuthorizationService via DI ou global
        $this->authService = $authService ?? $GLOBALS['limpvix_authorization_service'] ?? null;

        if (!$this->authService) {
            error_log('[ContractController] AuthorizationService not available');
        }
    }

    /**
     * Registrar rotas (compatibilidade com versão antiga)
     */
    public function register(): void
    {
        add_action('rest_api_init', [$this, 'register_routes']);
    }

    /**
     * Registrar rotas REST API
     */
    public function register_routes(): void
    {
        // GET /limpvix/v1/contracts
        register_rest_route($this->namespace, '/contracts', [
            'methods' => 'GET',
            'callback' => [$this, 'listContracts'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'client_user_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'Filtrar por ID do cliente'
                ],
                'status' => [
                    'required' => false,
                    'type' => 'string',
                    'enum' => ['draft', 'pending_allocation', 'active', 'paused', 'completed', 'cancelled'],
                ]
            ]
        ]);

        // POST /limpvix/v1/contracts
        register_rest_route($this->namespace, '/contracts', [
            'methods' => 'POST',
            'callback' => [$this, 'createContract'],
            'permission_callback' => [$this, 'checkPermissions'],
            'args' => [
                'client_user_id' => [
                    'required' => true,
                    'type' => 'integer',
                    'validate_callback' => function($param) {
                        return get_user_by('id', $param) !== false;
                    }
                ],
                'contract_type' => [
                    'required' => true,
                    'type' => 'string',
                    'enum' => ['monthly', 'weekly', 'biweekly']
                ],
                'recurrence_day' => [
                    'required' => true,
                    'type' => 'integer'
                ],
                'service_code' => [
                    'required' => true,
                    'type' => 'string'
                ],
                'property_type' => [
                    'required' => true,
                    'type' => 'string'
                ],
                'monthly_value' => [
                    'required' => true,
                    'type' => 'number',
                    'minimum' => 0
                ],
                'start_date' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'date'
                ],
                'auto_activate' => [
                    'required' => false,
                    'type' => 'boolean',
                    'default' => false,
                    'description' => 'Ativar imediatamente após criação'
                ],
                'professional_id' => [
                    'required' => false,
                    'type' => 'integer',
                    'description' => 'ID do profissional (obrigatório se auto_activate=true)'
                ]
            ]
        ]);

        // POST /limpvix/v1/contracts/{id}/activate
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/activate', [
            'methods' => 'POST',
            'callback' => [$this, 'activateContract'],
            'permission_callback' => [$this, 'checkAdminPermissions'],
            'args' => [
                'professional_id' => [
                    'required' => true,
                    'type' => 'integer'
                ]
            ]
        ]);

        // POST /limpvix/v1/contracts/{id}/pause
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/pause', [
            'methods' => 'POST',
            'callback' => [$this, 'pauseContract'],
            'permission_callback' => [$this, 'checkAdminPermissions'],
            'args' => [
                'reason' => [
                    'required' => false,
                    'type' => 'string'
                ]
            ]
        ]);

        // POST /limpvix/v1/contracts/{id}/cancel
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/cancel', [
            'methods' => 'POST',
            'callback' => [$this, 'cancelContract'],
            'permission_callback' => [$this, 'checkAdminPermissions'],
            'args' => [
                'reason' => [
                    'required' => false,
                    'type' => 'string'
                ]
            ]
        ]);

        // GET /limpvix/v1/contracts/{id}/executions (mantido como estava)
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/executions', [
            'methods' => 'GET',
            'callback' => [$this, 'getExecutions'],
            'permission_callback' => [$this, 'checkPermissions']
        ]);

        // POST /limpvix/v1/contracts/{id}/schedule-execution
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/schedule-execution', [
            'methods' => 'POST',
            'callback' => [$this, 'scheduleExecution'],
            'permission_callback' => [$this, 'checkAdminPermissions']
        ]);
    }

    /**
     * GET /limpvix/v1/contracts
     * Lista contratos usando ListContracts Use Case
     */
    public function listContracts(WP_REST_Request $request): WP_REST_Response
    {
        if (!isset($this->useCases['list'])) {
            return ApiResponse::error('ListContracts Use Case not available', null, 500);
        }

        $clientUserId = $request->get_param('client_user_id');
        $status = $request->get_param('status');
        $currentUserId = get_current_user_id();

        // Se não for admin, só pode ver seus próprios contratos
        if (!current_user_can('manage_options')) {
            $clientUserId = $currentUserId;
        }

        try {
            // Use Case: ListContracts
            $contracts = $this->useCases['list']->execute($clientUserId, $status);

            // Response DTO
            $response = new ContractListResponse($contracts, count($contracts));
            return new WP_REST_Response($response->toArray(), 200);

        } catch (\Exception $e) {
            return ApiResponse::error('Error listing contracts: ' . $e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/contracts
     * Cria novo contrato usando CreateContract Use Case
     */
    public function createContract(WP_REST_Request $request): WP_REST_Response
    {
        if (!isset($this->useCases['create'])) {
            return ApiResponse::error('CreateContract Use Case not available', null, 500);
        }

        try {
            // DTO: Validação de input
            $dto = CreateContractRequest::fromArray($request->get_params());

            // Authorization: User pode criar apenas para si mesmo (já validado no DTO)
            if ($this->authService && !current_user_can('manage_options')) {
                if (!$this->authService->authorize('create', get_current_user_id(), 'contract', $dto->toUseCaseParams())) {
                    return ApiResponse::unauthorized('Você só pode criar contratos para si mesmo');
                }
            }

            // Use Case: CreateContract
            $contract = $this->useCases['create']->execute($dto->toUseCaseParams());

            // Auto-ativar se solicitado
            if ($dto->auto_activate && $dto->professional_id && isset($this->useCases['activate'])) {
                $this->useCases['activate']->execute(
                    $contract->getId()->toInt(),
                    $dto->professional_id
                );

                // Re-buscar contrato atualizado
                $contract = $this->repository->findById($contract->getId());
            }

            // Response DTO
            return ApiResponse::success(
                ContractResponse::fromAggregate($contract)->toArray(),
                'Contrato criado com sucesso',
                201
            );

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\Exception $e) {
            return ApiResponse::error('Error creating contract: ' . $e->getMessage());
        }
    }

    /**
     * POST /limpvix/v1/contracts/{id}/activate
     */
    public function activateContract(WP_REST_Request $request): WP_REST_Response
    {
        if (!isset($this->useCases['activate'])) {
            return $this->errorResponse('ActivateContract Use Case not available', 500);
        }

        $contractId = (int) $request->get_param('id');
        $professionalId = (int) $request->get_param('professional_id');

        try {
            /** @var ActivateContract $useCase */
            $useCase = $this->useCases['activate'];
            $useCase->execute($contractId, $professionalId);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Contrato ativado com sucesso'
            ], 200);

        } catch (\Exception $e) {
            return $this->errorResponse('Error activating contract: ' . $e->getMessage(), 400);
        }
    }

    /**
     * POST /limpvix/v1/contracts/{id}/pause
     */
    public function pauseContract(WP_REST_Request $request): WP_REST_Response
    {
        if (!isset($this->useCases['pause'])) {
            return $this->errorResponse('PauseContract Use Case not available', 500);
        }

        $contractId = (int) $request->get_param('id');
        $reason = $request->get_param('reason') ?? '';

        try {
            /** @var PauseContract $useCase */
            $useCase = $this->useCases['pause'];
            $useCase->execute($contractId, $reason);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Contrato pausado com sucesso'
            ], 200);

        } catch (\Exception $e) {
            return $this->errorResponse('Error pausing contract: ' . $e->getMessage(), 400);
        }
    }

    /**
     * POST /limpvix/v1/contracts/{id}/cancel
     */
    public function cancelContract(WP_REST_Request $request): WP_REST_Response
    {
        if (!isset($this->useCases['cancel'])) {
            return $this->errorResponse('CancelContract Use Case not available', 500);
        }

        $contractId = (int) $request->get_param('id');
        $reason = $request->get_param('reason') ?? '';

        try {
            /** @var CancelContract $useCase */
            $useCase = $this->useCases['cancel'];
            $useCase->execute($contractId, $reason);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Contrato cancelado com sucesso'
            ], 200);

        } catch (\Exception $e) {
            return $this->errorResponse('Error cancelling contract: ' . $e->getMessage(), 400);
        }
    }

    /**
     * POST /limpvix/v1/contracts/{id}/schedule-execution
     * Usa ScheduleNextExecution Use Case
     */
    public function scheduleExecution(WP_REST_Request $request): WP_REST_Response
    {
        if (!isset($this->useCases['schedule_next'])) {
            return $this->errorResponse('ScheduleNextExecution Use Case not available', 500);
        }

        $contractId = (int) $request->get_param('id');

        try {
            // Verificar se contrato existe
            $contract = $this->repository->findById(ContractId::fromInt($contractId));

            if (!$contract) {
                return $this->errorResponse('Contrato não encontrado', 404);
            }

            if (!$contract->isActive()) {
                return $this->errorResponse('Contrato não está ativo', 400);
            }

            /** @var ScheduleNextExecution $useCase */
            $useCase = $this->useCases['schedule_next'];
            $useCase->execute($contractId);

            // Re-buscar para pegar next_execution_date atualizado
            $contract = $this->repository->findById(ContractId::fromInt($contractId));

            return new WP_REST_Response([
                'success' => true,
                'message' => 'Próxima execução agendada com sucesso',
                'data' => [
                    'contract_id' => $contractId,
                    'next_execution_date' => $contract->getNextExecutionDate()?->format('Y-m-d'),
                ]
            ], 200);

        } catch (\Exception $e) {
            return $this->errorResponse('Error scheduling execution: ' . $e->getMessage(), 400);
        }
    }

    /**
     * GET /limpvix/v1/contracts/{id}/executions
     * DEPRECATED - Use /limpvix/v1/executions?contract_id={id} instead
     */
    public function getExecutions(WP_REST_Request $request): WP_REST_Response
    {
        $contractId = (int) $request->get_param('id');

        return ApiResponse::deprecated(
            "/wp-json/limpvix/v1/executions?contract_id={$contractId}"
        );
    }

    // ========================================
    // MÉTODOS AUXILIARES
    // ========================================

    public function checkPermissions(): bool
    {
        if (current_user_can('manage_options')) {
            return true;
        }
        return is_user_logged_in();
    }

    public function checkAdminPermissions(): bool
    {
        return current_user_can('manage_options');
    }

    private function errorResponse(string $message, int $status): WP_REST_Response
    {
        return new WP_REST_Response([
            'success' => false,
            'message' => $message
        ], $status);
    }

    private function getContractTypeLabel(string $type): string
    {
        $labels = [
            'monthly' => 'Mensal',
            'weekly' => 'Semanal',
            'biweekly' => 'Quinzenal'
        ];
        return $labels[$type] ?? $type;
    }

    private function getStatusLabel(string $status): string
    {
        $labels = [
            'draft' => 'Rascunho',
            'pending_allocation' => 'Aguardando Alocação',
            'active' => 'Ativo',
            'paused' => 'Pausado',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado'
        ];
        return $labels[$status] ?? $status;
    }

    private function getExecutionStatusLabel(string $status): string
    {
        $labels = [
            'pending' => 'Pendente',
            'scheduled' => 'Agendado',
            'in_progress' => 'Em Andamento',
            'completed' => 'Concluído',
            'cancelled' => 'Cancelado',
            'failed' => 'Falhou'
        ];
        return $labels[$status] ?? $status;
    }
}
