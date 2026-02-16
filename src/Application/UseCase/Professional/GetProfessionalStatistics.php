<?php
/**
 * GetProfessionalStatistics Use Case
 *
 * Retorna estatísticas agregadas de profissionais para dashboard admin.
 *
 * RESPONSABILIDADES:
 * - Calcular total de profissionais cadastrados
 * - Calcular total de profissionais ativos
 * - Calcular total de profissionais verificados
 * - Calcular total de profissionais suspensos
 * - Calcular score médio da plataforma
 *
 * USADO EM:
 * - ProfessionalManagementPage::renderDashboard()
 *
 * @package LimpVix\Application\UseCase\Professional
 * @since 0.6.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Application\UseCase\Professional;

defined('ABSPATH') || exit;

final class GetProfessionalStatistics
{
    /**
     * Executa cálculo de estatísticas
     *
     * @return array Estatísticas de profissionais
     */
    public function execute(): array
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_professionals';

        // Query otimizada com 1 única chamada para todas estatísticas
        $stats = $wpdb->get_row(
            "SELECT
                COUNT(*) as total,
                COUNT(CASE WHEN is_active = 1 THEN 1 END) as active,
                COUNT(CASE WHEN is_verified = 1 THEN 1 END) as verified,
                COUNT(CASE
                    WHEN is_active = 1
                    AND suspended_until IS NOT NULL
                    AND suspended_until > NOW()
                    THEN 1
                END) as suspended,
                AVG(score) as avg_score,
                COUNT(CASE WHEN score < 3.0 THEN 1 END) as low_score_count
            FROM {$table}",
            ARRAY_A
        );

        // Normalizar valores (evitar NULL)
        $total = (int) ($stats['total'] ?? 0);
        $active = (int) ($stats['active'] ?? 0);
        $verified = (int) ($stats['verified'] ?? 0);
        $suspended = (int) ($stats['suspended'] ?? 0);
        $avgScore = round((float) ($stats['avg_score'] ?? 0), 2);
        $lowScoreCount = (int) ($stats['low_score_count'] ?? 0);

        // Calcular %
        $verifiedPercent = $total > 0 ? round(($verified / $total) * 100, 1) : 0;
        $activePercent = $total > 0 ? round(($active / $total) * 100, 1) : 0;

        // Log de debug (apenas se WP_DEBUG ativo)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[GetProfessionalStatistics] Total: %d | Ativos: %d | Verificados: %d | Suspensos: %d | Score Médio: %.2f',
                $total,
                $active,
                $verified,
                $suspended,
                $avgScore
            ));
        }

        return [
            'total' => $total,
            'active' => $active,
            'verified' => $verified,
            'suspended' => $suspended,
            'average_score' => $avgScore,
            'low_score_count' => $lowScoreCount,

            // Extras úteis para dashboard
            'verified_percent' => $verifiedPercent,
            'active_percent' => $activePercent,
            'score_formatted' => number_format($avgScore, 2, ',', '.'),
            'has_low_score_alerts' => $lowScoreCount > 0,
        ];
    }
}
