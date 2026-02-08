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
     * FLUXO:
     * 1. Buscar payout no banco
     * 2. Buscar Execution correspondente
     * 3. ✅ GOLDEN RULE: VALIDAR Execution::VALIDATED
     * 4. Se validado, executar payout via Provider
     * 5. Retornar Result com status
     *
     * REGRA CRÍTICA:
     * - Payout SÓ executa se Execution::VALIDATED
     * - Caso contrário, Result::fail com mensagem clara
     *
     * @param int $payoutId ID do payout
     * @return Result<array, string>
     */
    public function execute(int $payoutId): Result
    {
        try {
            // 1. Buscar payout
            $payout = $this->payoutRepository->getById($payoutId);

            if (!$payout) {
                return Result::fail(sprintf(
                    'Payout #%d não encontrado',
                    $payoutId
                ));
            }

            // 2. Buscar Execution correspondente
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

            // 4. Executar payout no MercadoPago
            $success = $this->payoutProvider->createPayout($payoutId);

            if (!$success) {
                return Result::fail(sprintf(
                    'Failed to create payout #%d on MercadoPago. Check error logs for details.',
                    $payoutId
                ));
            }

            // 5. Retornar sucesso
            return Result::ok([
                'payout_id' => $payoutId,
                'order_uuid' => $payout['order_uuid'],
                'execution_uuid' => $execution->getExecutionUuid(),
                'execution_status' => $execution->getStatus()->value,
                'status' => 'processing',
                'message' => 'Payout created successfully on MercadoPago'
            ]);

        } catch (\Exception $e) {
            return Result::fail(sprintf(
                'Unexpected error during payout execution: %s',
                $e->getMessage()
            ));
        }
    }
}
