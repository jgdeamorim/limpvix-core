<?php
declare(strict_types=1);

/**
 * CompleteServiceWithPayout - Use Case para completar serviço e autorizar payout (Sprint 0 - Dia 4)
 *
 * RESPONSABILIDADE:
 * - Transicionar Order: IN_EXECUTION → COMPLETED
 * - Transicionar Financial: HELD → PAYOUT_AUTHORIZED
 * - Validar regra crítica: só autoriza payout SE Order::COMPLETED
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - Orquestração crítica (Order DEVE completar ANTES de payout)
 * - Regra do DIA 3 garantida
 *
 * REGRA CRÍTICA:
 * ❌ Financial::authorizePayout() SÓ permite se Order::COMPLETED
 *
 * @package LimpVix\Application\UseCases\Order
 */

namespace LimpVix\Application\UseCases\Order;

use LimpVix\Common\Result;
use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Finance\Financial;
use LimpVix\Domain\Order\Exceptions\InvalidOrderTransitionException;
use LimpVix\Domain\Finance\Exceptions\InvalidFinancialTransitionException;

defined('ABSPATH') || exit;

class CompleteServiceWithPayout
{
    /**
     * Executar Use Case
     *
     * FLUXO:
     * 1. Completar Order (IN_EXECUTION → COMPLETED)
     * 2. Atualizar Financial com Order status
     * 3. Autorizar Payout (HELD → PAYOUT_AUTHORIZED)
     *
     * GARANTIA:
     * - Financial::authorizePayout() valida Order::COMPLETED internamente
     * - Se violar, exception é capturada e retorna Result::fail
     *
     * @param Order $order Aggregate Order
     * @param Financial $financial Aggregate Financial
     * @return Result<array, string>
     */
    public function execute(Order $order, Financial $financial): Result
    {
        try {
            // 1. Completar Order
            $order->complete();

            // 2. Atualizar Financial com novo status da Order
            $financial->updateOrderStatus($order->getStatus());

            // 3. Autorizar Payout (valida Order::COMPLETED internamente)
            $financial->authorizePayout();

            return Result::ok([
                'order' => $order,
                'financial' => $financial,
                'order_status' => $order->getStatus()->value,
                'financial_status' => $financial->getStatus()->value,
                'payout_authorized' => true,
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
