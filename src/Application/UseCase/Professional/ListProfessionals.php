<?php
/**
 * ListProfessionals Use Case
 *
 * Lista profissionais com filtros avançados para Admin UI.
 *
 * RESPONSABILIDADES:
 * - Listar profissionais com filtros (status, verificação, score, busca)
 * - Aplicar sanitização e whitelist de filtros (prevenir SQL injection)
 * - Suportar paginação (limit/offset)
 * - Retornar dados prontos para renderização
 *
 * FILTROS SUPORTADOS:
 * - 'status': 'all', 'active', 'inactive', 'suspended'
 * - 'verified': 'all', 'verified', 'not_verified'
 * - 'min_score': float (filtrar score >= valor)
 * - 'search': string (busca em full_name, cpf, email)
 * - 'limit': int (default: 100)
 * - 'offset': int (default: 0)
 *
 * USADO EM:
 * - ProfessionalManagementPage::renderList()
 *
 * @package LimpVix\Application\UseCase\Professional
 * @since 0.6.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Application\UseCase\Professional;

use LimpVix\Infrastructure\Persistence\WpMarketplaceProfessionalRepository;

defined('ABSPATH') || exit;

final class ListProfessionals
{
    private WpMarketplaceProfessionalRepository $repository;

    public function __construct(WpMarketplaceProfessionalRepository $repository)
    {
        $this->repository = $repository;
    }

    /**
     * Executa listagem de profissionais
     *
     * @param array $filters Filtros de busca
     * @return array Lista de profissionais ou ['data' => [...], 'total' => int]
     */
    public function execute(array $filters = []): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_professionals';

        $where = ['1=1'];
        $limit = (int) ($filters['limit'] ?? 100);
        $offset = (int) ($filters['offset'] ?? 0);

        // WHITELIST VALIDATION: Filter by status
        if (isset($filters['status']) && $filters['status'] !== 'all') {
            $allowedStatuses = ['active', 'inactive', 'suspended', 'banned'];
            if (in_array($filters['status'], $allowedStatuses, true)) {
                if ($filters['status'] === 'active') {
                    $where[] = 'is_active = 1 AND is_permanently_banned = 0 AND (suspended_until IS NULL OR suspended_until < NOW())';
                } elseif ($filters['status'] === 'inactive') {
                    $where[] = 'is_active = 0 AND is_permanently_banned = 0';
                } elseif ($filters['status'] === 'suspended') {
                    $where[] = 'is_permanently_banned = 0 AND suspended_until IS NOT NULL AND suspended_until > NOW()';
                } elseif ($filters['status'] === 'banned') {
                    $where[] = 'is_permanently_banned = 1';
                }
            }
        }

        // WHITELIST VALIDATION: Filter by KYC status
        if (!empty($filters['filter_kyc']) && $filters['filter_kyc'] !== 'all') {
            $allowedKyc = ['not_started', 'pending', 'processing', 'approved', 'rejected', 'expired'];
            if (in_array($filters['filter_kyc'], $allowedKyc, true)) {
                $where[] = $wpdb->prepare('kyc_status = %s', $filters['filter_kyc']);
            }
        }

        // WHITELIST VALIDATION: Filter by verification
        if (isset($filters['verified']) && $filters['verified'] !== 'all') {
            $allowedVerified = ['verified', 'not_verified'];
            if (in_array($filters['verified'], $allowedVerified, true)) {
                $isVerified = $filters['verified'] === 'verified' ? 1 : 0;
                $where[] = $wpdb->prepare('is_verified = %d', $isVerified);
            }
        }

        // SCORE FILTER: suporta presets semânticos ('below3', 'below2') e valores numéricos (>= X)
        // Prioridade: filter_score (novo, semântico) > min_score (legado, numérico)
        $scoreFilter = $filters['filter_score'] ?? $filters['min_score'] ?? '';

        if ($scoreFilter !== '' && $scoreFilter !== null) {
            if ($scoreFilter === 'below3') {
                $where[] = 'score < 3.00';
            } elseif ($scoreFilter === 'below2') {
                $where[] = 'score < 2.00';
            } elseif (is_numeric($scoreFilter) && (float) $scoreFilter > 0) {
                $where[] = $wpdb->prepare('score >= %f', (float) $scoreFilter);
            }
        }

        // SAFE ESCAPING: Search in full_name, cpf, email
        if (!empty($filters['search'])) {
            $search = sanitize_text_field($filters['search']);
            $like = '%' . $wpdb->esc_like($search) . '%';
            $where[] = $wpdb->prepare(
                '(full_name LIKE %s OR cpf LIKE %s OR email LIKE %s)',
                $like,
                $like,
                $like
            );
        }

        $whereSql = implode(' AND ', $where);

        // Get total count (for pagination)
        $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE {$whereSql}");

        // Ordenação (com whitelist para segurança)
        $orderby = $filters['orderby'] ?? 'created_at';
        $order = strtoupper($filters['order'] ?? 'DESC');

        $allowedOrderby = ['id', 'full_name', 'score', 'created_at', 'is_verified', 'is_active'];
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

        // Log de debug
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[ListProfessionals] Filtros: %s | Resultados: %d/%d',
                json_encode($filters),
                count($results ?? []),
                $total
            ));
        }

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
