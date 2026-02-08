<?php
/**
 * WpFinancialLedgerRepository - Repository para Financial Ledger
 *
 * RESPONSABILIDADE:
 * - Persistir eventos financeiros em wp_limpvix_financial_ledger
 * - Garantir append-only (sem UPDATE, sem DELETE)
 * - Queries otimizadas para auditoria
 * - Encapsular SQL (remover SQL direto de Use Cases)
 *
 * PRINCÍPIOS:
 * - Hexagonal Architecture (Adapter)
 * - Append-only (imutabilidade)
 * - Idempotência
 * - Performance (índices)
 *
 * CORREÇÃO: Bloqueador Arquitetural
 * - Remove SQL direto de RegisterBriefingAcceptance.php
 * - Remove SQL direto de StaffFinancialStatusResolver.php
 *
 * @package LimpVix\Infrastructure\Persistence
 */

namespace LimpVix\Infrastructure\Persistence;

defined('ABSPATH') || exit;

class WpFinancialLedgerRepository
{
    /**
     * Nome da tabela
     */
    private const TABLE_NAME = 'limpvix_financial_ledger';

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
     * Adicionar evento ao ledger
     *
     * @param array $data Dados do evento [order_uuid, event_type, customer_id, professional_id, event_data, etc.]
     * @return int ID da entrada criada
     * @throws \RuntimeException
     */
    public function append(array $data): int
    {
        // Validar campos obrigatórios
        if (empty($data['event_type'])) {
            throw new \InvalidArgumentException('event_type é obrigatório');
        }

        // Gerar ledger_uuid se não fornecido
        if (empty($data['ledger_uuid'])) {
            $data['ledger_uuid'] = $this->generateUuid();
        }

        // Preparar dados para insert
        $insertData = [
            'ledger_uuid' => $data['ledger_uuid'],
            'order_uuid' => $data['order_uuid'] ?? null,
            'customer_id' => $data['customer_id'] ?? null,
            'professional_id' => $data['professional_id'] ?? null,
            'appointment_id' => $data['appointment_id'] ?? null,
            'event_type' => $data['event_type'],
            'event_data' => isset($data['event_data']) ? (is_string($data['event_data']) ? $data['event_data'] : json_encode($data['event_data'])) : null,
            'resolved' => $data['resolved'] ?? 0,
            'created_at' => $data['created_at'] ?? current_time('mysql'),
        ];

        $format = ['%s', '%s', '%d', '%d', '%d', '%s', '%s', '%d', '%s'];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $result = $this->wpdb->insert($this->table, $insertData, $format);

        if ($result === false) {
            throw new \RuntimeException(
                "Erro ao adicionar evento ao ledger: {$this->wpdb->last_error}"
            );
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Verificar se existe evento específico
     *
     * @param string $orderUuid
     * @param string $eventType
     * @return bool
     */
    public function hasEvent(string $orderUuid, string $eventType): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE order_uuid = %s AND event_type = %s",
                $orderUuid,
                $eventType
            )
        );

        return $count > 0;
    }

    /**
     * Buscar evento mais recente por order e tipo
     *
     * @param string $orderUuid
     * @param string $eventType
     * @return array|null
     */
    public function findLatestEvent(string $orderUuid, string $eventType): ?array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE order_uuid = %s AND event_type = %s
                 ORDER BY id DESC LIMIT 1",
                $orderUuid,
                $eventType
            ),
            ARRAY_A
        );

        if (!is_array($result)) {
            return null;
        }

        // Decodificar JSON se existir
        if (!empty($result['event_data'])) {
            $result['event_data'] = json_decode($result['event_data'], true);
        }

        return $result;
    }

    /**
     * Buscar todos eventos de uma order
     *
     * @param string $orderUuid
     * @return array
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

        // Decodificar JSON
        return array_map(function($row) {
            if (!empty($row['event_data'])) {
                $row['event_data'] = json_decode($row['event_data'], true);
            }
            return $row;
        }, $results);
    }

    /**
     * Contar eventos de um profissional por tipo
     *
     * @param int $professionalId
     * @param string $eventType
     * @param bool|null $resolved NULL = todos, true = resolvidos, false = não resolvidos
     * @return int
     */
    public function countByProfessional(int $professionalId, string $eventType, ?bool $resolved = null): int
    {
        $sql = "SELECT COUNT(*) FROM {$this->table}
                WHERE professional_id = %d AND event_type = %s";

        $params = [$professionalId, $eventType];

        if ($resolved !== null) {
            $sql .= " AND resolved = %d";
            $params[] = $resolved ? 1 : 0;
        }

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $this->wpdb->get_var(
            $this->wpdb->prepare($sql, ...$params)
        );
    }

    /**
     * Buscar último evento de um profissional por tipo
     *
     * @param int $professionalId
     * @param string $eventType
     * @return array|null
     */
    public function findLatestByProfessional(int $professionalId, string $eventType): ?array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                 WHERE professional_id = %d AND event_type = %s
                 ORDER BY id DESC LIMIT 1",
                $professionalId,
                $eventType
            ),
            ARRAY_A
        );

        if (!is_array($result)) {
            return null;
        }

        // Decodificar JSON
        if (!empty($result['event_data'])) {
            $result['event_data'] = json_decode($result['event_data'], true);
        }

        return $result;
    }

    /**
     * Buscar disputas ativas de um profissional
     *
     * @param int $professionalId
     * @return array [active, resolved]
     */
    public function getDisputeStats(int $professionalId): array
    {
        $active = $this->countByProfessional($professionalId, 'dispute_opened', false);
        $resolved = $this->countByProfessional($professionalId, 'dispute_opened', true);

        return [
            'active' => $active,
            'resolved' => $resolved,
        ];
    }

    /**
     * Gerar UUID único
     *
     * @return string
     */
    private function generateUuid(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}
