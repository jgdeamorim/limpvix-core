<?php
/**
 * BriefingStepController - REST API Controller
 *
 * RESPONSABILIDADE:
 * - Endpoint POST /briefing/{uuid}/step
 * - Atualizar step individual do Briefing
 * - Orquestrar UpdateBriefingStep use case
 * - Transicionar estado automaticamente quando aplicável
 *
 * ENDPOINT:
 * POST /wp-json/limpvix/v1/briefing/{uuid}/step
 *
 * BODY:
 * {
 *   "step_name": "structure",
 *   "step_data": {
 *     "bedrooms": 3,
 *     "bathrooms": 2,
 *     "has_living_room": true,
 *     ...
 *   }
 * }
 *
 * @package LimpVix\Infrastructure\API
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\API;

use LimpVix\Application\UseCases\Briefing\UpdateBriefingStep;
use LimpVix\Domain\Briefing\BriefingRepositoryInterface;

defined('ABSPATH') || exit;

class BriefingStepController
{
    /**
     * @var UpdateBriefingStep
     */
    private $updateBriefingStepUseCase;

    /**
     * @var BriefingRepositoryInterface
     */
    private $briefingRepository;

    /**
     * @var array Steps válidos
     */
    private const VALID_STEPS = [
        'property_type',
        'cleaning_types',
        'structure',
        'frequency',
        'contract',
        'datetime',
        'location',
        'phone_verification',
        'checkout'
    ];

    /**
     * Construtor
     *
     * @param UpdateBriefingStep $updateBriefingStepUseCase
     * @param BriefingRepositoryInterface $briefingRepository
     */
    public function __construct(
        UpdateBriefingStep $updateBriefingStepUseCase,
        BriefingRepositoryInterface $briefingRepository
    ) {
        $this->updateBriefingStepUseCase = $updateBriefingStepUseCase;
        $this->briefingRepository = $briefingRepository;
    }

    /**
     * Registrar rotas
     *
     * @return void
     */
    public function register(): void
    {
        register_rest_route('limpvix/v1', '/briefing/(?P<uuid>[a-f0-9\-]{36})/step', [
            'methods' => \WP_REST_Server::CREATABLE,
            'callback' => [$this, 'updateStep'],
            'permission_callback' => [$this, 'checkPermission'],
            'args' => $this->getStepArgs()
        ]);
    }

    /**
     * POST /wp-json/limpvix/v1/briefing/{uuid}/step
     *
     * Atualizar step individual.
     *
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function updateStep(\WP_REST_Request $request): \WP_REST_Response
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

            // 4. Verificar se está locked
            if ($briefing->isLocked()) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Briefing locked não pode ser editado'
                ], 400);
            }

            // 5. Obter step_name e step_data
            $stepName = $request->get_param('step_name');
            $stepData = $request->get_param('step_data');

            // 6. Validar step_name
            if (!in_array($stepName, self::VALID_STEPS, true)) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'step_name inválido. Valores aceitos: ' . implode(', ', self::VALID_STEPS)
                ], 400);
            }

            // 7. Validar step_data
            $validationError = $this->validateStepData($stepName, $stepData);
            if ($validationError !== null) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $validationError
                ], 400);
            }

            // 8. Executar use case
            $result = $this->updateBriefingStepUseCase->execute($uuid, $stepName, $stepData);

            if (!$result->isSuccess()) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => $result->getErrorMessage()
                ], 400);
            }

            // 9. Serializar Briefing atualizado
            $updatedBriefing = $result->getBriefing();
            $data = $this->serializeBriefing($updatedBriefing);

            // 10. Retornar sucesso
            return new \WP_REST_Response([
                'success' => true,
                'data' => $data,
                'message' => "Step '{$stepName}' atualizado com sucesso",
                'metadata' => $result->getMetadata()
            ], 200);

        } catch (\DomainException $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Regra de negócio violada: ' . $e->getMessage()
            ], 400);

        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => 'Erro ao atualizar step: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Validar step_data baseado no step_name
     *
     * @param string $stepName
     * @param array $stepData
     * @return string|null Mensagem de erro ou null se válido
     */
    private function validateStepData(string $stepName, array $stepData): ?string
    {
        switch ($stepName) {
            case 'property_type':
                if (!isset($stepData['property_type']) || !in_array($stepData['property_type'], ['residential', 'commercial'], true)) {
                    return 'step_data.property_type deve ser "residential" ou "commercial"';
                }
                break;

            case 'cleaning_types':
                if (!isset($stepData['cleaning_types']) || !is_array($stepData['cleaning_types']) || empty($stepData['cleaning_types'])) {
                    return 'step_data.cleaning_types deve ser array não vazio';
                }
                break;

            case 'structure':
                $required = ['bathrooms'];
                foreach ($required as $field) {
                    if (!isset($stepData[$field])) {
                        return "step_data.{$field} é obrigatório";
                    }
                }
                break;

            case 'frequency':
                if (!isset($stepData['type']) || !in_array($stepData['type'], ['avulso', 'weekly', 'monthly'], true)) {
                    return 'step_data.type deve ser "avulso", "weekly" ou "monthly"';
                }
                break;

            case 'datetime':
                if (!isset($stepData['date']) || !isset($stepData['arrival_window'])) {
                    return 'step_data.date e step_data.arrival_window são obrigatórios';
                }
                break;

            case 'location':
                $required = ['address', 'city', 'state', 'zip_code'];
                foreach ($required as $field) {
                    if (!isset($stepData[$field]) || empty($stepData[$field])) {
                        return "step_data.{$field} é obrigatório";
                    }
                }
                break;

            default:
                // Steps sem validação específica
                break;
        }

        return null;
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
        // Validação fina (user_id) é feita no método updateStep()
        return is_user_logged_in();
    }

    /**
     * Schema dos argumentos
     *
     * @return array
     */
    private function getStepArgs(): array
    {
        return [
            'uuid' => [
                'required' => true,
                'type' => 'string',
                'format' => 'uuid',
                'description' => 'UUID do Briefing'
            ],
            'step_name' => [
                'required' => true,
                'type' => 'string',
                'enum' => self::VALID_STEPS,
                'description' => 'Nome do step a atualizar'
            ],
            'step_data' => [
                'required' => true,
                'type' => 'object',
                'description' => 'Dados do step (schema varia por step_name)'
            ]
        ];
    }
}
