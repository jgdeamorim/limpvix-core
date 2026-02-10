<?php
/**
 * Relatório Admin - LimpVix Core
 * Acesse via: http://localhost/wp-content/plugins/limpvix-core/admin-report.php
 */

// Bootstrap WordPress
require_once('../../../../../../wp-load.php');

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Acesso negado. Faça login como administrador.');
}

global $wpdb;

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Relatório Admin - LimpVix Core</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, sans-serif;
            background: #f0f0f1;
            padding: 20px;
        }
        .container { max-width: 1200px; margin: 0 auto; }
        h1 { color: #1d2327; margin-bottom: 30px; }
        h2 { color: #1d2327; margin: 30px 0 15px; padding-bottom: 10px; border-bottom: 2px solid #0073aa; }
        .card {
            background: white;
            border-radius: 4px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            text-align: left;
            padding: 12px 8px;
            border-bottom: 1px solid #e0e0e0;
        }
        th {
            background: #f6f7f7;
            font-weight: 600;
            color: #1d2327;
        }
        .status {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
        }
        .status-paid { background: #c6e1c6; color: #00a32a; }
        .status-created { background: #f0f0f1; color: #50575e; }
        .status-authorized { background: #d5e5f9; color: #135e96; }
        .status-transferred { background: #00ba37; color: #fff; }
        .severity-error { background: #fcf0f1; color: #b32d2e; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; }
        .severity-warning { background: #fcf9e8; color: #b32d2e; padding: 4px 8px; border-radius: 3px; font-size: 11px; font-weight: 600; }
        .health-good { color: #00a32a; font-size: 24px; font-weight: bold; }
        .health-warning { color: #dba617; font-size: 24px; font-weight: bold; }
        .health-critical { color: #b32d2e; font-size: 24px; font-weight: bold; }
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .stat-box {
            background: #f6f7f7;
            padding: 15px;
            border-radius: 4px;
            text-align: center;
        }
        .stat-box strong {
            display: block;
            font-size: 32px;
            color: #0073aa;
        }
        .stat-box span {
            display: block;
            font-size: 13px;
            color: #646970;
            margin-top: 5px;
        }
        code {
            background: #f6f7f7;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>📊 Relatório Admin - LimpVix Core</h1>

        <!-- ESTATÍSTICAS GERAIS -->
        <div class="card">
            <h2>📈 Estatísticas Gerais</h2>
            <div class="stats">
                <div class="stat-box">
                    <strong><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_orders"); ?></strong>
                    <span>Total Orders</span>
                </div>
                <div class="stat-box">
                    <strong><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_ledger"); ?></strong>
                    <span>Eventos no Ledger</span>
                </div>
                <div class="stat-box">
                    <strong><?php echo $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}limpvix_payouts"); ?></strong>
                    <span>Payouts</span>
                </div>
                <div class="stat-box">
                    <strong>R$ <?php echo number_format($wpdb->get_var("SELECT SUM(total_amount) FROM {$wpdb->prefix}limpvix_orders"), 2, ',', '.'); ?></strong>
                    <span>Volume Total</span>
                </div>
            </div>
        </div>

        <!-- ORDERS -->
        <div class="card">
            <h2>📋 Orders Financeiras</h2>
            <?php
            $orders = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}limpvix_orders ORDER BY created_at DESC LIMIT 20",
                ARRAY_A
            );

            if (count($orders) > 0):
            ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>UUID</th>
                        <th>Status</th>
                        <th>Total</th>
                        <th>Taxa (%)</th>
                        <th>Taxa (R$)</th>
                        <th>Líquido</th>
                        <th>Appointment</th>
                        <th>Criado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                    <tr>
                        <td><strong><?php echo $order['id']; ?></strong></td>
                        <td><code><?php echo substr($order['uuid'], 0, 13); ?>...</code></td>
                        <td>
                            <span class="status status-<?php echo strtolower($order['financial_status']); ?>">
                                <?php echo $order['financial_status']; ?>
                            </span>
                        </td>
                        <td>R$ <?php echo number_format($order['total_amount'], 2, ',', '.'); ?></td>
                        <td><?php echo number_format($order['platform_fee_percentage'], 1); ?>%</td>
                        <td>R$ <?php echo number_format($order['platform_fee_amount'], 2, ',', '.'); ?></td>
                        <td><strong>R$ <?php echo number_format($order['professional_net_amount'], 2, ',', '.'); ?></strong></td>
                        <td><?php echo $order['appointment_id'] ?: '-'; ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($order['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="padding: 40px; text-align: center; color: #646970;">
                <em>Nenhuma order encontrada</em>
            </p>
            <?php endif; ?>
        </div>

        <!-- SYNC VALIDATOR -->
        <div class="card">
            <h2>🔍 Sync Validator</h2>
            <?php
            // Executar validação
            $report = ['total_orders' => 0, 'valid' => 0, 'warnings' => 0, 'errors' => 0, 'issues' => []];
            $orders = $wpdb->get_results("SELECT id, uuid, appointment_id, total_amount FROM {$wpdb->prefix}limpvix_orders LIMIT 100", ARRAY_A);
            $report['total_orders'] = count($orders);

            foreach ($orders as $order) {
                $orderUuid = $order['uuid'];
                $hasIssue = false;

                if (empty($order['appointment_id'])) {
                    $report['issues'][] = [
                        'severity' => 'warning',
                        'order_id' => $order['id'],
                        'order_uuid' => substr($orderUuid, 0, 8) . '...',
                        'message' => 'Order sem Appointment ID'
                    ];
                    $hasIssue = true;
                } else {
                    $appointment = $wpdb->get_row($wpdb->prepare(
                        "SELECT a.id, s.price FROM {$wpdb->prefix}bkntc_appointments a
                         LEFT JOIN {$wpdb->prefix}bkntc_services s ON a.service_id = s.id
                         WHERE a.id = %d",
                        $order['appointment_id']
                    ), ARRAY_A);

                    if (!$appointment) {
                        $report['issues'][] = [
                            'severity' => 'error',
                            'order_id' => $order['id'],
                            'order_uuid' => substr($orderUuid, 0, 8) . '...',
                            'message' => "Appointment #{$order['appointment_id']} não existe"
                        ];
                        $hasIssue = true;
                    } elseif (abs((float)$appointment['price'] - (float)$order['total_amount']) > 0.01) {
                        $report['issues'][] = [
                            'severity' => 'error',
                            'order_id' => $order['id'],
                            'order_uuid' => substr($orderUuid, 0, 8) . '...',
                            'message' => sprintf('Divergência de price: Booknetic R$%.2f vs LimpVix R$%.2f', $appointment['price'], $order['total_amount'])
                        ];
                        $hasIssue = true;
                    }
                }

                if ($hasIssue) {
                    $hasError = false;
                    foreach ($report['issues'] as $issue) {
                        if ($issue['order_id'] === $order['id'] && $issue['severity'] === 'error') {
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

            $healthScore = $report['total_orders'] > 0 ? round(($report['valid'] / $report['total_orders']) * 100, 1) : 100;
            $healthClass = $healthScore >= 90 ? 'good' : ($healthScore >= 70 ? 'warning' : 'critical');
            ?>

            <div class="stats">
                <div class="stat-box">
                    <strong class="health-<?php echo $healthClass; ?>"><?php echo $healthScore; ?>%</strong>
                    <span>Health Score</span>
                </div>
                <div class="stat-box">
                    <strong style="color: #00a32a;"><?php echo $report['valid']; ?></strong>
                    <span>Válidas</span>
                </div>
                <div class="stat-box">
                    <strong style="color: #dba617;"><?php echo $report['warnings']; ?></strong>
                    <span>Warnings</span>
                </div>
                <div class="stat-box">
                    <strong style="color: #b32d2e;"><?php echo $report['errors']; ?></strong>
                    <span>Errors</span>
                </div>
            </div>

            <?php if (!empty($report['issues'])): ?>
            <h3 style="margin-top: 20px; color: #1d2327;">Problemas Detectados (<?php echo count($report['issues']); ?>)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Severidade</th>
                        <th>Order ID</th>
                        <th>Order UUID</th>
                        <th>Problema</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($report['issues'] as $issue): ?>
                    <tr>
                        <td>
                            <span class="severity-<?php echo $issue['severity']; ?>">
                                <?php echo strtoupper($issue['severity']); ?>
                            </span>
                        </td>
                        <td><?php echo $issue['order_id']; ?></td>
                        <td><code><?php echo $issue['order_uuid']; ?></code></td>
                        <td><?php echo $issue['message']; ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="padding: 20px; text-align: center; color: #00a32a;">
                <strong>🎉 Perfeito!</strong> Todos os sistemas estão sincronizados corretamente.
            </p>
            <?php endif; ?>
        </div>

        <!-- PAYOUTS -->
        <div class="card">
            <h2>💰 Payouts</h2>
            <?php
            $payouts = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}limpvix_payouts ORDER BY created_at DESC LIMIT 20",
                ARRAY_A
            );

            if (count($payouts) > 0):
            ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Order ID</th>
                        <th>Professional ID</th>
                        <th>Status</th>
                        <th>Gross</th>
                        <th>Taxa</th>
                        <th>Líquido</th>
                        <th>Gateway</th>
                        <th>Transfer ID</th>
                        <th>Criado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payouts as $payout): ?>
                    <tr>
                        <td><strong><?php echo $payout['id']; ?></strong></td>
                        <td><?php echo $payout['order_id']; ?></td>
                        <td><?php echo $payout['professional_id']; ?></td>
                        <td><span class="status"><?php echo $payout['status']; ?></span></td>
                        <td>R$ <?php echo number_format($payout['gross_amount'], 2, ',', '.'); ?></td>
                        <td>R$ <?php echo number_format($payout['platform_fee'], 2, ',', '.'); ?></td>
                        <td><strong>R$ <?php echo number_format($payout['net_amount'], 2, ',', '.'); ?></strong></td>
                        <td><?php echo $payout['gateway']; ?></td>
                        <td><?php echo $payout['gateway_transfer_id'] ?: '-'; ?></td>
                        <td><?php echo date('d/m/Y H:i', strtotime($payout['created_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="padding: 40px; text-align: center; color: #646970;">
                <em>Nenhum payout encontrado. Isso é normal se nenhuma order chegou a AUTHORIZED ainda.</em>
            </p>
            <?php endif; ?>
        </div>

        <!-- LEDGER -->
        <div class="card">
            <h2>📜 Ledger (Auditoria)</h2>
            <?php
            $ledger = $wpdb->get_results(
                "SELECT * FROM {$wpdb->prefix}limpvix_ledger ORDER BY occurred_at DESC LIMIT 20",
                ARRAY_A
            );

            if (count($ledger) > 0):
            ?>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Order UUID</th>
                        <th>De</th>
                        <th>Para</th>
                        <th>Motivo</th>
                        <th>Actor</th>
                        <th>Ocorreu em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($ledger as $entry): ?>
                    <tr>
                        <td><?php echo $entry['id']; ?></td>
                        <td><code><?php echo substr($entry['order_uuid'], 0, 13); ?>...</code></td>
                        <td><span class="status"><?php echo $entry['from_status']; ?></span></td>
                        <td><span class="status"><?php echo $entry['to_status']; ?></span></td>
                        <td><?php echo $entry['reason']; ?></td>
                        <td><?php echo $entry['actor']; ?></td>
                        <td><?php echo date('d/m/Y H:i:s', strtotime($entry['occurred_at'])); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <p style="padding: 40px; text-align: center; color: #646970;">
                <em>Ledger vazio. Isso indica que nenhuma transição de status foi gravada ainda.</em>
            </p>
            <?php endif; ?>
        </div>

        <p style="margin-top: 30px; text-align: center; color: #646970; font-size: 12px;">
            Gerado em <?php echo date('d/m/Y H:i:s'); ?> • LimpVix Core
        </p>
    </div>
</body>
</html>
