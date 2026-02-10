<?php
/**
 * TransitionFinancialStatus - Use Case Principal de Transição Financeira
 *
 * RESPONSABILIDADE:
 * - Orquestrar transição financeira completa
 * - Conectar FSM (5.1) → Ledger (5.2) → Order Cache
 * - Garantir atomicidade lógica
 * - Retornar resultado claro (sucesso/falha)
 *
 * PRINCÍPIOS:
 * - Orchestrator (não decide, coordena)
 * - Atomicidade lógica (tudo ou nada)
 * - Fail-fast (rejeitar cedo)
 * - Observabilidade (hooks em pontos-chave)
 *
 * FLUXO COMPLETO:
 * 1. Buscar order (estado atual)
 * 2. Consultar Policy (pode transicionar?)
 * 3. Se NÃO: retornar rejected
 * 4. Se SIM:
 *    a. Criar evento
 *    b. Gravar no ledger
 *    c. Atualizar status da order (cache)
 *    d. Disparar hooks
 *    e. Retornar success
 *
 * USO:
 * ```php
 * $command = new TransitionFinancialStatusCommand(
 *     orderUuid: '550e8400-...',
 *     toStatus: FinancialStatus::AUTHORIZED(),
 *     reason: 'positive_feedback',
 *     actor: 'system',
 *     context: $context
 * );
 *
 * $result = $useCase->execute($command);
 *
 * if ($result->isSuccess()) {
 *     echo "Transição bem-sucedida!";
 * } else {
 *     echo "Rejeitado: " . $result->getRejectReason();
 * }
 * ```
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Application\Commands\TransitionFinancialStatusCommand;
use LimpVix\Application\Results\TransitionFinancialStatusResult;
use LimpVix\Domain\Finance\FinancialPolicy;
use LimpVix\Domain\Finance\FinancialTransitionEvent;
use LimpVix\Domain\Finance\LedgerRepositoryInterface;
use LimpVix\Domain\Order\OrderRepositoryInterface;

defined('ABSPATH') || exit;

class TransitionFinancialStatus
{
    /**
     * Order Repository
     *
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * Ledger Repository
     *
     * @var LedgerRepositoryInterface
     */
    private $ledgerRepository;

    /**
     * Financial Policy
     *
     * @var FinancialPolicy
     */
    private $policy;

    /**
     * Append Ledger Use Case
     *
     * @var AppendLedgerEntry
     */
    private $appendLedger;

    /**
     * Construtor
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param LedgerRepositoryInterface $ledgerRepository
     * @param FinancialPolicy $policy
     * @param AppendLedgerEntry $appendLedger
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        LedgerRepositoryInterface $ledgerRepository,
        FinancialPolicy $policy,
        AppendLedgerEntry $appendLedger
    ) {
        $this->orderRepository = $orderRepository;
        $this->ledgerRepository = $ledgerRepository;
        $this->policy = $policy;
        $this->appendLedger = $appendLedger;
    }

    /**
     * Executar transição financeira
     *
     * @param TransitionFinancialStatusCommand $command
     * @return TransitionFinancialStatusResult
     */
    public function execute(TransitionFinancialStatusCommand $command): TransitionFinancialStatusResult
    {
        $orderUuid = $command->getOrderUuid();
        $toStatus = $command->getToStatus();

        // 1. Buscar order e estado atual
        $currentStatus = $this->orderRepository->getCurrentFinancialStatus($orderUuid);

        if ($currentStatus === null) {
            return TransitionFinancialStatusResult::rejected(
                $orderUuid,
                "Order {$orderUuid} não encontrada"
            );
        }

        // 2. Verificar se já está no estado desejado (idempotência)
        if ($currentStatus->equals($toStatus)) {
            return TransitionFinancialStatusResult::rejected(
                $orderUuid,
                sprintf(
                    "Order já está no estado %s (idempotente)",
                    $toStatus->getValue()
                )
            );
        }

        // 3. Consultar Policy (decisão de negócio)
        $canTransition = $this->policy->canTransition(
            $currentStatus,
            $toStatus,
            $command->getContext()
        );

        if (!$canTransition) {
            $reason = $this->policy->getRejectReason(
                $currentStatus,
                $toStatus,
                $command->getContext()
            );

            $this->logRejection($orderUuid, $currentStatus, $toStatus, $reason);

            return TransitionFinancialStatusResult::rejected(
                $orderUuid,
                $reason ?? 'Transição não permitida'
            );
        }

        // 4. Criar evento de transição
        $event = new FinancialTransitionEvent(
            orderUuid: $orderUuid,
            from: $currentStatus,
            to: $toStatus,
            reason: $command->getReason(),
            actor: $command->getActor(),
            actorId: $command->getActorId()
        );

        // REFATORADO (SPRINT 7): Atomic Ledger + Order Cache dual write
        // Ledger é source of truth (append-only)
        // Order cache é derived data (materialização)
        // CRITICAL: Ambos devem ser sincronizados atomicamente

        $transactionManager = $GLOBALS['limpvix_transaction_manager'] ?? null;

        if (!$transactionManager) {
            return TransitionFinancialStatusResult::rejected(
                $orderUuid,
                'TransactionManager não disponível. Sistema em estado inconsistente.'
            );
        }

        $transactionManager->beginTransaction();

        try {
            // 5. Gravar no ledger (fonte da verdade) - DENTRO DA TRANSAÇÃO
            $this->appendLedger->execute($event);

            // 6. Atualizar status da order (cache) - DENTRO DA TRANSAÇÃO
            $this->orderRepository->updateFinancialStatus($orderUuid, $toStatus);

            // COMMIT: Ledger e cache sincronizados atomicamente
            $transactionManager->commit();

            // 7. Disparar hook de sucesso (APÓS commit bem-sucedido)
            $this->logSuccess($event);

            // 8. Retornar sucesso
            return TransitionFinancialStatusResult::success(
                orderUuid: $orderUuid,
                fromStatus: $currentStatus,
                toStatus: $toStatus,
                ledgerUuid: 'generated-by-ledger' // TODO: capturar UUID real do ledger
            );

        } catch (\Exception $e) {
            // ROLLBACK: Falha em ledger OU order reverte AMBOS
            $transactionManager->rollback();

            // Falha crítica (ledger ou order)
            $this->logError($orderUuid, $event, $e);

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[TransitionFinancialStatus] Transaction rolled back for order %s: %s',
                    $orderUuid,
                    $e->getMessage()
                ));
            }

            return TransitionFinancialStatusResult::rejected(
                $orderUuid,
                sprintf('Erro ao executar transição: %s', $e->getMessage())
            );
        }
    }

    /**
     * Log de rejeição
     *
     * @param string $orderUuid
     * @param \LimpVix\Domain\Finance\FinancialStatus $from
     * @param \LimpVix\Domain\Finance\FinancialStatus $to
     * @param string|null $reason
     * @return void
     */
    private function logRejection($orderUuid, $from, $to, ?string $reason): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_financial_transition_rejected', [
            'order_uuid' => $orderUuid,
            'from_status' => $from->getValue(),
            'to_status' => $to->getValue(),
            'reason' => $reason
        ]);
    }

    /**
     * Log de sucesso
     *
     * @param FinancialTransitionEvent $event
     * @return void
     */
    private function logSuccess(FinancialTransitionEvent $event): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_financial_transition_success', [
            'order_uuid' => $event->getOrderUuid(),
            'from_status' => $event->getFrom()->getValue(),
            'to_status' => $event->getTo()->getValue(),
            'reason' => $event->getReason(),
            'actor' => $event->getActor(),
            'occurred_at' => $event->getOccurredAt()->format('Y-m-d H:i:s')
        ]);

        // Evento específico para sincronização de status (BLC-002)
        do_action(
            'limpvix_financial_status_changed',
            $event->getOrderUuid(),
            $event->getFrom()->getValue(),
            $event->getTo()->getValue()
        );
    }

    /**
     * Log de erro crítico
     *
     * @param string $orderUuid
     * @param FinancialTransitionEvent $event
     * @param \Exception $exception
     * @return void
     */
    private function logError(string $orderUuid, FinancialTransitionEvent $event, \Exception $exception): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_financial_transition_error', [
            'order_uuid' => $orderUuid,
            'event' => $event->toArray(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
