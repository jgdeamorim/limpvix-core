<?php
/**
 * ListContracts - Use Case for listing contracts
 *
 * RESPONSABILIDADE:
 * - Listar contratos com filtros
 * - Suportar filtro por client_id ou status
 * - Retornar array de Contract aggregates
 *
 * @package LimpVix\Application\UseCases\Contract
 * @since 0.10.0
 */

namespace LimpVix\Application\UseCases\Contract;

use LimpVix\Domain\Contract\ContractRepositoryInterface;

defined('ABSPATH') || exit;

final class ListContracts
{
    public function __construct(
        private ContractRepositoryInterface $repository
    ) {}

    /**
     * Execute Use Case
     *
     * REFATORADO (FASE 2): Agora suporta array de filtros flexível
     *
     * Filtros suportados:
     * - 'status' (string): 'active', 'suspended', 'cancelled', 'expired', 'all'
     * - 'client_id' (int): ID do cliente
     * - 'limit' (int): Limite de resultados (default: 100)
     * - 'offset' (int): Offset para paginação (default: 0)
     *
     * @param array|int|null $filters Array de filtros ou client_id (backward compatibility)
     * @param string|null $status Status (backward compatibility)
     * @return array Array of Contract aggregates ou ['data' => [...], 'total' => int]
     */
    public function execute($filters = null, ?string $status = null): array
    {
        // Backward compatibility: se primeiro param é int, é client_id
        if (is_int($filters)) {
            return $this->repository->findByClientId($filters);
        }

        // Backward compatibility: se segundo param é status
        if ($status === 'active' && $filters === null) {
            return $this->repository->findActiveContracts();
        }

        // Novo comportamento: array de filtros
        if (!is_array($filters)) {
            $filters = [];
        }

        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_contracts';

        $where = ['1=1'];
        $limit = (int) ($filters['limit'] ?? 100);
        $offset = (int) ($filters['offset'] ?? 0);

        // Filter by status
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $where[] = $wpdb->prepare('status = %s', $filters['status']);
        }

        // Filter by client_id
        if (isset($filters['client_id']) && $filters['client_id'] > 0) {
            $where[] = $wpdb->prepare('client_user_id = %d', $filters['client_id']);
        }

        $whereSql = implode(' AND ', $where);

        // Get total count (for pagination)
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}");

        // Ordenação (com whitelist para segurança)
        $orderby = $filters['orderby'] ?? 'created_at';
        $order = strtoupper($filters['order'] ?? 'DESC');

        $allowedOrderby = ['id', 'created_at', 'start_date', 'monthly_value', 'contract_number'];
        if (!in_array($orderby, $allowedOrderby, true)) {
            $orderby = 'created_at';
        }

        $allowedOrder = ['ASC', 'DESC'];
        if (!in_array($order, $allowedOrder, true)) {
            $order = 'DESC';
        }

        // Get data
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY {$orderby} {$order} LIMIT %d OFFSET %d",
            $limit,
            $offset
        );

        $results = $wpdb->get_results($sql, ARRAY_A);

        // Se não há paginação (offset=0 e sem total), retornar apenas data (backward compatibility)
        if ($offset === 0 && !isset($filters['return_total'])) {
            return $results ?? [];
        }

        // Retornar com paginação
        return [
            'data' => $results ?? [],
            'total' => $total,
        ];
    }
}
