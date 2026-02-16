<?php
/**
 * BriefingController - REST API Controller
 *
 * RESPONSABILIDADE:
 * - CRUD de Briefings via REST API
 * - Endpoints: POST /briefing, GET /briefing/{uuid}
 * - Orquestrar CreateBriefing use case
 * - Retornar dados serializados para frontend
 *
 * ENDPOINTS:
 * POST /wp-json/limpvix/v1/briefing - Criar novo Briefing
 * GET /wp-json/limpvix/v1/briefing/{uuid} - Buscar Briefing por UUID
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Briefing\CreateBriefing;
use LimpVix\Infrastructure\Auth\JwtAuthMiddleware;
use LimpVix\Infrastructure\Middleware\PhoneVerificationMiddleware;
use LimpVix\Domain\Briefing\BriefingRepositoryInterface;

defined('ABSPATH') || exit;

class BriefingController
{
    /**
     * @var CreateBriefing
     */
    private $createBriefingUseCase;

    /**
     * @var BriefingRepositoryInterface
     */
    private $briefingRepository;
    private $jwtMiddleware;

    /**
     * Construtor
     *
     * @param CreateBriefing $createBriefingUseCase
     * @param BriefingRepositoryInterface $briefingRepository
     */
    public function __construct(
        CreateBriefing $createBriefingUseCase,
        BriefingRepositoryInterface $briefingRepository,
        ?JwtAuthMiddleware $jwtMiddleware = null
    ) {
        $this->createBriefingUseCase = $createBriefingUseCase;
        $this->briefingRepository = $briefingRepository;
        $this->jwtMiddleware = $jwtMiddleware;
    }

    /**
     * Registrar rotas
     *
     * @return void
     */
    public function register(): void
    {
        // POST /briefing - Criar
        register_rest_route('limpvix/v1', '/briefing', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create'],
            'permission_callback' => [$this, 'checkCreatePermission'],
            'args' => $this->getCreateArgs()
        ]);

        // GET /briefing/{uuid} - Buscar
        register_rest_route('limpvix/v1', '/briefing/(?P<uuid>[a-f0-9\-]{36})', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get'],
            'permission_callback' => [$this, 'checkReadPermission'],
            'args' => [
                'uuid' => [
                    'required' => true,
                    'type' => 'string',
                    'format' => 'uuid',
                    'description' => 'UUID do Briefing'
                ]
            ]
        ]);
    }

    /**
     * POST /wp-json/limpvix/v1/briefing
     *
     * Criar novo Briefing.
     *
     * Body:
     * {
     *   "property_type": "residential",
     *   "user_id": 123 (opcional, usa current_user se omitido)
     * }
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            // 1. Obter property_type
            $propertyType = $request->get_param('property_type');

            // 2. Obter user_id (ou usar current_user)
            $userId = $request->get_param('user_id');

            if ($userId === null) {
                $userId = get_current_user_id();
            }

            if ($userId === 0) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'user_id obrigatório (usuário não autenticado)'
                ], 401);
            }

            // 3. Executar use case
            $result = $this->createBriefingUseCase->execute($propertyType, $userId);

            if (!$result->isSuccess()) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $result->getErrorMessage()
                ], 400);
            }

            // 4. Serializar Briefing para resposta
            $briefing = $result->getBriefing();
            $data = $this->serializeBriefing($briefing);

            // 5. Retornar sucesso
            return new \WP_REST_Response([
                'success' => true,
                'data' => $data,
                'message' => 'Briefing criado com sucesso'
            ], 201);

        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao criar Briefing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /wp-json/limpvix/v1/briefing/{uuid}
     *
     * Buscar Briefing por UUID.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            // 1. Obter UUID
            $uuid = $request->get_param('uuid');

            // 2. Buscar Briefing
            $briefing = $this->briefingRepository->findByUuid($uuid);

            if ($briefing === null) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Briefing não encontrado'
                ], 404);
            }

            // 3. Verificar permissão (user_id deve coincidir ou ser admin)
            $currentUserId = get_current_user_id();

            if ($briefing->getUserId() !== $currentUserId && !current_user_can('manage_options')) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Acesso negado a este Briefing'
                ], 403);
            }

            // 4. Serializar e retornar
            $data = $this->serializeBriefing($briefing);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $data
            ], 200);

        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao buscar Briefing: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Serializar Briefing para resposta REST
     *
     * Converte Aggregate Root → JSON amigável.
     *
     * @param \LimpVix\Domain\Briefing\Briefing $briefing
     * @return array
     */
    private function serializeBriefing($briefing): array
    {
        $data = [
            'uuid' => $briefing->getUuid(),
            'user_id' => $briefing->getUserId(),
            'order_id' => $briefing->getOrderId(),
            'status' => $briefing->getStatus()->getValue(),
            'property_type' => $briefing->getPropertyType()->getValue(),
            'phone_verified' => $briefing->isPhoneVerified(),
            'requires_contract' => $briefing->requiresContract(),
            'is_locked' => $briefing->isLocked(),
            'version' => $briefing->getVersion(),
            'created_at' => $briefing->getCreatedAt()->format('c'), // ISO 8601
            'updated_at' => $briefing->getUpdatedAt()->format('c')
        ];

        // Structure (se presente)
        if ($briefing->getStructure() !== null) {
            $data['structure'] = $briefing->getStructure()->toArray();
        }

        // Frequency (se presente)
        if ($briefing->getFrequency() !== null) {
            $data['frequency'] = $briefing->getFrequency()->toArray();
        }

        // Metrics (se presente)
        if ($briefing->getMetrics() !== null) {
            $data['metrics'] = [
                'm2' => $briefing->getMetrics()->getM2(),
                'duration_minutes' => $briefing->getMetrics()->getDurationMinutes(),
                'buffer_minutes' => $briefing->getMetrics()->getBufferMinutes(),
                'total_minutes' => $briefing->getMetrics()->getTotalMinutes()
            ];
        }

        // Locked at (se locked)
        if ($briefing->getLockedAt() !== null) {
            $data['locked_at'] = $briefing->getLockedAt()->format('c');
        }

        return $data;
    }

    /**
     * Verificar permissão para criar Briefing
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function checkCreatePermission(\WP_REST_Request $request): bool
    {
        // JWT authentication se disponível, senão fallback para WordPress session
        if ($this->jwtMiddleware) {
            return $this->jwtMiddleware->isAuthenticated($request);
        }
        return $this->jwtMiddleware ? $this->jwtMiddleware->isAuthenticated($request) : is_user_logged_in();
    }

    /**
     * Verificar permissão para ler Briefing
     *
     * Validação detalhada é feita no método get() (verificar user_id).
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function checkReadPermission(\WP_REST_Request $request): bool
    {
        // Permitir se autenticado (validação fina no método)
        return $this->jwtMiddleware ? $this->jwtMiddleware->isAuthenticated($request) : is_user_logged_in();
    }

    /**
     * Schema dos argumentos para POST /briefing
     *
     * @return array
     */
    private function getCreateArgs(): array
    {
        return [
            'property_type' => [
                'required' => true,
                'type' => 'string',
                'enum' => ['residential', 'commercial'],
                'description' => 'Tipo de propriedade',
                'validate_callback' => function ($param) {
                    return in_array($param, ['residential', 'commercial'], true);
                }
            ],
            'user_id' => [
                'required' => false,
                'type' => 'integer',
                'description' => 'ID do usuário (opcional, usa current_user se omitido)',
                'validate_callback' => function ($param) {
                    return is_numeric($param) && $param > 0;
                }
            ]
        ];
    }
}
