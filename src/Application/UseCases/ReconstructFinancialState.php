<?php
/**
 * ReconstructFinancialState - Reconstruir Estado a Partir do Ledger
 *
 * RESPONSABILIDADE:
 * - Reconstruir estado financeiro atual de uma order
 * - Baseado APENAS no ledger (fonte da verdade)
 * - Validar consistência do histórico
 * - Detectar anomalias
 *
 * PRINCÍPIOS:
 * - Ledger = fonte da verdade
 * - Status na order = cache (pode estar dessincronizado)
 * - Auditoria retroativa
 * - Event Sourcing leve
 *
 * CASOS DE USO:
 * - Auditoria após disputa
 * - Verificação de integridade
 * - Debugging de inconsistências
 * - Compliance e relatórios
 * - Recuperação após corrupção de dados
 *
 * RETORNO:
 * - Estado atual reconstruído
 * - Histórico completo de transições
 * - Flags de anomalias (se houver)
 *
 * USO:
 * ```php
 * $useCase = new ReconstructFinancialState($ledgerRepo);
 * $result = $useCase->execute('550e8400-...');
 *
 * echo $result->getCurrentStatus()->getValue();  // AUTHORIZED
 * echo count($result->getHistory());              // 5 transições
 * echo $result->hasAnomalies();                   // false
 * ```
 *
 * PASSO 5.2 - Ledger Imutável (Extra/Recomendado)
 *
 * @package LimpVix\Application\UseCases
 */

namespace LimpVix\Application\UseCases;

use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Domain\Finance\LedgerEntry;
use LimpVix\Domain\Finance\LedgerRepositoryInterface;

defined('ABSPATH') || exit;

class ReconstructFinancialState
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
     * @param string $orderUuid UUID da order
     * @return ReconstructionResult Resultado da reconstrução
     * @throws \InvalidArgumentException Se order não tem histórico
     */
    public function execute(string $orderUuid): ReconstructionResult
    {
        // 1. Buscar histórico completo
        $history = $this->ledgerRepository->findByOrder($orderUuid);

        if (empty($history)) {
            throw new \InvalidArgumentException(
                "Order {$orderUuid} não tem histórico no ledger"
            );
        }

        // 2. Reconstruir estado
        $currentStatus = $this->reconstructStatus($history);

        // 3. Detectar anomalias
        $anomalies = $this->detectAnomalies($history);

        // 4. Retornar resultado
        return new ReconstructionResult(
            orderUuid: $orderUuid,
            currentStatus: $currentStatus,
            history: $history,
            anomalies: $anomalies
        );
    }

    /**
     * Reconstruir estado atual
     *
     * Percorre histórico cronológico e aplica transições
     *
     * @param LedgerEntry[] $history
     * @return FinancialStatus
     */
    private function reconstructStatus(array $history): FinancialStatus
    {
        // Última entrada no histórico = estado atual
        $lastEntry = end($history);

        if (!$lastEntry instanceof LedgerEntry) {
            throw new \RuntimeException('Histórico inválido: última entrada não é LedgerEntry');
        }

        return $lastEntry->getToStatus();
    }

    /**
     * Detectar anomalias no histórico
     *
     * Anomalias possíveis:
     * - Gaps de transição (from ≠ to anterior)
     * - Estados finais que transitaram
     * - Timestamps fora de ordem
     *
     * @param LedgerEntry[] $history
     * @return array Array de anomalias encontradas
     */
    private function detectAnomalies(array $history): array
    {
        $anomalies = [];

        for ($i = 0; $i < count($history); $i++) {
            $current = $history[$i];

            // Anomalia 1: Estado final que transitou
            if ($i > 0) {
                $previous = $history[$i - 1];
                $previousToStatus = $previous->getToStatus();

                if ($previousToStatus->isFinal()) {
                    $anomalies[] = [
                        'type' => 'final_state_transition',
                        'message' => sprintf(
                            'Estado final %s transitou para %s',
                            $previousToStatus->getValue(),
                            $current->getToStatus()->getValue()
                        ),
                        'entry' => $current->getLedgerUuid(),
                        'timestamp' => $current->getCreatedAt()->format('Y-m-d H:i:s')
                    ];
                }

                // Anomalia 2: Gap de transição
                $currentFromStatus = $current->getFromStatus();
                if (!$previousToStatus->equals($currentFromStatus)) {
                    $anomalies[] = [
                        'type' => 'transition_gap',
                        'message' => sprintf(
                            'Gap detectado: %s (anterior) ≠ %s (origem atual)',
                            $previousToStatus->getValue(),
                            $currentFromStatus->getValue()
                        ),
                        'entry' => $current->getLedgerUuid(),
                        'timestamp' => $current->getCreatedAt()->format('Y-m-d H:i:s')
                    ];
                }

                // Anomalia 3: Timestamps fora de ordem
                if ($current->getCreatedAt() < $previous->getCreatedAt()) {
                    $anomalies[] = [
                        'type' => 'timestamp_out_of_order',
                        'message' => sprintf(
                            'Timestamp atual (%s) anterior ao anterior (%s)',
                            $current->getCreatedAt()->format('Y-m-d H:i:s'),
                            $previous->getCreatedAt()->format('Y-m-d H:i:s')
                        ),
                        'entry' => $current->getLedgerUuid(),
                        'timestamp' => $current->getCreatedAt()->format('Y-m-d H:i:s')
                    ];
                }
            }
        }

        return $anomalies;
    }
}

