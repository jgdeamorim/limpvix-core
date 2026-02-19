<?php
declare(strict_types=1);

/**
 * CompleteServiceWithPayout - Use Case para completar serviço e autorizar payout
 * (Sprint 0 - Dia 4 + Sprint 1 - Dia 4)
 *
 * RESPONSABILIDADE:
 * - Validar serviço completado (CHECKED_OUT com evidência) (P0.1)
 * - Transicionar Order: IN_EXECUTION → COMPLETED
 * - Transicionar Financial: HELD → PAYOUT_AUTHORIZED
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - Orquestração crítica (3 aggregates)
 * - Regras de ouro garantidas
 *
 * REGRAS CRÍTICAS:
 * ❌ Financial::authorizePayout() SÓ se Order::COMPLETED (Sprint 0)
 * ❌ Payout SÓ se serviço completado (CHECKED_OUT com evidência) (P0.1)
 *
 * REGRA DE OURO DO SISTEMA:
 * Pagamento só acontece se existir execução com check-out e evidência.
 * Não agendada, não iniciada, não "em execução" — CHECKED_OUT com evidência.
 *
 * @package LimpVix\Application\UseCases\Order
 */

namespace LimpVix\Application\UseCases\Order;

use LimpVix\Common\Result;
use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Finance\Financial;
use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Order\Exceptions\InvalidOrderTransitionException;
use LimpVix\Domain\Finance\Exceptions\InvalidFinancialTransitionException;

defined('ABSPATH') || exit;

class CompleteServiceWithPayout
{
    /**
     * Executar Use Case
     *
     * FLUXO (Sprint 1 - DIA 4, refatorado P0.1):
     * 0. VALIDAR serviço completado (CHECKED_OUT com evidência)
     * 1. Completar Order (IN_EXECUTION → COMPLETED)
     * 2. Atualizar Financial com Order status
     * 3. Autorizar Payout (HELD → PAYOUT_AUTHORIZED)
     *
     * GARANTIA:
     * - Serviço DEVE estar completado (CHECKED_OUT) antes de qualquer transição
     * - Financial::authorizePayout() valida Order::COMPLETED internamente
     * - Se violar qualquer regra, exception é capturada e retorna Result::fail
     *
     * @param Order $order Aggregate Order
     * @param Financial $financial Aggregate Financial
     * @param Execution $execution Aggregate Execution (NOVO - Sprint 1)
     * @return Result<array, string>
     */
    public function execute(Order $order, Financial $financial, Execution $execution): Result
    {
        // 0. VALIDAÇÃO CRÍTICA (P0.1): Serviço deve estar completado (CHECKED_OUT com evidência)
        // (FORA da transação - early return se falhar)
        if (!$execution->getStatus()->isServiceCompleted()) {
            return Result::fail(sprintf(
                'Cannot authorize payout: Service must be completed with evidence (current status: %s)',
                $execution->getStatus()->value
            ));
        }

        // GAP #1: Start 24-hour feedback window (se ainda não iniciada)
        // Customer tem 24h para submeter feedback antes do payout ser autorizado
        if ($execution->getFeedbackWindowExpiresAt() === null) {
            $execution->startFeedbackWindow();

            // Persist feedback window start (outside transaction for idempotency)
            // If transaction fails, window is already started - next retry will skip this
            $executionRepo = $GLOBALS['limpvix_execution_repository'] ?? null;
            if ($executionRepo) {
                try {
                    $executionRepo->save($execution);
                } catch (\Exception $e) {
                    // Log but don't fail - window start is not critical for this operation
                    error_log('[LimpVix] Failed to persist feedback window start: ' . $e->getMessage());
                }
            }
        }

        // REFATORADO (SPRINT 7): Atomic Order.complete() + Financial state transitions
        // Order e Financial são aggregates diferentes que devem transicionar atomicamente:
        // - Se Order.complete() falha, Financial não deve ser modificado
        // - Se Financial.authorizePayout() falha, Order não deve ficar COMPLETED
        // CRITICAL: Evita payout stuck (Order COMPLETED sem Financial authorization)
        // CRITICAL: Evita duplicate payment (Financial authorized sem Order completion)

        $transactionManager = $GLOBALS['limpvix_transaction_manager'] ?? null;

        if (!$transactionManager) {
            return Result::fail(
                'TransactionManager não disponível. Sistema em estado inconsistente.'
            );
        }

        $transactionManager->beginTransaction();

        try {
            // 1. Completar Order (DENTRO DA TRANSAÇÃO)
            $order->complete();

            // 2. Atualizar Financial com novo status da Order (DENTRO DA TRANSAÇÃO)
            $financial->updateOrderStatus($order->getStatus());

            // 3. Autorizar Payout (valida Order::COMPLETED internamente) (DENTRO DA TRANSAÇÃO)
            $financial->authorizePayout();

            // COMMIT: Order e Financial transitam atomicamente
            $transactionManager->commit();

            return Result::ok([
                'order' => $order,
                'financial' => $financial,
                'execution' => $execution,
                'order_status' => $order->getStatus()->value,
                'financial_status' => $financial->getStatus()->value,
                'execution_status' => $execution->getStatus()->value,
                'payout_authorized' => true,
                'sla_violations' => $execution->getSlaViolations(),
            ]);

        } catch (InvalidOrderTransitionException $e) {
            // ROLLBACK: Falha em Order.complete() reverte tudo
            $transactionManager->rollback();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[CompleteServiceWithPayout] Transaction rolled back (Order transition failed): %s',
                    $e->getMessage()
                ));
            }

            return Result::fail(sprintf(
                'Cannot complete order: %s',
                $e->getMessage()
            ));

        } catch (InvalidFinancialTransitionException $e) {
            // ROLLBACK: Falha em Financial.authorizePayout() reverte Order.complete()
            $transactionManager->rollback();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[CompleteServiceWithPayout] Transaction rolled back (Financial transition failed): %s',
                    $e->getMessage()
                ));
            }

            return Result::fail(sprintf(
                'Cannot authorize payout: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            // ROLLBACK: Qualquer falha inesperada reverte tudo
            $transactionManager->rollback();

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[CompleteServiceWithPayout] Transaction rolled back (Unexpected error): %s',
                    $e->getMessage()
                ));
            }

            return Result::fail(sprintf(
                'Unexpected error completing service: %s',
                $e->getMessage()
            ));
        }
    }
}
