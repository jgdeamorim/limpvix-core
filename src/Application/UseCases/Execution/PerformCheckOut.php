<?php
declare(strict_types=1);

/**
 * PerformCheckOut - Use Case para realizar check-out (Sprint 1 - Dia 6)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Orquestrar Execution::checkOut()
 * - Persistir mudanças
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - SEM lógica de negócio (delega para Execution)
 * - Execution valida se check-in foi feito
 * - Evidence é obrigatório
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
     * FLUXO:
     * 1. Buscar Execution por UUID
     * 2. Executar checkOut() (valida check-in + evidence)
     * 3. Persistir Execution
     * 4. Retornar Result com status + evidências + duração
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

            // 2. Executar check-out (Execution valida check-in + evidence)
            $execution->checkOut($currentLocation, $evidence);

            // 3. Persistir mudanças
            $this->executionRepository->save($execution);

            // 4. Retornar sucesso
            return Result::ok([
                'execution_uuid' => $execution->getExecutionUuid(),
                'order_uuid' => $execution->getOrderUuid(),
                'status' => $execution->getStatus()->value,
                'check_out_at' => $execution->getCheckOutAt()?->format('Y-m-d H:i:s'),
                'check_out_location' => [
                    'latitude' => $currentLocation->latitude,
                    'longitude' => $currentLocation->longitude,
                ],
                'evidence_count' => $evidence->count(),
                'has_photos' => $evidence->hasPhotos(),
                'has_videos' => $evidence->hasVideos(),
                'duration_minutes' => $execution->getDurationMinutes(),
                'sla_violations' => $execution->getSlaViolations(),
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
