<?php
declare(strict_types=1);

/**
 * PerformCheckIn - Use Case para realizar check-in (Sprint 1 - Dia 6)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Orquestrar Execution::checkIn()
 * - Persistir mudanças
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - SEM lógica de negócio (delega para Execution)
 * - SEM cálculo Haversine aqui
 * - SEM validações aqui (Execution valida)
 *
 * @package LimpVix\Application\UseCases\Execution
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\TimeWindow;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;

defined('ABSPATH') || exit;

class PerformCheckIn
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository
    ) {}

    /**
     * Executar Use Case
     *
     * FLUXO:
     * 1. Buscar Execution por UUID
     * 2. Executar checkIn() (valida geo + time window internamente)
     * 3. Persistir Execution
     * 4. Retornar Result com status + SLA violations
     *
     * @param string $executionUuid UUID da Execution
     * @param GeoLocation $currentLocation Localização atual do profissional
     * @param \DateTimeImmutable $now Timestamp do check-in
     * @return Result<array, string>
     */
    public function execute(
        string $executionUuid,
        GeoLocation $currentLocation,
        \DateTimeImmutable $now
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

            // 2. Criar TimeWindow se necessário
            $timeWindow = null;
            if ($execution->getScheduledStartTime() !== null) {
                $timeWindow = new TimeWindow($execution->getScheduledStartTime());
            }

            // 3. Executar check-in (Execution valida geo + time window)
            $execution->checkIn($currentLocation, $timeWindow);

            // 4. Persistir mudanças
            $this->executionRepository->save($execution);

            // 5. Retornar sucesso
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
