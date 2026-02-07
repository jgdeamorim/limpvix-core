<?php
/**
 * WpOrderRepository - Implementação WordPress para Orders
 *
 * RESPONSABILIDADE:
 * - Acesso a orders via wp_limpvix_orders
 * - Bridge com WooCommerce (se necessário)
 * - Atualização de status financeiro (cache)
 *
 * PRINCÍPIOS:
 * - Adapter (Hexagonal Architecture)
 * - Performance (queries otimizadas)
 * - Mínima dependência de WooCommerce
 *
 * IMPORTANTE:
 * - Status financeiro é armazenado em wp_limpvix_orders
 * - Não depende de WC_Order para status financeiro
 * - Ledger é fonte da verdade (isso é cache)
 *
 * PASSO 5.3 - Use Cases de Decisão
 *
 * @package LimpVix\Infrastructure\Persistence
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Order\OrderRepositoryInterface;
use LimpVix\Domain\Finance\FinancialStatus;

defined('ABSPATH') || exit;

class WpOrderRepository implements OrderRepositoryInterface
{
    /**
     * Nome da tabela
     */
    private const TABLE_NAME = 'limpvix_orders';

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
     * Buscar order por UUID
     *
     * @param string $uuid
     * @return Order|null
     */
    public function findByUuid(string $uuid): ?Order
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $row = $this->wpdb->get_row(
            $this->wpdb->prepare(
                "SELECT uuid, id, financial_status
                FROM {$this->table}
                WHERE uuid = %s
                LIMIT 1",
                $uuid
            ),
            ARRAY_A
        );

        if (!is_array($row)) {
            return null;
        }

        return $this->hydrate($row);
    }

    /**
     * Obter status financeiro atual
     *
     * @param string $uuid
     * @return FinancialStatus|null
     */
    public function getCurrentFinancialStatus(string $uuid): ?FinancialStatus
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $status = $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT financial_status
                FROM {$this->table}
                WHERE uuid = %s
                LIMIT 1",
                $uuid
            )
        );

        if ($status === null) {
            return null;
        }

        return FinancialStatus::fromValue($status);
    }

    /**
     * Atualizar status financeiro
     *
     * @param string $uuid
     * @param FinancialStatus $status
     * @return void
     * @throws \RuntimeException
     */
    public function updateFinancialStatus(string $uuid, FinancialStatus $status): void
    {
        // Verificar se order existe
        if (!$this->exists($uuid)) {
            throw new \RuntimeException("Order {$uuid} não encontrada");
        }

        // Atualizar status
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $result = $this->wpdb->update(
            $this->table,
            ['financial_status' => $status->getValue()],
            ['uuid' => $uuid],
            ['%s'],
            ['%s']
        );

        if ($result === false) {
            throw new \RuntimeException(
                "Falha ao atualizar status financeiro: {$this->wpdb->last_error}"
            );
        }
    }

    /**
     * Verificar se order existe
     *
     * @param string $uuid
     * @return bool
     */
    public function exists(string $uuid): bool
    {
        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
        $count = (int) $this->wpdb->get_var(
            $this->wpdb->prepare(
                "SELECT COUNT(*) FROM {$this->table}
                WHERE uuid = %s",
                $uuid
            )
        );

        return $count > 0;
    }

    /**
     * Hidratar Order a partir de dados do banco
     *
     * @param array $data
     * @return Order
     */
    private function hydrate(array $data): Order
    {
        return new Order(
            uuid: $data['uuid'],
            id: (int) $data['id'],
            financialStatus: FinancialStatus::fromValue($data['financial_status'])
        );
    }
}
