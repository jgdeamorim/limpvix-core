<?php
/**
 * ProcessServiceCompleted - Processar Conclusão de Serviço
 *
 * RESPONSABILIDADE:
 * - Use Case específico para HELD → REVIEW
 * - Triggered quando execução é validada (limpvix_execution_validated)
 * - Façade sobre TransitionFinancialStatus
 *
 * PRINCÍPIOS:
 * - Single Responsibility
 * - Façade Pattern
 * - Conveniente (esconde complexidade)
 *
 * TRIGGER:
 * - Hook: limpvix_execution_validated
 * - Evento: Serviço foi executado
 *
 * USO:
 * ```php
 * $useCase = new ProcessServiceCompleted($transitionUseCase);
 * $result = $useCase->execute('550e8400-...', $professionalId);
 * ```
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Application\Commands\TransitionFinancialStatusCommand;
use LimpVix\Application\Results\TransitionFinancialStatusResult;
use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Domain\Finance\FinancialContext;

defined('ABSPATH') || exit;

class ProcessServiceCompleted
{
    /**
     * Use Case de transição
     *
     * @var TransitionFinancialStatus
     */
    private $transitionUseCase;

    /**
     * Construtor
     *
     * @param TransitionFinancialStatus $transitionUseCase
     */
    public function __construct(TransitionFinancialStatus $transitionUseCase)
    {
        $this->transitionUseCase = $transitionUseCase;
    }

    /**
     * Executar
     *
     * @param string $orderUuid UUID da order
     * @param int|null $professionalId ID do profissional
     * @return TransitionFinancialStatusResult
     */
    public function execute(string $orderUuid, ?int $professionalId = null): TransitionFinancialStatusResult
    {
        // Contexto: serviço foi completado
        $context = new FinancialContext([
            'service_completed' => true
        ]);

        // Comando: HELD → REVIEW
        $command = new TransitionFinancialStatusCommand(
            orderUuid: $orderUuid,
            toStatus: FinancialStatus::REVIEW(),
            reason: 'service_completed',
            actor: 'limpvix',
            actorId: $professionalId,
            context: $context
        );

        return $this->transitionUseCase->execute($command);
    }
}
