<?php
/**
 * SyncValidatorController - Validador de Sincronização WC ↔ Booknetic (BLC-004)
 *
 * RESPONSABILIDADE:
 * - Verificar consistência entre WooCommerce e Booknetic
 * - Detectar divergências de price, status, mapeamento
 * - Gerar relatório de saúde da sincronização
 *
 * PRINCÍPIOS:
 * - Read-only (diagnóstico, não corrige)
 * - Fail-safe (não quebra sistemas)
 * - Actionable (mostra onde está o problema)
 *
 * VALIDAÇÕES:
 * - WC Order tem LimpVix Order UUID?
 * - LimpVix Order tem Booknetic Appointment?
 * - Price do Booknetic bate com LimpVix?
 * - Status sincronizados?
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;

defined('ABSPATH') || exit;

class SyncValidatorController
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

        // Executar validações
        $report = $this->runValidations();

        // Renderizar view
        $this->renderView($report);
    }

    /**
     * Executar validações
     *
     * @return array
     */
    private function runValidations(): array
    {
        $report = [
            'total_orders' => 0,
            'valid' => 0,
            'warnings' => 0,
            'errors' => 0,
            'issues' => []
        ];

        global $wpdb;

        // Buscar todas as orders LimpVix
        $orders = $wpdb->get_results(
            "SELECT id, uuid, appointment_id, total_amount, financial_status
             FROM {$wpdb->prefix}limpvix_orders
             ORDER BY created_at DESC
             LIMIT 100",
            ARRAY_A
        );

        $report['total_orders'] = count($orders);

        foreach ($orders as $order) {
            $this->validateOrder($order, $report);
        }

        return $report;
    }

    /**
     * Validar uma order específica
     *
     * @param array $order
     * @param array &$report
     * @return void
     */
    private function validateOrder(array $order, array &$report): void
    {
        global $wpdb;

        $orderUuid = $order['uuid'];
        $hasIssue = false;

        // 1. Verificar se tem appointment vinculado
        if (empty($order['appointment_id'])) {
            $report['issues'][] = [
                'severity' => 'warning',
                'order_uuid' => $orderUuid,
                'message' => 'Order sem Appointment ID vinculado'
            ];
            $hasIssue = true;
        } else {
            // 2. Verificar se appointment existe no Booknetic
            $appointment = $wpdb->get_row($wpdb->prepare(
                "SELECT a.id, a.price as appointment_price, s.price as service_price
                 FROM {$wpdb->prefix}bkntc_appointments a
                 LEFT JOIN {$wpdb->prefix}bkntc_services s ON a.service_id = s.id
                 WHERE a.id = %d",
                $order['appointment_id']
            ), ARRAY_A);

            if (!$appointment) {
                $report['issues'][] = [
                    'severity' => 'error',
                    'order_uuid' => $orderUuid,
                    'message' => "Appointment #{$order['appointment_id']} não existe no Booknetic"
                ];
                $hasIssue = true;
            } else {
                // 3. Verificar se price bate
                $bookneticPrice = (float)$appointment['service_price'];
                $limpvixPrice = (float)$order['total_amount'];

                if (abs($bookneticPrice - $limpvixPrice) > 0.01) {
                    $report['issues'][] = [
                        'severity' => 'error',
                        'order_uuid' => $orderUuid,
                        'message' => sprintf(
                            'Divergência de price: Booknetic R$%.2f vs LimpVix R$%.2f',
                            $bookneticPrice,
                            $limpvixPrice
                        )
                    ];
                    $hasIssue = true;
                }
            }
        }

        // 4. Verificar se tem WC Order vinculado
        $hposEnabled = get_option('woocommerce_custom_orders_table_enabled', 'no') === 'yes';

        if ($hposEnabled) {
            $wcOrderExists = $wpdb->get_var($wpdb->prepare(
                "SELECT order_id FROM {$wpdb->prefix}wc_orders_meta
                 WHERE meta_key = '_limpvix_order_uuid' AND meta_value = %s",
                $orderUuid
            ));
        } else {
            $wcOrderExists = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta}
                 WHERE meta_key = '_limpvix_order_uuid' AND meta_value = %s",
                $orderUuid
            ));
        }

        if (!$wcOrderExists) {
            $report['issues'][] = [
                'severity' => 'warning',
                'order_uuid' => $orderUuid,
                'message' => 'Order sem WC Order vinculado'
            ];
            $hasIssue = true;
        }

        // Contabilizar
        if ($hasIssue) {
            // Verificar severidade máxima
            $hasError = false;
            foreach ($report['issues'] as $issue) {
                if ($issue['order_uuid'] === $orderUuid && $issue['severity'] === 'error') {
                    $hasError = true;
                    break;
                }
            }

            if ($hasError) {
                $report['errors']++;
            } else {
                $report['warnings']++;
            }
        } else {
            $report['valid']++;
        }
    }

    /**
     * Renderizar view
     *
     * @param array $report
     * @return void
     */
    private function renderView(array $report): void
    {
        $healthScore = $report['total_orders'] > 0
            ? round(($report['valid'] / $report['total_orders']) * 100, 1)
            : 100;

        $healthClass = $healthScore >= 90 ? 'good' : ($healthScore >= 70 ? 'warning' : 'critical');

        ?>
        <div class="wrap limpvix-sync-validator">
            <h1>Sync Validator WC ↔ Booknetic</h1>

            <!-- Health Score -->
            <div class="limpvix-health-card limpvix-health-<?php echo esc_attr($healthClass); ?>">
                <div class="health-score">
                    <span class="score-value"><?php echo esc_html($healthScore); ?>%</span>
                    <span class="score-label">Saúde da Sincronização</span>
                </div>
                <div class="health-stats">
                    <div class="stat">
                        <strong><?php echo esc_html($report['total_orders']); ?></strong>
                        <span>Total Orders</span>
                    </div>
                    <div class="stat stat-valid">
                        <strong><?php echo esc_html($report['valid']); ?></strong>
                        <span>Válidas</span>
                    </div>
                    <div class="stat stat-warning">
                        <strong><?php echo esc_html($report['warnings']); ?></strong>
                        <span>Warnings</span>
                    </div>
                    <div class="stat stat-error">
                        <strong><?php echo esc_html($report['errors']); ?></strong>
                        <span>Errors</span>
                    </div>
                </div>
            </div>

            <!-- Issues -->
            <?php if (!empty($report['issues'])): ?>
                <h2>Problemas Detectados (<?php echo count($report['issues']); ?>)</h2>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 100px;">Severidade</th>
                            <th style="width: 150px;">Order UUID</th>
                            <th>Problema</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($report['issues'] as $issue): ?>
                            <tr>
                                <td>
                                    <span class="limpvix-severity limpvix-severity-<?php echo esc_attr($issue['severity']); ?>">
                                        <?php echo esc_html(strtoupper($issue['severity'])); ?>
                                    </span>
                                </td>
                                <td>
                                    <code><?php echo esc_html(substr($issue['order_uuid'], 0, 8)); ?>...</code>
                                </td>
                                <td><?php echo esc_html($issue['message']); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php else: ?>
                <div class="notice notice-success">
                    <p><strong>🎉 Perfeito!</strong> Todos os sistemas estão sincronizados corretamente.</p>
                </div>
            <?php endif; ?>

            <style>
                .limpvix-health-card {
                    background: #fff;
                    border-left: 4px solid #ccc;
                    padding: 20px;
                    margin: 20px 0;
                    display: flex;
                    gap: 40px;
                    align-items: center;
                }
                .limpvix-health-good { border-left-color: #00a32a; }
                .limpvix-health-warning { border-left-color: #dba617; }
                .limpvix-health-critical { border-left-color: #b32d2e; }

                .health-score {
                    text-align: center;
                }
                .score-value {
                    display: block;
                    font-size: 48px;
                    font-weight: bold;
                    line-height: 1;
                }
                .score-label {
                    display: block;
                    font-size: 12px;
                    color: #666;
                    margin-top: 8px;
                }

                .health-stats {
                    display: flex;
                    gap: 30px;
                }
                .stat {
                    text-align: center;
                }
                .stat strong {
                    display: block;
                    font-size: 24px;
                }
                .stat span {
                    display: block;
                    font-size: 11px;
                    color: #666;
                    margin-top: 4px;
                }

                .limpvix-severity {
                    display: inline-block;
                    padding: 4px 8px;
                    border-radius: 3px;
                    font-size: 11px;
                    font-weight: 600;
                }
                .limpvix-severity-warning {
                    background: #fcf9e8;
                    color: #b32d2e;
                }
                .limpvix-severity-error {
                    background: #fcf0f1;
                    color: #b32d2e;
                }
            </style>
        </div>
        <?php
    }
}
