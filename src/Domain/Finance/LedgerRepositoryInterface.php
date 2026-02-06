<?php
/**
 * LedgerRepositoryInterface - Port para Persistência do Ledger
 *
 * RESPONSABILIDADE:
 * - Contrato mínimo para persistência de ledger
 * - Append-only (somente escrita)
 * - Queries para auditoria
 *
 * PRINCÍPIOS:
 * - Port (Hexagonal Architecture)
 * - Append-only (sem update, sem delete)
 * - Idempotência
 * - Auditabilidade
 *
 * OPERAÇÕES:
 * - append(): Adicionar novo registro (nunca falha por duplicação)
 * - findByOrder(): Obter histórico completo de uma order
 * - findByUuid(): Obter registro específico
 * - exists(): Verificar se registro já existe (idempotência)
 *
 * ⚠️ PROIBIDO:
 * - update()
 * - delete()
 * - truncate()
 *
 * PASSO 5.2 - Ledger Imutável
 *
 * @package LimpVix\Domain\Finance
 */

namespace LimpVix\Domain\Finance;

defined('ABSPATH') || exit;

interface LedgerRepositoryInterface
{
    /**
     * Adicionar entrada ao ledger
     *
     * Operação idempotente: se ledger_uuid já existe, ignora silenciosamente
     *
     * @param LedgerEntry $entry Entrada a ser gravada
     * @return void
     * @throws \RuntimeException Em caso de erro de persistência
     */
    public function append(LedgerEntry $entry): void;

    /**
     * Buscar histórico completo de uma order
     *
     * Retorna todas as transições em ordem cronológica
     *
     * @param string $orderUuid UUID da order
     * @return LedgerEntry[] Array de entradas (vazio se não encontrado)
     */
    public function findByOrder(string $orderUuid): array;

    /**
     * Buscar entrada específica por UUID
     *
     * @param string $ledgerUuid UUID da entrada
     * @return LedgerEntry|null Entrada ou null se não encontrada
     */
    public function findByUuid(string $ledgerUuid): ?LedgerEntry;

    /**
     * Verificar se entrada já existe
     *
     * Útil para idempotência antes de append
     *
     * @param string $ledgerUuid UUID da entrada
     * @return bool True se existe
     */
    public function exists(string $ledgerUuid): bool;

    /**
     * Obter última transição de uma order
     *
     * Útil para reconstrução de estado atual
     *
     * @param string $orderUuid UUID da order
     * @return LedgerEntry|null Última entrada ou null
     */
    public function findLatestByOrder(string $orderUuid): ?LedgerEntry;

    /**
     * Contar registros de uma order
     *
     * Útil para métricas e validação
     *
     * @param string $orderUuid UUID da order
     * @return int Quantidade de transições
     */
    public function countByOrder(string $orderUuid): int;

    /**
     * Buscar transições por estado de destino
     *
     * Útil para auditoria (ex: todas as orders que chegaram a TRANSFERRED)
     *
     * @param FinancialStatus $status Estado de destino
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return LedgerEntry[] Array de entradas
     */
    public function findByToStatus(FinancialStatus $status, int $limit = 100, int $offset = 0): array;

    /**
     * Buscar transições por ator
     *
     * Útil para auditoria de ações humanas/sistema
     *
     * @param string $actor Nome do ator (system, admin, customer)
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return LedgerEntry[] Array de entradas
     */
    public function findByActor(string $actor, int $limit = 100, int $offset = 0): array;

    /**
     * Buscar transições em um período
     *
     * Útil para relatórios financeiros
     *
     * @param \DateTime $from Data inicial
     * @param \DateTime $to Data final
     * @param int $limit Limite de resultados
     * @param int $offset Offset para paginação
     * @return LedgerEntry[] Array de entradas
     */
    public function findByDateRange(\DateTime $from, \DateTime $to, int $limit = 100, int $offset = 0): array;
}
