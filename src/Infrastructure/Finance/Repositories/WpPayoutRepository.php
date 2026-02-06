<?php
/**
 * WpPayoutRepository
 *
 * Repository para gerenciar payouts (repasses financeiros)
 *
 * @package LimpVix\Infrastructure\Finance\Repositories
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Finance\Repositories;

class WpPayoutRepository
{
    private \wpdb $wpdb;
    private string $table;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->table = $wpdb->prefix . 'limpvix_payouts';
    }

    /**
     * Criar novo payout
     *
     * @param array $data Dados do payout
     * @return int ID do payout criado
     */
    public function create(array $data): int
    {
        $defaults = [
            'order_id' => 0,
            'professional_id' => 0,
            'ledger_event_id' => null,
            'gross_amount' => 0.00,
            'platform_fee' => 0.00,
            'net_amount' => 0.00,
            'status' => 'pending',
            'gateway' => 'mercadopago',
            'gateway_transfer_id' => null,
            'gateway_response' => null,
            'recipient_type' => 'pix',
            'recipient_key' => '',
            'recipient_name' => '',
            'recipient_document' => '',
            'approved_at' => null,
            'processed_at' => null,
            'completed_at' => null,
            'failed_at' => null,
            'failure_reason' => null,
            'retry_count' => 0,
            'max_retries' => 3,
            'created_at' => current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        $data = wp_parse_args($data, $defaults);

        $this->wpdb->insert($this->table, $data);

        return $this->wpdb->insert_id;
    }

    /**
     * Buscar payout por ID
     *
     * @param int $id
     * @return array|null
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
     * Buscar payouts de uma order
     *
     * @param int $order_id
     * @return array
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
     * Buscar payouts de um profissional
     *
     * @param int $professional_id
     * @param string|null $status Filtrar por status
     * @return array
     */
    public function getByProfessional(int $professional_id, ?string $status = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE professional_id = %d";
        $params = [$professional_id];

        if ($status) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        $sql .= " ORDER BY created_at DESC";

        return $this->wpdb->get_results(
            $this->wpdb->prepare($sql, ...$params),
            ARRAY_A
        );
    }

    /**
     * Buscar payouts por status
     *
     * @param string $status
     * @param int $limit
     * @return array
     */
    public function getByStatus(string $status, int $limit = 100): array
    {
        return $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE status = %s ORDER BY created_at ASC LIMIT %d",
                $status,
                $limit
            ),
            ARRAY_A
        );
    }

    /**
     * Buscar payouts pendentes de processamento
     *
     * @return array
     */
    public function getPendingPayouts(): array
    {
        return $this->getByStatus('approved', 50);
    }

    /**
     * Buscar payouts que falharam e podem ser retentados
     *
     * @return array
     */
    public function getRetriablePayouts(): array
    {
        return $this->wpdb->get_results(
            "SELECT * FROM {$this->table} 
            WHERE status = 'failed' 
            AND retry_count < max_retries 
            ORDER BY created_at ASC 
            LIMIT 20",
            ARRAY_A
        );
    }

    /**
     * Atualizar status do payout
     *
     * @param int $id
     * @param string $new_status
     * @param string|null $gateway_response
     * @return bool
     */
    public function updateStatus(int $id, string $new_status, ?string $gateway_response = null): bool
    {
        $data = [
            'status' => $new_status,
            'updated_at' => current_time('mysql'),
        ];

        // Timestamps específicos por status
        switch ($new_status) {
            case 'approved':
                $data['approved_at'] = current_time('mysql');
                break;
            case 'processing':
                $data['processed_at'] = current_time('mysql');
                break;
            case 'completed':
                $data['completed_at'] = current_time('mysql');
                break;
            case 'failed':
                $data['failed_at'] = current_time('mysql');
                break;
        }

        if ($gateway_response !== null) {
            $data['gateway_response'] = $gateway_response;
        }

        return (bool) $this->wpdb->update(
            $this->table,
            $data,
            ['id' => $id],
            ['%s', '%s', '%s'],
            ['%d']
        );
    }

    /**
     * Registrar falha de payout
     *
     * @param int $id
     * @param string $failure_reason
     * @return bool
     */
    public function registerFailure(int $id, string $failure_reason): bool
    {
        return (bool) $this->wpdb->update(
            $this->table,
            [
                'status' => 'failed',
                'failed_at' => current_time('mysql'),
                'failure_reason' => $failure_reason,
                'retry_count' => $this->wpdb->get_var(
                    $this->wpdb->prepare("SELECT retry_count FROM {$this->table} WHERE id = %d", $id)
                ) + 1,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id]
        );
    }

    /**
     * Atualizar gateway_transfer_id após sucesso
     *
     * @param int $id
     * @param string $transfer_id
     * @return bool
     */
    public function setTransferId(int $id, string $transfer_id): bool
    {
        return (bool) $this->wpdb->update(
            $this->table,
            [
                'gateway_transfer_id' => $transfer_id,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id]
        );
    }

    /**
     * Buscar por gateway_transfer_id
     *
     * @param string $transfer_id
     * @return array|null
     */
    public function getByTransferId(string $transfer_id): ?array
    {
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table} WHERE gateway_transfer_id = %s",
                $transfer_id
            ),
            ARRAY_A
        );

        return $result ?: null;
    }

    /**
     * Calcular total de payouts por profissional
     *
     * @param int $professional_id
     * @param string|null $status
     * @return float
     */
    public function getTotalByProfessional(int $professional_id, ?string $status = null): float
    {
        $sql = "SELECT SUM(net_amount) FROM {$this->table} WHERE professional_id = %d";
        $params = [$professional_id];

        if ($status) {
            $sql .= " AND status = %s";
            $params[] = $status;
        }

        return (float) $this->wpdb->get_var(
            $this->wpdb->prepare($sql, ...$params)
        );
    }

    /**
     * Estatísticas de payouts
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'total_pending' => (int) $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = 'pending'"
            ),
            'total_approved' => (int) $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = 'approved'"
            ),
            'total_processing' => (int) $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = 'processing'"
            ),
            'total_completed' => (int) $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = 'completed'"
            ),
            'total_failed' => (int) $this->wpdb->get_var(
                "SELECT COUNT(*) FROM {$this->table} WHERE status = 'failed'"
            ),
            'amount_pending' => (float) $this->wpdb->get_var(
                "SELECT SUM(net_amount) FROM {$this->table} WHERE status = 'pending'"
            ),
            'amount_completed' => (float) $this->wpdb->get_var(
                "SELECT SUM(net_amount) FROM {$this->table} WHERE status = 'completed'"
            ),
        ];
    }

    /**
     * Deletar payout (uso interno apenas)
     *
     * @param int $id
     * @return bool
     */
    public function delete(int $id): bool
    {
        return (bool) $this->wpdb->delete(
            $this->table,
            ['id' => $id],
            ['%d']
        );
    }
}
