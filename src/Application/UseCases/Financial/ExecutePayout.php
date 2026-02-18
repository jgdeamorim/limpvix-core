<?php
declare(strict_types=1);

/**
 * ExecutePayout - Use Case para Executar Payout (Golden Rule Protected)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution e validar estado VALIDATED
 * - Garantir Golden Rule: Payout SÓ se Execution::VALIDATED
 * - Checar Feedback rating (Flow 4.4 integration)
 * - Delegar execução para MercadoPagoPayoutProvider
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - Validação OBRIGATÓRIA de Execution::VALIDATED
 * - Feedback hold logic: rating ≤ 2 bloqueia payout até admin aprovar
 * - SEM lógica de negócio (apenas orquestração)
 *
 * FLOW 4.4 INTEGRATION:
 * - Injeta FeedbackRepositoryInterface
 * - Se feedback.blocksPayout() == true → payout fica "on_hold"
 * - Payout só processa se feedback aprovado OU rating >= 3
 *
 * @package LimpVix\Application\UseCases\Financial
 */

namespace LimpVix\Application\UseCases\Financial;

use LimpVix\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Finance\PayoutRepositoryInterface;
use LimpVix\Domain\Feedback\FeedbackRepositoryInterface;
use LimpVix\Domain\Finance\PayoutProviderInterface;
use LimpVix\Domain\Professional\ProfessionalRepositoryInterface;

defined('ABSPATH') || exit;

