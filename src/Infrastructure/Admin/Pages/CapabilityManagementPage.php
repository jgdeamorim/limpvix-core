<?php
/**
 * CapabilityManagementPage - Gerenciamento de Capabilities (SSOT)
 *
 * RESPONSABILIDADE:
 * - CRUD de capabilities (competencias tecnicas)
 * - Visualizacao de uso (quais complexities e additionals usam cada capability)
 * - SSOT para o sistema de matching profissional
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.4.0 (Service Domain Refactor - FASE 5)
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class CapabilityManagementPage
{
    private const PAGE_SLUG = 'limpvix-capabilities';
    private const NONCE_ACTION = 'limpvix_capability_action';

    private $wpdb;
    private $tableName;

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $wpdb->prefix . 'limpvix_capabilities';
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'handleFormSubmission']);
    }

    public function handleFormSubmission(): void
    {
        if (!isset($_POST['limpvix_capability_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissao');
        }

        $action = sanitize_text_field($_POST['limpvix_capability_action']);

        $nonceField = 'limpvix_capability_nonce_' . $action;
        if (!isset($_POST[$nonceField]) || !wp_verify_nonce($_POST[$nonceField], self::NONCE_ACTION . '_' . $action)) {
            wp_die('Nonce invalido');
        }

        switch ($action) {
            case 'create':
            case 'edit':
                $this->saveCapability();
                break;
            case 'delete':
                $this->deleteCapability();
                break;
            case 'toggle_status':
                $this->toggleStatus();
                break;
        }
    }

    private function saveCapability(): void
    {
        $capabilityId = isset($_POST['capability_id']) ? (int)$_POST['capability_id'] : 0;
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $displayName = sanitize_text_field($_POST['display_name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($slug) || empty($displayName)) {
            add_settings_error('limpvix_capabilities', 'invalid_data', 'Slug e nome sao obrigatorios', 'error');
            return;
        }

        if (!preg_match('/^[a-z][a-z0-9_]*$/', $slug)) {
            add_settings_error('limpvix_capabilities', 'invalid_slug', 'Slug deve conter apenas letras minusculas, numeros e underscore', 'error');
            return;
        }

        $data = [
            'slug' => $slug,
            'display_name' => $displayName,
            'description' => $description ?: null,
            'is_active' => $isActive,
            'updated_at' => current_time('mysql'),
        ];

        if ($capabilityId > 0) {
            $result = $this->wpdb->update(
                $this->tableName,
                $data,
                ['id' => $capabilityId],
                ['%s', '%s', '%s', '%d', '%s'],
                ['%d']
            );

            if ($result !== false) {
                add_settings_error('limpvix_capabilities', 'capability_updated', 'Capability atualizada!', 'success');
            } else {
                add_settings_error('limpvix_capabilities', 'update_failed', 'Erro ao atualizar', 'error');
            }
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $this->wpdb->insert(
                $this->tableName,
                $data,
                ['%s', '%s', '%s', '%d', '%s', '%s']
            );

            if ($result) {
                add_settings_error('limpvix_capabilities', 'capability_created', 'Capability criada!', 'success');
            } else {
                error_log('[CapabilityManagementPage] DB error: ' . $this->wpdb->last_error);
                add_settings_error('limpvix_capabilities', 'create_failed', 'Erro ao criar capability', 'error');
            }
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=1'));
        exit;
    }

    private function deleteCapability(): void
    {
        $capabilityId = (int)($_POST['capability_id'] ?? 0);

        if ($capabilityId <= 0) {
            add_settings_error('limpvix_capabilities', 'invalid_id', 'ID invalido', 'error');
            return;
        }

        // Check usage before deleting
        $usage = $this->getCapabilityUsage($capabilityId);
        if ($usage['complexity_count'] > 0 || $usage['additional_count'] > 0) {
            add_settings_error(
                'limpvix_capabilities',
                'in_use',
                'Capability em uso por ' . $usage['complexity_count'] . ' complexidades e ' . $usage['additional_count'] . ' adicionais. Desative ao inves de deletar.',
                'error'
            );
            wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&error=in_use'));
            exit;
        }

        $result = $this->wpdb->delete($this->tableName, ['id' => $capabilityId], ['%d']);

        if ($result) {
            add_settings_error('limpvix_capabilities', 'capability_deleted', 'Capability deletada!', 'success');
        } else {
            add_settings_error('limpvix_capabilities', 'delete_failed', 'Erro ao deletar', 'error');
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&deleted=1'));
        exit;
    }

    private function toggleStatus(): void
    {
        $capabilityId = (int)($_POST['capability_id'] ?? 0);

        if ($capabilityId <= 0) {
            return;
        }

        $current = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT is_active FROM {$this->tableName} WHERE id = %d",
            $capabilityId
        ));

        $newStatus = $current ? 0 : 1;

        $this->wpdb->update(
            $this->tableName,
            ['is_active' => $newStatus, 'updated_at' => current_time('mysql')],
            ['id' => $capabilityId],
            ['%d', '%s'],
            ['%d']
        );

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&toggled=1'));
        exit;
    }

    public function render(): void
    {
        $action = $_GET['action'] ?? 'list';
        $capabilityId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        ?>
        <div class="wrap limpvix-capabilities-wrap">
            <h1 class="wp-heading-inline">
                <?php echo esc_html($action === 'edit' ? 'Editar Capability' : ($action === 'create' ? 'Nova Capability' : 'Gerenciar Capabilities')); ?>
            </h1>

            <?php if ($action === 'list'): ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create" class="page-title-action">Adicionar Nova</a>
            <?php else: ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="page-title-action">← Voltar</a>
            <?php endif; ?>

            <hr class="wp-header-end">

            <?php settings_errors('limpvix_capabilities'); ?>

            <?php
            switch ($action) {
                case 'create':
                    $this->renderForm();
                    break;
                case 'edit':
                    $this->renderForm($capabilityId);
                    break;
                default:
                    $this->renderList();
                    break;
            }
            ?>
        </div>

        <style>
            .limpvix-capabilities-wrap .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .limpvix-capabilities-wrap .status-badge.active {
                background: #d4edda;
                color: #155724;
            }
            .limpvix-capabilities-wrap .status-badge.inactive {
                background: #f8d7da;
                color: #721c24;
            }
            .limpvix-capabilities-wrap .usage-info {
                font-size: 12px;
                color: #646970;
            }
            .limpvix-capabilities-wrap .usage-info .usage-badge {
                display: inline-block;
                padding: 2px 6px;
                border-radius: 3px;
                background: #e2e4e7;
                margin-right: 4px;
                font-size: 11px;
            }
        </style>
        <?php
    }

    private function renderList(): void
    {
        $capabilities = $this->wpdb->get_results(
            "SELECT * FROM {$this->tableName} ORDER BY slug ASC",
            ARRAY_A
        );

        // Pre-fetch usage counts
        $usageCounts = $this->getAllUsageCounts();

        ?>
        <p class="description" style="margin-bottom: 15px;">
            Capabilities sao competencias tecnicas que profissionais devem possuir para executar servicos.
            Elas sao vinculadas a Complexidades de Servico e Adicionais.
        </p>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 40px;">ID</th>
                    <th>Slug</th>
                    <th>Nome</th>
                    <th>Descricao</th>
                    <th>Uso</th>
                    <th style="width: 80px;">Status</th>
                    <th style="width: 200px;">Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($capabilities)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 30px; color: #646970;">
                            Nenhuma capability encontrada. <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create">Criar primeira</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($capabilities as $cap): ?>
                        <?php
                        $usage = $usageCounts[$cap['id']] ?? ['complexity_count' => 0, 'additional_count' => 0];
                        ?>
                        <tr>
                            <td><?php echo esc_html($cap['id']); ?></td>
                            <td><code><?php echo esc_html($cap['slug']); ?></code></td>
                            <td><strong><?php echo esc_html($cap['display_name']); ?></strong></td>
                            <td><?php echo esc_html($cap['description'] ?: '—'); ?></td>
                            <td class="usage-info">
                                <?php if ($usage['complexity_count'] > 0): ?>
                                    <span class="usage-badge"><?php echo (int)$usage['complexity_count']; ?> complexidades</span>
                                <?php endif; ?>
                                <?php if ($usage['additional_count'] > 0): ?>
                                    <span class="usage-badge"><?php echo (int)$usage['additional_count']; ?> adicionais</span>
                                <?php endif; ?>
                                <?php if ($usage['complexity_count'] == 0 && $usage['additional_count'] == 0): ?>
                                    <span style="color: #b32d2e;">Sem uso</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $cap['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $cap['is_active'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=edit&id=<?php echo esc_attr($cap['id']); ?>" class="button button-small">Editar</a>

                                <form method="post" style="display: inline-block;">
                                    <?php wp_nonce_field(self::NONCE_ACTION . '_toggle_status', 'limpvix_capability_nonce_toggle_status'); ?>
                                    <input type="hidden" name="limpvix_capability_action" value="toggle_status">
                                    <input type="hidden" name="capability_id" value="<?php echo esc_attr($cap['id']); ?>">
                                    <button type="submit" class="button button-small">
                                        <?php echo $cap['is_active'] ? 'Desativar' : 'Ativar'; ?>
                                    </button>
                                </form>

                                <?php if ($usage['complexity_count'] == 0 && $usage['additional_count'] == 0): ?>
                                    <form method="post" style="display: inline-block;" onsubmit="return confirm('Deletar esta capability?');">
                                        <?php wp_nonce_field(self::NONCE_ACTION . '_delete', 'limpvix_capability_nonce_delete'); ?>
                                        <input type="hidden" name="limpvix_capability_action" value="delete">
                                        <input type="hidden" name="capability_id" value="<?php echo esc_attr($cap['id']); ?>">
                                        <button type="submit" class="button button-small button-link-delete">Deletar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
        <?php
    }

    private function renderForm(int $capabilityId = 0): void
    {
        $capability = null;
        $isEdit = $capabilityId > 0;

        if ($isEdit) {
            $capability = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableName} WHERE id = %d",
                $capabilityId
            ), ARRAY_A);

            if (!$capability) {
                echo '<div class="notice notice-error"><p>Capability nao encontrada.</p></div>';
                return;
            }
        }

        $slug = $capability['slug'] ?? '';
        $displayName = $capability['display_name'] ?? '';
        $description = $capability['description'] ?? '';
        $isActive = isset($capability['is_active']) ? (bool)$capability['is_active'] : true;

        ?>
        <form method="post" action="">
            <?php wp_nonce_field(self::NONCE_ACTION . '_' . ($isEdit ? 'edit' : 'create'), 'limpvix_capability_nonce_' . ($isEdit ? 'edit' : 'create')); ?>
            <input type="hidden" name="limpvix_capability_action" value="<?php echo $isEdit ? 'edit' : 'create'; ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="capability_id" value="<?php echo esc_attr($capabilityId); ?>">
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="slug">Slug *</label></th>
                    <td>
                        <input type="text" name="slug" id="slug"
                               value="<?php echo esc_attr($slug); ?>"
                               class="regular-text"
                               <?php echo $isEdit ? 'readonly' : ''; ?>
                               required
                               pattern="[a-z][a-z0-9_]*"
                               title="Letras minusculas, numeros e underscore">
                        <p class="description">Identificador unico (ex: cleaning_basic, window_cleaning)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="display_name">Nome de Exibicao *</label></th>
                    <td>
                        <input type="text" name="display_name" id="display_name"
                               value="<?php echo esc_attr($displayName); ?>"
                               class="regular-text" required>
                        <p class="description">Nome legivel (ex: Limpeza Basica, Limpeza de Vidros)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="description">Descricao</label></th>
                    <td>
                        <textarea name="description" id="description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="is_active">Status</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" id="is_active" value="1" <?php checked($isActive); ?>>
                            Capability ativa
                        </label>
                    </td>
                </tr>
            </table>

            <?php if ($isEdit): ?>
                <?php $this->renderUsageDetail($capabilityId); ?>
            <?php endif; ?>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php echo $isEdit ? 'Atualizar' : 'Criar'; ?> Capability
                </button>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }

    private function renderUsageDetail(int $capabilityId): void
    {
        $prefix = $this->wpdb->prefix;

        // Complexities using this capability
        $complexities = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT sc.slug, sc.display_name, cat.display_name AS service_name
             FROM {$prefix}limpvix_complexity_capabilities cc
             JOIN {$prefix}limpvix_service_complexities sc ON cc.complexity_id = sc.id
             JOIN {$prefix}limpvix_service_catalog cat ON sc.service_id = cat.id
             WHERE cc.capability_id = %d",
            $capabilityId
        ), ARRAY_A);

        // Additionals using this capability
        $additionals = $this->wpdb->get_results($this->wpdb->prepare(
            "SELECT sa.additional_code, sa.display_name
             FROM {$prefix}limpvix_additional_capabilities ac
             JOIN {$prefix}limpvix_service_additionals sa ON ac.additional_id = sa.id
             WHERE ac.capability_id = %d",
            $capabilityId
        ), ARRAY_A);

        if (empty($complexities) && empty($additionals)) {
            return;
        }

        ?>
        <h3>Onde esta capability e usada</h3>
        <div style="display: flex; gap: 30px;">
            <?php if (!empty($complexities)): ?>
                <div>
                    <h4>Complexidades (<?php echo count($complexities); ?>)</h4>
                    <ul>
                        <?php foreach ($complexities as $c): ?>
                            <li><code><?php echo esc_html($c['slug']); ?></code> — <?php echo esc_html($c['service_name']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            <?php if (!empty($additionals)): ?>
                <div>
                    <h4>Adicionais (<?php echo count($additionals); ?>)</h4>
                    <ul>
                        <?php foreach ($additionals as $a): ?>
                            <li><code><?php echo esc_html($a['additional_code']); ?></code> — <?php echo esc_html($a['display_name']); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    private function getCapabilityUsage(int $capabilityId): array
    {
        $prefix = $this->wpdb->prefix;

        $complexityCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}limpvix_complexity_capabilities WHERE capability_id = %d",
            $capabilityId
        ));

        $additionalCount = (int)$this->wpdb->get_var($this->wpdb->prepare(
            "SELECT COUNT(*) FROM {$prefix}limpvix_additional_capabilities WHERE capability_id = %d",
            $capabilityId
        ));

        return [
            'complexity_count' => $complexityCount,
            'additional_count' => $additionalCount,
        ];
    }

    private function getAllUsageCounts(): array
    {
        $prefix = $this->wpdb->prefix;
        $counts = [];

        // Complexity usage
        $complexityCounts = $this->wpdb->get_results(
            "SELECT capability_id, COUNT(*) as cnt FROM {$prefix}limpvix_complexity_capabilities GROUP BY capability_id",
            ARRAY_A
        );
        foreach ($complexityCounts as $row) {
            $counts[$row['capability_id']]['complexity_count'] = (int)$row['cnt'];
        }

        // Additional usage
        $additionalCounts = $this->wpdb->get_results(
            "SELECT capability_id, COUNT(*) as cnt FROM {$prefix}limpvix_additional_capabilities GROUP BY capability_id",
            ARRAY_A
        );
        foreach ($additionalCounts as $row) {
            if (!isset($counts[$row['capability_id']])) {
                $counts[$row['capability_id']] = ['complexity_count' => 0];
            }
            $counts[$row['capability_id']]['additional_count'] = (int)$row['cnt'];
        }

        // Fill missing keys
        foreach ($counts as &$c) {
            $c += ['complexity_count' => 0, 'additional_count' => 0];
        }

        return $counts;
    }
}
