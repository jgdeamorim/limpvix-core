<?php
declare(strict_types=1);

/**
 * PerformCheckIn - Use Case para realizar check-in (Sprint 1 + P0.2)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Validar EPI obrigatório (se professional.requiresEpi())
 * - Validar fotos de cômodos (P0.2: obrigatório para qualquer serviço)
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
 * @since 0.12.0 (GAP #1 - EPI selfie validation), P0.2 (room evidence)
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
     * 4. Validar fotos de cômodos (P0.2 - WARN, não bloqueia ainda)
     * 5. Executar checkIn() (valida geo + time window internamente)
     * 6. Persistir Execution
     * 7. Retornar Result com status + SLA violations + room warnings
     *
     * @param string $executionUuid UUID da Execution
     * @param GeoLocation $currentLocation Localização atual do profissional
     * @param \DateTimeImmutable $now Timestamp do check-in
     * @param EvidenceCollection|null $epiEvidences Evidências de EPI (vídeo selfie)
     * @param EvidenceCollection|null $roomEvidence Fotos dos cômodos antes do serviço (P0.2)
     * @return Result<array, string>
     */
    public function execute(
        string $executionUuid,
        GeoLocation $currentLocation,
        \DateTimeImmutable $now,
        ?EvidenceCollection $epiEvidences = null,
        ?EvidenceCollection $roomEvidence = null
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

            // 4. Validar fotos de cômodos (S2-ROOM-PHOTOS: BLOCK when rooms > 0)
            $roomWarnings = [];
            $expectedRooms = $execution->getExpectedRoomsCount();
            $enforceRoomPhotos = (bool) get_option('limpvix_enforce_room_photos', true);

            if ($roomEvidence === null || !$roomEvidence->hasRoomEvidences()) {
                if ($enforceRoomPhotos && $expectedRooms > 0) {
                    return Result::fail(sprintf(
                        'Room photos are required at check-in. Expected %d room(s) but none were provided. ' .
                        'Please upload at least one photo per room before starting the service.',
                        $expectedRooms
                    ));
                }
                $roomWarnings[] = 'Room photos (before service) recommended but none were provided.';
            } else {
                $roomCount = $roomEvidence->countUniqueRooms('check_in');
                if ($enforceRoomPhotos && $expectedRooms > 0 && $roomCount < $expectedRooms) {
                    return Result::fail(sprintf(
                        'Insufficient room photos at check-in: %d/%d rooms documented. ' .
                        'Please upload photos of all %d rooms.',
                        $roomCount, $expectedRooms, $expectedRooms
                    ));
                }
                if ($roomCount < 1) {
                    $roomWarnings[] = 'Room photos must have stage=check_in and a valid roomType.';
                }
            }

            // 5. Criar TimeWindow se necessário
            $timeWindow = null;
            if ($execution->getScheduledStartTime() !== null) {
                $timeWindow = new TimeWindow($execution->getScheduledStartTime());
            }

            // 6. Executar check-in (Execution valida geo + time window)
            $execution->checkIn($currentLocation, $timeWindow);

            // 7. Persistir mudanças
            $this->executionRepository->save($execution);

            // 8. Retornar sucesso
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
                'room_evidence_count' => $roomEvidence?->countUniqueRooms('check_in') ?? 0,
                'room_warnings' => $roomWarnings,
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
