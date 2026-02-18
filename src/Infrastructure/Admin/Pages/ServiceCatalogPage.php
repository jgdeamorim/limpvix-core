<?php
/**
 * ServiceCatalogPage - Gerenciamento de Catálogo de Serviços e Adicionais
 *
 * RESPONSABILIDADE:
 * - CRUD de serviços principais (Comercial/Residencial)
 * - CRUD de adicionais/extras
 * - Sistema de abas para organização
 * - Seed data inicial
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.11
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class ServiceCatalogPage
{
    private const PAGE_SLUG = 'limpvix-services';
    private const NONCE_ACTION = 'limpvix_service_action';

    private $wpdb;
    private $tableServices;
    private $tableAdditionals;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableServices = $wpdb->prefix . 'limpvix_service_catalog';
        $this->tableAdditionals = $wpdb->prefix . 'limpvix_service_additionals';
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
            'Catálogo de Serviços',
            'Serviços',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function handleFormSubmission(): void
    {
        if (!isset($_POST['limpvix_service_action_type'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $action = sanitize_text_field($_POST['limpvix_service_action_type']);
        $tab = sanitize_text_field($_POST['tab'] ?? 'services');

        // Verificar nonce
        $nonceField = 'limpvix_service_nonce_' . $action;
        if (!isset($_POST[$nonceField]) || !wp_verify_nonce($_POST[$nonceField], self::NONCE_ACTION . '_' . $action)) {
            wp_die('Nonce inválido');
        }

        switch ($action) {
            case 'save_service':
                $this->saveService();
                break;
            case 'delete_service':
                $this->deleteService();
                break;
            case 'save_additional':
                $this->saveAdditional();
                break;
            case 'delete_additional':
                $this->deleteAdditional();
                break;
        }
    }

    private function saveService(): void
    {
        $serviceId = isset($_POST['service_id']) ? (int)$_POST['service_id'] : 0;
        $serviceCode = sanitize_text_field($_POST['service_code'] ?? '');
        $category = sanitize_text_field($_POST['category'] ?? '');
        $serviceType = sanitize_text_field($_POST['service_type'] ?? '');
        $displayName = sanitize_text_field($_POST['display_name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $requiredSkills = isset($_POST['required_skills']) && is_array($_POST['required_skills'])
            ? array_map('sanitize_text_field', $_POST['required_skills'])
            : [];
        $requiredSkillsJson = !empty($requiredSkills) ? json_encode($requiredSkills) : null;
        $basePrice = (float)($_POST['base_price'] ?? 0);
        $timeMultiplier = (float)($_POST['time_multiplier'] ?? 1.0);
        $requiresMultiple = isset($_POST['requires_multiple_professionals']) ? 1 : 0;
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        // Validações
        if (empty($serviceCode) || empty($displayName) || empty($category) || empty($serviceType)) {
            add_settings_error('limpvix_services', 'invalid_data', 'Campos obrigatórios não preenchidos', 'error');
            return;
        }

        $data = [
            'service_code' => $serviceCode,
            'category' => $category,
            'service_type' => $serviceType,
            'display_name' => $displayName,
            'description' => $description,
            'required_skills' => $requiredSkillsJson,
            'base_price' => $basePrice,
            'time_multiplier' => $timeMultiplier,
            'requires_multiple_professionals' => $requiresMultiple,
            'is_active' => $isActive,
            'updated_at' => current_time('mysql')
        ];

        if ($serviceId > 0) {
            $result = $this->wpdb->update(
                $this->tableServices,
                $data,
                ['id' => $serviceId],
                ['%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d', '%s'], // Added %s for required_skills
                ['%d']
            );
            $message = $result !== false ? 'Serviço atualizado!' : 'Erro ao atualizar';
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $this->wpdb->insert(
                $this->tableServices,
                $data,
                ['%s', '%s', '%s', '%s', '%s', '%s', '%f', '%f', '%d', '%d', '%s', '%s'] // Added %s for required_skills
            );
            $message = $result ? 'Serviço criado!' : 'Erro ao criar';
        }

        add_settings_error('limpvix_services', 'service_saved', $message, $result ? 'success' : 'error');
        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=services&updated=1'));
        exit;
    }

    private function deleteService(): void
    {
        $serviceId = (int)($_POST['service_id'] ?? 0);
        $result = $this->wpdb->delete($this->tableServices, ['id' => $serviceId], ['%d']);
        add_settings_error('limpvix_services', 'service_deleted', $result ? 'Serviço deletado!' : 'Erro ao deletar', $result ? 'success' : 'error');
        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=services&deleted=1'));
        exit;
    }

    private function saveAdditional(): void
    {
        $additionalId = isset($_POST['additional_id']) ? (int)$_POST['additional_id'] : 0;
        $additionalCode = sanitize_text_field($_POST['additional_code'] ?? '');
        $displayName = sanitize_text_field($_POST['display_name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $unitType = sanitize_text_field($_POST['unit_type'] ?? '');
        $basePrice = (float)($_POST['base_price'] ?? 0);
        $durationMinutes = (int)($_POST['estimated_duration_minutes'] ?? 0);
        $compatibleCategories = isset($_POST['compatible_categories']) ? (array)$_POST['compatible_categories'] : [];
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($additionalCode) || empty($displayName) || empty($unitType)) {
            add_settings_error('limpvix_services', 'invalid_data', 'Campos obrigatórios não preenchidos', 'error');
            return;
        }

        $data = [
            'additional_code' => $additionalCode,
            'display_name' => $displayName,
            'description' => $description,
            'unit_type' => $unitType,
            'base_price' => $basePrice,
            'estimated_duration_minutes' => $durationMinutes,
            'compatible_with_categories' => json_encode($compatibleCategories),
            'is_active' => $isActive,
            'updated_at' => current_time('mysql')
        ];

        if ($additionalId > 0) {
            $result = $this->wpdb->update(
                $this->tableAdditionals,
                $data,
                ['id' => $additionalId],
                ['%s', '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s'],
                ['%d']
            );
            $message = $result !== false ? 'Adicional atualizado!' : 'Erro ao atualizar';
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $this->wpdb->insert(
                $this->tableAdditionals,
                $data,
                ['%s', '%s', '%s', '%s', '%f', '%d', '%s', '%d', '%s', '%s']
            );
            $message = $result ? 'Adicional criado!' : 'Erro ao criar';
        }

        add_settings_error('limpvix_services', 'additional_saved', $message, $result ? 'success' : 'error');
        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=additionals&updated=1'));
        exit;
    }

    private function deleteAdditional(): void
    {
        $additionalId = (int)($_POST['additional_id'] ?? 0);
        $result = $this->wpdb->delete($this->tableAdditionals, ['id' => $additionalId], ['%d']);
        add_settings_error('limpvix_services', 'additional_deleted', $result ? 'Adicional deletado!' : 'Erro ao deletar', $result ? 'success' : 'error');
        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=additionals&deleted=1'));
        exit;
    }

    public function render(): void
    {
        $currentTab = $_GET['tab'] ?? 'services';
        $action = $_GET['action'] ?? 'list';
        $itemId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        ?>
        <div class="wrap limpvix-services-wrap">
            <h1 class="wp-heading-inline">Catálogo de Serviços</h1>

            <?php if ($action === 'list'): ?>
                <?php if ($currentTab === 'services'): ?>
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=services&action=create" class="page-title-action">Adicionar Serviço</a>
                <?php else: ?>
                    <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=additionals&action=create" class="page-title-action">Adicionar Adicional</a>
                <?php endif; ?>
            <?php else: ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=<?php echo esc_attr($currentTab); ?>" class="page-title-action">← Voltar</a>
            <?php endif; ?>

            <hr class="wp-header-end">

            <?php settings_errors('limpvix_services'); ?>

            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=services" class="nav-tab <?php echo $currentTab === 'services' ? 'nav-tab-active' : ''; ?>">
                    🏢 Serviços Principais
                </a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=additionals" class="nav-tab <?php echo $currentTab === 'additionals' ? 'nav-tab-active' : ''; ?>">
                    ➕ Adicionais/Extras
                </a>
            </h2>

            <div class="tab-content" style="margin-top: 20px;">
                <?php
                if ($currentTab === 'services') {
                    if ($action === 'create' || $action === 'edit') {
                        $this->renderServiceForm($itemId);
                    } else {
                        $this->renderServicesList();
                    }
                } else {
                    if ($action === 'create' || $action === 'edit') {
                        $this->renderAdditionalForm($itemId);
                    } else {
                        $this->renderAdditionalsList();
                    }
                }
                ?>
            </div>
        </div>

        <style>
            .limpvix-services-wrap .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .limpvix-services-wrap .status-badge.active {
                background: #d4edda;
                color: #155724;
            }
            .limpvix-services-wrap .status-badge.inactive {
                background: #f8d7da;
                color: #721c24;
            }
        </style>
        <?php
    }

    private function renderServicesList(): void
    {
        $services = $this->wpdb->get_results("SELECT * FROM {$this->tableServices} ORDER BY category, service_type", ARRAY_A);
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Código</th>
                    <th>Categoria</th>
                    <th>Tipo</th>
                    <th>Nome</th>
                    <th>Preço Base</th>
                    <th>Multiplicador</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($services)): ?>
                    <tr><td colspan="9" style="text-align:center; padding:30px;">Nenhum serviço encontrado. <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=services&action=create">Criar primeiro</a></td></tr>
                <?php else: ?>
                    <?php foreach ($services as $service): ?>
                        <tr>
                            <td><?php echo esc_html($service['id']); ?></td>
                            <td><code><?php echo esc_html($service['service_code']); ?></code></td>
                            <td><?php echo esc_html($service['category']); ?></td>
                            <td><?php echo esc_html($service['service_type']); ?></td>
                            <td><strong><?php echo esc_html($service['display_name']); ?></strong></td>
                            <td>R$ <?php echo number_format($service['base_price'], 2, ',', '.'); ?></td>
                            <td><?php echo number_format($service['time_multiplier'], 2); ?>x</td>
                            <td><span class="status-badge <?php echo $service['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $service['is_active'] ? '✓ Ativo' : '✗ Inativo'; ?></span></td>
                            <td>
                                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=services&action=edit&id=<?php echo $service['id']; ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Deletar este serviço?');">
                                    <?php wp_nonce_field(self::NONCE_ACTION . '_delete_service', 'limpvix_service_nonce_delete_service'); ?>
                                    <input type="hidden" name="limpvix_service_action_type" value="delete_service">
                                    <input type="hidden" name="tab" value="services">
                                    <input type="hidden" name="service_id" value="<?php echo $service['id']; ?>">
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

    private function renderServiceForm(int $serviceId = 0): void
    {
        $service = null;
        $isEdit = $serviceId > 0;

        if ($isEdit) {
            $service = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tableServices} WHERE id = %d", $serviceId), ARRAY_A);
            if (!$service) {
                echo '<div class="notice notice-error"><p>Serviço não encontrado.</p></div>';
                return;
            }
        }

        $serviceCode = $service['service_code'] ?? '';
        $category = $service['category'] ?? 'residential';
        $serviceType = $service['service_type'] ?? 'standard';
        $displayName = $service['display_name'] ?? '';
        $description = $service['description'] ?? '';
        $requiredSkills = isset($service['required_skills']) ? json_decode($service['required_skills'], true) : [];
        $requiredSkills = is_array($requiredSkills) ? $requiredSkills : [];
        $basePrice = $service['base_price'] ?? 0;
        $timeMultiplier = $service['time_multiplier'] ?? 1.0;
        $requiresMultiple = isset($service['requires_multiple_professionals']) ? (bool)$service['requires_multiple_professionals'] : false;
        $isActive = isset($service['is_active']) ? (bool)$service['is_active'] : true;

        ?>
        <form method="post">
            <?php wp_nonce_field(self::NONCE_ACTION . '_save_service', 'limpvix_service_nonce_save_service'); ?>
            <input type="hidden" name="limpvix_service_action_type" value="save_service">
            <input type="hidden" name="tab" value="services">
            <?php if ($isEdit): ?>
                <input type="hidden" name="service_id" value="<?php echo $serviceId; ?>">
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th><label for="service_code">Código do Serviço *</label></th>
                    <td>
                        <input type="text" name="service_code" id="service_code" value="<?php echo esc_attr($serviceCode); ?>" class="regular-text" <?php echo $isEdit ? 'readonly' : ''; ?> required>
                        <p class="description">Slug único (ex: commercial_standard)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="category">Categoria *</label></th>
                    <td>
                        <select name="category" id="category" required>
                            <option value="commercial" <?php selected($category, 'commercial'); ?>>Comercial</option>
                            <option value="residential" <?php selected($category, 'residential'); ?>>Residencial</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="service_type">Tipo de Serviço *</label></th>
                    <td>
                        <select name="service_type" id="service_type" required>
                            <option value="standard" <?php selected($serviceType, 'standard'); ?>>Padrão</option>
                            <option value="pre_move" <?php selected($serviceType, 'pre_move'); ?>>Pré-Mudança</option>
                            <option value="post_construction" <?php selected($serviceType, 'post_construction'); ?>>Pós-Obra</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="display_name">Nome de Exibição *</label></th>
                    <td>
                        <input type="text" name="display_name" id="display_name" value="<?php echo esc_attr($displayName); ?>" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="description">Descrição</label></th>
                    <td>
                        <textarea name="description" id="description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th><label for="required_skills">Skills Necessárias (GAP D)</label></th>
                    <td>
                        <?php
                        // Lista de skills disponíveis (em português para compatibilidade)
                        $availableSkills = [
                            'limpeza_residencial' => 'Limpeza Residencial',
                            'limpeza_comercial' => 'Limpeza Comercial',
                            'limpeza_vidros' => 'Limpeza de Vidros',
                            'limpeza_pesada' => 'Limpeza Pesada',
                            'limpeza_pos_obra' => 'Limpeza Pós-Obra',
                            'manutencao_piso' => 'Manutenção de Piso',
                            'sanitizacao' => 'Sanitização',
                            'organizacao' => 'Organização',
                            'limpeza_teto' => 'Limpeza de Teto',
                            'limpeza_cortinas' => 'Limpeza de Cortinas',
                        ];
                        ?>
                        <div style="max-width: 400px; border: 1px solid #ccc; padding: 10px; max-height: 200px; overflow-y: auto;">
                            <?php foreach ($availableSkills as $skillCode => $skillName): ?>
                                <label style="display: block; margin-bottom: 5px;">
                                    <input type="checkbox"
                                           name="required_skills[]"
                                           value="<?php echo esc_attr($skillCode); ?>"
                                           <?php checked(in_array($skillCode, $requiredSkills, true)); ?>>
                                    <?php echo esc_html($skillName); ?>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <p class="description">
                            Selecione as skills necessárias para executar este serviço.<br>
                            Profissionais precisam ter pelo menos uma dessas skills para receber offers.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="base_price">Preço Base</label></th>
                    <td>
                        R$ <input type="number" name="base_price" id="base_price" value="<?php echo esc_attr($basePrice); ?>" step="0.01" class="small-text">
                        <p class="description">0 se calculado por m²</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="time_multiplier">Multiplicador de Tempo</label></th>
                    <td>
                        <input type="number" name="time_multiplier" id="time_multiplier" value="<?php echo esc_attr($timeMultiplier); ?>" step="0.1" min="0.1" max="10" class="small-text">x
                        <p class="description">1.3 = +30% no tempo estimado</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="requires_multiple_professionals">Profissionais</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="requires_multiple_professionals" id="requires_multiple_professionals" <?php checked($requiresMultiple); ?>>
                            Requer múltiplos profissionais
                        </label>
                    </td>
                </tr>
                <tr>
                    <th><label for="is_active">Status</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" id="is_active" <?php checked($isActive); ?>>
                            Serviço ativo
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $isEdit ? 'Atualizar' : 'Criar'; ?> Serviço</button>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=services" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }

    private function renderAdditionalsList(): void
    {
        $additionals = $this->wpdb->get_results("SELECT * FROM {$this->tableAdditionals} ORDER BY display_name", ARRAY_A);
        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Código</th>
                    <th>Nome</th>
                    <th>Tipo de Unidade</th>
                    <th>Preço Base</th>
                    <th>Duração</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($additionals)): ?>
                    <tr><td colspan="8" style="text-align:center; padding:30px;">Nenhum adicional encontrado. <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=additionals&action=create">Criar primeiro</a></td></tr>
                <?php else: ?>
                    <?php foreach ($additionals as $additional): ?>
                        <tr>
                            <td><?php echo esc_html($additional['id']); ?></td>
                            <td><code><?php echo esc_html($additional['additional_code']); ?></code></td>
                            <td><strong><?php echo esc_html($additional['display_name']); ?></strong></td>
                            <td><?php echo esc_html($additional['unit_type']); ?></td>
                            <td>R$ <?php echo number_format($additional['base_price'], 2, ',', '.'); ?></td>
                            <td><?php echo esc_html($additional['estimated_duration_minutes']); ?> min</td>
                            <td><span class="status-badge <?php echo $additional['is_active'] ? 'active' : 'inactive'; ?>"><?php echo $additional['is_active'] ? '✓ Ativo' : '✗ Inativo'; ?></span></td>
                            <td>
                                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=additionals&action=edit&id=<?php echo $additional['id']; ?>" class="button button-small">Editar</a>
                                <form method="post" style="display:inline-block;" onsubmit="return confirm('Deletar este adicional?');">
                                    <?php wp_nonce_field(self::NONCE_ACTION . '_delete_additional', 'limpvix_service_nonce_delete_additional'); ?>
                                    <input type="hidden" name="limpvix_service_action_type" value="delete_additional">
                                    <input type="hidden" name="tab" value="additionals">
                                    <input type="hidden" name="additional_id" value="<?php echo $additional['id']; ?>">
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

    private function renderAdditionalForm(int $additionalId = 0): void
    {
        $additional = null;
        $isEdit = $additionalId > 0;

        if ($isEdit) {
            $additional = $this->wpdb->get_row($this->wpdb->prepare("SELECT * FROM {$this->tableAdditionals} WHERE id = %d", $additionalId), ARRAY_A);
            if (!$additional) {
                echo '<div class="notice notice-error"><p>Adicional não encontrado.</p></div>';
                return;
            }
        }

        $additionalCode = $additional['additional_code'] ?? '';
        $displayName = $additional['display_name'] ?? '';
        $description = $additional['description'] ?? '';
        $unitType = $additional['unit_type'] ?? 'fixed';
        $basePrice = $additional['base_price'] ?? 0;
        $durationMinutes = $additional['estimated_duration_minutes'] ?? 0;
        $compatibleCategories = isset($additional['compatible_with_categories']) ? json_decode($additional['compatible_with_categories'], true) : ['commercial', 'residential'];
        $isActive = isset($additional['is_active']) ? (bool)$additional['is_active'] : true;

        ?>
        <form method="post">
            <?php wp_nonce_field(self::NONCE_ACTION . '_save_additional', 'limpvix_service_nonce_save_additional'); ?>
            <input type="hidden" name="limpvix_service_action_type" value="save_additional">
            <input type="hidden" name="tab" value="additionals">
            <?php if ($isEdit): ?>
                <input type="hidden" name="additional_id" value="<?php echo $additionalId; ?>">
            <?php endif; ?>

            <table class="form-table">
                <tr>
                    <th><label for="additional_code">Código do Adicional *</label></th>
                    <td>
                        <input type="text" name="additional_code" id="additional_code" value="<?php echo esc_attr($additionalCode); ?>" class="regular-text" <?php echo $isEdit ? 'readonly' : ''; ?> required>
                        <p class="description">Slug único (ex: ceiling_pvc)</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="display_name">Nome de Exibição *</label></th>
                    <td>
                        <input type="text" name="display_name" id="display_name" value="<?php echo esc_attr($displayName); ?>" class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th><label for="description">Descrição</label></th>
                    <td>
                        <textarea name="description" id="description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th><label for="unit_type">Tipo de Unidade *</label></th>
                    <td>
                        <select name="unit_type" id="unit_type" required>
                            <option value="fixed" <?php selected($unitType, 'fixed'); ?>>Fixo (preço único)</option>
                            <option value="per_m2" <?php selected($unitType, 'per_m2'); ?>>Por m²</option>
                            <option value="per_unit" <?php selected($unitType, 'per_unit'); ?>>Por Unidade</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="base_price">Preço Base *</label></th>
                    <td>
                        R$ <input type="number" name="base_price" id="base_price" value="<?php echo esc_attr($basePrice); ?>" step="0.01" min="0" class="small-text" required>
                        <p class="description">Preço por unidade/m² ou fixo</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="estimated_duration_minutes">Duração Estimada</label></th>
                    <td>
                        <input type="number" name="estimated_duration_minutes" id="estimated_duration_minutes" value="<?php echo esc_attr($durationMinutes); ?>" min="0" class="small-text"> minutos
                    </td>
                </tr>
                <tr>
                    <th><label>Compatível com</label></th>
                    <td>
                        <label style="display:block;"><input type="checkbox" name="compatible_categories[]" value="commercial" <?php checked(in_array('commercial', $compatibleCategories)); ?>> Comercial</label>
                        <label style="display:block;"><input type="checkbox" name="compatible_categories[]" value="residential" <?php checked(in_array('residential', $compatibleCategories)); ?>> Residencial</label>
                    </td>
                </tr>
                <tr>
                    <th><label for="is_active">Status</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" id="is_active" <?php checked($isActive); ?>>
                            Adicional ativo
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo $isEdit ? 'Atualizar' : 'Criar'; ?> Adicional</button>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=additionals" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }
}