/**
 * ReconstructionResult - Resultado da Reconstrução
 *
 * DTO que encapsula resultado da reconstrução de estado
 */
class ReconstructionResult
{
    /**
     * @var string
     */
    private $orderUuid;

    /**
     * @var FinancialStatus
     */
    private $currentStatus;

    /**
     * @var LedgerEntry[]
     */
    private $history;

    /**
     * @var array
     */
    private $anomalies;

    /**
     * Construtor
     *
     * @param string $orderUuid
     * @param FinancialStatus $currentStatus
     * @param LedgerEntry[] $history
     * @param array $anomalies
     */
    public function __construct(
        string $orderUuid,
        FinancialStatus $currentStatus,
        array $history,
        array $anomalies
    ) {
        $this->orderUuid = $orderUuid;
        $this->currentStatus = $currentStatus;
        $this->history = $history;
        $this->anomalies = $anomalies;
    }

    /**
     * Obter UUID da order
     *
     * @return string
     */
    public function getOrderUuid(): string
    {
        return $this->orderUuid;
    }

    /**
     * Obter estado atual reconstruído
     *
     * @return FinancialStatus
     */
    public function getCurrentStatus(): FinancialStatus
    {
        return $this->currentStatus;
    }

    /**
     * Obter histórico completo
     *
     * @return LedgerEntry[]
     */
    public function getHistory(): array
    {
        return $this->history;
    }

    /**
     * Obter anomalias detectadas
     *
     * @return array
     */
    public function getAnomalies(): array
    {
        return $this->anomalies;
    }

    /**
     * Verificar se tem anomalias
     *
     * @return bool
     */
    public function hasAnomalies(): bool
    {
        return !empty($this->anomalies);
    }

    /**
     * Obter quantidade de transições
     *
     * @return int
     */
    public function getTransitionCount(): int
    {
        return count($this->history);
    }

    /**
     * Obter primeira transição
     *
     * @return LedgerEntry|null
     */
    public function getFirstTransition(): ?LedgerEntry
    {
        return $this->history[0] ?? null;
    }

    /**
     * Obter última transição
     *
     * @return LedgerEntry|null
     */
    public function getLastTransition(): ?LedgerEntry
    {
        return end($this->history) ?: null;
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'order_uuid' => $this->orderUuid,
            'current_status' => $this->currentStatus->getValue(),
            'transition_count' => $this->getTransitionCount(),
            'has_anomalies' => $this->hasAnomalies(),
            'anomalies' => $this->anomalies,
            'history' => array_map(
                fn($entry) => $entry->toArray(),
                $this->history
            )
        ];
    }
}
