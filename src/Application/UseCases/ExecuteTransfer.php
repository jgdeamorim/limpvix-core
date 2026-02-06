<?php
/**
 * ExecuteTransfer - Use Case para Executar Repasse
 *
 * RESPONSABILIDADE:
 * - Orquestrar execução de payout AUTORIZADO
 * - Garantir idempotência
 * - Gravar resultado
 * - Transicionar para TRANSFERRED (se sucesso)
 *
 * PRINCÍPIOS:
 * - Orchestrator (não executa, coordena)
 * - Idempotência via Repository
 * - Fail-fast
 * - Atomicidade lógica
 *
 * FLUXO:
 * 1. Verificar se order está AUTHORIZED
 * 2. Verificar idempotência (já foi executado?)
 * 3. Construir Payout
 * 4. Executar via Provider
 * 5. Gravar resultado no Repository
 * 6. Se sucesso: transicionar para TRANSFERRED
 * 7. Retornar resultado
 *
 * IMPORTANTE:
 * - NÃO executa se não estiver AUTHORIZED
 * - NÃO repete automaticamente
 * - TRANSFERRED é estado final
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Domain\Order\OrderRepositoryInterface;
use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Modules\Payouts\PayoutProviderInterface;
use LimpVix\Modules\Payouts\MercadoPago\Payout;
use LimpVix\Modules\Payouts\MercadoPago\PayoutResult;
use LimpVix\Modules\Payouts\MercadoPago\RepasseRepository;

defined('ABSPATH') || exit;

class ExecuteTransfer
{
    /**
     * Order Repository
     *
     * @var OrderRepositoryInterface
     */
    private $orderRepository;

    /**
     * Repasse Repository
     *
     * @var RepasseRepository
     */
    private $repasseRepository;

    /**
     * Payout Provider
     *
     * @var PayoutProviderInterface
     */
    private $payoutProvider;

    /**
     * Transition Use Case
     *
     * @var TransitionFinancialStatus
     */
    private $transitionUseCase;

    /**
     * Construtor
     *
     * @param OrderRepositoryInterface $orderRepository
     * @param RepasseRepository $repasseRepository
     * @param PayoutProviderInterface $payoutProvider
     * @param TransitionFinancialStatus $transitionUseCase
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        RepasseRepository $repasseRepository,
        PayoutProviderInterface $payoutProvider,
        TransitionFinancialStatus $transitionUseCase
    ) {
        $this->orderRepository = $orderRepository;
        $this->repasseRepository = $repasseRepository;
        $this->payoutProvider = $payoutProvider;
        $this->transitionUseCase = $transitionUseCase;
    }

    /**
     * Executar repasse
     *
     * @param string $orderUuid UUID da order
     * @param float $amount Valor do repasse
     * @param string $receiverMpUserId MP User ID do profissional
     * @param string $description Descrição
     * @return ExecuteTransferResult
     */
    public function execute(
        string $orderUuid,
        float $amount,
        string $receiverMpUserId,
        string $description
    ): ExecuteTransferResult {
        try {
            // 1. Verificar se order está AUTHORIZED
            $currentStatus = $this->orderRepository->getCurrentFinancialStatus($orderUuid);

            if ($currentStatus === null) {
                return ExecuteTransferResult::rejected(
                    "Order {$orderUuid} não encontrada"
                );
            }

            if (!$currentStatus->equals(FinancialStatus::AUTHORIZED())) {
                return ExecuteTransferResult::rejected(
                    sprintf(
                        "Order não está AUTHORIZED (atual: %s)",
                        $currentStatus->getValue()
                    )
                );
            }

            // 2. Gerar payout ID único
            $payoutId = $this->generatePayoutId();

            // 3. Verificar idempotência
            if ($this->repasseRepository->exists($payoutId)) {
                $status = $this->repasseRepository->getStatus($payoutId);
                return ExecuteTransferResult::rejected(
                    "Payout já foi executado (status: {$status})"
                );
            }

            // 4. Construir Payout
            $payout = new Payout(
                payoutId: $payoutId,
                amount: $amount,
                receiverMpUserId: $receiverMpUserId,
                description: $description,
                currency: 'BRL',
                metadata: ['order_uuid' => $orderUuid]
            );

            // 5. Executar via Provider
            $result = $this->payoutProvider->transfer($payout);

            // 6. Gravar resultado
            if ($result->isSuccess()) {
                $this->repasseRepository->recordSuccess($payout, $result);
                $this->logSuccess($orderUuid, $result);
            } else {
                $this->repasseRepository->recordFailure($payout, $result);
                $this->logFailure($orderUuid, $result);
            }

            // 7. Se sucesso: transicionar para TRANSFERRED
            if ($result->isSuccess()) {
                $this->transitionToTransferred($orderUuid, $payoutId);

                return ExecuteTransferResult::success(
                    $orderUuid,
                    $payoutId,
                    $result->getMpTransferId(),
                    $amount
                );
            }

            // 8. Se falha: retornar rejected
            return ExecuteTransferResult::rejected(
                sprintf(
                    'Payout falhou: %s - %s',
                    $result->getErrorCode(),
                    $result->getErrorMessage()
                )
            );

        } catch (\Exception $e) {
            $this->logError($orderUuid, $e);

            return ExecuteTransferResult::rejected(
                sprintf('Erro ao executar repasse: %s', $e->getMessage())
            );
        }
    }

    /**
     * Gerar payout ID único
     *
     * @return string UUID v4
     */
    private function generatePayoutId(): string
    {
        $data = random_bytes(16);
        $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
        $data[8] = chr(ord($data[8]) & 0x3f | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
    }

    /**
     * Transicionar para TRANSFERRED
     *
     * @param string $orderUuid
     * @param string $payoutId
     * @return void
     */
    private function transitionToTransferred(string $orderUuid, string $payoutId): void
    {
        $command = new \LimpVix\Application\Commands\TransitionFinancialStatusCommand(
            orderUuid: $orderUuid,
            toStatus: FinancialStatus::TRANSFERRED(),
            reason: "payout_executed_{$payoutId}",
            actor: 'system',
            actorId: null,
            context: new \LimpVix\Domain\Finance\FinancialContext([])
        );

        $this->transitionUseCase->execute($command);
    }

    /**
     * Log de sucesso
     *
     * @param string $orderUuid
     * @param PayoutResult $result
     * @return void
     */
    private function logSuccess(string $orderUuid, PayoutResult $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_payout_success', [
            'order_uuid' => $orderUuid,
            'mp_transfer_id' => $result->getMpTransferId(),
            'http_status' => $result->getHttpStatusCode()
        ]);
    }

    /**
     * Log de falha
     *
     * @param string $orderUuid
     * @param PayoutResult $result
     * @return void
     */
    private function logFailure(string $orderUuid, PayoutResult $result): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_payout_failure', [
            'order_uuid' => $orderUuid,
            'error_code' => $result->getErrorCode(),
            'error_message' => $result->getErrorMessage(),
            'http_status' => $result->getHttpStatusCode()
        ]);
    }

    /**
     * Log de erro
     *
     * @param string $orderUuid
     * @param \Exception $exception
     * @return void
     */
    private function logError(string $orderUuid, \Exception $exception): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_payout_error', [
            'order_uuid' => $orderUuid,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}

/**
 * ExecuteTransferResult - Resultado do Use Case
 */
class ExecuteTransferResult
{
    private $success;
    private $orderUuid;
    private $payoutId;
    private $mpTransferId;
    private $amount;
    private $rejectReason;

    public static function success(
        string $orderUuid,
        string $payoutId,
        string $mpTransferId,
        float $amount
    ): self {
        $result = new self();
        $result->success = true;
        $result->orderUuid = $orderUuid;
        $result->payoutId = $payoutId;
        $result->mpTransferId = $mpTransferId;
        $result->amount = $amount;
        $result->rejectReason = null;
        return $result;
    }

    public static function rejected(string $reason): self
    {
        $result = new self();
        $result->success = false;
        $result->rejectReason = $reason;
        return $result;
    }

    private function __construct() {}

    public function isSuccess(): bool { return $this->success; }
    public function getOrderUuid(): ?string { return $this->orderUuid; }
    public function getPayoutId(): ?string { return $this->payoutId; }
    public function getMpTransferId(): ?string { return $this->mpTransferId; }
    public function getAmount(): ?float { return $this->amount; }
    public function getRejectReason(): ?string { return $this->rejectReason; }
}
