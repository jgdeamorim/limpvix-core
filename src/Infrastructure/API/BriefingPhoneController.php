<?php
/**
 * BriefingPhoneController - REST API Controller
 *
 * RESPONSABILIDADE:
 * - Endpoint POST /briefing/{uuid}/verify-phone
 * - Validar Firebase ID Token (OTP SMS)
 * - Orquestrar VerifyBriefingPhone use case
 * - Transicionar: PENDING_PHONE_VERIFICATION → AWAITING_PAYMENT
 *
 * ENDPOINT:
 * POST /wp-json/limpvix/v1/briefing/{uuid}/verify-phone
 *
 * BODY:
 * {
 *   "id_token": "eyJhbGciOiJSUzI1NiIsImtpZCI6IjAxMDY..." (JWT do Firebase)
 * }
 *
 * FLUXO:
 * 1. Frontend autentica via Firebase (SMS OTP)
 * 2. Frontend envia ID Token para este endpoint
 * 3. Validar token via FirebaseAuthAdapter
 * 4. Marcar phone_verified = true
 * 5. Transicionar estado
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Briefing\VerifyBriefingPhone;
use LimpVix\Domain\Briefing\BriefingRepositoryInterface;

defined('ABSPATH') || exit;

class BriefingPhoneController
{
    /**
     * @var VerifyBriefingPhone
     */
    private $verifyBriefingPhoneUseCase;

    /**
     * @var BriefingRepositoryInterface
     */
    private $briefingRepository;

    /**
     * Construtor
     *
     * @param VerifyBriefingPhone $verifyBriefingPhoneUseCase
     * @param BriefingRepositoryInterface $briefingRepository
     */
    public function __construct(
        VerifyBriefingPhone $verifyBriefingPhoneUseCase,
        BriefingRepositoryInterface $briefingRepository
    ) {
        $this->verifyBriefingPhoneUseCase = $verifyBriefingPhoneUseCase;
        $this->briefingRepository = $briefingRepository;
    }

    /**
     * Registrar rotas
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route('limpvix/v1', '/briefing/(?P<uuid>[a-f0-9\-]{36})/verify-phone', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'verifyPhone'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getVerifyArgs()
        ]);
    }

    /**
     * POST /wp-json/limpvix/v1/briefing/{uuid}/verify-phone
     *
     * Verificar telefone via Firebase ID Token.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function verifyPhone(\WP_REST_Request $request): \WP_REST_Response
    {
        try {
            // 1. Obter UUID
            $uuid = $request->get_param('uuid');

            // 2. Verificar se Briefing existe
            $briefing = $this->briefingRepository->findByUuid($uuid);

            if ($briefing === null) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Briefing não encontrado'
                ], 404);
            }

            // 3. Verificar permissão (user_id deve coincidir)
            $currentUserId = get_current_user_id();

            if ($briefing->getUserId() !== $currentUserId && !current_user_can('manage_options')) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Acesso negado a este Briefing'
                ], 403);
            }

            // 4. Verificar se já está verificado
            if ($briefing->isPhoneVerified()) {
                return new \WP_REST_Response([
                    'success' => true,
                    'data' => [
                        'already_verified' => true,
                        'message' => 'Telefone já verificado anteriormente'
                    ]
                ], 200);
            }

            // 5. Obter ID Token do Firebase
            $idToken = $request->get_param('id_token');

            if (empty($idToken)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'id_token é obrigatório'
                ], 400);
            }

            // 6. Executar use case (valida Firebase Token internamente)
            $result = $this->verifyBriefingPhoneUseCase->execute($uuid, $idToken);

            if (!$result->isSuccess()) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $result->getErrorMessage()
                ], 400);
            }

            // 7. Serializar Briefing atualizado
            $updatedBriefing = $result->getBriefing();
            $data = $this->serializeBriefing($updatedBriefing);

            // 8. Retornar sucesso
            return new \WP_REST_Response([
                'success' => true,
                'data' => $data,
                'message' => 'Telefone verificado com sucesso',
                'metadata' => [
                    'phone_verified' => true,
                    'status_transitioned' => $result->getMetadata()['status_transitioned'] ?? false,
                    'new_status' => $updatedBriefing->getStatus()->getValue()
                ]
            ], 200);

        } catch (\InvalidArgumentException $e) {
            // Erro de validação do Firebase token
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Token Firebase inválido: ' . $e->getMessage()
            ], 400);

        } catch (\DomainException $e) {
            // Violação de regra de negócio
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Regra de negócio violada: ' . $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            // Erro inesperado
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao verificar telefone: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Serializar Briefing para resposta REST
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
            'created_at' => $briefing->getCreatedAt()->format('c'),
            'updated_at' => $briefing->getUpdatedAt()->format('c')
        ];

        // Structure
        if ($briefing->getStructure() !== null) {
            $data['structure'] = $briefing->getStructure()->toArray();
        }

        // Frequency
        if ($briefing->getFrequency() !== null) {
            $data['frequency'] = $briefing->getFrequency()->toArray();
        }

        // Metrics
        if ($briefing->getMetrics() !== null) {
            $data['metrics'] = [
                'm2' => $briefing->getMetrics()->getM2(),
                'duration_minutes' => $briefing->getMetrics()->getDurationMinutes(),
                'buffer_minutes' => $briefing->getMetrics()->getBufferMinutes(),
                'total_minutes' => $briefing->getMetrics()->getTotalMinutes()
            ];
        }

        // Locked at
        if ($briefing->getLockedAt() !== null) {
            $data['locked_at'] = $briefing->getLockedAt()->format('c');
        }

        return $data;
    }

    /**
     * Verificar permissão
     *
     * @param \WP_REST_Request $request
     * @return bool
     */
    public function checkPermission(\WP_REST_Request $request): bool
    {
        // Usuário deve estar autenticado
        // Validação fina (user_id) é feita no método verifyPhone()
        return is_user_logged_in();
    }

    /**
     * Schema dos argumentos
     *
     * @return array
     */
    private function getVerifyArgs(): array
    {
        return [
            'uuid' => [
                'required' => true,
                'type' => 'string',
                'format' => 'uuid',
                'description' => 'UUID do Briefing'
            ],
            'id_token' => [
                'required' => true,
                'type' => 'string',
                'description' => 'Firebase ID Token (JWT) obtido após autenticação SMS',
                'validate_callback' => function ($param) {
                    return is_string($param) && !empty($param) && strlen($param) > 100;
                }
            ]
        ];
    }
}
