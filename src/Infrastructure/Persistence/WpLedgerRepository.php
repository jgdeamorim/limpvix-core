<?php
/**
 * WpLedgerRepository - Implementação WordPress do Ledger
 *
 * RESPONSABILIDADE:
 * - Persistir ledger em wp_limpvix_ledger
 * - Garantir append-only (sem update/delete)
 * - Queries otimizadas para auditoria
 *
 * PRINCÍPIOS:
 * - Adapter (Hexagonal Architecture)
 * - Append-only (imutabilidade)
 * - Idempotência (INSERT IGNORE)
 * - Performance (índices adequados)
 *
 * SEGURANÇA:
 * - ❌ SEM update()
 * - ❌ SEM delete()
 * - ❌ SEM truncate()
 * - ✅ Apenas INSERT
 * - ✅ Apenas SELECT
 *
 * PASSO 5.2 - Ledger Imutável
 *
 * @package LimpVix\Infrastructure\Persistence
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Finance\LedgerEntry;
use LimpVix\Domain\Finance\LedgerRepositoryInterface;
use LimpVix\Domain\Finance\FinancialStatus;

defined('ABSPATH') || exit;

class WpLedgerRepository implements LedgerRepositoryInterface
{
    /**
     * Nome da tabela
     */
    private const TABLE_NAME = 'limpvix_ledger';

    /**
     * Database handle
     *
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Nome completo da tabela (com prefixo)
     *
     * @var string
     */
    private $table;

    /**
     * Construtor
     */
    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . self::TABLE_NAME;
    }

    /**
     * Adicionar entrada ao ledger
     *
     * Usa INSERT IGNORE para idempotência
     *
     * @param LedgerEntry $entry
     * @return void
     * @throws \RuntimeException
     */
    public function append(LedgerEntry $entry): void
    {
        $data = $entry->toArray();

        // INSERT IGNORE = idempotente (duplicação de UUID não é erro)
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $this->wpdb->query(
            $this->wpdb->prepare(
                "INSERT IGNORE INTO {$this->table}
                (ledger_uuid, order_uuid, from_status, to_status, reason, actor, actor_id, created_at, payload_json)
                VALUES (%s, %s, %s, %s, %s, %s, %d, %s, %s)",
                $data['ledger_uuid'],
                $data['order_uuid'],
                $data['from_status'],
                $data['to_status'],
                $data['reason'],
                $data['actor'],
                $data['actor_id'],
                $data['created_at'],
                $data['payload_json']
            )
        );

        // Verificar erro (não confundir com duplicação, que é OK)
        if ($result === false && $this->wpdb->last_error) {
            throw new \RuntimeException(
                "Erro ao adicionar entrada ao ledger: {$this->wpdb->last_error}"
            );
        }
    }

    /**
     * Buscar histórico completo de uma order
     *
     * @param string $orderUuid
     * @return LedgerEntry[]
     */
    public function findByOrder(string $orderUuid): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE order_uuid = %s
                ORDER BY created_at ASC",
                $orderUuid
            ),
            ARRAY_A
        );

        if (!is_array($results)) {
            return [];
        }

        return array_map(
            fn($row) => LedgerEntry::fromDatabase($row),
            $results
        );
    }

    /**
     * Buscar entrada específica por UUID
     *
     * @param string $ledgerUuid
     * @return LedgerEntry|null
     */
    public function findByUuid(string $ledgerUuid): ?LedgerEntry
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE ledger_uuid = %s
                LIMIT 1",
                $ledgerUuid
            ),
            ARRAY_A
        );

        if (!is_array($result)) {
            return null;
        }

        return LedgerEntry::fromDatabase($result);
    }

    /**
     * Verificar se entrada existe
     *
     * @param string $ledgerUuid
     * @return bool
     */
    public function exists(string $ledgerUuid): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                WHERE ledger_uuid = %s",
                $ledgerUuid
            )
        );

        return $count > 0;
    }

    /**
     * Obter última transição de uma order
     *
     * @param string $orderUuid
     * @return LedgerEntry|null
     */
    public function findLatestByOrder(string $orderUuid): ?LedgerEntry
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE order_uuid = %s
                ORDER BY created_at DESC
                LIMIT 1",
                $orderUuid
            ),
            ARRAY_A
        );

        if (!is_array($result)) {
            return null;
        }

        return LedgerEntry::fromDatabase($result);
    }

    /**
     * Contar registros de uma order
     *
     * @param string $orderUuid
     * @return int
     */
    public function countByOrder(string $orderUuid): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                WHERE order_uuid = %s",
                $orderUuid
            )
        );
    }

    /**
     * Buscar transições por estado de destino
     *
     * @param FinancialStatus $status
     * @param int $limit
     * @param int $offset
     * @return LedgerEntry[]
     */
    public function findByToStatus(FinancialStatus $status, int $limit = 100, int $offset = 0): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE to_status = %s
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d",
                $status->getValue(),
                $limit,
                $offset
            ),
            ARRAY_A
        );

        if (!is_array($results)) {
            return [];
        }

        return array_map(
            fn($row) => LedgerEntry::fromDatabase($row),
            $results
        );
    }

    /**
     * Buscar transições por ator
     *
     * @param string $actor
     * @param int $limit
     * @param int $offset
     * @return LedgerEntry[]
     */
    public function findByActor(string $actor, int $limit = 100, int $offset = 0): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE actor = %s
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d",
                $actor,
                $limit,
                $offset
            ),
            ARRAY_A
        );

        if (!is_array($results)) {
            return [];
        }

        return array_map(
            fn($row) => LedgerEntry::fromDatabase($row),
            $results
        );
    }

    /**
     * Buscar transições em um período
     *
     * @param \DateTime $from
     * @param \DateTime $to
     * @param int $limit
     * @param int $offset
     * @return LedgerEntry[]
     */
    public function findByDateRange(\DateTime $from, \DateTime $to, int $limit = 100, int $offset = 0): array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE created_at BETWEEN %s AND %s
                ORDER BY created_at DESC
                LIMIT %d OFFSET %d",
                $from->format('Y-m-d H:i:s'),
                $to->format('Y-m-d H:i:s'),
                $limit,
                $offset
            ),
            ARRAY_A
        );

        if (!is_array($results)) {
            return [];
        }

        return array_map(
            fn($row) => LedgerEntry::fromDatabase($row),
            $results
        );
    }
}
