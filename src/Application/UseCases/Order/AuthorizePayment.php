<?php
declare(strict_types=1);

/**
 * AuthorizePayment - Use Case para autorizar pagamento (Sprint 0 - Dia 4)
 *
 * RESPONSABILIDADE:
 * - Transicionar Financial: PENDING → AUTHORIZED
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - State Machine garante transição válida
 * - Exceptions de domínio encapsuladas em Result::fail
 *
 * @package LimpVix\Application\UseCases\Order
 */

namespace LimpVix\Application\UseCases\Order;

use LimpVix\Common\Result;
use LimpVix\Domain\Finance\Financial;
use LimpVix\Domain\Finance\Exceptions\InvalidFinancialTransitionException;

defined('ABSPATH') || exit;

class AuthorizePayment
{
    /**
     * Executar Use Case
     *
     * @param Financial $financial Aggregate Financial
     * @return Result<Financial, string>
     */
    public function execute(Financial $financial): Result
    {
        try {
            // State Machine garante transição válida
            $financial->authorize();

            return Result::ok($financial);

        } catch (InvalidFinancialTransitionException $e) {
            // Transição inválida - encapsular em Result
            return Result::fail(sprintf(
                'Cannot authorize payment: %s',
                $e->getMessage()
            ));

        } catch (\Exception $e) {
            // Exception inesperada
            return Result::fail(sprintf(
                'Unexpected error authorizing payment: %s',
                $e->getMessage()
            ));
        }
    }
}
