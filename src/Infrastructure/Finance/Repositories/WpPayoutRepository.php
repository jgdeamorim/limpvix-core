<?php
/**
 * WpPayoutRepository
 *
 * Repository para gerenciar payouts (repasses financeiros)
 *
 * CRITICAL FIX (Finance Core Stabilization Sprint):
 * - Now implements PayoutRepositoryInterface (DIP compliance)
 * - Application layer (ExecutePayout) now depends on interface
 * - Infrastructure implementation can be swapped without breaking use cases
 *
 * @package LimpVix\Infrastructure\Finance\Repositories
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Finance\Repositories;

use LimpVix\Domain\Finance\PayoutRepositoryInterface;

class WpPayoutRepository implements PayoutRepositoryInterface
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
        // Join with orders table to get order UUID
        $ordersTable = $this->wpdb->prefix . 'limpvix_orders';

        $results = $this->wpdb->get_results(
            $this->wpdb->prepare(
                "SELECT p.* FROM {$this->table} p
                 INNER JOIN {$ordersTable} o ON p.order_uuid = o.uuid
                 WHERE o.id = %d
                 ORDER BY p.created_at DESC",
                $order_id
            ),
            ARRAY_A
        );

        // Return first result or empty array if none found
        return !empty($results) ? $results[0] : [];
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
        // OTIMIZAÇÃO: UMA query com agregações (7 queries → 1 query)
        $sql = "SELECT
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as total_pending,
            COUNT(CASE WHEN status = 'approved' THEN 1 END) as total_approved,
            COUNT(CASE WHEN status = 'processing' THEN 1 END) as total_processing,
            COUNT(CASE WHEN status = 'completed' THEN 1 END) as total_completed,
            COUNT(CASE WHEN status = 'failed' THEN 1 END) as total_failed,
            SUM(CASE WHEN status = 'pending' THEN net_amount ELSE 0 END) as amount_pending,
            SUM(CASE WHEN status = 'completed' THEN net_amount ELSE 0 END) as amount_completed
        FROM {$this->table}";

        $result = $this->wpdb->get_row($sql, ARRAY_A);

        return [
            'total_pending' => (int) ($result['total_pending'] ?? 0),
            'total_approved' => (int) ($result['total_approved'] ?? 0),
            'total_processing' => (int) ($result['total_processing'] ?? 0),
            'total_completed' => (int) ($result['total_completed'] ?? 0),
            'total_failed' => (int) ($result['total_failed'] ?? 0),
            'amount_pending' => (float) ($result['amount_pending'] ?? 0.0),
            'amount_completed' => (float) ($result['amount_completed'] ?? 0.0),
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
