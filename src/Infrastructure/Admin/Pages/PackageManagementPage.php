<?php
/**
 * PackageManagementPage - Gerenciamento de Pacotes de Serviço
 *
 * RESPONSABILIDADE:
 * - CRUD completo de pacotes (basic, standard, premium, custom)
 * - Listagem com filtros (status: ativo/inativo)
 * - Formulário criar/editar pacotes
 * - Configuração: nome, percentual, profissionais, skills
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.10
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class PackageManagementPage
{
    private const PAGE_SLUG = 'limpvix-packages';
    private const NONCE_ACTION = 'limpvix_package_action';

    private $wpdb;
    private $tableName;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $wpdb->prefix . 'limpvix_package_configs';
    }

    public function register(): void
    {
        // Menu registration centralized in AdminBootstrap::registerMenu()
        add_action('admin_init', [$this, 'handleFormSubmission']);
    }

    public function addMenu(): void
    {
        add_submenu_page(
            'limpvix-finance',
            'Gerenciar Pacotes',
            'Pacotes',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function handleFormSubmission(): void
    {
        if (!isset($_POST['limpvix_package_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $action = sanitize_text_field($_POST['limpvix_package_action']);

        // Verificar nonce
        $nonceField = 'limpvix_package_nonce_' . $action;
        if (!isset($_POST[$nonceField]) || !wp_verify_nonce($_POST[$nonceField], self::NONCE_ACTION . '_' . $action)) {
            wp_die('Nonce inválido');
        }

        switch ($action) {
            case 'create':
            case 'edit':
                $this->savePackage();
                break;
            case 'delete':
                $this->deletePackage();
                break;
            case 'toggle_status':
                $this->toggleStatus();
                break;
        }
    }

    private function savePackage(): void
    {
        $packageId = isset($_POST['package_id']) ? (int)$_POST['package_id'] : 0;
        $packageType = sanitize_text_field($_POST['package_type'] ?? '');
        $displayName = sanitize_text_field($_POST['display_name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $percentageIncrease = (float)($_POST['percentage_increase'] ?? 0);
        $minProfessionals = (int)($_POST['min_professionals'] ?? 1);
        $maxProfessionals = (int)($_POST['max_professionals'] ?? 1);
        $requiredSkills = isset($_POST['required_skills']) ? (array)$_POST['required_skills'] : [];
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Validações
        if (empty($packageType) || empty($displayName)) {
            add_settings_error('limpvix_packages', 'invalid_data', 'Tipo e nome são obrigatórios', 'error');
            return;
        }

        if ($percentageIncrease < 0 || $percentageIncrease > 100) {
            add_settings_error('limpvix_packages', 'invalid_percentage', 'Percentual deve estar entre 0 e 100', 'error');
            return;
        }

        if ($minProfessionals < 1 || $maxProfessionals < $minProfessionals) {
            add_settings_error('limpvix_packages', 'invalid_professionals', 'Número de profissionais inválido', 'error');
            return;
        }

        $data = [
            'package_type' => $packageType,
            'display_name' => $displayName,
            'description' => $description,
            'percentage_increase' => $percentageIncrease / 100, // Converter % para decimal
            'min_professionals' => $minProfessionals,
            'max_professionals' => $maxProfessionals,
            'required_skills' => json_encode($requiredSkills),
            'is_active' => $isActive,
            'updated_at' => current_time('mysql')
        ];

        if ($packageId > 0) {
            // Atualizar
            $result = $this->wpdb->update(
                $this->tableName,
                $data,
                ['id' => $packageId],
                ['%s', '%s', '%s', '%f', '%d', '%d', '%s', '%d', '%s'],
                ['%d']
            );

            if ($result !== false) {
                add_settings_error('limpvix_packages', 'package_updated', 'Pacote atualizado com sucesso!', 'success');
            } else {
                add_settings_error('limpvix_packages', 'update_failed', 'Erro ao atualizar pacote', 'error');
            }
        } else {
            // Criar
            $data['created_at'] = current_time('mysql');
            $result = $this->wpdb->insert(
                $this->tableName,
                $data,
                ['%s', '%s', '%s', '%f', '%d', '%d', '%s', '%d', '%s', '%s']
            );

            if ($result) {
                add_settings_error('limpvix_packages', 'package_created', 'Pacote criado com sucesso!', 'success');
            } else {
                // FASE 1.4 - ADMIN UI REFACTORING: Log error internally, show generic message
                error_log('[PackageManagementPage] Database error on package creation: ' . $this->wpdb->last_error);
                add_settings_error('limpvix_packages', 'create_failed', 'Erro ao criar pacote. Por favor, tente novamente ou contate o suporte.', 'error');
            }
        }

        // Redirecionar para limpar POST
        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=1'));
        exit;
    }

    private function deletePackage(): void
    {
        $packageId = (int)($_POST['package_id'] ?? 0);

        if ($packageId <= 0) {
            add_settings_error('limpvix_packages', 'invalid_id', 'ID inválido', 'error');
            return;
        }

        $result = $this->wpdb->delete($this->tableName, ['id' => $packageId], ['%d']);

        if ($result) {
            add_settings_error('limpvix_packages', 'package_deleted', 'Pacote deletado com sucesso!', 'success');
        } else {
            add_settings_error('limpvix_packages', 'delete_failed', 'Erro ao deletar pacote', 'error');
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&deleted=1'));
        exit;
    }

    private function toggleStatus(): void
    {
        $packageId = (int)($_POST['package_id'] ?? 0);

        if ($packageId <= 0) {
            add_settings_error('limpvix_packages', 'invalid_id', 'ID inválido', 'error');
            return;
        }

        $current = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT is_active FROM {$this->tableName} WHERE id = %d",
            $packageId
        ));

        $newStatus = $current ? 0 : 1;

        $result = $this->wpdb->update(
            $this->tableName,
            ['is_active' => $newStatus, 'updated_at' => current_time('mysql')],
            ['id' => $packageId],
            ['%d', '%s'],
            ['%d']
        );

        if ($result !== false) {
            $message = $newStatus ? 'Pacote ativado!' : 'Pacote desativado!';
            add_settings_error('limpvix_packages', 'status_toggled', $message, 'success');
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&toggled=1'));
        exit;
    }

    public function render(): void
    {
        $action = $_GET['action'] ?? 'list';
        $packageId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        ?>
        <div class="wrap limpvix-packages-wrap">
            <h1 class="wp-heading-inline">
                <?php echo esc_html($action === 'edit' ? 'Editar Pacote' : ($action === 'create' ? 'Novo Pacote' : 'Gerenciar Pacotes')); ?>
            </h1>

            <?php if ($action === 'list'): ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create" class="page-title-action">Adicionar Novo</a>
            <?php else: ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="page-title-action">← Voltar</a>
            <?php endif; ?>

            <hr class="wp-header-end">

            <?php settings_errors('limpvix_packages'); ?>

            <?php
            switch ($action) {
                case 'create':
                    $this->renderForm();
                    break;
                case 'edit':
                    $this->renderForm($packageId);
                    break;
                default:
                    $this->renderList();
                    break;
            }
            ?>
        </div>

        <style>
            .limpvix-packages-wrap .packages-table {
                margin-top: 20px;
            }
            .limpvix-packages-wrap .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .limpvix-packages-wrap .status-badge.active {
                background: #d4edda;
                color: #155724;
            }
            .limpvix-packages-wrap .status-badge.inactive {
                background: #f8d7da;
                color: #721c24;
            }
            .limpvix-packages-wrap .skills-list {
                font-size: 12px;
                color: #646970;
            }
            .limpvix-packages-wrap .form-table th {
                width: 200px;
            }
        </style>
        <?php
    }

    private function renderList(): void
    {
        $filterStatus = isset($_GET['filter_status']) ? sanitize_text_field($_GET['filter_status']) : 'all';

        // Buscar pacotes
        $sql = "SELECT * FROM {$this->tableName}";
        if ($filterStatus === 'active') {
            $sql .= " WHERE is_active = 1";
        } elseif ($filterStatus === 'inactive') {
            $sql .= " WHERE is_active = 0";
        }
        $sql .= " ORDER BY package_type ASC";

        $packages = $this->wpdb->get_results($sql, ARRAY_A);

        ?>
        <div class="tablenav top">
            <div class="alignleft actions">
                <label for="filter-status" class="screen-reader-text">Filtrar por status</label>
                <select name="filter_status" id="filter-status" onchange="window.location='?page=<?php echo self::PAGE_SLUG; ?>&filter_status=' + this.value">
                    <option value="all" <?php selected($filterStatus, 'all'); ?>>Todos os status</option>
                    <option value="active" <?php selected($filterStatus, 'active'); ?>>Ativos</option>
                    <option value="inactive" <?php selected($filterStatus, 'inactive'); ?>>Inativos</option>
                </select>
            </div>
        </div>

        <table class="wp-list-table widefat fixed striped packages-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Tipo</th>
                    <th>Nome</th>
                    <th>Percentual</th>
                    <th>Profissionais</th>
                    <th>Skills</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($packages)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 30px; color: #646970;">
                            Nenhum pacote encontrado. <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create">Criar primeiro pacote</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($packages as $package): ?>
                        <?php
                        $skills = json_decode($package['required_skills'], true) ?: [];
                        $percentageDisplay = number_format($package['percentage_increase'] * 100, 1);
                        ?>
                        <tr>
                            <td><?php echo esc_html($package['id']); ?></td>
                            <td><code><?php echo esc_html($package['package_type']); ?></code></td>
                            <td><strong><?php echo esc_html($package['display_name']); ?></strong></td>
                            <td><?php echo esc_html($percentageDisplay); ?>%</td>
                            <td><?php echo esc_html($package['min_professionals']); ?> - <?php echo esc_html($package['max_professionals']); ?></td>
                            <td class="skills-list">
                                <?php echo esc_html(!empty($skills) ? implode(', ', $skills) : '—'); ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $package['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $package['is_active'] ? '✓ Ativo' : '✗ Inativo'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=edit&id=<?php echo esc_attr($package['id']); ?>" class="button button-small">Editar</a>

                                <form method="post" style="display: inline-block;">
                                    <?php wp_nonce_field(self::NONCE_ACTION . '_toggle_status', 'limpvix_package_nonce_toggle_status'); ?>
                                    <input type="hidden" name="limpvix_package_action" value="toggle_status">
                                    <input type="hidden" name="package_id" value="<?php echo esc_attr($package['id']); ?>">
                                    <button type="submit" class="button button-small">
                                        <?php echo $package['is_active'] ? 'Desativar' : 'Ativar'; ?>
                                    </button>
                                </form>

                                <form method="post" style="display: inline-block;" onsubmit="return confirm('Deletar este pacote? Esta ação não pode ser desfeita.');">
                                    <?php wp_nonce_field(self::NONCE_ACTION . '_delete', 'limpvix_package_nonce_delete'); ?>
                                    <input type="hidden" name="limpvix_package_action" value="delete">
                                    <input type="hidden" name="package_id" value="<?php echo esc_attr($package['id']); ?>">
                                    <button type="submit" class="button button-small button-link-delete">Deletar</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderForm(int $packageId = 0): void
    {
        $package = null;
        $isEdit = $packageId > 0;

        if ($isEdit) {
            $package = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableName} WHERE id = %d",
                $packageId
            ), ARRAY_A);

            if (!$package) {
                echo '<div class="notice notice-error"><p>Pacote não encontrado.</p></div>';
                return;
            }
        }

        $packageType = $package['package_type'] ?? '';
        $displayName = $package['display_name'] ?? '';
        $description = $package['description'] ?? '';
        $percentageIncrease = isset($package['percentage_increase']) ? ($package['percentage_increase'] * 100) : 0;
        $minProfessionals = $package['min_professionals'] ?? 1;
        $maxProfessionals = $package['max_professionals'] ?? 1;
        $requiredSkills = isset($package['required_skills']) ? json_decode($package['required_skills'], true) : [];
        $isActive = isset($package['is_active']) ? (bool)$package['is_active'] : true;

        // Skills disponíveis
        $availableSkills = [
            'limpeza_basica' => 'Limpeza Básica',
            'limpeza_pesada' => 'Limpeza Pesada',
            'pos_obra' => 'Pós-Obra',
            'pre_mudanca' => 'Pré-Mudança',
            'teto_pvc' => 'Limpeza de Teto PVC',
            'esquadrias' => 'Esquadrias e Vidros',
            'persianas' => 'Limpeza de Persianas',
            'cortinas' => 'Limpeza de Cortinas',
            'estofados' => 'Higienização de Estofados',
            'carpetes' => 'Limpeza de Carpetes',
        ];

        ?>
        <form method="post" action="">
            <?php wp_nonce_field(self::NONCE_ACTION . '_' . ($isEdit ? 'edit' : 'create'), 'limpvix_package_nonce_' . ($isEdit ? 'edit' : 'create')); ?>
            <input type="hidden" name="limpvix_package_action" value="<?php echo $isEdit ? 'edit' : 'create'; ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="package_id" value="<?php echo esc_attr($packageId); ?>">
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="package_type">Tipo do Pacote *</label>
                    </th>
                    <td>
                        <input type="text" name="package_type" id="package_type"
                               value="<?php echo esc_attr($packageType); ?>"
                               class="regular-text"
                               <?php echo $isEdit ? 'readonly' : ''; ?>
                               required
                               pattern="[a-z_]+"
                               title="Somente letras minúsculas e underscore">
                        <p class="description">Slug único (ex: basic, standard, premium, custom_deluxe). Não pode ser alterado após criação.</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="display_name">Nome de Exibição *</label>
                    </th>
                    <td>
                        <input type="text" name="display_name" id="display_name"
                               value="<?php echo esc_attr($displayName); ?>"
                               class="regular-text"
                               required>
                        <p class="description">Nome mostrado ao cliente (ex: Pacote Básico, Pacote Premium)</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="description">Descrição</label>
                    </th>
                    <td>
                        <textarea name="description" id="description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                        <p class="description">Descrição opcional do pacote</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="percentage_increase">Percentual de Aumento *</label>
                    </th>
                    <td>
                        <input type="number" name="percentage_increase" id="percentage_increase"
                               value="<?php echo esc_attr($percentageIncrease); ?>"
                               min="0" max="100" step="0.1"
                               class="small-text"
                               required> %
                        <p class="description">Percentual aplicado sobre o preço base (ex: 15 = +15% no preço)</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">Número de Profissionais *</th>
                    <td>
                        <label for="min_professionals">Mínimo:</label>
                        <input type="number" name="min_professionals" id="min_professionals"
                               value="<?php echo esc_attr($minProfessionals); ?>"
                               min="1" max="10"
                               class="small-text"
                               required>

                        <label for="max_professionals" style="margin-left: 15px;">Máximo:</label>
                        <input type="number" name="max_professionals" id="max_professionals"
                               value="<?php echo esc_attr($maxProfessionals); ?>"
                               min="1" max="10"
                               class="small-text"
                               required>
                        <p class="description">Quantidade de profissionais necessários para este pacote</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label>Skills Requeridas</label>
                    </th>
                    <td>
                        <fieldset>
                            <?php foreach ($availableSkills as $skillKey => $skillLabel): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox" name="required_skills[]" value="<?php echo esc_attr($skillKey); ?>"
                                           <?php checked(in_array($skillKey, $requiredSkills)); ?>>
                                    <?php echo esc_html($skillLabel); ?>
                                </label>
                            <?php endforeach; ?>
                        </fieldset>
                        <p class="description">Skills que os profissionais devem ter para este pacote</p>
                    </td>
                </tr>

                <tr>
                    <th scope="row">
                        <label for="is_active">Status</label>
                    </th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" id="is_active" value="1" <?php checked($isActive); ?>>
                            Pacote ativo (disponível para seleção)
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php echo $isEdit ? 'Atualizar Pacote' : 'Criar Pacote'; ?>
                </button>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }
}
