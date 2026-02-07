<?php
/**
 * WpMessageQueueRepository - Infrastructure
 *
 * RESPONSABILIDADE:
 * - Gerenciar queue de retry em wp_limpvix_message_queue
 * - Enfileirar mensagens para retry
 * - Buscar mensagens pendentes para processamento
 * - Limpar itens antigos (>30 dias)
 *
 * @package LimpVix\Infrastructure\Persistence
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Persistence;

defined('ABSPATH') || exit;

class WpMessageQueueRepository
{
    private $wpdb;
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_message_queue';
    }

    /**
     * Enfileirar mensagem para retry
     *
     * @param array $data Dados da mensagem
     *   - message_id: string
     *   - template_id: string
     *   - recipient: string
     *   - variables: array (será convertido para JSON)
     *   - event_id: string (nullable)
     *   - event_type: string (nullable)
     *   - user_id: int (nullable)
     *   - retry_count: int
     *   - scheduled_at: string (Y-m-d H:i:s)
     * @return int|false ID do item enfileirado ou false
     */
    public function enqueue(array $data)
    {
        $defaults = [
            'event_id' => null,
            'event_type' => null,
            'user_id' => null,
            'status' => 'pending',
            'created_at' => current_time('mysql'),
            'processed_at' => null,
        ];

        $data = wp_parse_args($data, $defaults);

        // Converter variables para JSON se for array
        if (isset($data['variables']) && is_array($data['variables'])) {
            $data['variables'] = json_encode($data['variables']);
        }

        $result = $this->wpdb->insert(
            $this->table,
            $data,
            [
                '%s', // message_id
                '%s', // template_id
                '%s', // recipient
                '%s', // variables
                '%s', // event_id
                '%s', // event_type
                '%d', // user_id
                '%d', // retry_count
                '%s', // scheduled_at
                '%s', // status
                '%s', // created_at
                '%s', // processed_at
            ]
        );

        if ($result === false) {
            error_log('[LimpVix] Failed to enqueue message: ' . $this->wpdb->last_error);
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Buscar item da queue por ID
     *
     * @param int $queueId ID do item
     * @return array|null
     */
    public function findById(int $queueId): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE id = %d LIMIT 1",
            $queueId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->parseRow($row);
    }

    /**
     * Buscar mensagens pendentes para processamento
     *
     * @param int $limit Limite de itens
     * @return array
     */
    public function findPending(int $limit = 10): array
    {
        $now = current_time('mysql');

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'pending'
             AND scheduled_at <= %s
             ORDER BY scheduled_at ASC
             LIMIT %d",
            $now,
            $limit
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'parseRow'], $rows);
    }

    /**
     * Atualizar status de item da queue
     *
     * @param int $queueId ID do item
     * @param string $status Status (pending|processing|completed|failed)
     * @return bool
     */
    public function updateStatus(int $queueId, string $status): bool
    {
        $updates = ['status' => $status];

        // Se completado ou falhado, registrar timestamp
        if (in_array($status, ['completed', 'failed'], true)) {
            $updates['processed_at'] = current_time('mysql');
        }

        $result = $this->wpdb->update(
            $this->table,
            $updates,
            ['id' => $queueId],
            null, // Formato automático
            ['%d']
        );

        return $result !== false;
    }

    /**
     * Deletar itens antigos (completed/failed > N dias)
     *
     * @param int $daysOld Idade em dias (default: 30)
     * @return int Número de itens removidos
     */
    public function deleteOlderThan(int $daysOld = 30): int
    {
        $cutoffDate = (new \DateTimeImmutable())
            ->modify("-{$daysOld} days")
            ->format('Y-m-d H:i:s');

        $sql = $this->wpdb->prepare(
            "DELETE FROM {$this->table}
             WHERE status IN ('completed', 'failed')
             AND processed_at IS NOT NULL
             AND processed_at < %s",
            $cutoffDate
        );

        $this->wpdb->query($sql);

        return $this->wpdb->rows_affected;
    }

    /**
     * Contar itens por status
     *
     * @param string $status Status (pending|processing|completed|failed)
     * @return int
     */
    public function countByStatus(string $status): int
    {
        $sql = $this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = %s",
            $status
        );

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Buscar itens travados (processing > 10 minutos)
     *
     * Útil para recuperação de itens que falharam sem atualizar status
     *
     * @return array
     */
    public function findStuck(): array
    {
        $cutoff = (new \DateTimeImmutable())
            ->modify('-10 minutes')
            ->format('Y-m-d H:i:s');

        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'processing'
             AND created_at < %s
             ORDER BY created_at ASC",
            $cutoff
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'parseRow'], $rows);
    }

    /**
     * Resetar itens travados para pending
     *
     * @return int Número de itens resetados
     */
    public function resetStuck(): int
    {
        $cutoff = (new \DateTimeImmutable())
            ->modify('-10 minutes')
            ->format('Y-m-d H:i:s');

        $sql = $this->wpdb->prepare(
            "UPDATE {$this->table}
             SET status = 'pending'
             WHERE status = 'processing'
             AND created_at < %s",
            $cutoff
        );

        $this->wpdb->query($sql);

        return $this->wpdb->rows_affected;
    }

    /**
     * Parse row do banco (decodifica JSON)
     *
     * @param array $row Linha do banco
     * @return array
     */
    private function parseRow(array $row): array
    {
        if (isset($row['variables']) && !empty($row['variables'])) {
            $row['variables'] = json_decode($row['variables'], true);
        }

        return $row;
    }
}
