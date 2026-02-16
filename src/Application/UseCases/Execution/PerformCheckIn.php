<?php
declare(strict_types=1);

/**
 * PerformCheckIn - Use Case para realizar check-in (Sprint 1 - Dia 6 + GAP #1)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Validar EPI obrigatório (se professional.requiresEpi())
 * - Orquestrar Execution::checkIn()
 * - Persistir mudanças
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - SEM lógica de negócio (delega para Execution)
 * - SEM cálculo Haversine aqui
 * - Validação EPI no Application Layer (cross-aggregate check)
 *
 * @package LimpVix\Application\UseCases\Execution
 * @since 0.12.0 (GAP #1 - EPI selfie validation)
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\TimeWindow;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;

defined('ABSPATH') || exit;

class PerformCheckIn
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository,
        private ProfessionalRepositoryInterface $professionalRepository
    ) {}

    /**
     * Executar Use Case
     *
     * FLUXO:
     * 1. Buscar Execution por UUID
     * 2. Buscar Professional para validar EPI (GAP #1)
     * 3. Validar EPI obrigatório se professional.requiresEpi()
     * 4. Executar checkIn() (valida geo + time window internamente)
     * 5. Persistir Execution
     * 6. Retornar Result com status + SLA violations
     *
     * @param string $executionUuid UUID da Execution
     * @param GeoLocation $currentLocation Localização atual do profissional
     * @param \DateTimeImmutable $now Timestamp do check-in
     * @param EvidenceCollection|null $epiEvidences Evidências de EPI (vídeo selfie)
     * @return Result<array, string>
     */
    public function execute(
        string $executionUuid,
        GeoLocation $currentLocation,
        \DateTimeImmutable $now,
        ?EvidenceCollection $epiEvidences = null
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

            // 2. Buscar Professional para validar EPI (GAP #1)
            $professional = $this->professionalRepository->findById(
                $execution->getProfessionalId()
            );

            if ($professional === null) {
                return Result::fail(sprintf(
                    'Professional not found: ID %d',
                    $execution->getProfessionalId()
                ));
            }

            // 3. Validar EPI obrigatório (GAP #1)
            if ($professional->requiresEpi()) {
                // Professional requer EPI - deve ter pelo menos 1 evidência EPI check-in
                if ($epiEvidences === null || !$epiEvidences->hasEpiCheckInEvidences()) {
                    return Result::fail(
                        'EPI video selfie is required for this professional. ' .
                        'Please provide at least one EPI check-in evidence (category: epi_checkin, type: video).'
                    );
                }

                // Validar que pelo menos 1 evidência é vídeo (selfie de EPI)
                $epiCheckInEvidences = $epiEvidences->epiCheckInEvidences();
                $hasVideo = false;
                foreach ($epiCheckInEvidences as $evidence) {
                    if ($evidence->isVideo()) {
                        $hasVideo = true;
                        break;
                    }
                }

                if (!$hasVideo) {
                    return Result::fail(
                        'EPI selfie must be a video (type: video, category: epi_checkin)'
                    );
                }
            }

            // 4. Criar TimeWindow se necessário
            $timeWindow = null;
            if ($execution->getScheduledStartTime() !== null) {
                $timeWindow = new TimeWindow($execution->getScheduledStartTime());
            }

            // 5. Executar check-in (Execution valida geo + time window)
            $execution->checkIn($currentLocation, $timeWindow);

            // 6. Persistir mudanças
            $this->executionRepository->save($execution);

            // 7. Retornar sucesso
            return Result::ok([
                'execution_uuid' => $execution->getExecutionUuid(),
                'order_uuid' => $execution->getOrderUuid(),
                'status' => $execution->getStatus()->value,
                'check_in_at' => $execution->getCheckInAt()?->format('Y-m-d H:i:s'),
                'check_in_location' => [
                    'latitude' => $currentLocation->latitude,
                    'longitude' => $currentLocation->longitude,
                ],
                'sla_violations' => $execution->getSlaViolations(),
                'has_sla_violations' => $execution->hasSlaViolations(),
                'epi_required' => $professional->requiresEpi(),
                'epi_validated' => $professional->requiresEpi() && $epiEvidences !== null,
            ]);

        } catch (InvalidExecutionTransitionException $e) {
            return Result::fail(sprintf(
                'Cannot perform check-in: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error during check-in: %s',
                $e->getMessage()
            ));
        }
    }
}
