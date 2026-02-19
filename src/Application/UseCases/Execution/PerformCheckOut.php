<?php
declare(strict_types=1);

/**
 * PerformCheckOut - Use Case para realizar check-out + validação (Sprint 1 + P0.1)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Orquestrar Execution::checkOut() (que agora inclui validação)
 * - Persistir mudanças
 * - Retornar Result com dados de validação
 *
 * P0.1: Absorveu a lógica do antigo ValidateExecution.
 * Check-out com evidência = validação do serviço.
 * Feedback window é iniciada automaticamente pelo aggregate.
 *
 * @package LimpVix\Application\UseCases\Execution
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;

defined('ABSPATH') || exit;

class PerformCheckOut
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository
    ) {}

    /**
     * Executar Use Case
     *
     * P0.1: Check-out agora inclui validação (absorveu ValidateExecution).
     *
     * FLUXO:
     * 1. Buscar Execution por UUID
     * 2. Executar checkOut() (valida check-in + evidence + inicia feedback window)
     * 3. Persistir Execution
     * 4. Retornar Result com status + evidências + validação + duração
     *
     * @param string $executionUuid UUID da Execution
     * @param GeoLocation $currentLocation Localização atual do profissional
     * @param EvidenceCollection $evidence Evidências do serviço (obrigatório)
     * @return Result<array, string>
     */
    public function execute(
        string $executionUuid,
        GeoLocation $currentLocation,
        EvidenceCollection $evidence
    ): Result {
        try {
            // 1. Buscar Execution
            $execution = $this->executionRepository->findByUuid($executionUuid);

            if ($execution === null) {
                return Result::fail(sprintf(
                    'Execution not found: %s',
                    $executionUuid
                ));
            }

            // 2. Executar check-out + validação (P0.1: checkout = validation)
            // Execution::checkOut() agora valida evidência e inicia feedback window
            $execution->checkOut($currentLocation, $evidence);

            // 3. Persistir mudanças
            $this->executionRepository->save($execution);

            // 4. Retornar sucesso com dados de validação (absorvido do ValidateExecution)
            return Result::ok([
                'execution_uuid' => $execution->getExecutionUuid(),
                'order_uuid' => $execution->getOrderUuid(),
                'status' => $execution->getStatus()->value,
                'is_service_completed' => $execution->isServiceCompleted(),
                'check_out_at' => $execution->getCheckOutAt()?->format('Y-m-d H:i:s'),
                'check_out_location' => [
                    'latitude' => $currentLocation->latitude,
                    'longitude' => $currentLocation->longitude,
                ],
                'evidence_count' => $evidence->count(),
                'has_photos' => $evidence->hasPhotos(),
                'has_videos' => $evidence->hasVideos(),
                'has_evidence' => true,
                'duration_minutes' => $execution->getDurationMinutes(),
                'sla_violations' => $execution->getSlaViolations(),
                'has_sla_violations' => $execution->hasSlaViolations(),
                'feedback_window_expires_at' => $execution->getFeedbackWindowExpiresAt()?->format('Y-m-d H:i:s'),
            ]);

        } catch (InvalidExecutionTransitionException $e) {
            return Result::fail(sprintf(
                'Cannot perform check-out: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error during check-out: %s',
                $e->getMessage()
            ));
        }
    }
}
