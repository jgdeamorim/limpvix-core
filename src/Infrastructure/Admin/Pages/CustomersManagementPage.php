<?php
/**
 * CustomersManagementPage - Gerenciamento de Clientes/Contas
 *
 * RESPONSABILIDADE:
 * - Dashboard com estatísticas de clientes
 * - Listagem com filtros (contratos ativos, gasto total, data de registro)
 * - Visualização de histórico de cliente (contratos, pedidos, pagamentos, feedback)
 * - Busca por nome, email, telefone, endereço
 * - Ações rápidas (visualizar detalhes, enviar mensagem, editar contato)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.9.0 (ONDA 2)
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class CustomersManagementPage
{
    private const PAGE_SLUG = 'limpvix-customers';
    private const NONCE_ACTION = 'limpvix_customer_action';

    private $wpdb;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'limpvix-finance',
            'Clientes',
            'Clientes',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function enqueueAssets($hook): void
    {
        if (strpos($hook, self::PAGE_SLUG) === false) {
            return;
        }

        wp_enqueue_style(
            'limpvix-customers',
            plugins_url('assets/css/customers.css', LIMPVIX_PLUGIN_FILE),
            [],
            LIMPVIX_VERSION
        );
    }

    public function render(): void
    {
        $action = $_GET['action'] ?? 'list';
        $customerId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        ?>
        <div class="wrap">
            <h1 class="wp-heading-inline">
                <span class="dashicons dashicons-groups" style="font-size: 28px; vertical-align: middle;"></span>
                Clientes
            </h1>

            <?php if ($action === 'view' && $customerId > 0): ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="page-title-action">← Voltar para Lista</a>
            <?php endif; ?>

            <hr class="wp-header-end">

            <?php settings_errors('limpvix_customers'); ?>

            <?php
            if ($action === 'view' && $customerId > 0) {
                $this->renderCustomerDetail($customerId);
            } else {
                $this->renderDashboard();
                $this->renderCustomersList();
            }
            ?>
        </div>

        <style>
            .limpvix-stats-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .stat-card {
                background: #fff;
                border-left: 4px solid #2271b1;
                padding: 20px;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .stat-value {
                font-size: 32px;
                font-weight: bold;
                color: #2271b1;
            }
            .stat-label {
                color: #646970;
                font-size: 14px;
                margin-top: 8px;
            }
            .customer-filters {
                background: #fff;
                padding: 15px;
                margin: 20px 0;
                box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            }
            .customer-detail {
                background: #fff;
                padding: 20px;
                margin-top: 20px;
            }
            .customer-section {
                margin-top: 30px;
                border-top: 1px solid #ddd;
                padding-top: 20px;
            }
            .customer-section h3 {
                margin-top: 0;
            }
        </style>
        <?php
    }

    private function renderDashboard(): void
    {
        $stats = $this->getCustomerStatistics();

        ?>
        <div class="limpvix-stats-grid">
            <div class="stat-card">
                <div class="stat-value"><?php echo esc_html($stats['total_customers']); ?></div>
                <div class="stat-label">Total de Clientes</div>
            </div>
            <div class="stat-card" style="border-left-color: #00a32a;">
                <div class="stat-value" style="color: #00a32a;"><?php echo esc_html($stats['active_contracts']); ?></div>
                <div class="stat-label">Com Contratos Ativos</div>
            </div>
            <div class="stat-card" style="border-left-color: #f0b849;">
                <div class="stat-value" style="color: #f0b849;"><?php echo esc_html($stats['total_revenue_formatted']); ?></div>
                <div class="stat-label">Receita Total (30 dias)</div>
            </div>
            <div class="stat-card" style="border-left-color: #d63638;">
                <div class="stat-value" style="color: #d63638;"><?php echo esc_html($stats['avg_contract_value_formatted']); ?></div>
                <div class="stat-label">Valor Médio de Contrato</div>
            </div>
            <div class="stat-card" style="border-left-color: #4ab866;">
                <div class="stat-value" style="color: #4ab866;"><?php echo esc_html($stats['avg_ltv_formatted']); ?></div>
                <div class="stat-label">LTV Médio</div>
            </div>
            <div class="stat-card" style="border-left-color: #8c8f94;">
                <div class="stat-value" style="color: #8c8f94;"><?php echo esc_html($stats['new_this_month']); ?></div>
                <div class="stat-label">Novos este Mês</div>
            </div>
        </div>
        <?php
    }

    private function renderCustomersList(): void
    {
        // Get filters from query params
        $search = $_GET['s'] ?? '';
        $filter_status = $_GET['filter_status'] ?? 'all';
        $filter_min_spent = isset($_GET['filter_min_spent']) ? (float)$_GET['filter_min_spent'] : 0;

        ?>
        <h2 style="margin-top: 30px;">Lista de Clientes</h2>

        <!-- Filters -->
        <div class="customer-filters">
            <form method="get">
                <input type="hidden" name="page" value="<?php echo esc_attr(self::PAGE_SLUG); ?>">
                <div style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                    <input type="search" name="s" value="<?php echo esc_attr($search); ?>"
                           placeholder="Buscar por nome, email, telefone ou endereço..." style="min-width: 350px;">

                    <select name="filter_status">
                        <option value="all" <?php selected($filter_status, 'all'); ?>>Todos os Status</option>
                        <option value="active" <?php selected($filter_status, 'active'); ?>>Com Contratos Ativos</option>
                        <option value="inactive" <?php selected($filter_status, 'inactive'); ?>>Sem Contratos Ativos</option>
                    </select>

                    <label style="display: flex; align-items: center; gap: 5px;">
                        Gasto mín:
                        <input type="number" name="filter_min_spent" value="<?php echo esc_attr($filter_min_spent); ?>"
                               min="0" step="0.01" style="width: 100px;" placeholder="R$ 0,00">
                    </label>

                    <button type="submit" class="button">Filtrar</button>
                    <a href="?page=<?php echo esc_attr(self::PAGE_SLUG); ?>" class="button">Limpar</a>
                </div>
            </form>
        </div>

        <?php
        $customers = $this->getCustomers($search, $filter_status, $filter_min_spent);
        ?>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Nome</th>
                    <th>Email</th>
                    <th>Telefone</th>
                    <th>Contratos Ativos</th>
                    <th>Gasto Total</th>
                    <th>Cliente Desde</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px;">
                            <p><span class="dashicons dashicons-info" style="font-size: 40px; opacity: 0.3;"></span></p>
                            <p>Nenhum cliente encontrado.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($customers as $customer): ?>
                        <?php $this->renderCustomerRow($customer); ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderCustomerRow(array $customer): void
    {
        $userId = $customer['ID'];
        $activeContracts = $this->getActiveContractsCount($userId);
        $totalSpent = $this->getTotalSpent($userId);
        $memberSince = date('d/m/Y', strtotime($customer['user_registered']));

        ?>
        <tr>
            <td><?php echo esc_html($userId); ?></td>
            <td><strong><?php echo esc_html($customer['display_name']); ?></strong></td>
            <td><?php echo esc_html($customer['user_email']); ?></td>
            <td><?php echo esc_html(get_user_meta($userId, 'billing_phone', true) ?: '-'); ?></td>
            <td><?php echo esc_html($activeContracts); ?></td>
            <td><strong>R$ <?php echo number_format($totalSpent, 2, ',', '.'); ?></strong></td>
            <td><?php echo esc_html($memberSince); ?></td>
            <td>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=view&id=<?php echo $userId; ?>" class="button button-small">
                    Ver Detalhes
                </a>
            </td>
        </tr>
        <?php
    }

    private function renderCustomerDetail(int $customerId): void
    {
        $customer = get_userdata($customerId);

        if (!$customer) {
            echo '<div class="notice notice-error"><p>Cliente não encontrado.</p></div>';
            return;
        }

        $activeContracts = $this->getActiveContractsCount($customerId);
        $totalSpent = $this->getTotalSpent($customerId);
        $contracts = $this->getCustomerContracts($customerId);

        ?>
        <div class="customer-detail">
            <h2><?php echo esc_html($customer->display_name); ?></h2>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-top: 20px;">
                <div>
                    <h3>Informações Pessoais</h3>
                    <table class="form-table">
                        <tr>
                            <th>Nome:</th>
                            <td><?php echo esc_html($customer->display_name); ?></td>
                        </tr>
                        <tr>
                            <th>Email:</th>
                            <td><?php echo esc_html($customer->user_email); ?></td>
                        </tr>
                        <tr>
                            <th>Telefone:</th>
                            <td><?php echo esc_html(get_user_meta($customerId, 'billing_phone', true) ?: '-'); ?></td>
                        </tr>
                        <tr>
                            <th>Cliente desde:</th>
                            <td><?php echo date('d/m/Y H:i', strtotime($customer->user_registered)); ?></td>
                        </tr>
                    </table>
                </div>
                <div>
                    <h3>Resumo Financeiro</h3>
                    <table class="form-table">
                        <tr>
                            <th>Contratos Ativos:</th>
                            <td><strong><?php echo $activeContracts; ?></strong></td>
                        </tr>
                        <tr>
                            <th>Gasto Total:</th>
                            <td><strong>R$ <?php echo number_format($totalSpent, 2, ',', '.'); ?></strong></td>
                        </tr>
                        <tr>
                            <th>LTV Estimado:</th>
                            <td>R$ <?php echo number_format($totalSpent * 1.5, 2, ',', '.'); ?></td>
                        </tr>
                    </table>
                </div>
            </div>

            <div class="customer-section">
                <h3>Histórico de Contratos</h3>
                <?php if (empty($contracts)): ?>
                    <p>Nenhum contrato encontrado.</p>
                <?php else: ?>
                    <table class="wp-list-table widefat">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Tipo</th>
                                <th>Valor Mensal</th>
                                <th>Status</th>
                                <th>Início</th>
                                <th>Fim</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($contracts as $contract): ?>
                                <tr>
                                    <td><?php echo esc_html($contract['id']); ?></td>
                                    <td><?php echo esc_html(ucfirst($contract['contract_type'])); ?></td>
                                    <td>R$ <?php echo number_format($contract['monthly_value'], 2, ',', '.'); ?></td>
                                    <td><?php echo esc_html(ucfirst($contract['status'])); ?></td>
                                    <td><?php echo date('d/m/Y', strtotime($contract['start_date'])); ?></td>
                                    <td><?php echo $contract['end_date'] ? date('d/m/Y', strtotime($contract['end_date'])) : '-'; ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>
        </div>
        <?php
    }

    private function getCustomerStatistics(): array
    {
        $contractsTable = $this->wpdb->prefix . 'limpvix_contracts';

        // Total customers with contracts
        $totalCustomers = $this->wpdb->get_var("
            SELECT COUNT(DISTINCT client_user_id)
            FROM {$contractsTable}
        ");

        // Customers with active contracts
        $activeContracts = $this->wpdb->get_var("
            SELECT COUNT(DISTINCT client_user_id)
            FROM {$contractsTable}
            WHERE status = 'active'
        ");

        // Total revenue last 30 days (from active contracts)
        $totalRevenue = $this->wpdb->get_var("
            SELECT COALESCE(SUM(monthly_value), 0)
            FROM {$contractsTable}
            WHERE status = 'active'
        ");

        // Average contract value
        $avgContractValue = $this->wpdb->get_var("
            SELECT COALESCE(AVG(monthly_value), 0)
            FROM {$contractsTable}
            WHERE status = 'active'
        ");

        // New customers this month
        $firstDayOfMonth = date('Y-m-01 00:00:00');
        $newThisMonth = $this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(DISTINCT client_user_id)
            FROM {$contractsTable}
            WHERE created_at >= %s
        ", $firstDayOfMonth));

        return [
            'total_customers' => (int)$totalCustomers,
            'active_contracts' => (int)$activeContracts,
            'total_revenue' => (float)$totalRevenue,
            'total_revenue_formatted' => 'R$ ' . number_format($totalRevenue, 2, ',', '.'),
            'avg_contract_value' => (float)$avgContractValue,
            'avg_contract_value_formatted' => 'R$ ' . number_format($avgContractValue, 2, ',', '.'),
            'avg_ltv' => (float)$avgContractValue * 1.5,
            'avg_ltv_formatted' => 'R$ ' . number_format($avgContractValue * 1.5, 2, ',', '.'),
            'new_this_month' => (int)$newThisMonth,
        ];
    }

    private function getCustomers(string $search, string $filter_status, float $filter_min_spent): array
    {
        $contractsTable = $this->wpdb->prefix . 'limpvix_contracts';

        // Get all unique customer IDs from contracts
        $customerIds = $this->wpdb->get_col("
            SELECT DISTINCT client_user_id
            FROM {$contractsTable}
        ");

        if (empty($customerIds)) {
            return [];
        }

        // Build user query args
        $args = [
            'include' => $customerIds,
            'orderby' => 'user_registered',
            'order' => 'DESC',
        ];

        // Search filter
        if (!empty($search)) {
            $args['search'] = '*' . esc_attr($search) . '*';
            $args['search_columns'] = ['user_login', 'user_email', 'display_name'];
        }

        $users = get_users($args);

        // Apply additional filters
        if ($filter_status !== 'all' || $filter_min_spent > 0) {
            $users = array_filter($users, function($user) use ($filter_status, $filter_min_spent) {
                $activeCount = $this->getActiveContractsCount($user->ID);
                $totalSpent = $this->getTotalSpent($user->ID);

                if ($filter_status === 'active' && $activeCount == 0) {
                    return false;
                }
                if ($filter_status === 'inactive' && $activeCount > 0) {
                    return false;
                }
                if ($filter_min_spent > 0 && $totalSpent < $filter_min_spent) {
                    return false;
                }

                return true;
            });
        }

        return array_map(function($user) {
            return [
                'ID' => $user->ID,
                'display_name' => $user->display_name,
                'user_email' => $user->user_email,
                'user_registered' => $user->user_registered,
            ];
        }, $users);
    }

    private function getActiveContractsCount(int $userId): int
    {
        $contractsTable = $this->wpdb->prefix . 'limpvix_contracts';

        return (int)$this->wpdb->get_var($this->wpdb->prepare("
            SELECT COUNT(*)
            FROM {$contractsTable}
            WHERE client_user_id = %d AND status = 'active'
        ", $userId));
    }

    private function getTotalSpent(int $userId): float
    {
        $contractsTable = $this->wpdb->prefix . 'limpvix_contracts';

        // Sum all contract values (active and completed)
        return (float)$this->wpdb->get_var($this->wpdb->prepare("
            SELECT COALESCE(SUM(monthly_value), 0)
            FROM {$contractsTable}
            WHERE client_user_id = %d
        ", $userId));
    }

    private function getCustomerContracts(int $userId): array
    {
        $contractsTable = $this->wpdb->prefix . 'limpvix_contracts';

        return $this->wpdb->get_results($this->wpdb->prepare("
            SELECT id, contract_type, monthly_value, status, start_date, end_date
            FROM {$contractsTable}
            WHERE client_user_id = %d
            ORDER BY created_at DESC
        ", $userId), ARRAY_A);
    }
}
