<?php
/**
 * ProcessPaymentConfirmed - Processar Confirmação de Pagamento
 *
 * RESPONSABILIDADE:
 * - Use Case específico para CREATED → PAID
 * - Triggered quando pagamento é confirmado (WooCommerce)
 * - Façade sobre TransitionFinancialStatus
 *
 * PRINCÍPIOS:
 * - Single Responsibility
 * - Façade Pattern
 * - Conveniente (esconde complexidade)
 *
 * TRIGGER:
 * - Hook: woocommerce_payment_complete
 * - Evento: Pagamento confirmado
 *
 * USO:
 * ```php
 * $useCase = new ProcessPaymentConfirmed($transitionUseCase);
 * $result = $useCase->execute('550e8400-...', $actorId);
 * ```
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Application\Commands\TransitionFinancialStatusCommand;
use LimpVix\Application\Results\TransitionFinancialStatusResult;
use LimpVix\Application\Services\PlatformFeeCalculator;
use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Domain\Finance\FinancialContext;
use LimpVix\Domain\Order\OrderRepositoryInterface;

defined('ABSPATH') || exit;

class ProcessPaymentConfirmed
{
    /**
     * Use Case de transição
     *
     * @var TransitionFinancialStatus
     */
    private $transitionUseCase;

    /**
     * Repository de orders
     *
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * Calculador de taxa da plataforma
     *
     * @var PlatformFeeCalculator
     */
    private $feeCalculator;

    /**
     * Construtor
     *
     * @param TransitionFinancialStatus $transitionUseCase
     * @param OrderRepositoryInterface $orderRepository
     * @param PlatformFeeCalculator $feeCalculator
     */
    public function __construct(
        TransitionFinancialStatus $transitionUseCase,
        OrderRepositoryInterface $orderRepository,
        PlatformFeeCalculator $feeCalculator
    ) {
        $this->transitionUseCase = $transitionUseCase;
        $this->orderRepository = $orderRepository;
        $this->feeCalculator = $feeCalculator;
    }

    /**
     * Executar
     *
     * FLUXO:
     * 1. Buscar Order para obter total_amount
     * 2. Calcular taxa da plataforma (15%)
     * 3. Transicionar: CREATED → PAID
     * 4. Atualizar campos de taxa na Order
     *
     * @param string $orderUuid UUID da order
     * @param int|null $actorId ID do customer (se aplicável)
     * @return TransitionFinancialStatusResult
     */
    public function execute(string $orderUuid, ?int $actorId = null): TransitionFinancialStatusResult
    {
        // 1. Buscar Order para obter total_amount
        $order = $this->orderRepository->findByUuid($orderUuid);

        if (!$order) {
            return TransitionFinancialStatusResult::rejected(
                "Order {$orderUuid} não encontrada"
            );
        }

        $totalAmount = $order->getTotalAmount();

        if ($totalAmount <= 0) {
            error_log("[ProcessPaymentConfirmed] Order {$orderUuid} tem total_amount ZERO ou negativo: R\${$totalAmount}");
            // Prosseguir mesmo assim (pode ser order antiga sem total_amount migrado)
        }

        // 2. Calcular taxa da plataforma
        $feeBreakdown = $this->feeCalculator->calculate($totalAmount);

        error_log(sprintf(
            "[ProcessPaymentConfirmed] Order %s | Total: R$%.2f | Fee: %.2f%% = R$%.2f | Líquido: R$%.2f",
            substr($orderUuid, 0, 8),
            $feeBreakdown['total_amount'],
            $feeBreakdown['platform_fee_percentage'],
            $feeBreakdown['platform_fee_amount'],
            $feeBreakdown['professional_net_amount']
        ));

        // 3. Contexto: pagamento foi confirmado + fee calculado
        $context = new FinancialContext([
            'payment_confirmed' => true,
            'total_amount' => $feeBreakdown['total_amount'],
            'platform_fee_percentage' => $feeBreakdown['platform_fee_percentage'],
            'platform_fee_amount' => $feeBreakdown['platform_fee_amount'],
            'professional_net_amount' => $feeBreakdown['professional_net_amount']
        ]);

        // 4. Comando: CREATED → PAID
        $command = new TransitionFinancialStatusCommand(
            orderUuid: $orderUuid,
            toStatus: FinancialStatus::PAID(),
            reason: 'payment_confirmed',
            actor: 'woocommerce',
            actorId: $actorId,
            context: $context
        );

        $result = $this->transitionUseCase->execute($command);

        // 5. Se transição bem-sucedida, atualizar campos de taxa
        if ($result->isSuccess()) {
            try {
                $this->orderRepository->updateFeeCalculation(
                    $orderUuid,
                    $feeBreakdown['platform_fee_amount'],
                    $feeBreakdown['professional_net_amount']
                );

                error_log("[ProcessPaymentConfirmed] ✅ Taxa atualizada na Order {$orderUuid}");
            } catch (\Exception $e) {
                error_log("[ProcessPaymentConfirmed] ⚠️ Erro ao atualizar taxa: " . $e->getMessage());
                // Não falhar transição por erro de atualização
            }
        }

        return $result;
    }
}
