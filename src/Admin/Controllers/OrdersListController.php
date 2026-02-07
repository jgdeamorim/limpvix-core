<?php
/**
 * OrdersListController - Controller para Lista de Orders (BLC-001)
 *
 * RESPONSABILIDADE:
 * - Renderizar lista de orders financeiras
 * - Filtros básicos por status
 * - Visibilidade operacional completa
 *
 * PRINCÍPIOS:
 * - MVC Pattern
 * - Read-only (não modifica estado)
 * - Permissions gated
 *
 * DADOS EXIBIDOS:
 * - Order ID + UUID
 * - Status financeiro
 * - Valores (Total, Taxa, Líquido)
 * - Data de criação
 * - Appointment vinculado
 *
 * @package LimpVix\Admin\Controllers
 */

namespace LimpVix\Admin\Controllers;

use LimpVix\Admin\Capabilities\FinanceCapabilities;

defined('ABSPATH') || exit;

class OrdersListController
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

        // Buscar orders
        $orders = $this->fetchOrders();

        // Renderizar view
        $this->renderView($orders);
    }

    /**
     * Buscar orders do banco
     *
     * @return array
     */
    private function fetchOrders(): array
    {
        global $wpdb;

        // Filtro de status (se aplicado)
        $statusFilter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';

        $query = "SELECT
            id,
            uuid,
            appointment_id,
            customer_id,
            professional_id,
            financial_status,
            total_amount,
            platform_fee_percentage,
            platform_fee_amount,
            professional_net_amount,
            created_at,
            updated_at
        FROM {$wpdb->prefix}limpvix_orders";

        if (!empty($statusFilter)) {
            $query .= $wpdb->prepare(" WHERE financial_status = %s", $statusFilter);
        }

        $query .= " ORDER BY created_at DESC LIMIT 100";

        // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
        $orders = $wpdb->get_results($query, ARRAY_A);

        return $orders ?: [];
    }

    /**
     * Renderizar view
     *
     * @param array $orders
     * @return void
     */
    private function renderView(array $orders): void
    {
        $statusFilter = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
        $totalOrders = count($orders);

        ?>
        <div class="wrap limpvix-finance">
            <h1>
                Orders Financeiras
                <span class="title-count">(<?php echo esc_html($totalOrders); ?>)</span>
            </h1>

            <!-- Filtros -->
            <div class="tablenav top">
                <div class="alignleft actions">
                    <form method="get">
                        <input type="hidden" name="page" value="limpvix-orders">
                        <select name="status">
                            <option value="">Todos os status</option>
                            <option value="CREATED" <?php selected($statusFilter, 'CREATED'); ?>>CREATED</option>
                            <option value="PAID" <?php selected($statusFilter, 'PAID'); ?>>PAID</option>
                            <option value="HELD" <?php selected($statusFilter, 'HELD'); ?>>HELD</option>
                            <option value="REVIEW" <?php selected($statusFilter, 'REVIEW'); ?>>REVIEW</option>
                            <option value="AUTHORIZED" <?php selected($statusFilter, 'AUTHORIZED'); ?>>AUTHORIZED</option>
                            <option value="TRANSFERRED" <?php selected($statusFilter, 'TRANSFERRED'); ?>>TRANSFERRED</option>
                            <option value="BLOCKED" <?php selected($statusFilter, 'BLOCKED'); ?>>BLOCKED</option>
                            <option value="REFUNDED" <?php selected($statusFilter, 'REFUNDED'); ?>>REFUNDED</option>
                        </select>
                        <button type="submit" class="button">Filtrar</button>
                        <?php if (!empty($statusFilter)): ?>
                            <a href="<?php echo esc_url(admin_url('admin.php?page=limpvix-orders')); ?>" class="button">Limpar</a>
                        <?php endif; ?>
                    </form>
                </div>
            </div>

            <!-- Tabela -->
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width: 60px;">ID</th>
                        <th style="width: 100px;">UUID</th>
                        <th>Status</th>
                        <th style="width: 100px;">Total</th>
                        <th style="width: 80px;">Taxa</th>
                        <th style="width: 100px;">Líquido Prof.</th>
                        <th style="width: 80px;">Appt ID</th>
                        <th style="width: 130px;">Criado em</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($orders)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; padding: 40px;">
                                <em>Nenhuma order encontrada.</em>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong><?php echo esc_html($order['id']); ?></strong></td>
                                <td>
                                    <code title="<?php echo esc_attr($order['uuid']); ?>">
                                        <?php echo esc_html(substr($order['uuid'], 0, 8)); ?>...
                                    </code>
                                </td>
                                <td>
                                    <span class="limpvix-status limpvix-status-<?php echo esc_attr(strtolower($order['financial_status'])); ?>">
                                        <?php echo esc_html($order['financial_status']); ?>
                                    </span>
                                </td>
                                <td>R$ <?php echo number_format($order['total_amount'], 2, ',', '.'); ?></td>
                                <td><?php echo number_format($order['platform_fee_percentage'], 1); ?>%</td>
                                <td><strong>R$ <?php echo number_format($order['professional_net_amount'], 2, ',', '.'); ?></strong></td>
                                <td><?php echo $order['appointment_id'] ? esc_html($order['appointment_id']) : '-'; ?></td>
                                <td><?php echo esc_html(date('d/m/Y H:i', strtotime($order['created_at']))); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

            <style>
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
        </div>
        <?php
    }
}
