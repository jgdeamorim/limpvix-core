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
        try {
            // 0. VALIDAÇÃO CRÍTICA (Sprint 1 - DIA 4): Execution DEVE estar VALIDATED
            if (!$execution->getStatus()->isValidated()) {
                return Result::fail(sprintf(
                    'Cannot authorize payout: Execution must be VALIDATED (current status: %s)',
                    $execution->getStatus()->value
                ));
            }

            // 1. Completar Order
            $order->complete();

            // 2. Atualizar Financial com novo status da Order
            $financial->updateOrderStatus($order->getStatus());

            // 3. Autorizar Payout (valida Order::COMPLETED internamente)
            $financial->authorizePayout();

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
            return Result::fail(sprintf(
                'Cannot complete order: %s',
                $e->getMessage()
            ));

        } catch (InvalidFinancialTransitionException $e) {
            // Inclui violação de regra Order::COMPLETED
            return Result::fail(sprintf(
                'Cannot authorize payout: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error completing service: %s',
                $e->getMessage()
            ));
        }
    }
}
