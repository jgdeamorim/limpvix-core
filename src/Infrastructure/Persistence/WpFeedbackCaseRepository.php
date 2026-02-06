<?php
/**
 * WpFeedbackCaseRepository
 *
 * Implementação WordPress do repositório de casos de feedback C2.
 * Usa post_meta da order WooCommerce para armazenar status e data de resolução.
 *
 * Meta keys:
 * - _limpvix_c2_status: Status do caso (pending|in_progress|resolved)
 * - _limpvix_c2_resolved_at: Data de resolução (Y-m-d H:i:s)
 *
 * @package LimpVix\Infrastructure\Persistence
 * @since 0.1.4
 */

namespace LimpVix\Infrastructure\Persistence;

use LimpVix\Domain\Support\FeedbackCaseRepositoryInterface;
use LimpVix\Domain\Support\FeedbackCaseStatus;

defined('ABSPATH') || exit;

class WpFeedbackCaseRepository implements FeedbackCaseRepositoryInterface
{
    /**
     * Meta keys
     */
    private const META_STATUS = '_limpvix_c2_status';
    private const META_RESOLVED_AT = '_limpvix_c2_resolved_at';

    /**
     * {@inheritDoc}
     */
    public function saveStatus(int $orderId, FeedbackCaseStatus $status, ?string $resolvedAt = null): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        try {
            // Atualizar status
            $statusUpdated = update_post_meta($orderId, self::META_STATUS, $status->getValue());

            // Atualizar data de resolução se fornecida
            if ($status->isResolved() && $resolvedAt !== null) {
                update_post_meta($orderId, self::META_RESOLVED_AT, $resolvedAt);
            } elseif ($status->isResolved() && $resolvedAt === null) {
                // Se resolvido mas sem data, usar data atual
                update_post_meta($orderId, self::META_RESOLVED_AT, current_time('mysql'));
            } elseif (!$status->isResolved()) {
                // Se não está resolvido, limpar data de resolução
                delete_post_meta($orderId, self::META_RESOLVED_AT);
            }

            return $statusUpdated !== false;

        } catch (\Exception $e) {
            // Log do erro se WordPress debug estiver ativo
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[LimpVix] Erro ao salvar status de feedback case: %s',
                    $e->getMessage()
                ));
            }
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getStatus(int $orderId): ?array
    {
        if ($orderId <= 0) {
            return null;
        }

        $status = get_post_meta($orderId, self::META_STATUS, true);

        if (empty($status)) {
            return null;
        }

        $resolvedAt = get_post_meta($orderId, self::META_RESOLVED_AT, true);

        return [
            'status' => $status,
            'resolved_at' => !empty($resolvedAt) ? $resolvedAt : null
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function exists(int $orderId): bool
    {
        if ($orderId <= 0) {
            return false;
        }

        $status = get_post_meta($orderId, self::META_STATUS, true);
        return !empty($status);
    }

    /**
     * Buscar casos por status (auxiliar para listagens admin)
     *
     * @param FeedbackCaseStatus|null $status Filtrar por status (null = todos)
     * @param int $limit Limite de resultados
     * @return array Array de order IDs
     */
    public function findByStatus(?FeedbackCaseStatus $status = null, int $limit = 100): array
    {
        global $wpdb;

        $query = "
            SELECT DISTINCT post_id
            FROM {$wpdb->postmeta}
            WHERE meta_key = %s
        ";

        $params = [self::META_STATUS];

        if ($status !== null) {
            $query .= " AND meta_value = %s";
            $params[] = $status->getValue();
        }

        $query .= " ORDER BY post_id DESC LIMIT %d";
        $params[] = $limit;

        $results = $wpdb->get_col($wpdb->prepare($query, ...$params));

        return array_map('intval', $results);
    }
}
