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
use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Domain\Finance\FinancialContext;

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
     * @param int|null $actorId ID do customer (se aplicável)
     * @return TransitionFinancialStatusResult
     */
    public function execute(string $orderUuid, ?int $actorId = null): TransitionFinancialStatusResult
    {
        // Contexto: pagamento foi confirmado
        $context = new FinancialContext([
            'payment_confirmed' => true
        ]);

        // Comando: CREATED → PAID
        $command = new TransitionFinancialStatusCommand(
            orderUuid: $orderUuid,
            toStatus: FinancialStatus::PAID(),
            reason: 'payment_confirmed',
            actor: 'woocommerce',
            actorId: $actorId,
            context: $context
        );

        return $this->transitionUseCase->execute($command);
    }
}
