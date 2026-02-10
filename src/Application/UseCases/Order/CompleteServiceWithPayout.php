<?php
declare(strict_types=1);

/**
 * CompleteServiceWithPayout - Use Case para completar serviço e autorizar payout
 * (Sprint 0 - Dia 4 + Sprint 1 - Dia 4)
 *
 * RESPONSABILIDADE:
 * - Validar Execution::VALIDATED (NOVO - Sprint 1)
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
 * ❌ Payout SÓ se Execution::VALIDATED (Sprint 1 - DIA 4)
 *
 * REGRA DE OURO DO SISTEMA:
 * Pagamento só acontece se existir execução VALIDADA.
 * Não agendada, não iniciada, não "em execução" — VALIDADA.
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
     * FLUXO (Sprint 1 - DIA 4):
     * 0. VALIDAR Execution::VALIDATED (BLOQUEIO)
     * 1. Completar Order (IN_EXECUTION → COMPLETED)
     * 2. Atualizar Financial com Order status
     * 3. Autorizar Payout (HELD → PAYOUT_AUTHORIZED)
     *
     * GARANTIA:
     * - Execution DEVE estar VALIDATED antes de qualquer transição
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
        // 0. VALIDAÇÃO CRÍTICA (Sprint 1 - DIA 4): Execution DEVE estar VALIDATED
        // (FORA da transação - early return se falhar)
        if (!$execution->getStatus()->isValidated()) {
            return Result::fail(sprintf(
                'Cannot authorize payout: Execution must be VALIDATED (current status: %s)',
                $execution->getStatus()->value
            ));
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
