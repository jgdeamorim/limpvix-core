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
            $allowedStatuses = ['active', 'inactive', 'suspended'];
            if (in_array($filters['status'], $allowedStatuses, true)) {
                if ($filters['status'] === 'active') {
                    $where[] = 'is_active = 1 AND (suspended_until IS NULL OR suspended_until < NOW())';
                } elseif ($filters['status'] === 'inactive') {
                    $where[] = 'is_active = 0';
                } elseif ($filters['status'] === 'suspended') {
                    $where[] = 'is_active = 1 AND suspended_until IS NOT NULL AND suspended_until > NOW()';
                }
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

        // SAFE CASTING: Filter by min score
        if (isset($filters['min_score']) && $filters['min_score'] > 0) {
            $where[] = $wpdb->prepare('score >= %f', (float) $filters['min_score']);
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

        // Get data
        $sql = $wpdb->prepare(
            "SELECT * FROM {$table} WHERE {$whereSql} ORDER BY created_at DESC LIMIT %d OFFSET %d",
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
