<?php
/**
 * MessageTemplatesAdminPage
 *
 * Gerenciamento de templates de mensagens
 * - Visualização de templates canônicos (read-only do domínio)
 * - CRUD de templates customizados (WordPress options)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.1.3
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Domain\Communication\Templates\MessageTemplates;

class MessageTemplatesAdminPage
{
    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        add_action('admin_post_limpvix_save_custom_template', [__CLASS__, 'handleSaveCustomTemplate']);
        add_action('admin_post_limpvix_delete_custom_template', [__CLASS__, 'handleDeleteCustomTemplate']);
        add_action('wp_ajax_limpvix_preview_template', [__CLASS__, 'handlePreviewTemplate']);
    }

    /**
     * Renderizar página
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $canonical_templates = $this->getCanonicalTemplates();
        $custom_templates = $this->getCustomTemplates();

        $message = $_GET['message'] ?? '';
        $editing = $_GET['edit'] ?? '';

        ?>
        <div class="wrap">
            <h1>📝 Templates de Mensagens</h1>
            <p class="description">Gerenciar templates canônicos e customizados</p>

            <?php if ($message === 'saved'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Template customizado salvo com sucesso!</strong></p>
                </div>
            <?php elseif ($message === 'deleted'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>🗑️ Template customizado removido.</strong></p>
                </div>
            <?php endif; ?>

            <!-- Tabs -->
            <h2 class="nav-tab-wrapper">
                <a href="#canonical" class="nav-tab nav-tab-active" onclick="switchTab(event, 'canonical')">Templates Canônicos</a>
                <a href="#custom" class="nav-tab" onclick="switchTab(event, 'custom')">Templates Customizados</a>
                <?php if ($editing): ?>
                    <a href="#editor" class="nav-tab" onclick="switchTab(event, 'editor')">Editor</a>
                <?php endif; ?>
            </h2>

            <!-- Tab: Templates Canônicos (Read-Only) -->
            <div id="canonical-tab" class="tab-content">
                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; margin: 20px 0;">
                    <strong>ℹ️ Templates Canônicos:</strong> Definidos no domínio (MessageTemplates.php).
                    Não podem ser editados pela UI. Para modificar, edite o arquivo fonte.
                </div>

                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th style="width: 80px;">ID</th>
                            <th>Nome</th>
                            <th>Canal</th>
                            <th>Tipo</th>
                            <th style="width: 200px;">Ações</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($canonical_templates as $tpl): ?>
                            <tr>
                                <td><code><?php echo esc_html($tpl['id']); ?></code></td>
                                <td>
                                    <strong><?php echo esc_html($tpl['name']); ?></strong>
                                    <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                        <?php echo esc_html($tpl['description']); ?>
                                    </div>
                                </td>
                                <td><?php echo $this->renderChannelBadge($tpl['channel']); ?></td>
                                <td><?php echo $this->renderTypeBadge($tpl['type']); ?></td>
                                <td>
                                    <button type="button" class="button" onclick="previewTemplate('<?php echo esc_attr($tpl['id']); ?>', 'canonical')">
                                        👁️ Visualizar
                                    </button>
                                    <span style="color: #6b7280; font-size: 12px;">🔒 Somente leitura</span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Tab: Templates Customizados (CRUD) -->
            <div id="custom-tab" class="tab-content" style="display: none;">
                <div style="margin: 20px 0;">
                    <a href="<?php echo add_query_arg(['page' => 'limpvix-templates', 'edit' => 'new'], admin_url('admin.php')); ?>" class="button button-primary">
                        ➕ Novo Template Customizado
                    </a>
                </div>

                <?php if (empty($custom_templates)): ?>
                    <div style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 20px 0;">
                        <p><strong>💡 Nenhum template customizado criado ainda.</strong></p>
                        <p>Templates customizados permitem criar mensagens personalizadas para casos especiais.</p>
                    </div>
                <?php else: ?>
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th style="width: 120px;">ID</th>
                                <th>Nome</th>
                                <th>Canal</th>
                                <th>Tipo</th>
                                <th style="width: 200px;">Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($custom_templates as $id => $tpl): ?>
                                <tr>
                                    <td><code><?php echo esc_html($id); ?></code></td>
                                    <td>
                                        <strong><?php echo esc_html($tpl['name']); ?></strong>
                                        <?php if (!empty($tpl['description'])): ?>
                                            <div style="color: #6b7280; font-size: 12px; margin-top: 4px;">
                                                <?php echo esc_html($tpl['description']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo $this->renderChannelBadge($tpl['channel']); ?></td>
                                    <td><?php echo $this->renderTypeBadge($tpl['type']); ?></td>
                                    <td>
                                        <a href="<?php echo add_query_arg(['page' => 'limpvix-templates', 'edit' => $id], admin_url('admin.php')); ?>" class="button">
                                            ✏️ Editar
                                        </a>
                                        <button type="button" class="button" onclick="previewTemplate('<?php echo esc_attr($id); ?>', 'custom')">
                                            👁️ Preview
                                        </button>
                                        <button type="button" class="button button-link-delete" onclick="deleteCustomTemplate('<?php echo esc_attr($id); ?>')">
                                            🗑️ Deletar
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; ?>
            </div>

            <!-- Tab: Editor (somente se editing) -->
            <?php if ($editing): ?>
                <div id="editor-tab" class="tab-content" style="display: none;">
                    <?php $this->renderEditor($editing, $custom_templates); ?>
                </div>
            <?php endif; ?>
        </div>

        <!-- Preview Modal -->
        <div id="template-preview-modal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.7); z-index: 9999; justify-content: center; align-items: center;">
            <div style="background: white; padding: 24px; border-radius: 8px; max-width: 600px; width: 90%; max-height: 80vh; overflow-y: auto;">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
                    <h2 style="margin: 0;">👁️ Preview do Template</h2>
                    <button onclick="closePreviewModal()" class="button" style="font-size: 20px; padding: 0 12px;">✕</button>
                </div>
                <div id="preview-content" style="border: 1px solid #ddd; padding: 16px; background: #f9f9f9; border-radius: 4px; white-space: pre-wrap; font-family: monospace;">
                    Carregando...
                </div>
            </div>
        </div>

        <script>
        function switchTab(event, tabId) {
            event.preventDefault();

            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => tab.style.display = 'none');
            document.querySelectorAll('.nav-tab').forEach(tab => tab.classList.remove('nav-tab-active'));

            // Show selected tab
            document.getElementById(tabId + '-tab').style.display = 'block';
            event.target.classList.add('nav-tab-active');
        }

        function previewTemplate(templateId, type) {
            const modal = document.getElementById('template-preview-modal');
            const content = document.getElementById('preview-content');

            modal.style.display = 'flex';
            content.innerHTML = 'Carregando preview...';

            // AJAX request
            jQuery.post(ajaxurl, {
                action: 'limpvix_preview_template',
                template_id: templateId,
                template_type: type,
                nonce: '<?php echo wp_create_nonce('limpvix_preview_template'); ?>'
            }, function(response) {
                if (response.success) {
                    content.innerHTML = response.data.preview;
                } else {
                    content.innerHTML = '❌ Erro: ' + (response.data.message || 'Falha ao carregar preview');
                }
            });
        }

        function closePreviewModal() {
            document.getElementById('template-preview-modal').style.display = 'none';
        }

        function deleteCustomTemplate(templateId) {
            if (!confirm('Tem certeza que deseja deletar este template customizado?')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo admin_url('admin-post.php'); ?>';

            const fields = {
                action: 'limpvix_delete_custom_template',
                template_id: templateId,
                _wpnonce: '<?php echo wp_create_nonce('limpvix_delete_custom_template'); ?>'
            };

            for (const [key, value] of Object.entries(fields)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
        }

        // Close modal on ESC key
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closePreviewModal();
            }
        });

        // Auto-switch to editor tab if editing
        <?php if ($editing): ?>
        setTimeout(() => {
            const editorTab = document.querySelector('a[href="#editor"]');
            if (editorTab) {
                editorTab.click();
            }
        }, 100);
        <?php endif; ?>
        </script>

        <style>
        .tab-content {
            margin-top: 20px;
        }
        .nav-tab-wrapper {
            margin-bottom: 0 !important;
        }
        </style>
        <?php
    }

    /**
     * Renderizar editor de template
     */
    private function renderEditor(string $templateId, array $customTemplates): void
    {
        $isNew = ($templateId === 'new');
        $template = $isNew ? [
            'name' => '',
            'description' => '',
            'channel' => 'whatsapp',
            'type' => 'client',
            'content' => '',
        ] : ($customTemplates[$templateId] ?? null);

        if (!$isNew && !$template) {
            echo '<div class="notice notice-error"><p>Template não encontrado.</p></div>';
            return;
        }

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <input type="hidden" name="action" value="limpvix_save_custom_template">
            <input type="hidden" name="template_id" value="<?php echo esc_attr($templateId); ?>">
            <?php wp_nonce_field('limpvix_save_custom_template'); ?>

            <table class="form-table">
                <tr>
                    <th scope="row"><label for="template_name">Nome do Template</label></th>
                    <td>
                        <input type="text" id="template_name" name="template_name"
                               value="<?php echo esc_attr($template['name']); ?>"
                               class="regular-text" required>
                        <p class="description">Nome descritivo do template</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="template_description">Descrição</label></th>
                    <td>
                        <textarea id="template_description" name="template_description"
                                  rows="2" class="large-text"><?php echo esc_textarea($template['description']); ?></textarea>
                        <p class="description">Descrição opcional para identificar o uso do template</p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="template_channel">Canal</label></th>
                    <td>
                        <select id="template_channel" name="template_channel" required>
                            <option value="whatsapp" <?php selected($template['channel'], 'whatsapp'); ?>>WhatsApp</option>
                            <option value="sms" <?php selected($template['channel'], 'sms'); ?>>SMS</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="template_type">Tipo</label></th>
                    <td>
                        <select id="template_type" name="template_type" required>
                            <option value="client" <?php selected($template['type'], 'client'); ?>>Cliente</option>
                            <option value="staff" <?php selected($template['type'], 'staff'); ?>>Profissional (Staff)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="template_content">Conteúdo do Template</label></th>
                    <td>
                        <textarea id="template_content" name="template_content"
                                  rows="10" class="large-text code" required><?php echo esc_textarea($template['content']); ?></textarea>
                        <p class="description">
                            <strong>Variáveis disponíveis:</strong><br>
                            <code>{{customer_name}}</code> - Nome do cliente<br>
                            <code>{{staff_name}}</code> - Nome do profissional<br>
                            <code>{{service_name}}</code> - Nome do serviço<br>
                            <code>{{service_date}}</code> - Data do serviço<br>
                            <code>{{rating_url}}</code> - URL para avaliação<br>
                            <code>{{google_review_url}}</code> - URL Google Review<br>
                            <code>{{amount}}</code> - Valor do pagamento
                        </p>
                    </td>
                </tr>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">💾 Salvar Template</button>
                <a href="<?php echo admin_url('admin.php?page=limpvix-templates'); ?>" class="button">Cancelar</a>
            </p>
        </form>
        <?php
    }

    /**
     * Buscar templates canônicos
     */
    private function getCanonicalTemplates(): array
    {
        return [
            [
                'id' => 'C1.1',
                'name' => 'Feedback D+1 (1ª tentativa)',
                'description' => 'Solicitação de feedback 24h após conclusão do serviço',
                'channel' => 'whatsapp',
                'type' => 'client',
            ],
            [
                'id' => 'C1.2',
                'name' => 'Feedback D+3 (2ª tentativa)',
                'description' => 'Lembrete de feedback após 72h',
                'channel' => 'whatsapp',
                'type' => 'client',
            ],
            [
                'id' => 'C1.3',
                'name' => 'Feedback D+7 (3ª tentativa)',
                'description' => 'Último lembrete de feedback após 7 dias',
                'channel' => 'sms',
                'type' => 'client',
            ],
            [
                'id' => 'C2',
                'name' => 'Feedback Negativo (≤3⭐)',
                'description' => 'BLOQUEADO - Requer atendimento manual',
                'channel' => 'none',
                'type' => 'client',
            ],
            [
                'id' => 'C3',
                'name' => 'Convite Google Review (5⭐)',
                'description' => 'Convite para avaliar no Google após feedback positivo',
                'channel' => 'whatsapp',
                'type' => 'client',
            ],
            [
                'id' => 'P1',
                'name' => 'Serviço Concluído',
                'description' => 'Notificação ao profissional sobre conclusão do serviço',
                'channel' => 'sms',
                'type' => 'staff',
            ],
            [
                'id' => 'P2',
                'name' => 'Pagamento Autorizado',
                'description' => 'Notificação ao profissional sobre pagamento liberado',
                'channel' => 'sms',
                'type' => 'staff',
            ],
            [
                'id' => 'P3',
                'name' => 'Pagamento em Análise',
                'description' => 'Notificação ao profissional sobre pagamento retido',
                'channel' => 'sms',
                'type' => 'staff',
            ],
        ];
    }

    /**
     * Buscar templates customizados
     */
    private function getCustomTemplates(): array
    {
        return get_option('limpvix_custom_templates', []);
    }

    /**
     * Renderizar badge de canal
     */
    private function renderChannelBadge(string $channel): string
    {
        $badges = [
            'sms' => '<span style="background: #3b82f6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">📱 SMS</span>',
            'whatsapp' => '<span style="background: #10b981; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">💬 WhatsApp</span>',
            'none' => '<span style="background: #6b7280; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">🔒 Nenhum</span>',
        ];

        return $badges[$channel] ?? '<span style="color: #6b7280;">—</span>';
    }

    /**
     * Renderizar badge de tipo
     */
    private function renderTypeBadge(string $type): string
    {
        $badges = [
            'client' => '<span style="background: #8b5cf6; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">👤 Cliente</span>',
            'staff' => '<span style="background: #f59e0b; color: #fff; padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: 600;">👔 Staff</span>',
        ];

        return $badges[$type] ?? '<span style="color: #6b7280;">—</span>';
    }

    /**
     * Handler: Salvar template customizado
     */
    public static function handleSaveCustomTemplate(): void
    {
        check_admin_referer('limpvix_save_custom_template');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $templateId = sanitize_text_field($_POST['template_id']);
        $isNew = ($templateId === 'new');

        if ($isNew) {
            // Gerar ID único
            $templateId = 'CUSTOM_' . strtoupper(substr(md5(uniqid()), 0, 8));
        }

        $custom_templates = get_option('limpvix_custom_templates', []);

        $custom_templates[$templateId] = [
            'name' => sanitize_text_field($_POST['template_name']),
            'description' => sanitize_textarea_field($_POST['template_description']),
            'channel' => sanitize_text_field($_POST['template_channel']),
            'type' => sanitize_text_field($_POST['template_type']),
            'content' => wp_kses_post($_POST['template_content']),
            'created_at' => $isNew ? current_time('mysql') : ($custom_templates[$templateId]['created_at'] ?? current_time('mysql')),
            'updated_at' => current_time('mysql'),
        ];

        update_option('limpvix_custom_templates', $custom_templates);

        wp_redirect(add_query_arg([
            'page' => 'limpvix-templates',
            'message' => 'saved'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handler: Deletar template customizado
     */
    public static function handleDeleteCustomTemplate(): void
    {
        check_admin_referer('limpvix_delete_custom_template');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $templateId = sanitize_text_field($_POST['template_id']);
        $custom_templates = get_option('limpvix_custom_templates', []);

        if (isset($custom_templates[$templateId])) {
            unset($custom_templates[$templateId]);
            update_option('limpvix_custom_templates', $custom_templates);
        }

        wp_redirect(add_query_arg([
            'page' => 'limpvix-templates',
            'message' => 'deleted'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handler AJAX: Preview de template
     */
    public static function handlePreviewTemplate(): void
    {
        check_ajax_referer('limpvix_preview_template', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão']);
        }

        $templateId = sanitize_text_field($_POST['template_id']);
        $templateType = sanitize_text_field($_POST['template_type']);

        try {
            if ($templateType === 'canonical') {
                // Buscar do domínio
                $content = MessageTemplates::getTemplate($templateId);
            } else {
                // Buscar dos customizados
                $custom_templates = get_option('limpvix_custom_templates', []);
                $content = $custom_templates[$templateId]['content'] ?? null;
            }

            if (!$content) {
                wp_send_json_error(['message' => 'Template não encontrado']);
            }

            // Renderizar com dados de exemplo
            $preview = self::renderPreviewWithMockData($content);

            wp_send_json_success(['preview' => $preview]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Renderizar preview com dados mockados
     */
    private static function renderPreviewWithMockData(string $template): string
    {
        $mockData = [
            '{{customer_name}}' => 'João Silva',
            '{{staff_name}}' => 'Maria Santos',
            '{{service_name}}' => 'Limpeza Residencial Completa',
            '{{service_date}}' => '15/02/2026',
            '{{rating_url}}' => 'https://limpvix.com.br/rating/abc123',
            '{{google_review_url}}' => 'https://g.page/limpvix/review',
            '{{amount}}' => 'R$ 250,00',
        ];

        return str_replace(array_keys($mockData), array_values($mockData), $template);
    }
}
