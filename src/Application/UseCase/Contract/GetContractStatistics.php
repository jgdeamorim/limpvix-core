<?php
/**
 * GetContractStatistics Use Case
 *
 * Retorna estatísticas agregadas de contratos para dashboard admin.
 *
 * RESPONSABILIDADES:
 * - Calcular total de contratos ativos
 * - Calcular valor mensal total (soma de monthly_value)
 * - Contar contratos expirando em 30 dias
 * - Contar execuções pendentes/agendadas
 *
 * USADO EM:
 * - ContractManagementPage::renderDashboard()
 *
 * @package LimpVix\Application\UseCase\Contract
 * @since 0.6.0
 * @author Claude Code + LimpVix Development Team
 */

namespace LimpVix\Application\UseCase\Contract;

defined('ABSPATH') || exit;

final class GetContractStatistics
{
    /**
     * Executa cálculo de estatísticas
     *
     * @return array Estatísticas de contratos
     */
    public function execute(): array
    {
        global $wpdb;

        $contractsTable = $wpdb->prefix . 'limpvix_contracts';
        $executionsTable = $wpdb->prefix . 'limpvix_contract_executions';

        // Query otimizada com 1 única chamada para estatísticas de contratos
        $contractStats = $wpdb->get_row(
            "SELECT
                COUNT(CASE WHEN status = 'active' THEN 1 END) as active_count,
                SUM(CASE WHEN status = 'active' THEN monthly_value ELSE 0 END) as total_monthly_value,
                COUNT(CASE
                    WHEN status = 'active'
                    AND end_date IS NOT NULL
                    AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
                    THEN 1
                END) as expiring_count
            FROM {$contractsTable}",
            ARRAY_A
        );

        // Query separada para execuções pendentes/agendadas
        $pendingExecutions = $wpdb->get_var(
            "SELECT COUNT(*)
             FROM {$executionsTable}
             WHERE status IN ('pending', 'scheduled')"
        );

        // Normalizar valores (evitar NULL)
        $activeCount = (int) ($contractStats['active_count'] ?? 0);
        $totalMonthly = (float) ($contractStats['total_monthly_value'] ?? 0);
        $expiringCount = (int) ($contractStats['expiring_count'] ?? 0);
        $pendingExecutionsCount = (int) ($pendingExecutions ?? 0);

        // Log de debug (apenas se WP_DEBUG ativo)
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[GetContractStatistics] Activos: %d | Mensal: R$ %.2f | Expirando: %d | Execuções Pendentes: %d',
                $activeCount,
                $totalMonthly,
                $expiringCount,
                $pendingExecutionsCount
            ));
        }

        return [
            'active_contracts' => $activeCount,
            'total_monthly_value' => $totalMonthly,
            'expiring_soon' => $expiringCount,
            'pending_executions' => $pendingExecutionsCount,

            // Extras úteis para dashboard
            'currency_formatted' => 'R$ ' . number_format($totalMonthly, 2, ',', '.'),
            'has_expiring' => $expiringCount > 0,
            'has_pending_executions' => $pendingExecutionsCount > 0,
        ];
    }
}
