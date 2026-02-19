<?php
/**
 * ExecutionLevelManagementPage - Gerenciamento de Niveis de Execucao
 *
 * RESPONSABILIDADE:
 * - CRUD de execution_levels (substitui PackageManagementPage)
 * - Campos: slug, display_name, price_multiplier, team_min/max, checklist_level, warranty_hours
 * - SEM campo de skills/capabilities (ExecutionLevel NAO participa do match tecnico)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.4.0 (Service Domain Refactor - FASE 5)
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class ExecutionLevelManagementPage
{
    private const PAGE_SLUG = 'limpvix-execution-levels';
    private const NONCE_ACTION = 'limpvix_execution_level_action';

    private $wpdb;
    private $tableName;

    private const CHECKLIST_LEVELS = [
        'basic' => 'Basico',
        'detailed' => 'Detalhado',
        'complete' => 'Completo',
    ];

    public function __construct()
    {
        global $wpdb;
        $this->wpdb = $wpdb;
        $this->tableName = $wpdb->prefix . 'limpvix_execution_levels';
    }

    public function register(): void
    {
        add_action('admin_init', [$this, 'handleFormSubmission']);
    }

    public function handleFormSubmission(): void
    {
        if (!isset($_POST['limpvix_execution_level_action'])) {
            return;
        }

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissao');
        }

        $action = sanitize_text_field($_POST['limpvix_execution_level_action']);

        $nonceField = 'limpvix_el_nonce_' . $action;
        if (!isset($_POST[$nonceField]) || !wp_verify_nonce($_POST[$nonceField], self::NONCE_ACTION . '_' . $action)) {
            wp_die('Nonce invalido');
        }

        switch ($action) {
            case 'create':
            case 'edit':
                $this->saveExecutionLevel();
                break;
            case 'delete':
                $this->deleteExecutionLevel();
                break;
            case 'toggle_status':
                $this->toggleStatus();
                break;
        }
    }

    private function saveExecutionLevel(): void
    {
        $levelId = isset($_POST['level_id']) ? (int)$_POST['level_id'] : 0;
        $slug = sanitize_text_field($_POST['slug'] ?? '');
        $displayName = sanitize_text_field($_POST['display_name'] ?? '');
        $description = sanitize_textarea_field($_POST['description'] ?? '');
        $priceMultiplier = (float)($_POST['price_multiplier'] ?? 1.0);
        $teamMin = (int)($_POST['team_min'] ?? 1);
        $teamMax = (int)($_POST['team_max'] ?? 1);
        $checklistLevel = sanitize_text_field($_POST['checklist_level'] ?? 'basic');
        $warrantyHours = (int)($_POST['warranty_hours'] ?? 0);
        $isActive = isset($_POST['is_active']) ? 1 : 0;

        if (empty($slug) || empty($displayName)) {
            add_settings_error('limpvix_execution_levels', 'invalid_data', 'Slug e nome sao obrigatorios', 'error');
            return;
        }

        if ($priceMultiplier < 0.5 || $priceMultiplier > 5.0) {
            add_settings_error('limpvix_execution_levels', 'invalid_multiplier', 'Multiplicador deve estar entre 0.50 e 5.00', 'error');
            return;
        }

        if ($teamMin < 1 || $teamMax < $teamMin) {
            add_settings_error('limpvix_execution_levels', 'invalid_team', 'Numero de profissionais invalido', 'error');
            return;
        }

        if (!array_key_exists($checklistLevel, self::CHECKLIST_LEVELS)) {
            $checklistLevel = 'basic';
        }

        $data = [
            'slug' => $slug,
            'display_name' => $displayName,
            'description' => $description ?: null,
            'price_multiplier' => $priceMultiplier,
            'team_min' => $teamMin,
            'team_max' => $teamMax,
            'checklist_level' => $checklistLevel,
            'warranty_hours' => $warrantyHours,
            'is_active' => $isActive,
            'updated_at' => current_time('mysql'),
        ];

        if ($levelId > 0) {
            $result = $this->wpdb->update(
                $this->tableName,
                $data,
                ['id' => $levelId],
                ['%s', '%s', '%s', '%f', '%d', '%d', '%s', '%d', '%d', '%s'],
                ['%d']
            );

            if ($result !== false) {
                add_settings_error('limpvix_execution_levels', 'level_updated', 'Nivel de Execucao atualizado!', 'success');
            } else {
                add_settings_error('limpvix_execution_levels', 'update_failed', 'Erro ao atualizar', 'error');
            }
        } else {
            $data['created_at'] = current_time('mysql');
            $result = $this->wpdb->insert(
                $this->tableName,
                $data,
                ['%s', '%s', '%s', '%f', '%d', '%d', '%s', '%d', '%d', '%s', '%s']
            );

            if ($result) {
                add_settings_error('limpvix_execution_levels', 'level_created', 'Nivel de Execucao criado!', 'success');
            } else {
                error_log('[ExecutionLevelManagementPage] DB error: ' . $this->wpdb->last_error);
                add_settings_error('limpvix_execution_levels', 'create_failed', 'Erro ao criar', 'error');
            }
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=1'));
        exit;
    }

    private function deleteExecutionLevel(): void
    {
        $levelId = (int)($_POST['level_id'] ?? 0);

        if ($levelId <= 0) {
            add_settings_error('limpvix_execution_levels', 'invalid_id', 'ID invalido', 'error');
            return;
        }

        $result = $this->wpdb->delete($this->tableName, ['id' => $levelId], ['%d']);

        if ($result) {
            add_settings_error('limpvix_execution_levels', 'level_deleted', 'Nivel deletado!', 'success');
        } else {
            add_settings_error('limpvix_execution_levels', 'delete_failed', 'Erro ao deletar', 'error');
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&deleted=1'));
        exit;
    }

    private function toggleStatus(): void
    {
        $levelId = (int)($_POST['level_id'] ?? 0);

        if ($levelId <= 0) {
            return;
        }

        $current = $this->wpdb->get_var($this->wpdb->prepare(
            "SELECT is_active FROM {$this->tableName} WHERE id = %d",
            $levelId
        ));

        $newStatus = $current ? 0 : 1;

        $this->wpdb->update(
            $this->tableName,
            ['is_active' => $newStatus, 'updated_at' => current_time('mysql')],
            ['id' => $levelId],
            ['%d', '%s'],
            ['%d']
        );

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&toggled=1'));
        exit;
    }

    public function render(): void
    {
        $action = $_GET['action'] ?? 'list';
        $levelId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

        ?>
        <div class="wrap limpvix-execution-levels-wrap">
            <h1 class="wp-heading-inline">
                <?php echo esc_html($action === 'edit' ? 'Editar Nivel de Execucao' : ($action === 'create' ? 'Novo Nivel de Execucao' : 'Niveis de Execucao')); ?>
            </h1>

            <?php if ($action === 'list'): ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create" class="page-title-action">Adicionar Novo</a>
            <?php else: ?>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="page-title-action">← Voltar</a>
            <?php endif; ?>

            <hr class="wp-header-end">

            <?php settings_errors('limpvix_execution_levels'); ?>

            <?php
            switch ($action) {
                case 'create':
                    $this->renderForm();
                    break;
                case 'edit':
                    $this->renderForm($levelId);
                    break;
                default:
                    $this->renderList();
                    break;
            }
            ?>
        </div>

        <style>
            .limpvix-execution-levels-wrap .status-badge {
                display: inline-block;
                padding: 3px 8px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 500;
            }
            .limpvix-execution-levels-wrap .status-badge.active {
                background: #d4edda;
                color: #155724;
            }
            .limpvix-execution-levels-wrap .status-badge.inactive {
                background: #f8d7da;
                color: #721c24;
            }
            .limpvix-execution-levels-wrap .form-table th {
                width: 220px;
            }
            .limpvix-execution-levels-wrap .info-card {
                background: #f0f6fc;
                border-left: 4px solid #2271b1;
                padding: 12px 16px;
                margin-bottom: 20px;
            }
        </style>
        <?php
    }

    private function renderList(): void
    {
        $levels = $this->wpdb->get_results(
            "SELECT * FROM {$this->tableName} ORDER BY price_multiplier ASC",
            ARRAY_A
        );

        ?>
        <div class="info-card">
            <strong>Niveis de Execucao</strong> definem a qualidade operacional do servico:
            multiplicador de preco, tamanho da equipe, nivel do checklist e garantia.
            <strong>Nao possuem capabilities</strong> — o match tecnico e feito pela Complexidade do Servico.
        </div>

        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 40px;">ID</th>
                    <th>Slug</th>
                    <th>Nome</th>
                    <th>Multiplicador</th>
                    <th>Equipe</th>
                    <th>Checklist</th>
                    <th>Garantia</th>
                    <th style="width: 80px;">Status</th>
                    <th style="width: 220px;">Acoes</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($levels)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 30px; color: #646970;">
                            Nenhum nivel encontrado. <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=create">Criar primeiro</a>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($levels as $level): ?>
                        <tr>
                            <td><?php echo esc_html($level['id']); ?></td>
                            <td><code><?php echo esc_html($level['slug']); ?></code></td>
                            <td><strong><?php echo esc_html($level['display_name']); ?></strong></td>
                            <td><?php echo number_format($level['price_multiplier'], 2); ?>x
                                <span style="color:#646970; font-size:12px;">(+<?php echo number_format(($level['price_multiplier'] - 1) * 100, 0); ?>%)</span>
                            </td>
                            <td><?php echo esc_html($level['team_min']); ?> - <?php echo esc_html($level['team_max']); ?></td>
                            <td><?php echo esc_html(self::CHECKLIST_LEVELS[$level['checklist_level']] ?? $level['checklist_level']); ?></td>
                            <td>
                                <?php if ($level['warranty_hours'] > 0): ?>
                                    <?php echo esc_html($level['warranty_hours']); ?>h
                                <?php else: ?>
                                    <span style="color: #646970;">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-badge <?php echo $level['is_active'] ? 'active' : 'inactive'; ?>">
                                    <?php echo $level['is_active'] ? 'Ativo' : 'Inativo'; ?>
                                </span>
                            </td>
                            <td>
                                <a href="?page=<?php echo self::PAGE_SLUG; ?>&action=edit&id=<?php echo esc_attr($level['id']); ?>" class="button button-small">Editar</a>

                                <form method="post" style="display: inline-block;">
                                    <?php wp_nonce_field(self::NONCE_ACTION . '_toggle_status', 'limpvix_el_nonce_toggle_status'); ?>
                                    <input type="hidden" name="limpvix_execution_level_action" value="toggle_status">
                                    <input type="hidden" name="level_id" value="<?php echo esc_attr($level['id']); ?>">
                                    <button type="submit" class="button button-small">
                                        <?php echo $level['is_active'] ? 'Desativar' : 'Ativar'; ?>
                                    </button>
                                </form>

                                <form method="post" style="display: inline-block;" onsubmit="return confirm('Deletar este nivel? Esta acao nao pode ser desfeita.');">
                                    <?php wp_nonce_field(self::NONCE_ACTION . '_delete', 'limpvix_el_nonce_delete'); ?>
                                    <input type="hidden" name="limpvix_execution_level_action" value="delete">
                                    <input type="hidden" name="level_id" value="<?php echo esc_attr($level['id']); ?>">
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

    private function renderForm(int $levelId = 0): void
    {
        $level = null;
        $isEdit = $levelId > 0;

        if ($isEdit) {
            $level = $this->wpdb->get_row($this->wpdb->prepare(
                "SELECT * FROM {$this->tableName} WHERE id = %d",
                $levelId
            ), ARRAY_A);

            if (!$level) {
                echo '<div class="notice notice-error"><p>Nivel nao encontrado.</p></div>';
                return;
            }
        }

        $slug = $level['slug'] ?? '';
        $displayName = $level['display_name'] ?? '';
        $description = $level['description'] ?? '';
        $priceMultiplier = $level['price_multiplier'] ?? 1.0;
        $teamMin = $level['team_min'] ?? 1;
        $teamMax = $level['team_max'] ?? 1;
        $checklistLevel = $level['checklist_level'] ?? 'basic';
        $warrantyHours = $level['warranty_hours'] ?? 0;
        $isActive = isset($level['is_active']) ? (bool)$level['is_active'] : true;

        ?>
        <form method="post" action="">
            <?php wp_nonce_field(self::NONCE_ACTION . '_' . ($isEdit ? 'edit' : 'create'), 'limpvix_el_nonce_' . ($isEdit ? 'edit' : 'create')); ?>
            <input type="hidden" name="limpvix_execution_level_action" value="<?php echo $isEdit ? 'edit' : 'create'; ?>">
            <?php if ($isEdit): ?>
                <input type="hidden" name="level_id" value="<?php echo esc_attr($levelId); ?>">
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
                        <p class="description">Identificador unico (ex: basic_execution, standard_execution, premium_execution)</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="display_name">Nome de Exibicao *</label></th>
                    <td>
                        <input type="text" name="display_name" id="display_name"
                               value="<?php echo esc_attr($displayName); ?>"
                               class="regular-text" required>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="description">Descricao</label></th>
                    <td>
                        <textarea name="description" id="description" rows="3" class="large-text"><?php echo esc_textarea($description); ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="price_multiplier">Multiplicador de Preco *</label></th>
                    <td>
                        <input type="number" name="price_multiplier" id="price_multiplier"
                               value="<?php echo esc_attr($priceMultiplier); ?>"
                               min="0.5" max="5.0" step="0.01"
                               class="small-text" required>x
                        <p class="description">1.00 = preco base, 1.15 = +15%, 1.30 = +30%</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Tamanho da Equipe *</th>
                    <td>
                        <label for="team_min">Minimo:</label>
                        <input type="number" name="team_min" id="team_min"
                               value="<?php echo esc_attr($teamMin); ?>"
                               min="1" max="10" class="small-text" required>

                        <label for="team_max" style="margin-left: 15px;">Maximo:</label>
                        <input type="number" name="team_max" id="team_max"
                               value="<?php echo esc_attr($teamMax); ?>"
                               min="1" max="10" class="small-text" required>
                        <p class="description">Quantidade de profissionais alocados neste nivel</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="checklist_level">Nivel do Checklist</label></th>
                    <td>
                        <select name="checklist_level" id="checklist_level">
                            <?php foreach (self::CHECKLIST_LEVELS as $value => $label): ?>
                                <option value="<?php echo esc_attr($value); ?>" <?php selected($checklistLevel, $value); ?>>
                                    <?php echo esc_html($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <p class="description">Determina o nivel de detalhamento do checklist de verificacao</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="warranty_hours">Garantia (horas)</label></th>
                    <td>
                        <input type="number" name="warranty_hours" id="warranty_hours"
                               value="<?php echo esc_attr($warrantyHours); ?>"
                               min="0" max="720" class="small-text">h
                        <p class="description">0 = sem garantia, 12 = 12h, 24 = 24h de garantia pos-servico</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="is_active">Status</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="is_active" id="is_active" value="1" <?php checked($isActive); ?>>
                            Nivel ativo (disponivel para selecao)
                        </label>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">
                    <?php echo $isEdit ? 'Atualizar' : 'Criar'; ?> Nivel de Execucao
                </button>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }
}
