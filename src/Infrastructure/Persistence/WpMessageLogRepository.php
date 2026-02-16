<?php
/**
 * WpMessageLogRepository - Infrastructure
 *
 * RESPONSABILIDADE:
 * - Persistir histórico de mensagens em wp_limpvix_message_log
 * - APPEND-ONLY: nunca DELETE, sempre INSERT
 * - Buscar mensagens por filtros (status, recipient, event_id, etc)
 *
 * @package LimpVix\Infrastructure\Persistence
 * @since 0.3.0
 */

namespace LimpVix\Infrastructure\Persistence;

defined('ABSPATH') || exit;

class WpMessageLogRepository
{
    private $wpdb;
    private $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_message_log';
    }

    /**
     * Salvar entrada no log (APPEND-ONLY)
     *
     * @param array $data Dados da mensagem
     *   - message_id: string
     *   - template_id: string
     *   - template_version: string
     *   - recipient: string
     *   - channel: string
     *   - content: string (nullable)
     *   - status: string (pending|sent|delivered|read|failed)
     *   - retry_count: int
     *   - sent_at: string (nullable)
     *   - delivered_at: string (nullable)
     *   - read_at: string (nullable)
     *   - failed_at: string (nullable)
     *   - event_id: string (nullable)
     *   - event_type: string (nullable)
     *   - user_id: int (nullable)
     *   - error_type: string (nullable)
     *   - error_message: string (nullable)
     *   - provider_response: array (nullable)
     * @return int|false ID do registro inserido ou false
     */
    public function save(array $data)
    {
        $defaults = [
            'retry_count' => 0,
            'sent_at' => null,
            'delivered_at' => null,
            'read_at' => null,
            'failed_at' => null,
            'event_id' => null,
            'event_type' => null,
            'user_id' => null,
            'error_type' => null,
            'error_message' => null,
            'provider_response' => null,
            'content' => null,
            'created_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        // Converter provider_response para JSON se for array
        if (isset($data['provider_response']) && is_array($data['provider_response'])) {
            $data['provider_response'] = json_encode($data['provider_response']);
        }

        $result = $this->wpdb->insert(
            $this->table,
            $data,
            [
                '%s', // message_id
                '%s', // template_id
                '%s', // template_version
                '%s', // recipient
                '%s', // channel
                '%s', // content
                '%s', // status
                '%d', // retry_count
                '%s', // sent_at
                '%s', // delivered_at
                '%s', // read_at
                '%s', // failed_at
                '%s', // event_id
                '%s', // event_type
                '%d', // user_id
                '%s', // error_type
                '%s', // error_message
                '%s', // provider_response
                '%s', // created_at
            ]
        );

        if ($result === false) {
            error_log('[LimpVix] Failed to save message log: ' . $this->wpdb->last_error);
            return false;
        }

        return $this->wpdb->insert_id;
    }

    /**
     * Atualizar status de mensagem existente
     *
     * IMPORTANTE: Usar apenas para atualizar status de entrega (sent → delivered → read)
     * NÃO usar para modificar dados históricos
     *
     * @param string $messageId ID da mensagem
     * @param array $updates Campos para atualizar
     * @return bool
     */
    public function updateStatus(string $messageId, array $updates): bool
    {
        $allowed_fields = ['status', 'delivered_at', 'read_at', 'provider_response'];
        $safe_updates = array_intersect_key($updates, array_flip($allowed_fields));

        if (empty($safe_updates)) {
            return false;
        }

        // Converter provider_response para JSON se for array
        if (isset($safe_updates['provider_response']) && is_array($safe_updates['provider_response'])) {
            $safe_updates['provider_response'] = json_encode($safe_updates['provider_response']);
        }

        $result = $this->wpdb->update(
            $this->table,
            $safe_updates,
            ['message_id' => $messageId],
            null, // Formato automático
            ['%s']
        );

        return $result !== false;
    }

    /**
     * Buscar mensagem por ID
     *
     * @param string $messageId ID da mensagem
     * @return array|null
     */
    public function findByMessageId(string $messageId): ?array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table} WHERE message_id = %s LIMIT 1",
            $messageId
        );

        $row = $this->wpdb->get_row($sql, ARRAY_A);

        if (!$row) {
            return null;
        }

        return $this->parseRow($row);
    }

    /**
     * Buscar mensagens por evento
     *
     * @param string $eventId ID do evento de domínio
     * @return array
     */
    public function findByEventId(string $eventId): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE event_id = %s
             ORDER BY created_at DESC",
            $eventId
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'parseRow'], $rows);
    }

    /**
     * Buscar mensagens por recipient
     *
     * @param string $recipient Destinatário (phone/email)
     * @param int $limit Limite de resultados
     * @return array
     */
    public function findByRecipient(string $recipient, int $limit = 50): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE recipient = %s
             ORDER BY created_at DESC
             LIMIT %d",
            $recipient,
            $limit
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'parseRow'], $rows);
    }

    /**
     * Buscar mensagens falhadas com retry disponível
     *
     * @param int $limit Limite de resultados
     * @return array
     */
    public function findFailedWithRetry(int $limit = 50): array
    {
        $sql = $this->wpdb->prepare(
            "SELECT * FROM {$this->table}
             WHERE status = 'failed'
             AND retry_count < 3
             ORDER BY failed_at DESC
             LIMIT %d",
            $limit
        );

        $rows = $this->wpdb->get_results($sql, ARRAY_A);

        if (!$rows) {
            return [];
        }

        return array_map([$this, 'parseRow'], $rows);
    }

    /**
     * Contar mensagens por status
     *
     * @param string $status Status (pending|sent|delivered|read|failed)
     * @param \DateTimeImmutable|null $since Data inicial (opcional)
     * @return int
     */
    public function countByStatus(string $status, ?\DateTimeImmutable $since = null): int
    {
        if ($since) {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE status = %s
                 AND created_at >= %s",
                $status,
                $since->format('Y-m-d H:i:s')
            );
        } else {
            $sql = $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                 WHERE status = %s",
                $status
            );
        }

        return (int) $this->wpdb->get_var($sql);
    }

    /**
     * Parse row do banco (decodifica JSON)
     *
     * @param array $row Linha do banco
     * @return array
     */
    private function parseRow(array $row): array
    {
        if (isset($row['provider_response']) && !empty($row['provider_response'])) {
            $row['provider_response'] = json_decode($row['provider_response'], true);
        }

        return $row;
    }
}