class ExecutePayout
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository,
        private PayoutProviderInterface $payoutProvider,
        private PayoutRepositoryInterface $payoutRepository,
        private FeedbackRepositoryInterface $feedbackRepository, // Flow 4.4 integration
        private ProfessionalRepositoryInterface $professionalRepository
    ) {}

    /**
     * Executar payout com validação Golden Rule + Feedback hold
     *
     * FLUXO (REFATORADO - FLOW 4.4):
     * 1. Buscar payout no banco
     * 2. Buscar Execution correspondente
     * 3. ✅ GOLDEN RULE: VALIDAR Execution::VALIDATED
     * 4. ✅ FEEDBACK CHECK: Se rating ≤ 2 e não aprovado → HOLD payout
     * 5. Chamar MercadoPago Provider (IRREVERSÍVEL - fora de transação)
     * 6. SE sucesso no provider:
     *    a. START TRANSACTION
     *    b. Atualizar payout status no banco local
     *    c. COMMIT
     * 7. Retornar Result com status
     *
     * REGRA CRÍTICA:
     * - Payout SÓ executa se Execution::VALIDATED
     * - Payout SÓ executa se Feedback aprovado OU rating >= 3
     * - Provider call ANTES de DB transaction (chamadas externas são irreversíveis)
     * - DB update DEPOIS de provider success (protegido por transação)
     *
     * @param int $payoutId ID do payout
     * @return Result<array, string>
     */
    public function execute(int $payoutId): Result
    {
        try {
            // 1. Buscar payout (FORA de transação - read-only)
            $payout = $this->payoutRepository->getById($payoutId);

            if (!$payout) {
                return Result::fail(sprintf(
                    'Payout #%d não encontrado',
                    $payoutId
                ));
            }

            // 2. Buscar Execution correspondente (FORA de transação - read-only)
            $execution = $this->executionRepository->findByOrderUuid($payout['order_uuid']);

            if (!$execution) {
                return Result::fail(sprintf(
                    'Execution não encontrada para Order %s',
                    $payout['order_uuid']
                ));
            }

            // 3. ✅ GOLDEN RULE: VALIDAR Execution::VALIDATED
            if (!$execution->getStatus()->isValidated()) {
                return Result::fail(sprintf(
                    'Cannot execute payout: Execution must be VALIDATED (current status: %s). ' .
                    'Professional must complete check-in, check-out and evidence validation before payout.',
                    $execution->getStatus()->value
                ));
            }

            // 4. ✅ FEEDBACK HOLD CHECK (Flow 4.4) + 48H TIMEOUT (Tier 1 Fix)
            // Se feedback existe e bloqueia payout (rating ≤ 2 sem aprovação admin),
            // marcar payout como "on_hold" e não processar
            // TIER 1 FIX: Se hold expirou (>48h), liberar automaticamente
            $feedback = $this->feedbackRepository->findByOrderId((int) $payout['order_id']);

            if ($feedback !== null && $feedback->blocksPayout()) {
                // Check if hold has expired (48h timeout)
                $holdExpiryHours = 48;
                $feedbackCreatedAt = $feedback->getCreatedAt();
                $holdExpiry = $feedbackCreatedAt->modify("+{$holdExpiryHours} hours");
                $now = new \DateTimeImmutable();

                // TIER 1 FIX: Se hold expirou, liberar automaticamente
                if ($now >= $holdExpiry) {
                    error_log(sprintf(
                        '[ExecutePayout] Feedback hold EXPIRED for payout #%d (feedback rating: %d, created: %s, now: %s). ' .
                        'Auto-releasing payout after %d hours hold period.',
                        $payoutId,
                        $feedback->getRating(),
                        $feedbackCreatedAt->format('Y-m-d H:i:s'),
                        $now->format('Y-m-d H:i:s'),
                        $holdExpiryHours
                    ));

                    // Continuar processamento - hold expirou, liberar payout
                    // Payout será processado normalmente abaixo
                } else {
                    // Hold ainda válido - bloquear payout
                    // Calcular tempo restante até expiração
                    $hoursRemaining = round(($holdExpiry->getTimestamp() - $now->getTimestamp()) / 3600, 1);

                    $transactionManager = $GLOBALS['limpvix_transaction_manager'] ?? null;

                    if ($transactionManager) {
                        $transactionManager->beginTransaction();
                        try {
                            $this->payoutRepository->updateStatus($payoutId, 'on_hold');
                            $transactionManager->commit();
                        } catch (\Exception $e) {
                            $transactionManager->rollback();
                            return Result::fail(sprintf(
                                'Failed to set payout on_hold: %s',
                                $e->getMessage()
                            ));
                        }
                    }

                    return Result::fail(sprintf(
                        'Payout #%d placed on HOLD: Customer feedback rating is %d/5 (critical threshold). ' .
                        'Admin must review and approve feedback before payout can be processed. ' .
                        'Feedback ID: %d. Hold expires in %.1f hours (max 48h hold period). ' .
                        'After expiration, payout will be automatically released.',
                        $payoutId,
                        $feedback->getRating(),
                        $feedback->getId(),
                        $hoursRemaining
                    ));
                }
            }

            // 5a. Identificar chave PIX do profissional e atualizar payout se necessário
            // On-demand model: payout via PIX automático usando chave PIX do profissional
            $professionalId = $execution->getProfessionalId();
            $professional   = $this->professionalRepository->findById($professionalId);

            if ($professional === null) {
                return Result::fail(sprintf(
                    'Profissional #%d não encontrado para o payout #%d',
                    $professionalId,
                    $payoutId
                ));
            }

            if (!$professional->hasPixKey()) {
                return Result::fail(sprintf(
                    'Profissional #%d não possui chave PIX cadastrada. ' .
                    'Payout #%d não pode ser processado automaticamente.',
                    $professionalId,
                    $payoutId
                ));
            }

            // Atualizar destinatário do payout com dados do profissional
            $this->payoutRepository->setRecipientInfo(
                $payoutId,
                $professional->getPixKey(),
                'pix',
                $professional->getFullName()
            );

            error_log(sprintf(
                '[ExecutePayout] Payout #%d: PIX do profissional #%d (chave=%s, tipo=%s) configurado.',
                $payoutId,
                $professionalId,
                $professional->getPixKey(),
                $professional->getPixKeyType()
            ));

            // 5b. Executar payout via EFI Bank PIX (IRREVERSÍVEL - fora de transação)
            // CRITICAL: Esta chamada é irreversível. Se suceder, o dinheiro foi transferido.
            // Não podemos fazer rollback no provider, apenas no banco local.
            $success = $this->payoutProvider->createPayout($payoutId);

            if (!$success) {
                // Provider falhou - nada foi alterado no banco, safe return
                return Result::fail(sprintf(
                    'Failed to create payout #%d on MercadoPago. Check error logs for details.',
                    $payoutId
                ));
            }

            // 6. Provider sucedeu - agora persistir resultado no banco (TRANSAÇÃO)
            $transactionManager = $GLOBALS['limpvix_transaction_manager'] ?? null;

            if (!$transactionManager) {
                // CRITICAL: Provider já executou, mas não podemos persistir
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[ExecutePayout] CRITICAL: Payout #%d created on MercadoPago but TransactionManager unavailable. Manual reconciliation required.',
                        $payoutId
                    ));
                }

                return Result::fail(
                    'TransactionManager não disponível. Payout criado no MercadoPago mas não persistido localmente. Reconciliation necessária.'
                );
            }

            $transactionManager->beginTransaction();

            try {
                // Atualizar status do payout para "processing" ou "completed"
                $this->payoutRepository->updateStatus($payoutId, 'processing');

                // COMMIT: Status persistido com sucesso
                $transactionManager->commit();

                // 7. Retornar sucesso
                return Result::ok([
                    'payout_id' => $payoutId,
                    'order_uuid' => $payout['order_uuid'],
                    'execution_uuid' => $execution->getExecutionUuid(),
                    'execution_status' => $execution->getStatus()->value,
                    'status' => 'processing',
                    'message' => 'Payout created successfully on MercadoPago and persisted locally'
                ]);

            } catch (\Exception $e) {
                // ROLLBACK: Falha ao persistir status
                $transactionManager->rollback();

                // CRITICAL: Provider já executou, mas DB update falhou
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log(sprintf(
                        '[ExecutePayout] CRITICAL: Payout #%d created on MercadoPago but DB update failed: %s. Manual reconciliation required.',
                        $payoutId,
                        $e->getMessage()
                    ));
                }

                return Result::fail(sprintf(
                    'Payout created on MercadoPago but failed to persist locally: %s. Reconciliation required.',
                    $e->getMessage()
                ));
            }

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error during payout execution: %s',
                $e->getMessage()
            ));
        }
    }
}
