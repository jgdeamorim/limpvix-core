<?php
declare(strict_types=1);

/**
 * CreateOrder - Use Case para criar Order (Sprint 0 - Dia 4)
 *
 * RESPONSABILIDADE:
 * - Criar Order em estado CREATED
 * - Criar Financial em estado PENDING
 * - Retornar Result<OrderCreatedData, string>
 *
 * PRINCÍPIOS:
 * - Use Result Pattern (não lança exceptions)
 * - State Machine desde o início
 * - Atomicidade lógica (Order + Financial)
 *
 * @package LimpVix\Application\UseCases\Order
 */

namespace LimpVix\Application\UseCases\Order;

use LimpVix\Common\Result;
use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Finance\Financial;
use LimpVix\Domain\Finance\FinancialStatus;

defined('ABSPATH') || exit;

class CreateOrder
{
    /**
     * Executar Use Case
     *
     * @param string $uuid UUID da Order
     * @param int $id ID numérico da Order
     * @param float $totalAmount Total da Order
     * @param float $platformFeePercentage Porcentagem de taxa
     * @return Result<array, string>
     */
    public function execute(
        string $uuid,
        int $id,
        float $totalAmount,
        float $platformFeePercentage = 15.0
    ): Result {
        try {
            // Validações básicas
            if ($totalAmount <= 0) {
                return Result::fail('Total amount must be positive');
            }

            if ($platformFeePercentage < 0 || $platformFeePercentage > 100) {
                return Result::fail('Platform fee percentage must be between 0 and 100');
            }

            // Calcular valores
            $platformFeeAmount = ($totalAmount * $platformFeePercentage) / 100;
            $professionalPayout = $totalAmount - $platformFeeAmount;

            // Criar Financial (estado PENDING)
            $financial = Financial::create(
                $uuid,
                $totalAmount,
                $platformFeeAmount,
                $professionalPayout
            );

            // Criar Order (estado CREATED)
            // Nota: FinancialStatus é legado, mas Order ainda depende dele
            $financialStatusLegacy = FinancialStatus::CREATED();

            $order = Order::create(
                $uuid,
                $id,
                $financialStatusLegacy,
                $totalAmount,
                $platformFeePercentage,
                $platformFeeAmount,
                $professionalPayout
            );

            // Retornar sucesso com dados
            return Result::ok([
                'order' => $order,
                'financial' => $financial,
                'uuid' => $uuid,
                'total_amount' => $totalAmount,
                'platform_fee' => $platformFeeAmount,
                'professional_payout' => $professionalPayout,
            ]);

        } catch (\Exception $e) {
            // Capturar qualquer exception e encapsular em Result
            return Result::fail(sprintf(
                'Failed to create order: %s',
                $e->getMessage()
            ));
        }
    }
}
