<?php
/**
 * MessageRepository
 *
 * Repository para gerenciar log de mensagens enviadas (SMS/WhatsApp)
 *
 * @package LimpVix\Infrastructure\Communication\Repositories
 * @since 0.1.2
 */

namespace LimpVix\Infrastructure\Communication\Repositories;

class MessageRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_messages_log';
    }

    /**
     * Criar novo registro de mensagem
     */
    public function create(array $data): int
    {
        $defaults = [
            'order_id' => null,
            'booking_id' => null,
            'recipient_phone' => '',
            'recipient_type' => 'client',
            'channel' => 'sms',
            'template_id' => '',
            'flow_id' => '',
            'message_content' => '',
            'status' => 'pending',
            'provider_response' => null,
            'sent_at' => null,
            'delivered_at' => null,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql')
        ];

        $data = wp_parse_args($data, $defaults);
        $this->wpdb->insert($this->table, $data);
        return $this->wpdb->insert_id;
    }

    /**
     * Buscar mensagem por ID
     */
    public function getById(int $id): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );
        return $result ?: null;
    }

    /**
     * Buscar mensagens por order_id
     */
    public function getByOrder(int $order_id): array
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE order_id = %d ORDER BY created_at DESC",
                $order_id
            ),
            ARRAY_A
        );
    }

    /**
     * Atualizar status da mensagem
     */
    public function updateStatus(int $id, string $status, ?string $provider_response = null): bool
    {
        $data = [
            'status' => $status,
            'updated_at' => current_time('mysql')
        ];

        if ($status === 'sent') {
            $data['sent_at'] = current_time('mysql');
        }

        if ($status === 'delivered') {
            $data['delivered_at'] = current_time('mysql');
        }

        if ($provider_response !== null) {
            $data['provider_response'] = $provider_response;
        }

        return $this->wpdb->update($this->table, $data, ['id' => $id]) !== false;
    }

    /**
     * Buscar mensagens falhadas
     */
    public function getFailedMessages(int $limit = 100): array
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = 'failed' ORDER BY created_at DESC LIMIT %d",
                $limit
            ),
            ARRAY_A
        );
    }
}
