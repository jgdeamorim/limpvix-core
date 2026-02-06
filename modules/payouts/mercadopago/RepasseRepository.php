<?php
/**
 * RepasseRepository - Persistência e Idempotência de Payouts
 *
 * RESPONSABILIDADE:
 * - Garantir idempotência absoluta via payout_id
 * - Gravar resultado de execução (SUCCESS | FAILED)
 * - Queries de auditoria
 *
 * PRINCÍPIOS:
 * - Idempotência (payout_id único)
 * - Append-only (nunca UPDATE)
 * - Auditoria completa
 * - Performance (índices)
 *
 * REGRA DE OURO:
 * - Um payout_id só pode existir UMA VEZ
 * - Se existe com SUCCESS: bloqueado
 * - Se existe com FAILED: bloqueado (admin decide retry)
 *
 * PASSO 5.5 - Payout Engine
 *
 * @package LimpVix\Modules\Payouts\MercadoPago
 */

namespace LimpVix\Modules\Payouts\MercadoPago;

defined('ABSPATH') || exit;

class RepasseRepository
{
    /**
     * Nome da tabela
     */
    private const TABLE_NAME = 'limpvix_mp_payouts';

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
     * Verificar se payout já foi executado
     *
     * @param string $payoutId
     * @return bool True se já existe
     */
    public function exists(string $payoutId): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table} WHERE payout_id = %s",
                $payoutId
            )
        );

        return $count > 0;
    }

    /**
     * Obter status de um payout
     *
     * @param string $payoutId
     * @return string|null 'SUCCESS' | 'FAILED' | null
     */
    public function getStatus(string $payoutId): ?string
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $status = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT status FROM {$this->table} WHERE payout_id = %s LIMIT 1",
                $payoutId
            )
        );

        return $status ?: null;
    }

    /**
     * Gravar execução bem-sucedida
     *
     * @param Payout $payout
     * @param PayoutResult $result
     * @return void
     * @throws \RuntimeException
     */
    public function recordSuccess(Payout $payout, PayoutResult $result): void
    {
        // Verificar duplicação (proteção adicional)
        if ($this->exists($payout->getPayoutId())) {
            throw new \RuntimeException(
                "Payout {$payout->getPayoutId()} já foi executado (violação de idempotência)"
            );
        }

        $data = [
            'payout_id' => $payout->getPayoutId(),
            'order_uuid' => $payout->getMetadata()['order_uuid'] ?? '',
            'mp_transfer_id' => $result->getMpTransferId(),
            'amount' => $payout->getAmount(),
            'receiver_id' => $payout->getReceiverMpUserId(),
            'status' => 'SUCCESS',
            'error_code' => null,
            'error_message' => null,
            'http_status_code' => $result->getHttpStatusCode(),
            'created_at' => current_time('mysql'),
            'raw_response' => json_encode($result->getRawResponse())
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $this->wpdb->insert(
            $this->table,
            $data,
            ['%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException(
                "Falha ao gravar payout SUCCESS: {$this->wpdb->last_error}"
            );
        }
    }

    /**
     * Gravar execução com falha
     *
     * @param Payout $payout
     * @param PayoutResult $result
     * @return void
     * @throws \RuntimeException
     */
    public function recordFailure(Payout $payout, PayoutResult $result): void
    {
        // Verificar duplicação (proteção adicional)
        if ($this->exists($payout->getPayoutId())) {
            throw new \RuntimeException(
                "Payout {$payout->getPayoutId()} já foi executado (violação de idempotência)"
            );
        }

        $data = [
            'payout_id' => $payout->getPayoutId(),
            'order_uuid' => $payout->getMetadata()['order_uuid'] ?? '',
            'mp_transfer_id' => null,
            'amount' => $payout->getAmount(),
            'receiver_id' => $payout->getReceiverMpUserId(),
            'status' => 'FAILED',
            'error_code' => $result->getErrorCode(),
            'error_message' => $result->getErrorMessage(),
            'http_status_code' => $result->getHttpStatusCode(),
            'created_at' => current_time('mysql'),
            'raw_response' => json_encode($result->getRawResponse())
        ];

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $inserted = $this->wpdb->insert(
            $this->table,
            $data,
            ['%s', '%s', '%s', '%f', '%s', '%s', '%s', '%s', '%d', '%s', '%s']
        );

        if ($inserted === false) {
            throw new \RuntimeException(
                "Falha ao gravar payout FAILED: {$this->wpdb->last_error}"
            );
        }
    }

    /**
     * Buscar payouts por order
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
                ORDER BY created_at DESC",
                $orderUuid
            ),
            ARRAY_A
        );

        return is_array($results) ? $results : [];
    }

    /**
     * Buscar por MP Transfer ID
     *
     * @param string $mpTransferId
     * @return array|null
     */
    public function findByMpTransferId(string $mpTransferId): ?array
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT * FROM {$this->table}
                WHERE mp_transfer_id = %s
                LIMIT 1",
                $mpTransferId
            ),
            ARRAY_A
        );

        return is_array($result) ? $result : null;
    }

    /**
     * Contar payouts bem-sucedidos
     *
     * @return int
     */
    public function countSuccessful(): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'SUCCESS'"
        );
    }

    /**
     * Contar payouts com falha
     *
     * @return int
     */
    public function countFailed(): int
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        return (int) $this->wpdb->get_var(
            "SELECT COUNT(*) FROM {$this->table} WHERE status = 'FAILED'"
        );
    }

    /**
     * Obter total transferido com sucesso
     *
     * @return float
     */
    public function getTotalTransferred(): float
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $total = $this->wpdb->get_var(
            "SELECT SUM(amount) FROM {$this->table} WHERE status = 'SUCCESS'"
        );

        return $total ? (float) $total : 0.0;
    }
}
