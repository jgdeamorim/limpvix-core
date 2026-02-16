<?php
declare(strict_types=1);

/**
 * ValidateExecution - Use Case para validar execução (Sprint 1 - Dia 6)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution via Repository
 * - Orquestrar Execution::validate()
 * - Persistir mudanças
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - SEM lógica de negócio (delega para Execution)
 * - Execution valida se evidence existe
 * - Transição para estado VALIDATED
 *
 * @package LimpVix\Application\UseCases\Execution
 */

namespace LimpVix\Application\UseCases\Execution;

use LimpVix\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;

defined('ABSPATH') || exit;

class ValidateExecution
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository
    ) {}

    /**
     * Executar Use Case
     *
     * FLUXO:
     * 1. Buscar Execution por UUID
     * 2. Executar validate() (valida evidence + estado)
     * 3. Persistir Execution
     * 4. Retornar Result com status final
     *
     * REGRA CRÍTICA:
     * - Execution DEVE estar CHECKED_OUT
     * - Evidence DEVE existir
     * - Após validate(), Execution → VALIDATED (ready for payout)
     *
     * @param string $executionUuid UUID da Execution
     * @return Result<array, string>
     */
    public function execute(string $executionUuid): Result
    {
        try {
            // 1. Buscar Execution
            $execution = $this->executionRepository->findByUuid($executionUuid);

            if ($execution === null) {
                return Result::fail(sprintf(
                    'Execution not found: %s',
                    $executionUuid
                ));
            }

            // 2. Executar validate() (Execution valida evidence + estado)
            $execution->validate();

            // 3. Persistir mudanças
            $this->executionRepository->save($execution);

            // 4. Retornar sucesso
            return Result::ok([
                'execution_uuid' => $execution->getExecutionUuid(),
                'order_uuid' => $execution->getOrderUuid(),
                'status' => $execution->getStatus()->value,
                'is_validated' => $execution->getStatus()->isValidated(),
                'has_evidence' => $execution->hasEvidence(),
                'evidence_count' => $execution->getEvidence()?->count() ?? 0,
                'duration_minutes' => $execution->getDurationMinutes(),
                'sla_violations' => $execution->getSlaViolations(),
                'has_sla_violations' => $execution->hasSlaViolations(),
            ]);

        } catch (InvalidExecutionTransitionException $e) {
            return Result::fail(sprintf(
                'Cannot validate execution: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error during validation: %s',
                $e->getMessage()
            ));
        }
    }
}
