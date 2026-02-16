<?php
/**
 * AppendLedgerEntry - Use Case para Gravar no Ledger
 *
 * RESPONSABILIDADE:
 * - Receber FinancialTransitionEvent válido
 * - Converter para LedgerEntry
 * - Persistir no Ledger
 * - Garantir idempotência
 *
 * PRINCÍPIOS:
 * - Confia no Domain (evento já foi validado)
 * - Idempotente (pode executar múltiplas vezes)
 * - Fail-safe (nunca lança exceção por duplicação)
 * - Auditável (log de todas as operações)
 *
 * IMPORTANTE:
 * - Este Use Case NÃO valida regras de negócio
 * - Validação já foi feita em FinancialPolicy
 * - Se recebeu o evento, confia que é válido
 * - Única responsabilidade: gravar fato histórico
 *
 * FLUXO:
 * 1. Receber FinancialTransitionEvent
 * 2. Converter para LedgerEntry
 * 3. Verificar se já existe (idempotência)
 * 4. Gravar no repository
 * 5. Log de auditoria
 *
 * USO:
 * ```php
 * // Após decisão financeira aprovada
 * $event = new FinancialTransitionEvent(...);
 *
 * $useCase = new AppendLedgerEntry($ledgerRepo);
 * $useCase->execute($event);
 * ```
 *
 * PASSO 5.2 - Ledger Imutável
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Domain\Finance\FinancialTransitionEvent;
use LimpVix\Domain\Finance\LedgerEntry;
use LimpVix\Domain\Finance\LedgerRepositoryInterface;

defined('ABSPATH') || exit;

class AppendLedgerEntry
{
    /**
     * Repository do Ledger
     *
     * @var LedgerRepositoryInterface
     */
    private $ledgerRepository;

    /**
     * Construtor
     *
     * @param LedgerRepositoryInterface $ledgerRepository
     */
    public function __construct(LedgerRepositoryInterface $ledgerRepository)
    {
        $this->ledgerRepository = $ledgerRepository;
    }

    /**
     * Executar use case
     *
     * @param FinancialTransitionEvent $event Evento válido (já passou pelo Domain)
     * @return void
     * @throws \RuntimeException Em caso de erro crítico de persistência
     */
    public function execute(FinancialTransitionEvent $event): void
    {
        // 1. Converter evento para entrada do ledger
        $entry = LedgerEntry::fromEvent($event);

        // 2. Verificar idempotência (opcional, INSERT IGNORE já garante)
        // Mas permite log diferenciado
        $ledgerUuid = $entry->getLedgerUuid();
        $alreadyExists = $this->ledgerRepository->exists($ledgerUuid);

        if ($alreadyExists) {
            // Não é erro - idempotência é esperada
            $this->logIdempotent($entry);
            return;
        }

        // 3. Persistir no ledger
        try {
            $this->ledgerRepository->append($entry);
            $this->logSuccess($entry);
        } catch (\RuntimeException $e) {
            // Erro crítico de persistência
            $this->logError($entry, $e);
            throw $e;
        }
    }

    /**
     * Log de sucesso
     *
     * @param LedgerEntry $entry
     * @return void
     */
    private function logSuccess(LedgerEntry $entry): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_ledger_appended', [
            'ledger_uuid' => $entry->getLedgerUuid(),
            'order_uuid' => $entry->getOrderUuid(),
            'transition' => sprintf(
                '%s → %s',
                $entry->getFromStatus()->getValue(),
                $entry->getToStatus()->getValue()
            ),
            'reason' => $entry->getReason(),
            'actor' => $entry->getActor(),
            'timestamp' => $entry->getCreatedAt()->format('Y-m-d H:i:s')
        ]);
    }

    /**
     * Log de idempotência
     *
     * @param LedgerEntry $entry
     * @return void
     */
    private function logIdempotent(LedgerEntry $entry): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_ledger_idempotent', [
            'ledger_uuid' => $entry->getLedgerUuid(),
            'order_uuid' => $entry->getOrderUuid(),
            'message' => 'Entrada já existe no ledger (idempotência)'
        ]);
    }

    /**
     * Log de erro
     *
     * @param LedgerEntry $entry
     * @param \RuntimeException $exception
     * @return void
     */
    private function logError(LedgerEntry $entry, \RuntimeException $exception): void
    {
        if (!function_exists('do_action')) {
            return;
        }

        do_action('limpvix_ledger_error', [
            'ledger_uuid' => $entry->getLedgerUuid(),
            'order_uuid' => $entry->getOrderUuid(),
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString()
        ]);
    }
}
