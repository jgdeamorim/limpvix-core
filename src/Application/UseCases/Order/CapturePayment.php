<?php
declare(strict_types=1);

/**
 * CapturePayment - Use Case para capturar pagamento (Sprint 0 - Dia 4)
 *
 * RESPONSABILIDADE:
 * - Transicionar Financial: AUTHORIZED → CAPTURED
 * - Transicionar Order: CREATED → CONFIRMED
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - Orquestração de 2 aggregates (Order + Financial)
 * - Atomicidade lógica
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

class CapturePayment
{
    /**
     * Executar Use Case
     *
     * @param Order $order Aggregate Order
     * @param Financial $financial Aggregate Financial
     * @return Result<array, string>
     */
    public function execute(Order $order, Financial $financial): Result
    {
        try {
            // 1. Capturar pagamento (Financial)
            $financial->capture();

            // 2. Confirmar Order
            $order->confirm();

            return Result::ok([
                'order' => $order,
                'financial' => $financial,
                'order_status' => $order->getStatus()->value,
                'financial_status' => $financial->getStatus()->value,
            ]);

        } catch (InvalidFinancialTransitionException $e) {
            return Result::fail(sprintf(
                'Cannot capture payment: %s',
                $e->getMessage()
            ));

        } catch (InvalidOrderTransitionException $e) {
            return Result::fail(sprintf(
                'Cannot confirm order: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error capturing payment: %s',
                $e->getMessage()
            ));
        }
    }
}
