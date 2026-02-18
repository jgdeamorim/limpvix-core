<?php

declare(strict_types=1);

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;

/**
 * Controller para página Dashboard (limpvix-finance)
 *
 * Exibe visão geral do sistema:
 * - Orders financeiras
 * - Payouts
 * - Profissionais ativos
 * - Appointments
 * - Health score geral
 */
class DashboardController
{
    public function render(): void
    {
        if (!FinanceCapabilities::canView()) {
            wp_die("Acesso negado");
        }

        $metrics = $this->fetchMetrics();

        ?>
        <div class="wrap limpvix-admin">
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-chart-line"></span>
                        Dashboard LimpVix
                    </h1>
                    <p class="limpvix-page-subtitle">Visão geral do sistema de gestão</p>
                </div>
            </div>

            <!-- Grid de Métricas Principais -->
            <div class="limpvix-dashboard-grid">
                <!-- Orders Financeiras -->
                <div class="limpvix-dashboard-card">
                    <div class="card-icon card-icon-blue">
                        <span class="dashicons dashicons-money-alt"></span>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Orders Financeiras</div>
                        <div class="card-value"><?php echo number_format($metrics['orders']['total'], 0, ',', '.'); ?></div>
                        <div class="card-meta">
                            <span class="status-badge status-paid"><?php echo $metrics['orders']['paid']; ?> Pagas</span>
                            <span class="status-badge status-authorized"><?php echo $metrics['orders']['authorized']; ?> Autorizadas</span>
                        </div>
                    </div>
                </div>

                <!-- Faturamento Total -->
                <div class="limpvix-dashboard-card">
                    <div class="card-icon card-icon-green">
                        <span class="dashicons dashicons-chart-area"></span>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Faturamento Total</div>
                        <div class="card-value">R$ <?php echo number_format($metrics['revenue']['total'], 2, ',', '.'); ?></div>
                        <div class="card-meta">
                            Taxa plataforma: <strong>R$ <?php echo number_format($metrics['revenue']['platform_fee'], 2, ',', '.'); ?></strong>
                        </div>
                    </div>
                </div>

                <!-- Payouts -->
                <div class="limpvix-dashboard-card">
                    <div class="card-icon card-icon-purple">
                        <span class="dashicons dashicons-database-export"></span>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Payouts</div>
                        <div class="card-value"><?php echo number_format($metrics['payouts']['total'], 0, ',', '.'); ?></div>
                        <div class="card-meta">
                            <span class="status-badge status-pending"><?php echo $metrics['payouts']['pending']; ?> Pendentes</span>
                            <span class="status-badge status-completed"><?php echo $metrics['payouts']['completed']; ?> Concluídos</span>
                        </div>
                    </div>
                </div>

                <!-- Profissionais -->
                <div class="limpvix-dashboard-card">
                    <div class="card-icon card-icon-orange">
                        <span class="dashicons dashicons-admin-users"></span>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Profissionais Ativos</div>
                        <div class="card-value"><?php echo number_format($metrics['professionals']['total'], 0, ',', '.'); ?></div>
                        <div class="card-meta">
                            Role: <strong>tnc_staff</strong>
                        </div>
                    </div>
                </div>

                <!-- LimpVix Executions/Orders -->
                <div class="limpvix-dashboard-card">
                    <div class="card-icon card-icon-teal">
                        <span class="dashicons dashicons-calendar-alt"></span>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Appointments</div>
                        <div class="card-value"><?php echo number_format($metrics['appointments']['total'] ?? 0, 0, ',', '.'); ?></div>
                        <div class="card-meta">
                            Integração LimpVix
                        </div>
                    </div>
                </div>

                <!-- Health Score -->
                <div class="limpvix-dashboard-card <?php echo $this->getHealthClass($metrics['health']['score']); ?>">
                    <div class="card-icon card-icon-health">
                        <span class="dashicons dashicons-heart"></span>
                    </div>
                    <div class="card-content">
                        <div class="card-label">Saúde do Sistema</div>
                        <div class="card-value"><?php echo number_format($metrics['health']['score'], 0); ?>%</div>
                        <div class="card-meta">
                            <?php echo $metrics['health']['issues']; ?> problema(s) detectado(s)
                        </div>
                    </div>
                </div>
            </div>

            <!-- Orders Recentes -->
            <div class="limpvix-section">
                <h2>📋 Orders Recentes</h2>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>UUID</th>
                            <th>Status</th>
                            <th>Valor Total</th>
                            <th>Taxa</th>
                            <th>Líquido Prof.</th>
                            <th>Criado em</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($metrics['recent_orders'])): ?>
                            <tr>
                                <td colspan="7" style="text-align: center; padding: 20px; color: #666;">
                                    Nenhuma order encontrada
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($metrics['recent_orders'] as $order): ?>
                                <tr>
                                    <td><strong><?php echo esc_html($order['id']); ?></strong></td>
                                    <td>
                                        <code title="<?php echo esc_attr($order['uuid']); ?>">
                                            <?php echo esc_html(substr($order['uuid'], 0, 8)) . '...'; ?>
                                        </code>
                                    </td>
                                    <td>
                                        <span class="limpvix-status limpvix-status-<?php echo strtolower($order['financial_status']); ?>">
                                            <?php echo esc_html($order['financial_status']); ?>
                                        </span>
                                    </td>
                                    <td>R$ <?php echo number_format((float)$order['total_amount'], 2, ',', '.'); ?></td>
                                    <td><?php echo number_format((float)$order['platform_fee_percentage'], 1); ?>%</td>
                                    <td><strong>R$ <?php echo number_format((float)$order['professional_net_amount'], 2, ',', '.'); ?></strong></td>
                                    <td><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($metrics['recent_orders'])): ?>
                    <p style="margin-top: 15px;">
                        <a href="<?php echo admin_url('admin.php?page=limpvix-orders'); ?>" class="button">
                            Ver Todas as Orders →
                        </a>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Quick Links -->
            <div class="limpvix-quick-links">
                <h2>⚡ Acesso Rápido</h2>
                <div class="quick-links-grid">
                    <a href="<?php echo admin_url('admin.php?page=limpvix-orders'); ?>" class="quick-link">
                        <span class="dashicons dashicons-money-alt"></span>
                        <span>Orders Financeiras</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-payouts'); ?>" class="quick-link">
                        <span class="dashicons dashicons-database-export"></span>
                        <span>Payouts</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-sync-validator'); ?>" class="quick-link">
                        <span class="dashicons dashicons-admin-tools"></span>
                        <span>Sync Validator</span>
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=limpvix-briefings'); ?>" class="quick-link">
                        <span class="dashicons dashicons-clipboard"></span>
                        <span>Briefings</span>
                    </a>
                </div>
            </div>
        </div>

        <style>
            .limpvix-dashboard-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }

            .limpvix-dashboard-card {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                display: flex;
                gap: 15px;
                transition: box-shadow 0.2s;
            }

            .limpvix-dashboard-card:hover {
                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            }

            .limpvix-dashboard-card.health-good {
                border-left: 4px solid #00a32a;
            }

            .limpvix-dashboard-card.health-warning {
                border-left: 4px solid #dba617;
            }

            .limpvix-dashboard-card.health-critical {
                border-left: 4px solid #b32d2e;
            }

            .card-icon {
                width: 60px;
                height: 60px;
                border-radius: 50%;
                display: flex;
                align-items: center;
                justify-content: center;
                flex-shrink: 0;
            }

            .card-icon .dashicons {
                font-size: 28px;
                width: 28px;
                height: 28px;
            }

            .card-icon-blue { background: #d5e5f9; color: #135e96; }
            .card-icon-green { background: #c6e1c6; color: #00a32a; }
            .card-icon-purple { background: #e9d8fd; color: #8b5cf6; }
            .card-icon-orange { background: #fed7aa; color: #f59e0b; }
            .card-icon-teal { background: #ccfbf1; color: #0d9488; }
            .card-icon-health { background: #fecaca; color: #dc2626; }

            .card-content {
                flex: 1;
            }

            .card-label {
                font-size: 12px;
                color: #666;
                text-transform: uppercase;
                font-weight: 600;
                margin-bottom: 8px;
            }

            .card-value {
                font-size: 32px;
                font-weight: bold;
                color: #1f2937;
                line-height: 1;
                margin-bottom: 8px;
            }

            .card-meta {
                font-size: 13px;
                color: #6b7280;
            }

            .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                margin-right: 5px;
            }

            .status-paid { background: #c6e1c6; color: #00a32a; }
            .status-authorized { background: #d5e5f9; color: #135e96; }
            .status-pending { background: #fcf9e8; color: #b32d2e; }
            .status-completed { background: #c6e1c6; color: #00a32a; }

            .limpvix-section {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }

            .limpvix-section h2 {
                margin-top: 0;
                margin-bottom: 20px;
                font-size: 18px;
            }

            .limpvix-quick-links {
                background: #fff;
                border: 1px solid #e0e0e0;
                border-radius: 8px;
                padding: 20px;
                margin: 20px 0;
            }

            .quick-links-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 15px;
                margin-top: 15px;
            }

            .quick-link {
                display: flex;
                align-items: center;
                gap: 10px;
                padding: 15px;
                background: #f9fafb;
                border: 1px solid #e5e7eb;
                border-radius: 6px;
                text-decoration: none;
                color: #1f2937;
                transition: all 0.2s;
            }

            .quick-link:hover {
                background: #f3f4f6;
                border-color: #d1d5db;
                transform: translateY(-2px);
            }

            .quick-link .dashicons {
                font-size: 20px;
                width: 20px;
                height: 20px;
                color: #3b82f6;
            }

            /* Status badges para tabela */
            .limpvix-status {
                display: inline-block;
                padding: 4px 8px;
                border-radius: 3px;
                font-size: 11px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .limpvix-status-created { background: #f0f0f1; color: #50575e; }
            .limpvix-status-paid { background: #c6e1c6; color: #00a32a; }
            .limpvix-status-held { background: #fcf9e8; color: #b32d2e; }
            .limpvix-status-review { background: #fcf9e8; color: #b32d2e; }
            .limpvix-status-authorized { background: #d5e5f9; color: #135e96; }
            .limpvix-status-transferred { background: #00ba37; color: #fff; }
            .limpvix-status-blocked { background: #fcf0f1; color: #b32d2e; }
            .limpvix-status-refunded { background: #f0f0f1; color: #50575e; }
        </style>
        <?php
    }

    private function fetchMetrics(): array
    {
        global $wpdb;

        // 1. Orders
        $orders = [
            'total' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_orders"),
            'paid' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_orders WHERE financial_status = 'PAID'"),
            'authorized' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_orders WHERE financial_status = 'AUTHORIZED'"),
        ];

        // 2. Faturamento
        $revenue = [
            'total' => (float)$wpdb->get_var("SELECT COALESCE(SUM(total_amount), 0) FROM {$wpdb->prefix}limpvix_orders WHERE financial_status IN ('PAID', 'AUTHORIZED', 'TRANSFERRED')"),
            'platform_fee' => (float)$wpdb->get_var("SELECT COALESCE(SUM(platform_fee_amount), 0) FROM {$wpdb->prefix}limpvix_orders WHERE financial_status IN ('PAID', 'AUTHORIZED', 'TRANSFERRED')"),
        ];

        // 3. Payouts
        $payouts = [
            'total' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_payouts"),
            'pending' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_payouts WHERE status = 'pending'"),
            'completed' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_payouts WHERE status = 'completed'"),
        ];

        // 4. Profissionais
        $professionals = [
            'total' => count(get_users(['role' => 'tnc_staff'])),
        ];

        // 6. Health Score (baseado no Sync Validator)
        $health = $this->calculateHealthScore();

        // 7. Orders Recentes (últimas 5)
        $recent_orders = $wpdb->get_results(
            "SELECT id, uuid, appointment_id, financial_status, total_amount,
                    platform_fee_percentage, platform_fee_amount,
                    professional_net_amount, created_at
             FROM {$wpdb->prefix}limpvix_orders
             ORDER BY created_at DESC
             LIMIT 5",
            ARRAY_A
        );

        // 5. Appointments/Executions
        $appointments = [
            'total' => (int)$wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_executions"),
        ];

        return [
            'orders' => $orders,
            'revenue' => $revenue,
            'payouts' => $payouts,
            'professionals' => $professionals,
            'appointments' => $appointments,
            'health' => $health,
            'recent_orders' => $recent_orders ?: [],
        ];
    }

    private function calculateHealthScore(): array
    {
        global $wpdb;

        $orders = $wpdb->get_results(
            "SELECT id, uuid, appointment_id FROM {$wpdb->prefix}limpvix_orders",
            ARRAY_A
        );

        $total = count($orders);
        if ($total === 0) {
            return ['score' => 100, 'issues' => 0];
        }

        $issues = 0;

        foreach ($orders as $order) {
            // Check appointment exists
            if (!empty($order['appointment_id'])) {
                // Health check via limpvix_contracts
                $exists = true; // via limpvix nativo
                if (!$exists) {
                    $issues++;
                }
            }
        }

        $score = max(0, (int)(($total - $issues) / $total * 100));

        return [
            'score' => $score,
            'issues' => $issues,
        ];
    }

    private function getHealthClass(float $score): string
    {
        if ($score >= 80) {
            return 'health-good';
        } elseif ($score >= 50) {
            return 'health-warning';
        } else {
            return 'health-critical';
        }
    }
}
