<?php
/**
 * ProfessionalController - REST API Controller
 *
 * RESPONSABILIDADE:
 * - API REST completa para Professional Module (Marketplace)
 * - CRUD de profissionais
 * - Gerenciamento de ofertas (first-to-accept)
 * - Atualização de disponibilidade
 * - Histórico de score e alocações
 *
 * ENDPOINTS:
 * GET    /wp-json/limpvix/v1/professionals           - Listar profissionais (admin)
 * POST   /wp-json/limpvix/v1/professionals           - Registrar profissional
 * GET    /wp-json/limpvix/v1/professionals/{id}      - Detalhes do profissional
 * PATCH  /wp-json/limpvix/v1/professionals/{id}      - Atualizar profissional
 * GET    /wp-json/limpvix/v1/professionals/{id}/offers              - Listar ofertas
 * POST   /wp-json/limpvix/v1/professionals/{id}/offers/{offer_id}/accept - Aceitar oferta
 * POST   /wp-json/limpvix/v1/professionals/{id}/offers/{offer_id}/reject - Rejeitar oferta
 * PATCH  /wp-json/limpvix/v1/professionals/{id}/availability       - Atualizar disponibilidade
 * GET    /wp-json/limpvix/v1/professionals/{id}/score-history      - Histórico de score
 * GET    /wp-json/limpvix/v1/professionals/{id}/allocations        - Histórico de alocações
 *
 * AUTENTICAÇÃO:
 * - Admin: manage_options (listar todos, registrar, atualizar qualquer)
 * - Professional: próprio perfil (ver, atualizar próprio, ofertas)
 * - Público: nenhum acesso
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;
use LimpVix\Application\UseCases\Professional\RegisterProfessional;
use LimpVix\Application\UseCases\Professional\UpdateProfessionalScore;
use LimpVix\Infrastructure\Authorization\AuthorizationService;
use LimpVix\Application\DTO\Request\AcceptOfferRequest;
use LimpVix\Application\DTO\Request\RejectOfferRequest;
use LimpVix\Application\DTO\Request\RegisterProfessionalRequest;
use LimpVix\Application\DTO\Request\UpdateAvailabilityRequest;
use LimpVix\Application\DTO\Response\ProfessionalResponse;

defined('ABSPATH') || exit;

class ProfessionalController
{
    private $repository;
    private array $useCases;
    private ?AuthorizationService $authService;

    /**
     * Construtor com Dependency Injection
     *
     * @param array $useCases Array com Use Cases injetados
     * @param AuthorizationService|null $authService Authorization service
     */
    public function __construct(array $useCases = [], ?AuthorizationService $authService = null)
    {
        $this->useCases = $useCases;
        $this->authService = $authService ?? $GLOBALS['limpvix_authorization_service'] ?? null;

        // Repository via global ou DI
        $this->repository = $GLOBALS['limpvix_professional_repository'] ?? new WpMarketplaceProfessionalRepository();
    }

    /**
     * Registrar rotas REST
     */
    public function register(): void
    {
        // GET /professionals - Listar
        register_rest_route('limpvix/v1', '/professionals', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'list'],
            'permission_callback' => [$this, 'checkAdminPermission'],
            'args' => $this->getListArgs()
        ]);

        // POST /professionals - Registrar
        register_rest_route('limpvix/v1', '/professionals', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'create'],
            'permission_callback' => [$this, 'checkAdminPermission'],
            'args' => $this->getCreateArgs()
        ]);

        // GET /professionals/{id} - Detalhes
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'get'],
            'permission_callback' => [$this, 'checkProfessionalPermission'],
            'args' => [
                'id' => [
                    'required' => true,
                    'type' => 'integer',
                    'description' => 'ID do profissional'
                ]
            ]
        ]);

        // PATCH /professionals/{id} - Atualizar
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)', [
            'methods' => \WP_REST_Server::EDITABLE,
            'callback' => [$this, 'update'],
            'permission_callback' => [$this, 'checkProfessionalPermission'],
            'args' => $this->getUpdateArgs()
        ]);

        // GET /professionals/{id}/offers - Listar ofertas
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)/offers', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'listOffers'],
            'permission_callback' => [$this, 'checkProfessionalPermission'],
        ]);

        // POST /professionals/{id}/offers/{offer_id}/accept - Aceitar oferta
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)/offers/(?P<offer_id>\d+)/accept', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'acceptOffer'],
            'permission_callback' => [$this, 'checkProfessionalPermission'],
        ]);

        // POST /professionals/{id}/offers/{offer_id}/reject - Rejeitar oferta
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)/offers/(?P<offer_id>\d+)/reject', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'rejectOffer'],
            'permission_callback' => [$this, 'checkProfessionalPermission'],
        ]);

        // PATCH /professionals/{id}/availability - Atualizar disponibilidade
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)/availability', [
            'methods' => \WP_REST_Server::EDITABLE,
            'callback' => [$this, 'updateAvailability'],
            'permission_callback' => [$this, 'checkProfessionalPermission'],
        ]);

        // GET /professionals/{id}/score-history - Histórico de score
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)/score-history', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getScoreHistory'],
            'permission_callback' => [$this, 'checkProfessionalOrAdminPermission'],
        ]);

        // GET /professionals/{id}/allocations - Histórico de alocações
        register_rest_route('limpvix/v1', '/professionals/(?P<id>\d+)/allocations', [
            'methods' => \WP_REST_Server::READABLE,
            'callback' => [$this, 'getAllocations'],
            'permission_callback' => [$this, 'checkProfessionalOrAdminPermission'],
        ]);
    }

    /**
     * GET /wp-json/limpvix/v1/professionals
     *
     * Listar profissionais (admin only)
     */
    public function list(\WP_REST_Request $request): \WP_REST_Response
    {
        $status = $request->get_param('status') ?: 'all';
        $verified = $request->get_param('verified') ?: 'all';
        $min_score = $request->get_param('min_score') ?: 0;
        $search = $request->get_param('search') ?: '';
        $page = $request->get_param('page') ?: 1;
        $per_page = $request->get_param('per_page') ?: 20;

        try {
            $professionals = $this->getProfessionals($status, $verified, $min_score, $search, $page, $per_page);
            $stats = $this->repository->getStatistics();

            return new \WP_REST_Response([
                'success' => true,
                'data' => array_map([$this, 'serializeProfessional'], $professionals),
                'pagination' => [
                    'page' => $page,
                    'per_page' => $per_page,
                    'total' => count($professionals), // Simplificado
                ],
                'stats' => $stats,
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /wp-json/limpvix/v1/professionals
     *
     * Registrar novo profissional
     */
    /**
     * POST /wp-json/limpvix/v1/professionals
     *
     * Criar novo profissional - REFATORADO
     */
    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!isset($this->useCases['register'])) {
            return ApiResponse::error('RegisterProfessional Use Case not available', null, 500);
        }

        try {
            $dto = RegisterProfessionalRequest::fromArray([
                'full_name' => $request->get_param('full_name'),
                'cpf' => $request->get_param('cpf'),
                'phone' => $request->get_param('phone'),
                'email' => $request->get_param('email'),
                'address' => $request->get_param('address'),
                'skills' => $request->get_param('skills'),
                'certifications' => $request->get_param('certifications') ?: [],
                'physical_limitations' => $request->get_param('physical_limitations') ?: [],
                'service_radius_km' => $request->get_param('service_radius_km') ?: 20,
                'weekly_availability' => $request->get_param('weekly_availability'),
            ]);

            // Authorization: Admin pode criar qualquer profissional
            if ($this->authService && !$this->authService->authorize('create', get_current_user_id(), 'professional', $dto->toArray())) {
                return ApiResponse::unauthorized('Você não tem permissão para criar profissionais');
            }

            // Use Case executa o registro
            $result = $this->useCases['register']->execute($dto->toArray());

            // RegisterProfessional pode retornar WP_Error
            if (is_wp_error($result)) {
                return ApiResponse::error($result->get_error_message(), $result->get_error_code(), 400);
            }

            return ApiResponse::success(
                $result['professional'],
                'Profissional registrado com sucesso',
                201
            );

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\Exception $e) {
            return ApiResponse::error('Erro ao registrar profissional: ' . $e->getMessage());
        }
    }

    /**
     * GET /wp-json/limpvix/v1/professionals/{id}
     *
     * Detalhes do profissional
     */
    public function get(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        $professional = $this->repository->findById($id);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => $this->serializeProfessional($professional)
        ], 200);
    }

    /**
     * PATCH /wp-json/limpvix/v1/professionals/{id}
     *
     * Atualizar profissional
     */
    public function update(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        $professional = $this->repository->findById($id);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        // Atualizar campos permitidos
        // (Implementação simplificada - expandir conforme necessário)

        try {
            $this->repository->save($professional);

            return new \WP_REST_Response([
                'success' => true,
                'data' => $this->serializeProfessional($professional),
                'message' => 'Profissional atualizado'
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * GET /wp-json/limpvix/v1/professionals/{id}/offers
     *
     * Listar ofertas do profissional
     */
    /**
     * GET /wp-json/limpvix/v1/professionals/{id}/offers
     *
     * Listar ofertas do profissional - REFATORADO
     */
    public function listOffers(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!isset($this->useCases['list_offers'])) {
            return ApiResponse::error('ListOffers Use Case not available', null, 500);
        }

        try {
            $professionalId = (int) $request->get_param('id');
            $limit = (int) ($request->get_param('limit') ?: 50);

            // Authorization: Professional pode ver apenas suas próprias ofertas
            if ($this->authService && !$this->authService->authorize('view', get_current_user_id(), 'professional', $professionalId)) {
                return ApiResponse::unauthorized('Você só pode ver suas próprias ofertas');
            }

            // Use Case executa a busca
            $offers = $this->useCases['list_offers']->execute($professionalId, $limit);

            return ApiResponse::success($offers);

        } catch (\RuntimeException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error('Erro ao listar ofertas: ' . $e->getMessage());
        }
    }

    /**
     * POST /wp-json/limpvix/v1/professionals/{id}/offers/{offer_id}/accept
     *
     * Aceitar oferta (first-to-accept) - REFATORADO
     */
    public function acceptOffer(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!isset($this->useCases['accept_offer'])) {
            return ApiResponse::error('AcceptOffer Use Case not available', null, 500);
        }

        try {
            $professionalId = (int) $request->get_param('id');
            $offerId = (int) $request->get_param('offer_id');

            // Authorization: Professional pode aceitar apenas suas próprias ofertas
            if ($this->authService && !$this->authService->authorize('update', get_current_user_id(), 'professional', $professionalId)) {
                return ApiResponse::unauthorized('Você só pode aceitar suas próprias ofertas');
            }

            // Use Case executa toda a lógica
            $result = $this->useCases['accept_offer']->execute($professionalId, $offerId);

            return ApiResponse::success(
                $result,
                'Oferta aceita! Contrato alocado para você.'
            );

        } catch (\RuntimeException $e) {
            // Erros de negócio (oferta não encontrada, expirada, etc)
            return ApiResponse::error($e->getMessage(), 'business_error', 400);
        } catch (\Exception $e) {
            // Erros inesperados
            return ApiResponse::error('Erro ao aceitar oferta: ' . $e->getMessage());
        }
    }

    /**
     * POST /wp-json/limpvix/v1/professionals/{id}/offers/{offer_id}/reject
     *
     * Rejeitar oferta - REFATORADO
     */
    public function rejectOffer(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!isset($this->useCases['reject_offer'])) {
            return ApiResponse::error('RejectOffer Use Case not available', null, 500);
        }

        try {
            $dto = RejectOfferRequest::fromArray([
                'professional_id' => (int) $request->get_param('id'),
                'offer_id' => (int) $request->get_param('offer_id'),
                'reason' => $request->get_param('reason') ?: 'not_interested',
                'notes' => $request->get_param('notes'),
            ]);

            // Authorization
            if ($this->authService && !$this->authService->authorize('update', get_current_user_id(), 'professional', $dto->professional_id)) {
                return ApiResponse::unauthorized('Você só pode rejeitar suas próprias ofertas');
            }

            // Use Case
            $this->useCases['reject_offer']->execute(
                $dto->professional_id,
                $dto->offer_id,
                $dto->reason,
                $dto->notes
            );

            return ApiResponse::success(null, 'Oferta rejeitada');

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\RuntimeException $e) {
            return ApiResponse::error($e->getMessage(), 'business_error', 400);
        } catch (\Exception $e) {
            return ApiResponse::error('Erro ao rejeitar oferta: ' . $e->getMessage());
        }
    }

    /**
     * PATCH /wp-json/limpvix/v1/professionals/{id}/availability
     *
     * Atualizar disponibilidade
     */
    /**
     * PUT /wp-json/limpvix/v1/professionals/{id}/availability
     *
     * Atualizar disponibilidade do profissional - REFATORADO
     */
    public function updateAvailability(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!isset($this->useCases['update_availability'])) {
            return ApiResponse::error('UpdateAvailability Use Case not available', null, 500);
        }

        try {
            $dto = UpdateAvailabilityRequest::fromArray([
                'professional_id' => (int) $request->get_param('id'),
                'availability' => $request->get_param('availability'),
            ]);

            // Authorization: Professional pode atualizar apenas sua própria disponibilidade
            if ($this->authService && !$this->authService->authorize('update', get_current_user_id(), 'professional', $dto->professional_id)) {
                return ApiResponse::unauthorized('Você só pode atualizar sua própria disponibilidade');
            }

            // Use Case executa a atualização
            $result = $this->useCases['update_availability']->execute($dto->professional_id, $dto->availability);

            return ApiResponse::success(
                $result,
                'Disponibilidade atualizada com sucesso'
            );

        } catch (\InvalidArgumentException $e) {
            return ApiResponse::validationError([$e->getMessage()]);
        } catch (\RuntimeException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error('Erro ao atualizar disponibilidade: ' . $e->getMessage());
        }
    }

    /**
     * GET /wp-json/limpvix/v1/professionals/{id}/score-history
     *
     * Histórico de score - REFATORADO
     */
    public function getScoreHistory(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!isset($this->useCases['get_score_history'])) {
            return ApiResponse::error('GetScoreHistory Use Case not available', null, 500);
        }

        try {
            $professionalId = (int) $request->get_param('id');
            $limit = (int) ($request->get_param('limit') ?: 50);

            // Authorization: Professional pode ver apenas seu próprio histórico
            if ($this->authService && !$this->authService->authorize('view', get_current_user_id(), 'professional', $professionalId)) {
                return ApiResponse::unauthorized('Você só pode ver seu próprio histórico');
            }

            // Use Case executa a busca
            $history = $this->useCases['get_score_history']->execute($professionalId, $limit);

            return ApiResponse::success($history);

        } catch (\RuntimeException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error('Erro ao buscar histórico de score: ' . $e->getMessage());
        }
    }

    /**
     * GET /wp-json/limpvix/v1/professionals/{id}/allocations
     *
     * Histórico de alocações - REFATORADO
     */
    public function getAllocations(\WP_REST_Request $request): \WP_REST_Response
    {
        if (!isset($this->useCases['get_allocation_history'])) {
            return ApiResponse::error('GetAllocationHistory Use Case not available', null, 500);
        }

        try {
            $professionalId = (int) $request->get_param('id');

            // Authorization: Professional pode ver apenas suas próprias alocações
            if ($this->authService && !$this->authService->authorize('view', get_current_user_id(), 'professional', $professionalId)) {
                return ApiResponse::unauthorized('Você só pode ver suas próprias alocações');
            }

            // Use Case executa a busca
            $allocations = $this->useCases['get_allocation_history']->execute($professionalId);

            return ApiResponse::success($allocations);

        } catch (\RuntimeException $e) {
            return ApiResponse::notFound($e->getMessage());
        } catch (\Exception $e) {
            return ApiResponse::error('Erro ao buscar histórico de alocações: ' . $e->getMessage());
        }
    }

    // ==================== PERMISSION CALLBACKS ====================

    public function checkAdminPermission(): bool
    {
        return current_user_can('manage_options');
    }

    public function checkProfessionalPermission(\WP_REST_Request $request): bool
    {
        $professionalId = (int) $request->get_param('id');

        // Admin pode tudo
        if (current_user_can('manage_options')) {
            return true;
        }

        // Professional pode apenas seu próprio perfil
        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            return false;
        }

        return get_current_user_id() === $professional->getUserId();
    }

    public function checkProfessionalOrAdminPermission(\WP_REST_Request $request): bool
    {
        return $this->checkProfessionalPermission($request);
    }

    // ==================== HELPERS ====================

    private function getProfessionals($status, $verified, $min_score, $search, $page, $per_page): array
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professionals';

        $where = ['1=1'];

        // Filter by status
        if ($status === 'active') {
            $where[] = 'is_active = 1 AND (suspended_until IS NULL OR suspended_until < NOW())';
        } elseif ($status === 'inactive') {
            $where[] = 'is_active = 0';
        } elseif ($status === 'suspended') {
            $where[] = 'suspended_until IS NOT NULL AND suspended_until > NOW()';
        }

        // Filter by verified
        if ($verified === 'verified') {
            $where[] = 'is_verified = 1';
        } elseif ($verified === 'not_verified') {
            $where[] = 'is_verified = 0';
        }

        // Filter by score
        if ($min_score > 0) {
            $where[] = $wpdb->prepare('score >= %f', $min_score);
        }

        // Search
        if (!empty($search)) {
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare(
                '(full_name LIKE %s OR cpf LIKE %s OR email LIKE %s)',
                $like, $like, $like
            );
        }

        $offset = ($page - 1) * $per_page;
        $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) .
               " ORDER BY created_at DESC LIMIT %d OFFSET %d";
        $sql = $wpdb->prepare($sql, $per_page, $offset);

        $results = $wpdb->get_results($sql, ARRAY_A);

        return array_map(function($row) {
            return $this->repository->findById($row['id']);
        }, $results);
    }

    private function serializeProfessional($professional): array
    {
        if (is_array($professional)) {
            $professional = $this->repository->findById($professional['id']);
        }

        return [
            'id' => $professional->getId(),
            'user_id' => $professional->getUserId(),
            'full_name' => $professional->getFullName(),
            'cpf' => $professional->getCpf(),
            'phone' => $professional->getPhone(),
            'email' => $professional->getEmail(),
            'score' => $professional->getScore(),
            'total_services' => $professional->getTotalServices(),
            'completed_services' => $professional->getCompletedServices(),
            'cancelled_services' => $professional->getCancelledServices(),
            'no_show_count' => $professional->getNoShowCount(),
            'acceptance_rate' => $professional->getAcceptanceRate(),
            'on_time_count' => $professional->getOnTimeCount(),
            'late_count' => $professional->getLateCount(),
            'is_active' => $professional->isActive(),
            'is_verified' => $professional->isVerified(),
            'is_suspended' => $professional->isSuspended(),
            'is_in_good_standing' => $professional->isInGoodStanding(),
            'service_region' => $professional->getServiceRegion()->toArray(),
            'skills' => $professional->getSkills()->getSkills(),
            'certifications' => $professional->getSkills()->getCertifications(),
            'physical_limitations' => $professional->getSkills()->getLimitations(),
            'availability' => $professional->getAvailability()->toArray(),
            'max_daily_hours' => $professional->getMaxDailyHours(),
            'created_at' => $professional->getCreatedAt()->format('Y-m-d H:i:s'),
            'updated_at' => $professional->getUpdatedAt()->format('Y-m-d H:i:s'),
        ];
    }

    private function getListArgs(): array
    {
        return [
            'status' => [
                'type' => 'string',
                'enum' => ['all', 'active', 'inactive', 'suspended'],
                'default' => 'all',
            ],
            'verified' => [
                'type' => 'string',
                'enum' => ['all', 'verified', 'not_verified'],
                'default' => 'all',
            ],
            'min_score' => [
                'type' => 'number',
                'minimum' => 0,
                'maximum' => 5,
                'default' => 0,
            ],
            'search' => [
                'type' => 'string',
                'default' => '',
            ],
            'page' => [
                'type' => 'integer',
                'minimum' => 1,
                'default' => 1,
            ],
            'per_page' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'default' => 20,
            ],
        ];
    }

    private function getCreateArgs(): array
    {
        return [
            'full_name' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Nome completo'
            ],
            'cpf' => [
                'required' => true,
                'type' => 'string',
                'description' => 'CPF (com ou sem máscara)'
            ],
            'phone' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Telefone'
            ],
            'email' => [
                'required' => true,
                'type' => 'string',
                'format' => 'email',
                'description' => 'Email'
            ],
            'address' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Endereço completo'
            ],
            'skills' => [
                'required' => true,
                'type' => 'array',
                'items' => ['type' => 'string'],
                'description' => 'Skills (pelo menos 1)'
            ],
            'certifications' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'default' => []
            ],
            'physical_limitations' => [
                'type' => 'array',
                'items' => ['type' => 'string'],
                'default' => []
            ],
            'service_radius_km' => [
                'type' => 'integer',
                'minimum' => 1,
                'maximum' => 100,
                'default' => 20
            ],
            'weekly_availability' => [
                'type' => 'object',
                'description' => 'Disponibilidade semanal'
            ],
        ];
    }

    private function getUpdateArgs(): array
    {
        return [
            'id' => [
                'required' => true,
                'type' => 'integer'
            ],
            // Adicionar campos permitidos para update conforme necessário
        ];
    }
}
