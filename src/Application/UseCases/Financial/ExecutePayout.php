<?php
declare(strict_types=1);

/**
 * ExecutePayout - Use Case para Executar Payout (Golden Rule Protected)
 *
 * RESPONSABILIDADE:
 * - Buscar Execution e validar estado VALIDATED
 * - Garantir Golden Rule: Payout SÓ se Execution::VALIDATED
 * - Delegar execução para MercadoPagoPayoutProvider
 * - Retornar Result (não lança exceptions)
 *
 * PRINCÍPIOS:
 * - Use Result Pattern
 * - Validação OBRIGATÓRIA de Execution::VALIDATED
 * - SEM lógica de negócio (apenas orquestração)
 *
 * CORREÇÃO: P0-001 (GO LIVE Blocker)
 * - PayoutsPage.php estava chamando provider DIRETO
 * - Agora SEMPRE passa por este Use Case
 * - Golden Rule garantida em nível de Application Layer
 *
 * @package LimpVix\Application\UseCases\Financial
 */

namespace LimpVix\Application\UseCases\Financial;

use LimpVix\Common\Result;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;
use LimpVix\Infrastructure\Finance\Repositories\WpPayoutRepository;

defined('ABSPATH') || exit;

class ExecutePayout
{
    public function __construct(
        private ExecutionRepositoryInterface $executionRepository,
        private MercadoPagoPayoutProvider $payoutProvider,
        private WpPayoutRepository $payoutRepository
    ) {}

    /**
     * Executar payout com validação Golden Rule
     *
     * FLUXO (REFATORADO - SPRINT 7):
     * 1. Buscar payout no banco
     * 2. Buscar Execution correspondente
     * 3. ✅ GOLDEN RULE: VALIDAR Execution::VALIDATED
     * 4. Chamar MercadoPago Provider (IRREVERSÍVEL - fora de transação)
     * 5. SE sucesso no provider:
     *    a. START TRANSACTION
     *    b. Atualizar payout status no banco local
     *    c. COMMIT
     * 6. Retornar Result com status
     *
     * REGRA CRÍTICA:
     * - Payout SÓ executa se Execution::VALIDATED
     * - Provider call ANTES de DB transaction (chamadas externas são irreversíveis)
     * - DB update DEPOIS de provider success (protegido por transação)
     *
     * SAGA PATTERN FOR EXTERNAL PROVIDERS:
     * - Provider call não pode ser revertido (transferência já processada)
     * - DB transaction protege APENAS o update local
     * - Ordem: Validate → Call Provider → Persist Result
     * - Se provider sucede mas DB falha, reconciliation job corrige depois
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

            // 3. ✅ GOLDEN RULE: VALIDAR Execution::VALIDATED (FORA de transação - validação apenas)
            if (!$execution->getStatus()->isValidated()) {
                return Result::fail(sprintf(
                    'Cannot execute payout: Execution must be VALIDATED (current status: %s). ' .
                    'Professional must complete check-in, check-out and evidence validation before payout.',
                    $execution->getStatus()->value
                ));
            }

            // 4. Executar payout no MercadoPago (IRREVERSÍVEL - fora de transação)
            // CRITICAL: Esta chamada é irreversível. Se suceder, o dinheiro foi transferido.
            // Não podemos fazer rollback no MercadoPago, apenas no banco local.
            $success = $this->payoutProvider->createPayout($payoutId);

            if (!$success) {
                // Provider falhou - nada foi alterado no banco, safe return
                return Result::fail(sprintf(
                    'Failed to create payout #%d on MercadoPago. Check error logs for details.',
                    $payoutId
                ));
            }

            // 5. Provider sucedeu - agora persistir resultado no banco (TRANSAÇÃO)
            // REFATORADO (SPRINT 7): DB update protegido por transação
            // Se falhar aqui, reconciliation job detectará inconsistência:
            // - MercadoPago tem transferência processada
            // - Banco local ainda mostra "pending"
            // - Job consulta MP API e corrige status

            $transactionManager = $GLOBALS['limpvix_transaction_manager'] ?? null;

            if (!$transactionManager) {
                // CRITICAL: Provider já executou, mas não podemos persistir
                // Log para reconciliation manual
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

                // 6. Retornar sucesso
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
                // Log para reconciliation
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
