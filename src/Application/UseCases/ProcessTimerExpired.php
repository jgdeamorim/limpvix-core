<?php
/**
 * ProcessTimerExpired - Processar Expiração do Timer de Review
 *
 * RESPONSABILIDADE:
 * - Use Case específico para REVIEW → AUTHORIZED (via timer)
 * - Triggered quando timer de 24h expira sem feedback
 * - Façade sobre TransitionFinancialStatus
 *
 * PRINCÍPIOS:
 * - Single Responsibility
 * - Façade Pattern
 * - Conveniente (esconde complexidade)
 *
 * TRIGGER:
 * - Cron Job: Verificar orders em REVIEW há mais de 24h
 * - Automático: Sistema processa sem intervenção
 *
 * REGRA:
 * - Só autoriza se:
 *   - Não tem disputa
 *   - Profissional é válido
 *   - Não tem payout anterior
 *
 * USO:
 * ```php
 * $useCase = new ProcessTimerExpired($transitionUseCase);
 * $result = $useCase->execute('550e8400-...');
 * ```
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Application\Commands\TransitionFinancialStatusCommand;
use LimpVix\Application\Results\TransitionFinancialStatusResult;
use LimpVix\Application\UseCases\Feedback\CheckFeedbackWindowStatus;
use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Domain\Finance\FinancialContext;

defined('ABSPATH') || exit;

class ProcessTimerExpired
{
    /**
     * Use Case de transição
     *
     * @var TransitionFinancialStatus
     */
    private $transitionUseCase;

    /**
     * Use Case de verificação de feedback window (GAP #1)
     *
     * @var CheckFeedbackWindowStatus|null
     */
    private $checkFeedbackWindow;

    /**
     * Construtor
     *
     * @param TransitionFinancialStatus $transitionUseCase
     * @param CheckFeedbackWindowStatus|null $checkFeedbackWindow (GAP #1)
     */
    public function __construct(
        TransitionFinancialStatus $transitionUseCase,
        ?CheckFeedbackWindowStatus $checkFeedbackWindow = null
    ) {
        $this->transitionUseCase = $transitionUseCase;
        $this->checkFeedbackWindow = $checkFeedbackWindow;
    }

    /**
     * Executar
     *
     * @param string $orderUuid UUID da order
     * @param bool $hasDispute Se tem disputa aberta
     * @param bool $professionalValid Se profissional é válido
     * @param bool $hasPreviousPayout Se já teve payout anterior
     * @return TransitionFinancialStatusResult
     */
    public function execute(
        string $orderUuid,
        bool $hasDispute = false,
        bool $professionalValid = true,
        bool $hasPreviousPayout = false
    ): TransitionFinancialStatusResult {
        // ========================================
        // GAP #1: Check Feedback Window Status
        // ========================================
        if ($this->checkFeedbackWindow !== null) {
            $feedbackCheck = $this->checkFeedbackWindow->execute($orderUuid);

            if (!$feedbackCheck->isSuccess()) {
                // Failed to check feedback window
                return TransitionFinancialStatusResult::failed(
                    $orderUuid,
                    'feedback_window_check_failed',
                    $feedbackCheck->getError()
                );
            }

            $feedbackData = $feedbackCheck->getValue();

            // BLOCK if window active without feedback
            if (!$feedbackData['can_authorize_payout']) {
                return TransitionFinancialStatusResult::failed(
                    $orderUuid,
                    $feedbackData['reason'],
                    $feedbackData['message']
                );
            }

            // BLOCK and transition to BLOCKED if negative feedback
            if ($feedbackData['requires_manual_review']) {
                $context = new FinancialContext([
                    'timer_expired' => true,
                    'has_dispute' => $hasDispute,
                    'professional_valid' => $professionalValid,
                    'has_previous_payout' => $hasPreviousPayout,
                    'feedback_rating' => $feedbackData['feedback_score'],
                    'feedback_reason' => $feedbackData['reason'],
                    'requires_manual_review' => true
                ]);

                $command = new TransitionFinancialStatusCommand(
                    orderUuid: $orderUuid,
                    toStatus: FinancialStatus::BLOCKED(),
                    reason: 'negative_feedback_manual_review',
                    actor: 'system',
                    actorId: null,
                    context: $context
                );

                return $this->transitionUseCase->execute($command);
            }

            // Continue with normal flow (positive feedback or window expired)
        }

        // ========================================
        // Original Logic: REVIEW → AUTHORIZED
        // ========================================

        // Contexto: timer expirou (24h sem feedback)
        $context = new FinancialContext([
            'timer_expired' => true,
            'has_dispute' => $hasDispute,
            'professional_valid' => $professionalValid,
            'has_previous_payout' => $hasPreviousPayout,
            'feedback_rating' => null  // Sem feedback
        ]);

        // Comando: REVIEW → AUTHORIZED
        $command = new TransitionFinancialStatusCommand(
            orderUuid: $orderUuid,
            toStatus: FinancialStatus::AUTHORIZED(),
            reason: 'timer_24h_expired',
            actor: 'system',
            actorId: null,
            context: $context
        );

        return $this->transitionUseCase->execute($command);
    }
}
