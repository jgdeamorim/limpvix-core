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

defined('ABSPATH') || exit;

class ProfessionalController
{
    private $repository;

    public function __construct()
    {
        $this->repository = new WpMarketplaceProfessionalRepository();
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
    public function create(\WP_REST_Request $request): \WP_REST_Response
    {
        $data = [
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
        ];

        $useCase = new RegisterProfessional($this->repository);
        $result = $useCase->execute($data);

        if (is_wp_error($result)) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $result->get_error_message(),
                'code' => $result->get_error_code()
            ], 400);
        }

        return new \WP_REST_Response([
            'success' => true,
            'data' => $result['professional'],
            'message' => 'Profissional registrado com sucesso'
        ], 201);
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
    public function listOffers(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;
        $id = (int) $request->get_param('id');

        $professional = $this->repository->findById($id);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        $table = $wpdb->prefix . 'limpvix_contract_offers';
        $offers = $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table} WHERE professional_id = %d ORDER BY offered_at DESC LIMIT 50",
            $id
        ), ARRAY_A);

        return new \WP_REST_Response([
            'success' => true,
            'data' => $offers
        ], 200);
    }

    /**
     * POST /wp-json/limpvix/v1/professionals/{id}/offers/{offer_id}/accept
     *
     * Aceitar oferta (first-to-accept)
     */
    public function acceptOffer(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;
        $professionalId = (int) $request->get_param('id');
        $offerId = (int) $request->get_param('offer_id');

        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        $table = $wpdb->prefix . 'limpvix_contract_offers';

        // Buscar oferta
        $offer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND professional_id = %d",
            $offerId, $professionalId
        ), ARRAY_A);

        if (!$offer) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Oferta não encontrada'
            ], 404);
        }

        // Validar status
        if ($offer['status'] !== 'pending') {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Oferta não está mais disponível (status: ' . $offer['status'] . ')'
            ], 400);
        }

        // Validar expiração
        if (strtotime($offer['expires_at']) < time()) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Oferta expirada'
            ], 400);
        }

        // Transação: aceitar oferta + rejeitar demais
        $wpdb->query('START TRANSACTION');

        try {
            // Atualizar oferta para accepted
            $wpdb->update(
                $table,
                [
                    'status' => 'accepted',
                    'responded_at' => current_time('mysql')
                ],
                ['id' => $offerId],
                ['%s', '%s'],
                ['%d']
            );

            // Expirar outras ofertas do mesmo contrato
            $wpdb->update(
                $table,
                ['status' => 'expired'],
                [
                    'contract_id' => $offer['contract_id'],
                    'status' => 'pending'
                ],
                ['%s'],
                ['%d', '%s']
            );

            // Alocar profissional ao contrato
            $contractsTable = $wpdb->prefix . 'limpvix_contracts';
            $wpdb->update(
                $contractsTable,
                [
                    'allocated_professional_id' => $professionalId,
                    'allocation_status' => 'allocated',
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $offer['contract_id']],
                ['%d', '%s', '%s'],
                ['%d']
            );

            $wpdb->query('COMMIT');

            // Disparar evento
            do_action('limpvix_offer_accepted', [
                'offer_id' => $offerId,
                'professional_id' => $professionalId,
                'contract_id' => $offer['contract_id'],
            ]);

            // Atualizar last_activity
            $this->repository->updateLastActivity($professionalId);

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Oferta aceita! Contrato alocado para você.',
                'data' => [
                    'offer_id' => $offerId,
                    'contract_id' => $offer['contract_id'],
                ]
            ], 200);

        } catch (\Exception $e) {
            $wpdb->query('ROLLBACK');

            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao aceitar oferta: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * POST /wp-json/limpvix/v1/professionals/{id}/offers/{offer_id}/reject
     *
     * Rejeitar oferta
     */
    public function rejectOffer(\WP_REST_Request $request): \WP_REST_Response
    {
        global $wpdb;
        $professionalId = (int) $request->get_param('id');
        $offerId = (int) $request->get_param('offer_id');
        $reason = $request->get_param('reason') ?: 'not_interested';
        $notes = $request->get_param('notes') ?: '';

        $professional = $this->repository->findById($professionalId);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        $table = $wpdb->prefix . 'limpvix_contract_offers';

        // Buscar oferta
        $offer = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE id = %d AND professional_id = %d",
            $offerId, $professionalId
        ), ARRAY_A);

        if (!$offer) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Oferta não encontrada'
            ], 404);
        }

        // Validar status
        if ($offer['status'] !== 'pending') {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Oferta não pode ser rejeitada (status: ' . $offer['status'] . ')'
            ], 400);
        }

        // Atualizar oferta para rejected
        $wpdb->update(
            $table,
            [
                'status' => 'rejected',
                'responded_at' => current_time('mysql'),
                'rejection_reason' => $reason,
                'rejection_notes' => $notes,
            ],
            ['id' => $offerId],
            ['%s', '%s', '%s', '%s'],
            ['%d']
        );

        // Disparar evento
        do_action('limpvix_offer_rejected', [
            'offer_id' => $offerId,
            'professional_id' => $professionalId,
            'contract_id' => $offer['contract_id'],
            'reason' => $reason,
        ]);

        // Atualizar last_activity
        $this->repository->updateLastActivity($professionalId);

        return new \WP_REST_Response([
            'success' => true,
            'message' => 'Oferta rejeitada'
        ], 200);
    }

    /**
     * PATCH /wp-json/limpvix/v1/professionals/{id}/availability
     *
     * Atualizar disponibilidade
     */
    public function updateAvailability(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $availabilityData = $request->get_param('availability');

        $professional = $this->repository->findById($id);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        try {
            $availability = \LimpVix\Domain\Professional\ValueObjects\WeeklyAvailability::fromJson(
                json_encode($availabilityData)
            );

            $professional->updateAvailability($availability);
            $this->repository->save($professional);

            return new \WP_REST_Response([
                'success' => true,
                'message' => 'Disponibilidade atualizada',
                'data' => [
                    'availability' => $availability->toArray()
                ]
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao atualizar disponibilidade: ' . $e->getMessage()
            ], 400);
        }
    }

    /**
     * GET /wp-json/limpvix/v1/professionals/{id}/score-history
     *
     * Histórico de score
     */
    public function getScoreHistory(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');
        $limit = $request->get_param('limit') ?: 50;

        $professional = $this->repository->findById($id);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        $history = $this->repository->getScoreHistory($id, $limit);

        return new \WP_REST_Response([
            'success' => true,
            'data' => $history
        ], 200);
    }

    /**
     * GET /wp-json/limpvix/v1/professionals/{id}/allocations
     *
     * Histórico de alocações
     */
    public function getAllocations(\WP_REST_Request $request): \WP_REST_Response
    {
        $id = (int) $request->get_param('id');

        $professional = $this->repository->findById($id);
        if (!$professional) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Profissional não encontrado'
            ], 404);
        }

        $allocations = $this->repository->getAllocationHistory($id);

        return new \WP_REST_Response([
            'success' => true,
            'data' => $allocations
        ], 200);
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
