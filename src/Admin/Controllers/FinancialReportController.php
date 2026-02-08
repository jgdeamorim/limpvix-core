<?php
/**
 * FinancialReportController - Relatório Financeiro Completo
 *
 * RESPONSABILIDADE:
 * - Dashboard executivo financeiro
 * - Repasses para profissionais
 * - Lucro LimpVix (taxas/comissões)
 * - Gastos operacionais (futuro)
 * - Filtros por período
 *
 * PRINCÍPIOS:
 * - Read-only (não modifica estado)
 * - Permissions gated (limpvix_finance_view)
 * - Dados agregados do banco
 *
 * MÉTRICAS:
 * - Receita total (total_amount de orders)
 * - Repasses (professional_net_amount)
 * - Lucro LimpVix (platform_fee_amount)
 * - Breakdown por status financeiro
 * - Top profissionais por receita
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;

defined('ABSPATH') || exit;

class FinancialReportController
{
    /**
     * Renderizar página
     *
     * @return void
     */
    public function render(): void
    {
        // Gate de permissão
        if (!FinanceCapabilities::canView()) {
            wp_die('Você não tem permissão para acessar esta página.');
        }

        // Obter filtro de período
        $period = isset($_GET['period']) ? sanitize_text_field($_GET['period']) : '30d';
        $customStart = isset($_GET['start_date']) ? sanitize_text_field($_GET['start_date']) : '';
        $customEnd = isset($_GET['end_date']) ? sanitize_text_field($_GET['end_date']) : '';

        // Calcular datas do período
        $dateRange = $this->calculateDateRange($period, $customStart, $customEnd);

        // Buscar dados financeiros
        $summary = $this->fetchFinancialSummary($dateRange);
        $payoutsByProfessional = $this->fetchPayoutsByProfessional($dateRange);
        $ordersByStatus = $this->fetchOrdersByStatus($dateRange);
        $dailyRevenue = $this->fetchDailyRevenue($dateRange);

        // Renderizar view
        $this->renderView($period, $dateRange, $summary, $payoutsByProfessional, $ordersByStatus, $dailyRevenue);
    }

    /**
     * Calcular range de datas baseado no período
     *
     * @param string $period
     * @param string $customStart
     * @param string $customEnd
     * @return array
     */
    private function calculateDateRange(string $period, string $customStart, string $customEnd): array
    {
        $endDate = current_time('Y-m-d');

        switch ($period) {
            case 'today':
                $startDate = current_time('Y-m-d');
                $label = 'Hoje';
                break;
            case '7d':
                $startDate = date('Y-m-d', strtotime('-7 days'));
                $label = 'Últimos 7 dias';
                break;
            case '30d':
                $startDate = date('Y-m-d', strtotime('-30 days'));
                $label = 'Últimos 30 dias';
                break;
            case '90d':
                $startDate = date('Y-m-d', strtotime('-90 days'));
                $label = 'Últimos 90 dias';
                break;
            case 'custom':
                $startDate = !empty($customStart) ? $customStart : date('Y-m-d', strtotime('-30 days'));
                $endDate = !empty($customEnd) ? $customEnd : current_time('Y-m-d');
                $label = 'Período personalizado';
                break;
            default:
                $startDate = date('Y-m-d', strtotime('-30 days'));
                $label = 'Últimos 30 dias';
        }

        return [
            'start' => $startDate,
            'end' => $endDate,
            'label' => $label
        ];
    }

    /**
     * Buscar resumo financeiro
     *
     * @param array $dateRange
     * @return array
     */
    private function fetchFinancialSummary(array $dateRange): array
    {
        global $wpdb;

        $result = $wpdb->get_row($wpdb->prepare(
            "SELECT
                COUNT(*) as total_orders,
                COALESCE(SUM(total_amount), 0) as total_revenue,
                COALESCE(SUM(platform_fee_amount), 0) as total_limpvix_profit,
                COALESCE(SUM(professional_net_amount), 0) as total_professional_payouts
            FROM {$wpdb->prefix}limpvix_orders
            WHERE created_at BETWEEN %s AND %s
            AND financial_status IN ('PAID', 'AUTHORIZED', 'TRANSFERRED')",
            $dateRange['start'] . ' 00:00:00',
            $dateRange['end'] . ' 23:59:59'
        ), ARRAY_A);

        return $result ?: [
            'total_orders' => 0,
            'total_revenue' => 0,
            'total_limpvix_profit' => 0,
            'total_professional_payouts' => 0
        ];
    }

    /**
     * Buscar repasses por profissional
     *
     * @param array $dateRange
     * @return array
     */
    private function fetchPayoutsByProfessional(array $dateRange): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                o.professional_id,
                u.display_name as professional_name,
                COUNT(*) as order_count,
                COALESCE(SUM(o.total_amount), 0) as total_revenue,
                COALESCE(SUM(o.professional_net_amount), 0) as total_payout,
                COALESCE(SUM(o.platform_fee_amount), 0) as total_fees
            FROM {$wpdb->prefix}limpvix_orders o
            LEFT JOIN {$wpdb->prefix}users u ON o.professional_id = u.ID
            WHERE o.created_at BETWEEN %s AND %s
            AND o.financial_status IN ('PAID', 'AUTHORIZED', 'TRANSFERRED')
            GROUP BY o.professional_id, u.display_name
            ORDER BY total_payout DESC
            LIMIT 50",
            $dateRange['start'] . ' 00:00:00',
            $dateRange['end'] . ' 23:59:59'
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Buscar orders por status financeiro
     *
     * @param array $dateRange
     * @return array
     */
    private function fetchOrdersByStatus(array $dateRange): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                financial_status,
                COUNT(*) as count,
                COALESCE(SUM(total_amount), 0) as total_amount
            FROM {$wpdb->prefix}limpvix_orders
            WHERE created_at BETWEEN %s AND %s
            GROUP BY financial_status
            ORDER BY count DESC",
            $dateRange['start'] . ' 00:00:00',
            $dateRange['end'] . ' 23:59:59'
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Buscar receita diária
     *
     * @param array $dateRange
     * @return array
     */
    private function fetchDailyRevenue(array $dateRange): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT
                DATE(created_at) as date,
                COUNT(*) as order_count,
                COALESCE(SUM(total_amount), 0) as revenue,
                COALESCE(SUM(platform_fee_amount), 0) as profit
            FROM {$wpdb->prefix}limpvix_orders
            WHERE created_at BETWEEN %s AND %s
            AND financial_status IN ('PAID', 'AUTHORIZED', 'TRANSFERRED')
            GROUP BY DATE(created_at)
            ORDER BY date ASC",
            $dateRange['start'] . ' 00:00:00',
            $dateRange['end'] . ' 23:59:59'
        ), ARRAY_A);

        return $results ?: [];
    }

    /**
     * Renderizar view completa
     *
     * @param string $period
     * @param array $dateRange
     * @param array $summary
     * @param array $payoutsByProfessional
     * @param array $ordersByStatus
     * @param array $dailyRevenue
     * @return void
     */
    private function renderView(
        string $period,
        array $dateRange,
        array $summary,
        array $payoutsByProfessional,
        array $ordersByStatus,
        array $dailyRevenue
    ): void {
        ?>
        <div class="wrap limpvix-finance">
            <h1>
                💰 Relatório Financeiro
                <span class="title-count">(<?php echo esc_html($dateRange['label']); ?>)</span>
            </h1>

            <?php $this->renderPeriodFilter($period, $dateRange); ?>
            <?php $this->renderExecutiveSummary($summary); ?>

            <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
                <?php $this->renderDailyRevenueChart($dailyRevenue); ?>
                <?php $this->renderOrdersByStatusChart($ordersByStatus); ?>
            </div>

            <?php $this->renderPayoutsByProfessionalTable($payoutsByProfessional); ?>
            <?php $this->renderFutureExpenses(); ?>
        </div>
        <?php
    }

    /**
     * Renderizar filtro de período
     *
     * @param string $period
     * @param array $dateRange
     * @return void
     */
    private function renderPeriodFilter(string $period, array $dateRange): void
    {
        ?>
        <div class="tablenav top" style="margin: 20px 0;">
            <div class="alignleft actions">
                <form method="get" id="limpvix-period-filter">
                    <input type="hidden" name="page" value="limpvix-financial-report">

                    <select name="period" id="period-select" onchange="toggleCustomDates()">
                        <option value="today" <?php selected($period, 'today'); ?>>Hoje</option>
                        <option value="7d" <?php selected($period, '7d'); ?>>Últimos 7 dias</option>
                        <option value="30d" <?php selected($period, '30d'); ?>>Últimos 30 dias</option>
                        <option value="90d" <?php selected($period, '90d'); ?>>Últimos 90 dias</option>
                        <option value="custom" <?php selected($period, 'custom'); ?>>Período personalizado</option>
                    </select>

                    <span id="custom-dates" style="display: <?php echo $period === 'custom' ? 'inline' : 'none'; ?>;">
                        <input type="date" name="start_date" value="<?php echo esc_attr($dateRange['start']); ?>">
                        até
                        <input type="date" name="end_date" value="<?php echo esc_attr($dateRange['end']); ?>">
                    </span>

                    <button type="submit" class="button">Filtrar</button>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-financial-report')); ?>" class="button">Limpar</a>
                </form>
            </div>
        </div>

        <script>
        function toggleCustomDates() {
            const select = document.getElementById('period-select');
            const customDates = document.getElementById('custom-dates');
            customDates.style.display = select.value === 'custom' ? 'inline' : 'none';
        }
        </script>
        <?php
    }

    /**
     * Renderizar resumo executivo
     *
     * @param array $summary
     * @return void
     */
    private function renderExecutiveSummary(array $summary): void
    {
        $totalRevenue = floatval($summary['total_revenue']);
        $totalProfit = floatval($summary['total_limpvix_profit']);
        $totalPayouts = floatval($summary['total_professional_payouts']);
        $totalOrders = intval($summary['total_orders']);
        $avgOrderValue = $totalOrders > 0 ? $totalRevenue / $totalOrders : 0;
        $profitMargin = $totalRevenue > 0 ? ($totalProfit / $totalRevenue) * 100 : 0;

        ?>
        <div class="limpvix-grid limpvix-grid-4" style="margin-top: 20px;">
            <!-- Card 1: Receita Total -->
            <div class="limpvix-card limpvix-card-success">
                <div class="limpvix-card-body" style="text-align: center;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 5px;">💵 Receita Total</div>
                    <div style="font-size: 32px; font-weight: bold; color: #00a32a;">
                        R$ <?php echo number_format($totalRevenue, 2, ',', '.'); ?>
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 5px;">
                        <?php echo number_format($totalOrders); ?> orders
                    </div>
                </div>
            </div>

            <!-- Card 2: Lucro LimpVix -->
            <div class="limpvix-card limpvix-card-primary">
                <div class="limpvix-card-body" style="text-align: center;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 5px;">🏦 Lucro LimpVix</div>
                    <div style="font-size: 32px; font-weight: bold; color: #135e96;">
                        R$ <?php echo number_format($totalProfit, 2, ',', '.'); ?>
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 5px;">
                        <?php echo number_format($profitMargin, 1); ?>% de margem
                    </div>
                </div>
            </div>

            <!-- Card 3: Repasses -->
            <div class="limpvix-card limpvix-card-warning">
                <div class="limpvix-card-body" style="text-align: center;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 5px;">💸 Repasses</div>
                    <div style="font-size: 32px; font-weight: bold; color: #d63638;">
                        R$ <?php echo number_format($totalPayouts, 2, ',', '.'); ?>
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 5px;">
                        Profissionais
                    </div>
                </div>
            </div>

            <!-- Card 4: Ticket Médio -->
            <div class="limpvix-card limpvix-card-info">
                <div class="limpvix-card-body" style="text-align: center;">
                    <div style="font-size: 14px; color: #666; margin-bottom: 5px;">📊 Ticket Médio</div>
                    <div style="font-size: 32px; font-weight: bold; color: #0073aa;">
                        R$ <?php echo number_format($avgOrderValue, 2, ',', '.'); ?>
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 5px;">
                        Por order
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizar gráfico de receita diária
     *
     * @param array $dailyRevenue
     * @return void
     */
    private function renderDailyRevenueChart(array $dailyRevenue): void
    {
        ?>
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>📈 Receita e Lucro Diário</h3>
            </div>
            <div class="limpvix-card-body">
                <?php if (empty($dailyRevenue)): ?>
                    <p><em>Sem dados para o período selecionado.</em></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Data</th>
                                <th style="text-align: center;">Orders</th>
                                <th style="text-align: right;">Receita</th>
                                <th style="text-align: right;">Lucro</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dailyRevenue as $day): ?>
                                <tr>
                                    <td><?php echo esc_html(date('d/m/Y', strtotime($day['date']))); ?></td>
                                    <td style="text-align: center;"><?php echo esc_html($day['order_count']); ?></td>
                                    <td style="text-align: right;"><strong>R$ <?php echo number_format($day['revenue'], 2, ',', '.'); ?></strong></td>
                                    <td style="text-align: right; color: #00a32a;"><strong>R$ <?php echo number_format($day['profit'], 2, ',', '.'); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizar gráfico de orders por status
     *
     * @param array $ordersByStatus
     * @return void
     */
    private function renderOrdersByStatusChart(array $ordersByStatus): void
    {
        ?>
        <div class="limpvix-card">
            <div class="limpvix-card-header">
                <h3>🎯 Orders por Status Financeiro</h3>
            </div>
            <div class="limpvix-card-body">
                <?php if (empty($ordersByStatus)): ?>
                    <p><em>Sem dados para o período selecionado.</em></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Status</th>
                                <th style="text-align: center;">Quantidade</th>
                                <th style="text-align: right;">Valor Total</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($ordersByStatus as $status): ?>
                                <tr>
                                    <td>
                                        <span class="limpvix-status limpvix-status-<?php echo esc_attr(strtolower($status['financial_status'])); ?>">
                                            <?php echo esc_html($status['financial_status']); ?>
                                        </span>
                                    </td>
                                    <td style="text-align: center;"><?php echo esc_html($status['count']); ?></td>
                                    <td style="text-align: right;"><strong>R$ <?php echo number_format($status['total_amount'], 2, ',', '.'); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizar tabela de repasses por profissional
     *
     * @param array $payoutsByProfessional
     * @return void
     */
    private function renderPayoutsByProfessionalTable(array $payoutsByProfessional): void
    {
        ?>
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header">
                <h3>👷 Repasses por Profissional</h3>
            </div>
            <div class="limpvix-card-body">
                <?php if (empty($payoutsByProfessional)): ?>
                    <p><em>Sem repasses no período selecionado.</em></p>
                <?php else: ?>
                    <table class="widefat striped">
                        <thead>
                            <tr>
                                <th>Profissional</th>
                                <th style="text-align: center;">Orders</th>
                                <th style="text-align: right;">Receita Gerada</th>
                                <th style="text-align: right;">Taxa LimpVix</th>
                                <th style="text-align: right;">Repasse Líquido</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($payoutsByProfessional as $payout): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo esc_html($payout['professional_name'] ?: 'Profissional #' . $payout['professional_id']); ?></strong>
                                    </td>
                                    <td style="text-align: center;"><?php echo esc_html($payout['order_count']); ?></td>
                                    <td style="text-align: right;">R$ <?php echo number_format($payout['total_revenue'], 2, ',', '.'); ?></td>
                                    <td style="text-align: right; color: #135e96;">R$ <?php echo number_format($payout['total_fees'], 2, ',', '.'); ?></td>
                                    <td style="text-align: right;"><strong style="color: #00a32a;">R$ <?php echo number_format($payout['total_payout'], 2, ',', '.'); ?></strong></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    /**
     * Renderizar seção de gastos futuros
     *
     * @return void
     */
    private function renderFutureExpenses(): void
    {
        ?>
        <div class="limpvix-card" style="margin-top: 20px;">
            <div class="limpvix-card-header">
                <h3>🔮 Gastos Operacionais (Futuro)</h3>
            </div>
            <div class="limpvix-card-body">
                <div class="notice notice-info inline">
                    <p>
                        <strong>📋 Planejado para implementação futura:</strong><br>
                        • Passagens/Vale transporte para profissionais<br>
                        • Vale alimentação/Almoço<br>
                        • Outros gastos operacionais<br>
                        • Cálculo de lucro líquido (receita - repasses - gastos)
                    </p>
                </div>

                <div style="margin-top: 15px; padding: 15px; background: #f0f0f1; border-radius: 4px;">
                    <strong>💡 Como será implementado:</strong>
                    <ul style="margin: 10px 0 0 20px;">
                        <li>Nova tabela: <code>wp_limpvix_expenses</code></li>
                        <li>Tipos de gasto: TRANSPORT, MEAL, OTHER</li>
                        <li>Vinculação a order ou profissional</li>
                        <li>Cálculo automático de lucro líquido</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php
    }
}
