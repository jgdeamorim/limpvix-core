<?php
/**
 * OfferController - REST API para Contract Offers
 *
 * RESPONSABILIDADE:
 * - Expor endpoints REST para gerenciamento de offers
 * - Validar ownership e autorização
 * - Orquestrar use cases de offers
 *
 * ENDPOINTS:
 * - POST /contracts/{id}/send-offers       - Enviar offers para profissionais (Admin/Customer)
 * - GET  /contracts/{id}/offers            - Listar offers de um contrato (Admin/Customer)
 * - GET  /offers/{id}                      - Detalhes de uma offer específica
 * - POST /offers/{id}/accept               - Aceitar offer (Professional)
 * - POST /offers/{id}/reject               - Rejeitar offer (Professional)
 * - GET  /professionals/{id}/offers        - Listar offers do profissional (Professional)
 *
 * AUTORIZAÇÃO:
 * - Admin: Acesso total
 * - Customer: Apenas seus contratos
 * - Professional: Apenas suas offers
 *
 * @package LimpVix\Infrastructure\API
 * @since 1.0.0 (GAP #3 - Sprint 8)
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use LimpVix\Application\UseCase\Briefing\SendOffers;
use LimpVix\Application\UseCase\Professional\AcceptOffer;
use LimpVix\Application\UseCase\Professional\RejectOffer;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Infrastructure\Auth\JwtAuthMiddleware;
use LimpVix\Infrastructure\Middleware\PhoneVerificationMiddleware;

defined('ABSPATH') || exit;

final class OfferController
{
    private string $namespace = 'limpvix/v1';
    private array $useCases;
    private ?ContractRepositoryInterface $contractRepository;
    private ?JwtAuthMiddleware $jwtMiddleware;

    public function __construct(
        array $useCases = [],
        ?ContractRepositoryInterface $contractRepository = null,
        ?JwtAuthMiddleware $jwtMiddleware = null
    ) {
        $this->useCases = $useCases;
        $this->contractRepository = $contractRepository ?? $GLOBALS['limpvix_contract_repository'] ?? null;
        $this->jwtMiddleware = $jwtMiddleware ?? $GLOBALS['limpvix_jwt_middleware'] ?? null;
    }

    public function register_routes(): void
    {
        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/send-offers', [
            'methods' => 'POST',
            'callback' => [$this, 'sendOffers'],
            'permission_callback' => [$this, 'canManageContract'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer'],
                'offer_count' => ['required' => false, 'type' => 'integer', 'default' => 10, 'minimum' => 1, 'maximum' => 50],
            ],
        ]);

        register_rest_route($this->namespace, '/contracts/(?P<id>\d+)/offers', [
            'methods' => 'GET',
            'callback' => [$this, 'listContractOffers'],
            'permission_callback' => [$this, 'canViewContract'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer'],
                'status' => ['required' => false, 'type' => 'string', 'enum' => ['pending', 'accepted', 'rejected', 'expired']],
            ],
        ]);

        register_rest_route($this->namespace, '/offers/(?P<id>\d+)', [
            'methods' => 'GET',
            'callback' => [$this, 'getOffer'],
            'permission_callback' => [$this, 'canViewOffer'],
            'args' => ['id' => ['required' => true, 'type' => 'integer']],
        ]);

        register_rest_route($this->namespace, '/offers/(?P<id>\d+)/accept', [
            'methods' => 'POST',
            'callback' => [$this, 'acceptOffer'],
            'permission_callback' => [$this, 'canRespondToOffer'],
            'args' => ['id' => ['required' => true, 'type' => 'integer']],
        ]);

        register_rest_route($this->namespace, '/offers/(?P<id>\d+)/reject', [
            'methods' => 'POST',
            'callback' => [$this, 'rejectOffer'],
            'permission_callback' => [$this, 'canRespondToOffer'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer'],
                'reason' => ['required' => false, 'type' => 'string'],
            ],
        ]);

        register_rest_route($this->namespace, '/professionals/(?P<id>\d+)/offers', [
            'methods' => 'GET',
            'callback' => [$this, 'listProfessionalOffers'],
            'permission_callback' => [$this, 'canViewProfessionalOffers'],
            'args' => [
                'id' => ['required' => true, 'type' => 'integer'],
                'status' => ['required' => false, 'type' => 'string', 'enum' => ['pending', 'accepted', 'rejected', 'expired']],
                'limit' => ['required' => false, 'type' => 'integer', 'default' => 20, 'minimum' => 1, 'maximum' => 100],
            ],
        ]);
    }

    public function sendOffers(WP_REST_Request $request): WP_REST_Response
    {
        $contractId = (int) $request['id'];
        $offerCount = (int) ($request['offer_count'] ?? 10);

        try {
            $sendOffersUseCase = $this->useCases['send_offers'] ?? $this->createSendOffersUseCase();

            if (!$sendOffersUseCase) {
                return new WP_REST_Response(['success' => false, 'message' => 'SendOffers use case not available'], 500);
            }

            $result = $sendOffersUseCase->execute($contractId, $offerCount);

            return new WP_REST_Response([
                'success' => true,
                'data' => [
                    'contract_id' => $contractId,
                    'offers_sent' => $result['offers_sent'],
                    'professionals_notified' => $result['offers_sent'],
                    'offers' => $result['offers'] ?? [],
                ],
                'message' => sprintf('Successfully sent %d offers to professionals', $result['offers_sent']),
            ], 200);

        } catch (\RuntimeException $e) {
            return new WP_REST_Response(['success' => false, 'message' => $e->getMessage(), 'code' => 'business_rule_violation'], 400);
        } catch (\Exception $e) {
            error_log('[OfferController] sendOffers error: ' . $e->getMessage());
            return new WP_REST_Response(['success' => false, 'message' => 'Failed to send offers', 'error' => WP_DEBUG ? $e->getMessage() : null], 500);
        }
    }

    public function listContractOffers(WP_REST_Request $request): WP_REST_Response
    {
        $contractId = (int) $request['id'];
        $statusFilter = $request['status'] ?? null;

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'limpvix_contract_offers';
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE contract_id = %d", $contractId);
            if ($statusFilter) $sql .= $wpdb->prepare(" AND status = %s", $statusFilter);
            $sql .= " ORDER BY created_at DESC";
            $offers = $wpdb->get_results($sql, ARRAY_A);

            return new WP_REST_Response(['success' => true, 'data' => ['contract_id' => $contractId, 'total' => count($offers), 'offers' => $offers]], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => 'Failed to list offers'], 500);
        }
    }

    public function getOffer(WP_REST_Request $request): WP_REST_Response
    {
        $offerId = (int) $request['id'];

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'limpvix_contract_offers';
            $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $offerId), ARRAY_A);

            if (!$offer) return new WP_REST_Response(['success' => false, 'message' => 'Offer not found'], 404);

            return new WP_REST_Response(['success' => true, 'data' => $offer], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => 'Failed to get offer'], 500);
        }
    }

    public function acceptOffer(WP_REST_Request $request): WP_REST_Response
    {
        $offerId = (int) $request['id'];

        try {
            $acceptOfferUseCase = $this->useCases['accept_offer'] ?? $this->createAcceptOfferUseCase();

            if (!$acceptOfferUseCase) {
                return new WP_REST_Response(['success' => false, 'message' => 'AcceptOffer use case not available'], 500);
            }

            $currentUser = wp_get_current_user();
            $professionalId = $this->getProfessionalIdFromUser($currentUser->ID);

            if (!$professionalId) return new WP_REST_Response(['success' => false, 'message' => 'Professional not found'], 403);

            $result = $acceptOfferUseCase->execute($offerId, $professionalId);

            if (!$result->isOk()) return new WP_REST_Response(['success' => false, 'message' => $result->error()], 400);

            return new WP_REST_Response(['success' => true, 'data' => $result->value(), 'message' => 'Offer accepted successfully'], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => 'Failed to accept offer'], 500);
        }
    }

    public function rejectOffer(WP_REST_Request $request): WP_REST_Response
    {
        $offerId = (int) $request['id'];
        $reason = $request['reason'] ?? null;

        try {
            $rejectOfferUseCase = $this->useCases['reject_offer'] ?? $this->createRejectOfferUseCase();

            if (!$rejectOfferUseCase) {
                return new WP_REST_Response(['success' => false, 'message' => 'RejectOffer use case not available'], 500);
            }

            $currentUser = wp_get_current_user();
            $professionalId = $this->getProfessionalIdFromUser($currentUser->ID);

            if (!$professionalId) return new WP_REST_Response(['success' => false, 'message' => 'Professional not found'], 403);

            $result = $rejectOfferUseCase->execute($offerId, $professionalId, $reason);

            if (!$result->isOk()) return new WP_REST_Response(['success' => false, 'message' => $result->error()], 400);

            return new WP_REST_Response(['success' => true, 'data' => $result->value(), 'message' => 'Offer rejected successfully'], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => 'Failed to reject offer'], 500);
        }
    }

    public function listProfessionalOffers(WP_REST_Request $request): WP_REST_Response
    {
        $professionalId = (int) $request['id'];
        $statusFilter = $request['status'] ?? null;
        $limit = (int) ($request['limit'] ?? 20);

        try {
            global $wpdb;
            $table = $wpdb->prefix . 'limpvix_contract_offers';
            $sql = $wpdb->prepare("SELECT * FROM {$table} WHERE professional_id = %d", $professionalId);
            if ($statusFilter) $sql .= $wpdb->prepare(" AND status = %s", $statusFilter);
            $sql .= " ORDER BY created_at DESC LIMIT " . $limit;
            $offers = $wpdb->get_results($sql, ARRAY_A);

            return new WP_REST_Response(['success' => true, 'data' => ['professional_id' => $professionalId, 'total' => count($offers), 'offers' => $offers]], 200);
        } catch (\Exception $e) {
            return new WP_REST_Response(['success' => false, 'message' => 'Failed to list professional offers'], 500);
        }
    }

    public function canManageContract(WP_REST_Request $request): bool|\WP_Error
    {
        // 🔒 SPRINT 9: Phone verification required to send offers
        $middleware = new PhoneVerificationMiddleware();
        $phoneCheck = $middleware->checkPhoneVerified();
        if (is_wp_error($phoneCheck)) {
            return $phoneCheck;
        }

        if (current_user_can('manage_options')) return true;

        $contractId = (int) $request['id'];
        $currentUserId = get_current_user_id();

        if ($this->contractRepository) {
            $contract = $this->contractRepository->findById($contractId);
            if ($contract && $contract->getClientUserId() === $currentUserId) return true;
        }

        return false;
    }

    public function canViewContract(WP_REST_Request $request): bool
    {
        return $this->canManageContract($request);
    }

    public function canViewOffer(WP_REST_Request $request): bool
    {
        if (current_user_can('manage_options')) return true;

        $offerId = (int) $request['id'];
        $currentUserId = get_current_user_id();

        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contract_offers';
        $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $offerId), ARRAY_A);

        if (!$offer) return false;

        $professionalId = $this->getProfessionalIdFromUser($currentUserId);
        if ($professionalId && $offer['professional_id'] == $professionalId) return true;

        if ($this->contractRepository) {
            $contract = $this->contractRepository->findById($offer['contract_id']);
            if ($contract && $contract->getClientUserId() === $currentUserId) return true;
        }

        return false;
    }

    public function canRespondToOffer(WP_REST_Request $request): bool|\WP_Error
    {
        // 🔒 SPRINT 9: Phone verification required to accept/reject offers
        $middleware = new PhoneVerificationMiddleware();
        $phoneCheck = $middleware->checkPhoneVerified();
        if (is_wp_error($phoneCheck)) {
            return $phoneCheck;
        }

        $offerId = (int) $request['id'];
        $currentUserId = get_current_user_id();

        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contract_offers';
        $offer = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", $offerId), ARRAY_A);

        if (!$offer) return false;

        $professionalId = $this->getProfessionalIdFromUser($currentUserId);
        return $professionalId && $offer['professional_id'] == $professionalId;
    }

    public function canViewProfessionalOffers(WP_REST_Request $request): bool
    {
        if (current_user_can('manage_options')) return true;

        $professionalId = (int) $request['id'];
        $currentUserId = get_current_user_id();

        $userProfessionalId = $this->getProfessionalIdFromUser($currentUserId);
        return $userProfessionalId && $userProfessionalId === $professionalId;
    }

    private function getProfessionalIdFromUser(int $userId): ?int
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_professionals';
        $professionalId = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$table} WHERE user_id = %d AND status = 'active' LIMIT 1", $userId));
        return $professionalId ? (int) $professionalId : null;
    }

    private function createSendOffersUseCase(): ?SendOffers
    {
        $contractRepo = $GLOBALS['limpvix_contract_repository'] ?? null;
        $professionalRepo = $GLOBALS['limpvix_professional_repository'] ?? null;

        if ($contractRepo && $professionalRepo) {
            return new SendOffers($contractRepo, $professionalRepo);
        }

        return null;
    }

    private function createAcceptOfferUseCase(): ?AcceptOffer
    {
        $professionalRepo = $GLOBALS['limpvix_professional_repository'] ?? null;
        $contractRepo = $GLOBALS['limpvix_contract_repository'] ?? null;

        if ($professionalRepo && $contractRepo) {
            return new AcceptOffer($professionalRepo, $contractRepo);
        }

        return null;
    }

    private function createRejectOfferUseCase(): ?RejectOffer
    {
        $professionalRepo = $GLOBALS['limpvix_professional_repository'] ?? null;

        if ($professionalRepo) {
            return new RejectOffer($professionalRepo);
        }

        return null;
    }
}
