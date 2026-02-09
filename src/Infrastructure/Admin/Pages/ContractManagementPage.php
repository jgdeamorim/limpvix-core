<?php
/**
 * ContractManagementPage - Gerenciamento de Contratos Recorrentes
 *
 * RESPONSABILIDADE:
 * - Dashboard com estatísticas de contratos
 * - CRUD completo de contratos recorrentes
 * - Listagem com filtros (status, cliente, data)
 * - Calendário de próximas execuções
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.13
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class ContractManagementPage
{
    private const PAGE_SLUG = 'limpvix-contracts';
    private const NONCE_ACTION = 'limpvix_contract_action';

    private $wpdb;
    private $tableContracts;
    private $tableExecutions;
    private $repository;
    private $useCases;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableContracts = $wpdb->prefix . 'limpvix_contracts';
        $this->tableExecutions = $wpdb->prefix . 'limpvix_contract_executions';

        // Dependency Injection via globals (Bootstrap)
        $this->repository = $GLOBALS['limpvix_contract_repository'] ?? null;
        $this->useCases = $GLOBALS['limpvix_contract_use_cases'] ?? [];
    }

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'handleFormSubmission']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'limpvix',
            'Contratos Recorrentes',
            'Contratos',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function handleFormSubmission(): void
    {
        if (!isset($_POST['limpvix_contract_action_type'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $action = sanitize_text_field($_POST['limpvix_contract_action_type']);

        $nonceField = 'limpvix_contract_nonce_' . $action;
        if (!isset($_POST[$nonceField]) || !wp_verify_nonce($_POST[$nonceField], self::NONCE_ACTION . '_' . $action)) {
            wp_die('Nonce inválido');
        }

        switch ($action) {
            case 'save_contract':
                $this->saveContract();
                break;
            case 'cancel_contract':
                $this->cancelContract();
                break;
            case 'reactivate_contract':
                $this->reactivateContract();
                break;
        }
    }

    private function saveContract(): void
    {
        $contractId = isset($_POST['contract_id']) ? (int)$_POST['contract_id'] : 0;
        $clientUserId = (int)($_POST['client_user_id'] ?? 0);
        $contractType = sanitize_text_field($_POST['contract_type'] ?? '');
        $recurrenceDay = isset($_POST['recurrence_day']) ? (int)$_POST['recurrence_day'] : 1;
        $serviceCode = sanitize_text_field($_POST['service_code'] ?? '');
        $propertyType = sanitize_text_field($_POST['property_type'] ?? '');
        $monthlyValue = (float)($_POST['monthly_value'] ?? 0);
        $startDate = sanitize_text_field($_POST['start_date'] ?? '');
        $endDate = !empty($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : null;
        $autoRenew = isset($_POST['auto_renew']) ? true : false;

        // Validations
        if ($clientUserId <= 0 || empty($contractType) || empty($serviceCode) || empty($startDate) || $monthlyValue <= 0) {
            add_settings_error('limpvix_contracts', 'invalid_data', 'Campos obrigatórios não preenchidos ou inválidos', 'error');
            return;
        }

        try {
            if ($contractId > 0) {
                // UPDATE: Para updates, vamos usar Repository diretamente
                // TODO: Criar Use Case específico para Update se necessário
                if (!$this->repository) {
                    throw new \Exception('Repository not available');
                }

                $contract = $this->repository->findById(\LimpVix\Domain\Contract\ContractId::fromInt($contractId));
                if (!$contract) {
                    throw new \Exception('Contract not found');
                }

                // Re-save para atualizar timestamps
                $this->repository->save($contract);
                $message = 'Contrato atualizado!';
            } else {
                // CREATE: Usar CreateContract Use Case
                if (!isset($this->useCases['create'])) {
                    throw new \Exception('CreateContract Use Case not available');
                }

                /** @var \LimpVix\Application\UseCase\Contract\CreateContract $createUseCase */
                $createUseCase = $this->useCases['create'];

                $contract = $createUseCase->execute([
                    'client_user_id' => $clientUserId,
                    'contract_type' => $contractType,
                    'recurrence_day' => $recurrenceDay,
                    'service_code' => $serviceCode,
                    'property_type' => $propertyType,
                    'monthly_value' => $monthlyValue,
                    'start_date' => $startDate,
                    'end_date' => $endDate,
                    'auto_renew' => $autoRenew
                ]);

                $message = 'Contrato criado!';
            }

            add_settings_error('limpvix_contracts', 'contract_saved', $message, 'success');
        } catch (\Exception $e) {
            add_settings_error('limpvix_contracts', 'contract_error', 'Erro: ' . $e->getMessage(), 'error');
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=1'));
        exit;
    }

    private function cancelContract(): void
    {
        $contractId = (int)($_POST['contract_id'] ?? 0);
        $reason = sanitize_textarea_field($_POST['cancellation_reason'] ?? '');

        try {
            if (!isset($this->useCases['cancel'])) {
                throw new \Exception('CancelContract Use Case not available');
            }

            /** @var \LimpVix\Application\UseCase\Contract\CancelContract $cancelUseCase */
            $cancelUseCase = $this->useCases['cancel'];
            $cancelUseCase->execute($contractId, $reason);

            add_settings_error('limpvix_contracts', 'contract_cancelled', 'Contrato cancelado!', 'success');
        } catch (\Exception $e) {
            add_settings_error('limpvix_contracts', 'contract_error', 'Erro ao cancelar: ' . $e->getMessage(), 'error');
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&cancelled=1'));
        exit;
    }

    private function reactivateContract(): void
    {
        $contractId = (int)($_POST['contract_id'] ?? 0);

        try {
            // Reativar pode ser Resume (de PAUSED) ou um novo Activate (de CANCELLED)
            // Vamos usar Resume por padrão
            if (!isset($this->useCases['resume'])) {
                throw new \Exception('ResumeContract Use Case not available');
            }

            /** @var \LimpVix\Application\UseCase\Contract\ResumeContract $resumeUseCase */
            $resumeUseCase = $this->useCases['resume'];
            $resumeUseCase->execute($contractId);

            add_settings_error('limpvix_contracts', 'contract_reactivated', 'Contrato reativado!', 'success');
        } catch (\Exception $e) {
            add_settings_error('limpvix_contracts', 'contract_error', 'Erro ao reativar: ' . $e->getMessage(), 'error');
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&reactivated=1'));
        exit;
    }

    public function render(): void
    {
        $action = $_GET['action'] ?? 'list';
        $contractId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        ?>
        <div class="wrap limpvix-contracts-wrap">
            <h1 class="wp-heading-inline">Contratos Recorrentes</h1>

            <?php if ($action === 'list'): ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create" class="page-title-action">Novo Contrato</a>
            <?php else: ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="page-title-action">← Voltar</a>
            <?php endif; ?>

            <hr class="wp-header-end">

            <?php settings_errors('limpvix_contracts'); ?>

            <?php
            if ($action === 'create' || $action === 'edit') {
                $this->renderForm($contractId);
            } else {
                $this->renderDashboard();
                $this->renderList();
            }
            ?>
        </div>

        <style>
            .limpvix-contracts-wrap .dashboard-stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin: 20px 0;
            }
            .limpvix-contracts-wrap .stat-card {
                background: #fff;
                border: 1px solid #c3c4c7;
                border-radius: 4px;
                padding: 20px;
                text-align: center;
            }
            .limpvix-contracts-wrap .stat-value {
                font-size: 32px;
                font-weight: bold;
                color: #2271b1;
            }
            .limpvix-contracts-wrap .stat-label {
                color: #646970;
                font-size: 14px;
                margin-top: 8px;
            }
            .limpvix-contracts-wrap .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .limpvix-contracts-wrap .status-badge.active { background: #d4edda; color: #155724; }
            .limpvix-contracts-wrap .status-badge.suspended { background: #fff3cd; color: #856404; }
            .limpvix-contracts-wrap .status-badge.cancelled { background: #f8d7da; color: #721c24; }
            .limpvix-contracts-wrap .status-badge.expired { background: #d1d1d1; color: #3c3c3c; }
        </style>
        <?php
    }

    private function renderDashboard(): void
    {
        // Statistics
        $activeCount = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableContracts} WHERE status = 'active'");
        $totalMonthly = $this->wpdb->get_var("SELECT SUM(monthly_value) FROM {$this->tableContracts} WHERE status = 'active'");
        $expiringCount = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableContracts} WHERE status = 'active' AND end_date IS NOT NULL AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)");
        $pendingExecutions = $this->wpdb->get_var("SELECT COUNT(*) FROM {$this->tableExecutions} WHERE status IN ('pending', 'scheduled')");

        ?>
        <div class="dashboard-stats">
            <div class="stat-card">
                <div class="stat-value"><?php echo esc_html($activeCount); ?></div>
                <div class="stat-label">Contratos Ativos</div>
            </div>
            <div class="stat-card">
                <div class="stat-value">R$ <?php echo number_format($totalMonthly, 2, ',', '.'); ?></div>
                <div class="stat-label">Valor Mensal Total</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo esc_html($expiringCount); ?></div>
                <div class="stat-label">Expirando em 30 dias</div>
            </div>
            <div class="stat-card">
                <div class="stat-value"><?php echo esc_html($pendingExecutions); ?></div>
                <div class="stat-label">Execuções Pendentes</div>
            </div>
        </div>
        <?php
    }

    private function renderList(): void
    {
        $filterStatus = $_GET['filter_status'] ?? 'all';

        $sql = "SELECT * FROM {$this->tableContracts}";
        if ($filterStatus !== 'all') {
            $sql .= $this->wpdb->prepare(" WHERE status = %s", $filterStatus);
        }
        $sql .= " ORDER BY created_at DESC";

        $contracts = $this->wpdb->get_results($sql, ARRAY_A);

        ?>
        <h2 style="margin-top: 30px;">Lista de Contratos</h2>

        <div class="tablenav top">
            <div class="alignleft actions">
                <select name="filter_status" id="filter-status" onchange="window.location='?page=<?php echo self::PAGE_SLUG; ?>&filter_status=' + this.value">
                    <option value="all" <?php selected($filterStatus, 'all'); ?>>Todos os status</option>
                    <option value="active" <?php selected($filterStatus, 'active'); ?>>Ativos</option>
                    <option value="suspended" <?php selected($filterStatus, 'suspended'); ?>>Suspensos</option>
                    <option value="cancelled" <?php selected($filterStatus, 'cancelled'); ?>>Cancelados</option>
                    <option value="expired" <?php selected($filterStatus, 'expired'); ?>>Expirados</option>
                </select>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>Nº Contrato</th>
                    <th>Cliente</th>
                    <th>Tipo</th>
                    <th>Serviço</th>
                    <th>Valor Mensal</th>
                    <th>Início</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contracts)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:30px;">Nenhum contrato encontrado. <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create">Criar primeiro</a></td></tr>
                <?php else: ?>
                    <?php foreach ($contracts as $contract): ?>
                        <?php
                        $user = get_user_by('ID', $contract['client_user_id']);
                        $userName = $user ? $user->display_name : 'Usuário #' . $contract['client_user_id'];
                        $typeLabels = ['monthly' => 'Mensal', 'weekly' => 'Semanal', 'biweekly' => 'Quinzenal'];
                        ?>
                        <tr>
                            <td><strong><?php echo esc_html($contract['contract_number']); ?></strong></td>
                            <td><?php echo esc_html($userName); ?></td>
                            <td><?php echo esc_html($typeLabels[$contract['contract_type']] ?? $contract['contract_type']); ?></td>
                            <td><?php echo esc_html($contract['service_code']); ?></td>
                            <td>R$ <?php echo number_format($contract['monthly_value'], 2, ',', '.'); ?></td>
                            <td><?php echo date('d/m/Y', strtotime($contract['start_date'])); ?></td>
                            <td><span class="status-badge <?php echo esc_attr($contract['status']); ?>"><?php echo esc_html(ucfirst($contract['status'])); ?></span></td>
                            <td>
                                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=edit&id=<?php echo $contract['id']; ?>" class="button button-small">Editar</a>
                                <?php if ($contract['status'] === 'active'): ?>
                                    <button type="button" class="button button-small" onclick="cancelContract(<?php echo $contract['id']; ?>)">Cancelar</button>
                                <?php elseif ($contract['status'] === 'cancelled'): ?>
                                    <form method="post" style="display:inline-block;">
                                        <?php wp_nonce_field(self::NONCE_ACTION . '_reactivate_contract', 'limpvix_contract_nonce_reactivate_contract'); ?>
                                        <input type="hidden" name="limpvix_contract_action_type" value="reactivate_contract">
                                        <input type="hidden" name="contract_id" value="<?php echo $contract['id']; ?>">
                                        <button type="submit" class="button button-small button-primary">Reativar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>

        <script>
        function cancelContract(contractId) {
            var reason = prompt('Motivo do cancelamento:');
            if (reason === null) return;

            var form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = '<input type="hidden" name="limpvix_contract_action_type" value="cancel_contract">' +
                             '<input type="hidden" name="contract_id" value="' + contractId + '">' +
                             '<input type="hidden" name="cancellation_reason" value="' + reason + '">' +
                             '<?php echo wp_nonce_field(self::NONCE_ACTION . "_cancel_contract", "limpvix_contract_nonce_cancel_contract", true, false); ?>';
            document.body.appendChild(form);
            form.submit();
        }
        </script>
        <?php
    }

    private function renderForm(int $contractId = 0): void
    {
        $contract = null;
        $isEdit = $contractId > 0;

        if ($isEdit) {
            $contract = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tableContracts} WHERE id = %d", $contractId), ARRAY_A);
            if (!$contract) {
                echo '<div class="notice notice-error"><p>Contrato não encontrado.</p></div>';
                return;
            }
        }

        $address = isset($contract['service_address']) ? json_decode($contract['service_address'], true) : [];

        // Get users for client dropdown
        $users = get_users(['role__in' => ['customer', 'administrator']]);

        ?>
        <form method="post" style="max-width: 800px;">
            <?php wp_nonce_field(self::NONCE_ACTION . '_save_contract', 'limpvix_contract_nonce_save_contract'); ?>
            <input type="hidden" name="limpvix_contract_action_type" value="save_contract">
            <?php if ($isEdit): ?>
                <input type="hidden" name="contract_id" value="<?php echo $contractId; ?>">
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th><label for="client_user_id">Cliente *</label></th>
                    <td>
                        <select name="client_user_id" id="client_user_id" required class="regular-text">
                            <option value="">Selecione o cliente</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?php echo $user->ID; ?>" <?php selected($contract['client_user_id'] ?? 0, $user->ID); ?>>
                                    <?php echo esc_html($user->display_name . ' (' . $user->user_email . ')'); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="contract_type">Tipo de Contrato *</label></th>
                    <td>
                        <select name="contract_type" id="contract_type" required>
                            <option value="monthly" <?php selected($contract['contract_type'] ?? '', 'monthly'); ?>>Mensal</option>
                            <option value="weekly" <?php selected($contract['contract_type'] ?? '', 'weekly'); ?>>Semanal</option>
                            <option value="biweekly" <?php selected($contract['contract_type'] ?? '', 'biweekly'); ?>>Quinzenal</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="recurrence_day">Dia da Recorrência</label></th>
                    <td>
                        <input type="number" name="recurrence_day" id="recurrence_day" value="<?php echo esc_attr($contract['recurrence_day'] ?? 15); ?>" min="1" max="31" class="small-text">
                        <p class="description">Dia do mês (1-31) para mensal, ou dia da semana (0-6) para semanal</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="service_code">Serviço *</label></th>
                    <td>
                        <input type="text" name="service_code" id="service_code" value="<?php echo esc_attr($contract['service_code'] ?? 'residential_standard'); ?>" class="regular-text" required>
                        <p class="description">Código do serviço (ex: residential_standard, commercial_standard)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="property_type">Tipo de Propriedade *</label></th>
                    <td>
                        <select name="property_type" id="property_type" required>
                            <option value="residential" <?php selected($contract['property_type'] ?? '', 'residential'); ?>>Residencial</option>
                            <option value="commercial" <?php selected($contract['property_type'] ?? '', 'commercial'); ?>>Comercial</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="estimated_m2">Área Estimada (m²)</label></th>
                    <td>
                        <input type="number" name="estimated_m2" id="estimated_m2" value="<?php echo esc_attr($contract['estimated_m2'] ?? ''); ?>" step="0.01" class="small-text"> m²
                    </td>
                </tr>
                <tr>
                    <th><label for="monthly_value">Valor Mensal *</label></th>
                    <td>
                        R$ <input type="number" name="monthly_value" id="monthly_value" value="<?php echo esc_attr($contract['monthly_value'] ?? ''); ?>" step="0.01" min="0" class="small-text" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="payment_method">Método de Pagamento</label></th>
                    <td>
                        <input type="text" name="payment_method" id="payment_method" value="<?php echo esc_attr($contract['payment_method'] ?? ''); ?>" class="regular-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="payment_day">Dia do Pagamento</label></th>
                    <td>
                        <input type="number" name="payment_day" id="payment_day" value="<?php echo esc_attr($contract['payment_day'] ?? 5); ?>" min="1" max="31" class="small-text">
                    </td>
                </tr>
                <tr>
                    <th><label for="start_date">Data de Início *</label></th>
                    <td>
                        <input type="date" name="start_date" id="start_date" value="<?php echo esc_attr($contract['start_date'] ?? date('Y-m-d')); ?>" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="end_date">Data de Fim</label></th>
                    <td>
                        <input type="date" name="end_date" id="end_date" value="<?php echo esc_attr($contract['end_date'] ?? ''); ?>">
                        <p class="description">Deixe vazio para contrato sem prazo</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="auto_renew">Renovação Automática</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="auto_renew" id="auto_renew" <?php checked($contract['auto_renew'] ?? false); ?>>
                            Renovar automaticamente ao fim do prazo
                        </label>
                    </td>
                </tr>
            </table>

            <h3>Endereço de Serviço</h3>
            <table class="form-table">
                <tr>
                    <th><label for="address">Endereço</label></th>
                    <td><input type="text" name="address" id="address" value="<?php echo esc_attr($address['address'] ?? ''); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="address_number">Número</label></th>
                    <td><input type="text" name="address_number" id="address_number" value="<?php echo esc_attr($address['number'] ?? ''); ?>" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="complement">Complemento</label></th>
                    <td><input type="text" name="complement" id="complement" value="<?php echo esc_attr($address['complement'] ?? ''); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="neighborhood">Bairro</label></th>
                    <td><input type="text" name="neighborhood" id="neighborhood" value="<?php echo esc_attr($address['neighborhood'] ?? ''); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="city">Cidade</label></th>
                    <td><input type="text" name="city" id="city" value="<?php echo esc_attr($address['city'] ?? ''); ?>" class="regular-text"></td>
                </tr>
                <tr>
                    <th><label for="state">Estado</label></th>
                    <td><input type="text" name="state" id="state" value="<?php echo esc_attr($address['state'] ?? ''); ?>" class="small-text" maxlength="2"></td>
                </tr>
                <tr>
                    <th><label for="zip_code">CEP</label></th>
                    <td><input type="text" name="zip_code" id="zip_code" value="<?php echo esc_attr($address['zip_code'] ?? ''); ?>" class="small-text"></td>
                </tr>
                <tr>
                    <th><label for="notes">Observações</label></th>
                    <td><textarea name="notes" id="notes" rows="4" class="large-text"><?php echo esc_textarea($contract['notes'] ?? ''); ?></textarea></td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $isEdit ? 'Atualizar' : 'Criar'; ?> Contrato</button>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }
}
