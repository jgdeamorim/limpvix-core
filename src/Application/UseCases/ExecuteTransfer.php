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
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;

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
     * Payout Repository
     *
     * @var WpPayoutRepository
     */
    private $payoutRepository;

    /**
     * Payout Provider
     *
     * @var MercadoPagoPayoutProvider
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
     * @param WpPayoutRepository $payoutRepository
     * @param MercadoPagoPayoutProvider $payoutProvider
     * @param TransitionFinancialStatus $transitionUseCase
     */
    public function __construct(
        OrderRepositoryInterface $orderRepository,
        WpPayoutRepository $payoutRepository,
        MercadoPagoPayoutProvider $payoutProvider,
        TransitionFinancialStatus $transitionUseCase
    ) {
        $this->orderRepository = $orderRepository;
        $this->payoutRepository = $payoutRepository;
        $this->payoutProvider = $payoutProvider;
        $this->transitionUseCase = $transitionUseCase;
    }

    /**
     * Executar repasse
     *
     * CORREÇÃO BLC-000: Usar professional_net_amount ao invés de total_amount
     *
     * @param string $orderUuid UUID da order
     * @param string $receiverMpUserId MP User ID do profissional (não usado diretamente)
     * @param string $description Descrição
     * @return ExecuteTransferResult
     */
    public function execute(
        string $orderUuid,
        string $receiverMpUserId,
        string $description
    ): ExecuteTransferResult {
        try {
            // 1. Buscar Order completa
            $order = $this->orderRepository->findByUuid($orderUuid);

            if (!$order) {
                return ExecuteTransferResult::rejected(
                    "Order {$orderUuid} não encontrada"
                );
            }

            // 2. Verificar se order está AUTHORIZED
            if (!$order->getFinancialStatus()->equals(FinancialStatus::AUTHORIZED())) {
                return ExecuteTransferResult::rejected(
                    sprintf(
                        "Order não está AUTHORIZED (atual: %s)",
                        $order->getFinancialStatus()->getValue()
                    )
                );
            }

            // 3. Obter valor líquido do profissional (após taxa LimpVix)
            $netAmount = $order->getProfessionalNetAmount();

            if ($netAmount <= 0) {
                return ExecuteTransferResult::rejected(
                    "Professional net amount é ZERO ou negativo (R\${$netAmount})"
                );
            }

            error_log(sprintf(
                "[ExecuteTransfer] Order %s | Total: R$%.2f | Taxa: R$%.2f (%.1f%%) | Líquido Profissional: R$%.2f",
                substr($orderUuid, 0, 8),
                $order->getTotalAmount(),
                $order->getPlatformFeeAmount(),
                $order->getPlatformFeePercentage(),
                $netAmount
            ));

            // 4. Buscar dados do profissional
            $professionalData = $this->getProfessionalData($order);

            if (!$professionalData) {
                return ExecuteTransferResult::rejected(
                    "Dados do profissional não encontrados"
                );
            }

            // 5. Verificar idempotência (já existe payout para esta order?)
            global $wpdb;
            $existingPayout = $wpdb->get_row($wpdb->prepare(
                "SELECT id, status, gateway_transfer_id FROM {$wpdb->prefix}limpvix_payouts
                 WHERE order_id = %d",
                $order->getId()
            ), ARRAY_A);

            if ($existingPayout) {
                return ExecuteTransferResult::rejected(
                    sprintf(
                        "Payout já existe para esta order (ID: %d, Status: %s)",
                        $existingPayout['id'],
                        $existingPayout['status']
                    )
                );
            }

            // REFATORADO (SPRINT 7): Saga Pattern multi-step
            // 3-way consistency: Payout record, MercadoPago transfer, Order status
            //
            // FLUXO:
            // 1. CREATE payout (status: pending) - transação curta
            // 2. CALL MercadoPago (irreversível, fora de transação)
            // 3. IF success:
            //    a. UPDATE payout (status: processing) - transação
            //    b. TRANSITION order (AUTHORIZED → TRANSFERRED) - transação interna
            // 4. IF failure:
            //    a. UPDATE payout (status: failed) - transação
            //    b. Return rejected

            $transactionManager = $GLOBALS['limpvix_transaction_manager'] ?? null;

            if (!$transactionManager) {
                return ExecuteTransferResult::rejected(
                    'TransactionManager não disponível. Sistema em estado inconsistente.'
                );
            }

            // STEP 1: Criar payout no banco (status: pending)
            // Transação curta e focada - apenas INSERT
            $transactionManager->beginTransaction();

            try {
                $payoutId = $this->payoutRepository->create([
                    'order_id' => $order->getId(),
                    'professional_id' => $order->getProfessionalId(),
                    'gross_amount' => $order->getTotalAmount(),
                    'platform_fee' => $order->getPlatformFeeAmount(),
                    'net_amount' => $netAmount,
                    'status' => 'pending', // Status inicial: pending (antes de chamar provider)
                    'gateway' => 'mercadopago',
                    'recipient_type' => $professionalData['recipient_type'],
                    'recipient_key' => $professionalData['recipient_key'],
                    'recipient_name' => $professionalData['recipient_name'],
                    'recipient_document' => $professionalData['recipient_document'],
                ]);

                if (!$payoutId) {
                    throw new \RuntimeException('Falha ao criar payout no banco');
                }

                $transactionManager->commit();

            } catch (\Exception $e) {
                $transactionManager->rollback();
                error_log("[ExecuteTransfer] ❌ Falha ao criar payout: " . $e->getMessage());
                return ExecuteTransferResult::rejected(
                    "Falha ao criar payout no banco: " . $e->getMessage()
                );
            }

            error_log("[ExecuteTransfer] Payout #{$payoutId} criado (pending) - executando via Mercado Pago...");

            // STEP 2: Executar via MercadoPagoPayoutProvider (IRREVERSÍVEL, fora de transação)
            $success = $this->payoutProvider->createPayout($payoutId);

            if (!$success) {
                // STEP 2a: Provider falhou - marcar payout como "failed"
                $transactionManager->beginTransaction();
                try {
                    $this->payoutRepository->updateStatus($payoutId, 'failed');
                    $transactionManager->commit();
                } catch (\Exception $e) {
                    $transactionManager->rollback();
                    error_log("[ExecuteTransfer] ⚠️  Falha ao atualizar status failed: " . $e->getMessage());
                }

                error_log("[ExecuteTransfer] ❌ Payout #{$payoutId} falhou no MercadoPago");
                return ExecuteTransferResult::rejected(
                    "Falha ao executar payout via Mercado Pago"
                );
            }

            // STEP 3: Provider sucedeu - persistir resultado e transicionar order
            $transactionManager->beginTransaction();

            try {
                // 3a. Atualizar status do payout para "processing" (já tem gateway_transfer_id do provider)
                $this->payoutRepository->updateStatus($payoutId, 'processing');

                // 3b. Buscar dados atualizados do payout
                $payoutData = $this->payoutRepository->getById($payoutId);
                $mpTransferId = $payoutData['gateway_transfer_id'] ?? null;

                $transactionManager->commit();

                error_log(sprintf(
                    "[ExecuteTransfer] ✅ Payout #{$payoutId} executado com sucesso! MP Transfer ID: %s",
                    $mpTransferId ?? 'N/A'
                ));

            } catch (\Exception $e) {
                $transactionManager->rollback();

                // CRITICAL: Provider executou mas DB update falhou
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[ExecuteTransfer] CRITICAL: Payout #%d created on MercadoPago but DB update failed: %s. Manual reconciliation required.',
                        $payoutId,
                        $e->getMessage()
                    ));
                }

                return ExecuteTransferResult::rejected(
                    sprintf(
                        'Payout executado no MercadoPago mas falha ao persistir localmente: %s. Reconciliation necessária.',
                        $e->getMessage()
                    )
                );
            }

            // STEP 4: Transicionar para TRANSFERRED
            // TransitionFinancialStatus já tem transação interna (refatorado anteriormente)
            $this->transitionToTransferred($orderUuid, $payoutId);

            return ExecuteTransferResult::success(
                $orderUuid,
                (string) $payoutId,
                $mpTransferId ?? 'unknown',
                $netAmount
            );

        } catch (\Exception $e) {
            error_log("[ExecuteTransfer] ❌ EXCEPTION: " . $e->getMessage());
            error_log("[ExecuteTransfer] Stack trace: " . $e->getTraceAsString());

            return ExecuteTransferResult::rejected(
                sprintf('Erro ao executar repasse: %s', $e->getMessage())
            );
        }
    }

    /**
     * Buscar dados do profissional para payout
     *
     * @param object $order Order entity
     * @return array|null
     */
    private function getProfessionalData($order): ?array
    {
        $professionalId = $order->getProfessionalId();

        if (!$professionalId) {
            return null;
        }

        // Buscar configurações de payout do profissional (user meta)
        // TODO: Migrar para tabela limpvix_professionals quando implementada
        $recipientType = get_user_meta($professionalId, '_limpvix_payout_recipient_type', true);
        $recipientKey = get_user_meta($professionalId, '_limpvix_payout_recipient_key', true);
        $recipientName = get_user_meta($professionalId, '_limpvix_payout_recipient_name', true);
        $recipientDocument = get_user_meta($professionalId, '_limpvix_payout_recipient_document', true);

        if (empty($recipientType) || empty($recipientKey)) {
            error_log("[ExecuteTransfer] ⚠️  Profissional #{$professionalId} sem dados de payout configurados");
            return null;
        }

        return [
            'recipient_type' => $recipientType,
            'recipient_key' => $recipientKey,
            'recipient_name' => $recipientName ?: 'Nome não informado',
            'recipient_document' => $recipientDocument ?: '',
        ];
    }

    /**
     * Transicionar para TRANSFERRED
     *
     * @param string $orderUuid
     * @param int $payoutId
     * @return void
     */
    private function transitionToTransferred(string $orderUuid, int $payoutId): void
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

        // Disparar evento de sucesso
        if (function_exists('do_action')) {
            do_action('limpvix_payout_success', [
                'order_uuid' => $orderUuid,
                'payout_id' => $payoutId
            ]);
        }
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
