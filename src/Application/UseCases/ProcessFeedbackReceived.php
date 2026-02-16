<?php
/**
 * ProcessFeedbackReceived - Processar Recebimento de Feedback
 *
 * RESPONSABILIDADE:
 * - Use Case específico para REVIEW → AUTHORIZED
 * - Triggered quando cliente envia feedback positivo
 * - Façade sobre TransitionFinancialStatus
 *
 * PRINCÍPIOS:
 * - Single Responsibility
 * - Façade Pattern
 * - Conveniente (esconde complexidade)
 *
 * TRIGGER:
 * - Evento: Feedback positivo recebido (rating ≥ 4)
 * - Manual: Cliente avalia serviço
 *
 * USO:
 * ```php
 * $useCase = new ProcessFeedbackReceived($transitionUseCase);
 * $result = $useCase->execute('550e8400-...', 5, $customerId);
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

class ProcessFeedbackReceived
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
     * @param int $rating Rating do feedback (1-5)
     * @param int|null $customerId ID do cliente
     * @return TransitionFinancialStatusResult
     */
    public function execute(string $orderUuid, int $rating, ?int $customerId = null): TransitionFinancialStatusResult
    {
        // Validação básica
        if ($rating < 1 || $rating > 5) {
            return TransitionFinancialStatusResult::rejected(
                $orderUuid,
                "Rating inválido: {$rating} (deve ser 1-5)"
            );
        }

        // Contexto: feedback recebido
        $context = new FinancialContext([
            'feedback_rating' => $rating,
            'has_dispute' => false,        // Assumir que não há disputa se deu feedback
            'professional_valid' => true,  // Assumir que profissional é válido
            'has_previous_payout' => false // Verificado pela Policy
        ]);

        // Comando: REVIEW → AUTHORIZED
        $command = new TransitionFinancialStatusCommand(
            orderUuid: $orderUuid,
            toStatus: FinancialStatus::AUTHORIZED(),
            reason: sprintf('positive_feedback_rating_%d', $rating),
            actor: 'customer',
            actorId: $customerId,
            context: $context
        );

        return $this->transitionUseCase->execute($command);
    }
}
