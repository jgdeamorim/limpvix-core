<?php
/**
 * MessageTemplatesAdminPage
 *
 * Gerenciamento completo de templates de mensagens
 * - Edição de templates canônicos (via override)
 * - CRUD de templates customizados
 * - Preview com dados reais
 * - Gestão de providers (NVoip/Twilio)
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 1.0.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Domain\Communication\MessageTemplates;

class MessageTemplatesAdminPage
{
    /**
     * Registrar hooks
     */
    public static function register(): void
    {
        add_action('admin_post_limpvix_save_template', [__CLASS__, 'handleSaveTemplate']);
        add_action('admin_post_limpvix_reset_template', [__CLASS__, 'handleResetTemplate']);
        add_action('admin_post_limpvix_delete_custom_template', [__CLASS__, 'handleDeleteCustomTemplate']);
        add_action('wp_ajax_limpvix_preview_template', [__CLASS__, 'handlePreviewTemplate']);
        add_action('wp_ajax_limpvix_get_template_data', [__CLASS__, 'handleGetTemplateData']);
    }

    /**
     * Renderizar página
     */
    public function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        // Detectar provider ativo
        $twilioConfigured = !empty(get_option('limpvix_twilio_account_sid')) &&
                           !empty(get_option('limpvix_twilio_auth_token'));

        // Buscar status NVoip
        $nvoipConfigured = false;
        if (class_exists('LimpVix\\Infrastructure\\Communication\\NVoipSettings')) {
            $nvoipConfigured = \LimpVix\Infrastructure\Communication\NVoipSettings::isConnected();
        }

        // Determinar provider ativo
        if ($twilioConfigured && !$nvoipConfigured) {
            $activeProvider = 'twilio';
        } elseif ($nvoipConfigured && !$twilioConfigured) {
            $activeProvider = 'nvoip';
        } elseif ($twilioConfigured && $nvoipConfigured) {
            $activeProvider = get_option('limpvix_active_sms_provider', 'twilio');
        } else {
            $activeProvider = 'nenhum';
        }

        $templates = $this->getAllTemplates();
        $message = $_GET['message'] ?? '';
        $editing = $_GET['edit'] ?? '';

        ?>
        <style>
        .limpvix-template-card {
            background: white;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 16px;
            transition: all 0.3s ease;
        }
        .limpvix-template-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .limpvix-template-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .limpvix-template-card-body {
            padding: 20px;
        }
        .limpvix-template-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .limpvix-template-content {
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 16px;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            line-height: 1.6;
            white-space: pre-wrap;
            word-wrap: break-word;
            max-height: 200px;
            overflow-y: auto;
        }
        .limpvix-variable {
            background: #dbeafe;
            color: #1e40af;
            padding: 2px 6px;
            border-radius: 3px;
            font-weight: 600;
        }
        .limpvix-hero-stat {
            background: rgba(255,255,255,0.15);
            padding: 16px;
            border-radius: 6px;
            text-align: center;
            backdrop-filter: blur(10px);
        }
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.75);
            z-index: 99999;
            justify-content: center;
            align-items: center;
            animation: fadeIn 0.2s ease;
        }
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        .modal-content {
            background: white;
            padding: 0;
            border-radius: 12px;
            max-width: 700px;
            width: 90%;
            max-height: 85vh;
            overflow: hidden;
            box-shadow: 0 20px 25px -5px rgba(0,0,0,0.3);
            animation: slideUp 0.3s ease;
        }
        @keyframes slideUp {
            from { transform: translateY(20px); opacity: 0; }
            to { transform: translateY(0); opacity: 1; }
        }
        .modal-header {
            padding: 20px 24px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            justify-content: space-between;
            align-items: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }
        .modal-body {
            padding: 24px;
            overflow-y: auto;
            max-height: calc(85vh - 80px);
        }
        </style>

        <div class="wrap">
            <!-- HERO CARD -->
            <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 24px; border: none;">
                <div class="limpvix-card-body" style="padding: 24px;">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <div>
                            <h1 style="color: white; margin: 0 0 8px 0; font-size: 28px;">📝 Templates de Mensagens</h1>
                            <p style="color: #f0f0f0; margin: 0; font-size: 14px;">
                                Gerencie templates de WhatsApp, SMS e Email com suporte dual provider
                            </p>
                        </div>
                        <div style="text-align: right;">
                            <div style="background: rgba(255,255,255,0.2); padding: 12px 20px; border-radius: 6px; backdrop-filter: blur(10px);">
                                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 1px; opacity: 0.9;">Provider Ativo</div>
                                <div style="font-size: 18px; font-weight: bold; margin-top: 2px;"><?php echo strtoupper($activeProvider); ?></div>
                            </div>
                        </div>
                    </div>

                    <!-- Quick Stats -->
                    <div style="display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px;">
                        <div class="limpvix-hero-stat">
                            <div style="font-size: 28px; font-weight: bold; margin-bottom: 4px;"><?php echo count($templates['canonical']); ?></div>
                            <div style="font-size: 11px; opacity: 0.9;">Templates Sistema</div>
                        </div>
                        <div class="limpvix-hero-stat">
                            <div style="font-size: 28px; font-weight: bold; margin-bottom: 4px;"><?php echo count($templates['custom']); ?></div>
                            <div style="font-size: 11px; opacity: 0.9;">Templates Custom</div>
                        </div>
                        <div class="limpvix-hero-stat">
                            <div style="font-size: 28px; font-weight: bold; margin-bottom: 4px;">7</div>
                            <div style="font-size: 11px; opacity: 0.9;">Fluxos Automáticos</div>
                        </div>
                        <div class="limpvix-hero-stat">
                            <div style="font-size: 28px; font-weight: bold; margin-bottom: 4px;">✓</div>
                            <div style="font-size: 11px; opacity: 0.9;">Fallback Ativo</div>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($message === 'saved'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>✅ Template salvo com sucesso!</strong></p>
                </div>
            <?php elseif ($message === 'reset'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>🔄 Template restaurado para o padrão do sistema.</strong></p>
                </div>
            <?php elseif ($message === 'deleted'): ?>
                <div class="notice notice-success is-dismissible">
                    <p><strong>🗑️ Template customizado removido.</strong></p>
                </div>
            <?php endif; ?>

            <!-- TEMPLATES DO SISTEMA -->
            <div class="limpvix-card" style="margin-bottom: 24px;">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%); color: white;">
                    <h2 style="color: white; margin: 0; font-size: 18px;">
                        <span class="dashicons dashicons-admin-settings"></span>
                        🔧 Templates do Sistema
                    </h2>
                    <p style="color: #e0e7ff; margin: 5px 0 0 0; font-size: 13px;">Edite e personalize as mensagens enviadas automaticamente</p>
                </div>
                <div class="limpvix-card-body" style="padding: 0;">
                    <div style="padding: 20px; background: #eff6ff; border-bottom: 1px solid #dbeafe;">
                        <p style="margin: 0; color: #1e40af; font-size: 13px;">
                            💡 <strong>Dica:</strong> Você pode editar qualquer template do sistema. Suas alterações serão salvas e usadas no lugar do template padrão. Para voltar ao original, use "Restaurar Padrão".
                        </p>
                    </div>

                    <?php foreach ($templates['canonical'] as $tpl): ?>
                        <div style="border-bottom: 1px solid #e5e7eb; padding: 20px;">
                            <div style="display: flex; justify-content: space-between; align-items: start; margin-bottom: 12px;">
                                <div style="flex: 1;">
                                    <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 8px;">
                                        <code style="background: #f3f4f6; padding: 4px 8px; border-radius: 4px; font-weight: 600; color: #1f2937;"><?php echo esc_html($tpl['id']); ?></code>
                                        <h3 style="margin: 0; font-size: 16px; color: #111827;"><?php echo esc_html($tpl['name']); ?></h3>
                                    </div>
                                    <p style="color: #6b7280; font-size: 13px; margin: 0 0 12px 0;">
                                        <?php echo esc_html($tpl['description']); ?>
                                    </p>
                                    <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                        <?php echo $this->renderChannelBadge($tpl['channel']); ?>
                                        <?php echo $this->renderTypeBadge($tpl['type']); ?>
                                        <?php if (!empty($tpl['is_override'])): ?>
                                            <span class="limpvix-template-badge" style="background: #fbbf24; color: #78350f;">
                                                ✏️ CUSTOMIZADO
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div style="display: flex; gap: 8px;">
                                    <button type="button" class="button" onclick="editTemplate('<?php echo esc_attr($tpl['id']); ?>', 'canonical')">
                                        ✏️ Editar
                                    </button>
                                    <button type="button" class="button" onclick="previewTemplate('<?php echo esc_attr($tpl['id']); ?>', 'canonical')">
                                        👁️ Preview
                                    </button>
                                    <?php if (!empty($tpl['is_override'])): ?>
                                        <button type="button" class="button button-secondary" onclick="resetTemplate('<?php echo esc_attr($tpl['id']); ?>')">
                                            🔄 Restaurar
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <?php if (!empty($tpl['trigger_event'])): ?>
                                <div style="background: #f0fdf4; border-left: 3px solid #10b981; padding: 12px; border-radius: 4px; margin-top: 12px;">
                                    <div style="color: #065f46; font-size: 12px;">
                                        <strong>🔔 Trigger:</strong> <?php echo esc_html($tpl['trigger_event']); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- TEMPLATES CUSTOMIZADOS -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #8b5cf6 0%, #7c3aed 100%); color: white;">
                    <h2 style="color: white; margin: 0; font-size: 18px;">
                        <span class="dashicons dashicons-admin-customizer"></span>
                        ✨ Templates Customizados
                    </h2>
                    <button type="button" class="button button-primary" onclick="createCustomTemplate()" style="background: white; color: #7c3aed; border: none; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                        ➕ Novo Template
                    </button>
                </div>
                <div class="limpvix-card-body">
                    <?php if (empty($templates['custom'])): ?>
                        <div style="text-align: center; padding: 40px 20px;">
                            <div style="font-size: 48px; margin-bottom: 16px; opacity: 0.5;">📋</div>
                            <h3 style="color: #6b7280; margin: 0 0 8px 0;">Nenhum template customizado</h3>
                            <p style="color: #9ca3af; margin: 0 0 20px 0;">Crie templates personalizados para casos especiais</p>
                            <button type="button" class="button button-primary" onclick="createCustomTemplate()">
                                ➕ Criar Primeiro Template
                            </button>
                        </div>
                    <?php else: ?>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 16px;">
                            <?php foreach ($templates['custom'] as $id => $tpl): ?>
                                <div class="limpvix-template-card">
                                    <div class="limpvix-template-card-header">
                                        <div>
                                            <h4 style="margin: 0 0 4px 0; font-size: 14px;"><?php echo esc_html($tpl['name']); ?></h4>
                                            <code style="font-size: 10px; color: #6b7280;"><?php echo esc_html($id); ?></code>
                                        </div>
                                    </div>
                                    <div class="limpvix-template-card-body">
                                        <?php if (!empty($tpl['description'])): ?>
                                            <p style="color: #6b7280; font-size: 12px; margin: 0 0 12px 0;">
                                                <?php echo esc_html($tpl['description']); ?>
                                            </p>
                                        <?php endif; ?>
                                        <div style="display: flex; gap: 6px; margin-bottom: 12px;">
                                            <?php echo $this->renderChannelBadge($tpl['channel']); ?>
                                            <?php echo $this->renderTypeBadge($tpl['type']); ?>
                                        </div>
                                        <div style="display: flex; gap: 6px;">
                                            <button type="button" class="button button-small" onclick="editTemplate('<?php echo esc_attr($id); ?>', 'custom')" style="flex: 1;">
                                                ✏️ Editar
                                            </button>
                                            <button type="button" class="button button-small" onclick="previewTemplate('<?php echo esc_attr($id); ?>', 'custom')">
                                                👁️
                                            </button>
                                            <button type="button" class="button button-small button-link-delete" onclick="deleteCustomTemplate('<?php echo esc_attr($id); ?>')">
                                                🗑️
                                            </button>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- VARIÁVEIS DISPONÍVEIS -->
            <div class="limpvix-card" style="margin-top: 24px;">
                <div class="limpvix-card-header">
                    <h3 style="margin: 0;">
                        <span class="dashicons dashicons-editor-code"></span>
                        📌 Variáveis Disponíveis
                    </h3>
                </div>
                <div class="limpvix-card-body">
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 16px;">
                        <?php
                        $variables = [
                            ['var' => '{{customer_name}}', 'desc' => 'Nome do cliente', 'example' => 'João Silva'],
                            ['var' => '{{staff_name}}', 'desc' => 'Nome do profissional', 'example' => 'Maria Santos'],
                            ['var' => '{{service_name}}', 'desc' => 'Nome do serviço', 'example' => 'Limpeza Residencial'],
                            ['var' => '{{service_date}}', 'desc' => 'Data do serviço', 'example' => '16/02/2026'],
                            ['var' => '{{service_time}}', 'desc' => 'Horário do serviço', 'example' => '14:00'],
                            ['var' => '{{address}}', 'desc' => 'Endereço completo', 'example' => 'Rua Exemplo, 123'],
                            ['var' => '{{amount}}', 'desc' => 'Valor do serviço', 'example' => 'R$ 250,00'],
                            ['var' => '{{rating_url}}', 'desc' => 'URL para avaliação', 'example' => 'https://limpvix.com.br/rating/abc'],
                            ['var' => '{{google_review_url}}', 'desc' => 'URL Google Review', 'example' => 'https://g.page/limpvix/review'],
                            ['var' => '{{professional_phone}}', 'desc' => 'Telefone do profissional', 'example' => '(27) 99999-9999'],
                            ['var' => '{{order_id}}', 'desc' => 'ID do pedido', 'example' => '#12345'],
                            ['var' => '{{payment_status}}', 'desc' => 'Status do pagamento', 'example' => 'Aprovado'],
                        ];

                        foreach ($variables as $var):
                        ?>
                            <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 12px;">
                                <code style="background: #dbeafe; color: #1e40af; padding: 4px 8px; border-radius: 3px; font-size: 12px; font-weight: 600; display: inline-block; margin-bottom: 8px;">
                                    <?php echo esc_html($var['var']); ?>
                                </code>
                                <div style="color: #374151; font-size: 13px; margin-bottom: 4px;"><?php echo esc_html($var['desc']); ?></div>
                                <div style="color: #9ca3af; font-size: 11px;">Ex: <em><?php echo esc_html($var['example']); ?></em></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- MODAL DE EDIÇÃO -->
        <div id="edit-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 style="margin: 0; color: white;">✏️ Editar Template</h2>
                    <button onclick="closeModal('edit-modal')" class="button" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 20px; padding: 0 12px; cursor: pointer;">✕</button>
                </div>
                <div class="modal-body">
                    <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" id="edit-template-form">
                        <input type="hidden" name="action" value="limpvix_save_template">
                        <input type="hidden" name="template_id" id="edit_template_id">
                        <input type="hidden" name="template_type" id="edit_template_type">
                        <?php wp_nonce_field('limpvix_save_template'); ?>

                        <table class="form-table">
                            <tr id="name-row">
                                <th scope="row"><label for="edit_template_name">Nome</label></th>
                                <td>
                                    <input type="text" id="edit_template_name" name="template_name" class="regular-text" required>
                                </td>
                            </tr>
                            <tr id="description-row">
                                <th scope="row"><label for="edit_template_description">Descrição</label></th>
                                <td>
                                    <textarea id="edit_template_description" name="template_description" rows="2" class="large-text"></textarea>
                                </td>
                            </tr>
                            <tr id="channel-row">
                                <th scope="row"><label for="edit_template_channel">Canal</label></th>
                                <td>
                                    <select id="edit_template_channel" name="template_channel">
                                        <option value="whatsapp">💬 WhatsApp</option>
                                        <option value="sms">📱 SMS</option>
                                        <option value="email">📧 Email</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="type-row">
                                <th scope="row"><label for="edit_template_type">Tipo</label></th>
                                <td>
                                    <select id="edit_template_type" name="template_type">
                                        <option value="client">👤 Cliente</option>
                                        <option value="staff">👔 Profissional</option>
                                    </select>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row"><label for="edit_template_content">Conteúdo</label></th>
                                <td>
                                    <textarea id="edit_template_content" name="template_content" rows="12" class="large-text code" required style="font-family: 'Courier New', monospace; font-size: 13px;"></textarea>
                                    <p class="description">Use as variáveis listadas abaixo para personalizar a mensagem</p>
                                </td>
                            </tr>
                        </table>

                        <p class="submit" style="padding: 0 20px 20px;">
                            <button type="submit" class="button button-primary button-large">💾 Salvar Template</button>
                            <button type="button" class="button button-large" onclick="closeModal('edit-modal')">Cancelar</button>
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <!-- MODAL DE PREVIEW -->
        <div id="preview-modal" class="modal-overlay">
            <div class="modal-content">
                <div class="modal-header">
                    <h2 style="margin: 0; color: white;">👁️ Preview do Template</h2>
                    <button onclick="closeModal('preview-modal')" class="button" style="background: rgba(255,255,255,0.2); border: none; color: white; font-size: 20px; padding: 0 12px; cursor: pointer;">✕</button>
                </div>
                <div class="modal-body">
                    <div id="preview-info" style="background: #eff6ff; border-left: 4px solid #3b82f6; padding: 16px; border-radius: 6px; margin-bottom: 20px;">
                        <div style="color: #1e40af; font-size: 13px;" id="preview-details">Carregando...</div>
                    </div>
                    <div style="background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 8px; padding: 20px;">
                        <div style="color: #6b7280; font-size: 11px; text-transform: uppercase; font-weight: 600; margin-bottom: 12px; letter-spacing: 1px;">Mensagem que será enviada:</div>
                        <div id="preview-content" style="color: #111827; font-size: 14px; line-height: 1.7; white-space: pre-wrap; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif;">
                            Carregando preview...
                        </div>
                    </div>
                    <div style="margin-top: 20px; padding: 16px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 6px;">
                        <div style="color: #92400e; font-size: 12px;">
                            <strong>💡 Nota:</strong> As variáveis foram preenchidas com dados de exemplo. Na mensagem real, serão substituídas pelos dados do pedido/serviço.
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <script>
        function editTemplate(templateId, type) {
            const modal = document.getElementById('edit-modal');
            const form = document.getElementById('edit-template-form');

            document.getElementById('edit_template_id').value = templateId;
            document.getElementById('edit_template_type').value = type;

            // Buscar dados do template via AJAX
            jQuery.post(ajaxurl, {
                action: 'limpvix_get_template_data',
                template_id: templateId,
                template_type: type,
                nonce: '<?php echo wp_create_nonce('limpvix_get_template_data'); ?>'
            }, function(response) {
                if (response.success) {
                    const data = response.data;

                    // Ocultar campos nome/desc/canal/tipo para templates canônicos
                    const isCanonical = type === 'canonical';
                    document.getElementById('name-row').style.display = isCanonical ? 'none' : 'table-row';
                    document.getElementById('description-row').style.display = isCanonical ? 'none' : 'table-row';
                    document.getElementById('channel-row').style.display = isCanonical ? 'none' : 'table-row';
                    document.getElementById('type-row').style.display = isCanonical ? 'none' : 'table-row';

                    if (!isCanonical) {
                        document.getElementById('edit_template_name').value = data.name || '';
                        document.getElementById('edit_template_description').value = data.description || '';
                        document.getElementById('edit_template_channel').value = data.channel || 'whatsapp';
                        document.getElementById('edit_template_type').value = data.type || 'client';
                    }

                    document.getElementById('edit_template_content').value = data.content || '';

                    modal.style.display = 'flex';
                }
            });
        }

        function createCustomTemplate() {
            const modal = document.getElementById('edit-modal');

            document.getElementById('edit_template_id').value = 'new';
            document.getElementById('edit_template_type').value = 'custom';

            // Mostrar todos os campos
            document.getElementById('name-row').style.display = 'table-row';
            document.getElementById('description-row').style.display = 'table-row';
            document.getElementById('channel-row').style.display = 'table-row';
            document.getElementById('type-row').style.display = 'table-row';

            // Limpar campos
            document.getElementById('edit_template_name').value = '';
            document.getElementById('edit_template_description').value = '';
            document.getElementById('edit_template_channel').value = 'whatsapp';
            document.getElementById('edit_template_type').value = 'client';
            document.getElementById('edit_template_content').value = '';

            modal.style.display = 'flex';
        }

        function previewTemplate(templateId, type) {
            const modal = document.getElementById('preview-modal');
            const content = document.getElementById('preview-content');
            const details = document.getElementById('preview-details');

            modal.style.display = 'flex';
            content.innerHTML = 'Carregando preview...';

            jQuery.post(ajaxurl, {
                action: 'limpvix_preview_template',
                template_id: templateId,
                template_type: type,
                nonce: '<?php echo wp_create_nonce('limpvix_preview_template'); ?>'
            }, function(response) {
                if (response.success) {
                    content.innerHTML = response.data.preview;
                    details.innerHTML = response.data.details || '';
                } else {
                    content.innerHTML = '❌ Erro: ' + (response.data.message || 'Falha ao carregar preview');
                }
            });
        }

        function resetTemplate(templateId) {
            if (!confirm('Tem certeza que deseja restaurar o template para o padrão do sistema? Suas customizações serão perdidas.')) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.action = '<?php echo admin_url('admin-post.php'); ?>';

            const fields = {
                action: 'limpvix_reset_template',
                template_id: templateId,
                _wpnonce: '<?php echo wp_create_nonce('limpvix_reset_template'); ?>'
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

        function closeModal(modalId) {
            document.getElementById(modalId).style.display = 'none';
        }

        // Close modal on ESC key or click outside
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') {
                closeModal('edit-modal');
                closeModal('preview-modal');
            }
        });

        document.querySelectorAll('.modal-overlay').forEach(modal => {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    closeModal(this.id);
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Buscar todos os templates
     */
    private function getAllTemplates(): array
    {
        $canonical = $this->getCanonicalTemplates();
        $custom = $this->getCustomTemplates();
        $overrides = get_option('limpvix_template_overrides', []);

        // Marcar templates canônicos que foram customizados
        foreach ($canonical as &$tpl) {
            if (isset($overrides[$tpl['id']])) {
                $tpl['is_override'] = true;
                $tpl['content'] = $overrides[$tpl['id']]['content'];
            }
        }

        return [
            'canonical' => $canonical,
            'custom' => $custom,
        ];
    }

    /**
     * Buscar templates canônicos (do sistema)
     */
    private function getCanonicalTemplates(): array
    {
        return [
            [
                'id' => 'C1.1',
                'name' => 'Solicitação de Feedback (D+1)',
                'description' => 'Primeira tentativa de solicitar feedback 24h após conclusão',
                'channel' => 'whatsapp',
                'type' => 'client',
                'trigger_event' => 'D+1 após conclusão do serviço',
            ],
            [
                'id' => 'C1.2',
                'name' => 'Lembrete de Feedback (D+3)',
                'description' => 'Segunda tentativa de solicitar feedback após 72h',
                'channel' => 'whatsapp',
                'type' => 'client',
                'trigger_event' => 'D+3 se cliente não avaliou',
            ],
            [
                'id' => 'C1.3',
                'name' => 'Último Lembrete Feedback (D+7)',
                'description' => 'Terceira e última tentativa de solicitar feedback',
                'channel' => 'sms',
                'type' => 'client',
                'trigger_event' => 'D+7 se cliente ainda não avaliou',
            ],
            [
                'id' => 'C2',
                'name' => 'Alerta Feedback Negativo',
                'description' => 'Notificação interna quando cliente dá ≤3 estrelas (não envia ao cliente)',
                'channel' => 'email',
                'type' => 'staff',
                'trigger_event' => 'Imediatamente após feedback ≤3⭐',
            ],
            [
                'id' => 'C3',
                'name' => 'Convite Google Review',
                'description' => 'Convite para avaliar no Google após feedback 5 estrelas',
                'channel' => 'whatsapp',
                'type' => 'client',
                'trigger_event' => 'Após feedback 5⭐',
            ],
            [
                'id' => 'P1',
                'name' => 'Confirmação de Check-in',
                'description' => 'Notificação ao profissional confirmando check-in bem-sucedido',
                'channel' => 'sms',
                'type' => 'staff',
                'trigger_event' => 'Após check-in do profissional',
            ],
            [
                'id' => 'P2',
                'name' => 'Pagamento Liberado',
                'description' => 'Notificação ao profissional de que o pagamento foi aprovado',
                'channel' => 'sms',
                'type' => 'staff',
                'trigger_event' => 'Após aprovação de pagamento',
            ],
            [
                'id' => 'P3',
                'name' => 'Pagamento em Hold',
                'description' => 'Notificação ao profissional sobre pagamento retido por feedback negativo',
                'channel' => 'sms',
                'type' => 'staff',
                'trigger_event' => 'Quando pagamento entra em hold (feedback <4⭐)',
            ],
            [
                'id' => 'CHECK_IN_NOTIFICATION',
                'name' => 'Notificação Check-in ao Cliente (GAP #3)',
                'description' => 'Cliente recebe notificação quando profissional faz check-in no local',
                'channel' => 'whatsapp',
                'type' => 'client',
                'trigger_event' => 'Ao realizar check-in (commit 28fb29a)',
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
            'sms' => '<span class="limpvix-template-badge" style="background: #3b82f6; color: #fff;">📱 SMS</span>',
            'whatsapp' => '<span class="limpvix-template-badge" style="background: #10b981; color: #fff;">💬 WhatsApp</span>',
            'email' => '<span class="limpvix-template-badge" style="background: #8b5cf6; color: #fff;">📧 Email</span>',
            'none' => '<span class="limpvix-template-badge" style="background: #6b7280; color: #fff;">🔒 Nenhum</span>',
        ];

        return $badges[$channel] ?? '<span style="color: #6b7280;">—</span>';
    }

    /**
     * Renderizar badge de tipo
     */
    private function renderTypeBadge(string $type): string
    {
        $badges = [
            'client' => '<span class="limpvix-template-badge" style="background: #ec4899; color: #fff;">👤 Cliente</span>',
            'staff' => '<span class="limpvix-template-badge" style="background: #f59e0b; color: #fff;">👔 Staff</span>',
        ];

        return $badges[$type] ?? '<span style="color: #6b7280;">—</span>';
    }

    /**
     * Handler: Salvar template
     */
    public static function handleSaveTemplate(): void
    {
        check_admin_referer('limpvix_save_template');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $templateId = sanitize_text_field($_POST['template_id']);
        $templateType = sanitize_text_field($_POST['template_type']);
        $content = wp_kses_post($_POST['template_content']);

        if ($templateType === 'canonical') {
            // Salvar override de template canônico
            $overrides = get_option('limpvix_template_overrides', []);
            $overrides[$templateId] = [
                'content' => $content,
                'updated_at' => current_time('mysql'),
            ];
            update_option('limpvix_template_overrides', $overrides);
        } else {
            // Salvar template customizado
            $custom_templates = get_option('limpvix_custom_templates', []);

            $isNew = ($templateId === 'new');
            if ($isNew) {
                $templateId = 'CUSTOM_' . strtoupper(substr(md5(uniqid()), 0, 8));
            }

            $custom_templates[$templateId] = [
                'name' => sanitize_text_field($_POST['template_name']),
                'description' => sanitize_textarea_field($_POST['template_description'] ?? ''),
                'channel' => sanitize_text_field($_POST['template_channel']),
                'type' => sanitize_text_field($_POST['template_type']),
                'content' => $content,
                'created_at' => $isNew ? current_time('mysql') : ($custom_templates[$templateId]['created_at'] ?? current_time('mysql')),
                'updated_at' => current_time('mysql'),
            ];

            update_option('limpvix_custom_templates', $custom_templates);
        }

        wp_redirect(add_query_arg([
            'page' => 'limpvix-settings',
            'tab' => 'templates',
            'message' => 'saved'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handler: Restaurar template para o padrão
     */
    public static function handleResetTemplate(): void
    {
        check_admin_referer('limpvix_reset_template');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $templateId = sanitize_text_field($_POST['template_id']);
        $overrides = get_option('limpvix_template_overrides', []);

        if (isset($overrides[$templateId])) {
            unset($overrides[$templateId]);
            update_option('limpvix_template_overrides', $overrides);
        }

        wp_redirect(add_query_arg([
            'page' => 'limpvix-settings',
            'tab' => 'templates',
            'message' => 'reset'
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
            'page' => 'limpvix-settings',
            'tab' => 'templates',
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
            $content = '';
            $details = '';

            if ($templateType === 'canonical') {
                // Verificar se tem override
                $overrides = get_option('limpvix_template_overrides', []);
                if (isset($overrides[$templateId])) {
                    $content = $overrides[$templateId]['content'];
                    $details = '<strong>📝 Template customizado</strong> (usando sua versão editada)';
                } else {
                    // Buscar template padrão do domínio
                    if (class_exists('LimpVix\\Domain\\Communication\\MessageTemplates')) {
                        $content = MessageTemplates::getTemplate($templateId);
                        $details = '<strong>📋 Template padrão do sistema</strong>';
                    } else {
                        $content = '[Template padrão - classe MessageTemplates não encontrada]';
                        $details = '<strong>⚠️ Aviso:</strong> Classe MessageTemplates não foi encontrada';
                    }
                }
            } else {
                // Template customizado
                $custom_templates = get_option('limpvix_custom_templates', []);
                $content = $custom_templates[$templateId]['content'] ?? null;
                $details = '<strong>✨ Template customizado</strong> (criado por você)';
            }

            if (!$content) {
                wp_send_json_error(['message' => 'Template não encontrado']);
            }

            // Renderizar com dados de exemplo
            $preview = self::renderPreviewWithMockData($content);

            wp_send_json_success([
                'preview' => $preview,
                'details' => $details,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }

    /**
     * Handler AJAX: Buscar dados do template
     */
    public static function handleGetTemplateData(): void
    {
        check_ajax_referer('limpvix_get_template_data', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Sem permissão']);
        }

        $templateId = sanitize_text_field($_POST['template_id']);
        $templateType = sanitize_text_field($_POST['template_type']);

        try {
            if ($templateType === 'canonical') {
                // Buscar override ou padrão
                $overrides = get_option('limpvix_template_overrides', []);
                if (isset($overrides[$templateId])) {
                    $content = $overrides[$templateId]['content'];
                } else {
                    // Buscar do domínio
                    if (class_exists('LimpVix\\Domain\\Communication\\MessageTemplates')) {
                        $content = MessageTemplates::getTemplate($templateId);
                    } else {
                        $content = '';
                    }
                }

                wp_send_json_success(['content' => $content]);
            } else {
                // Template customizado
                $custom_templates = get_option('limpvix_custom_templates', []);
                $template = $custom_templates[$templateId] ?? null;

                if (!$template) {
                    wp_send_json_error(['message' => 'Template não encontrado']);
                }

                wp_send_json_success($template);
            }
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
            '{{service_date}}' => '16/02/2026',
            '{{service_time}}' => '14:00',
            '{{address}}' => 'Rua das Flores, 123 - Praia do Canto, Vitória/ES',
            '{{rating_url}}' => 'https://limpvix.com.br/rating/abc123',
            '{{google_review_url}}' => 'https://g.page/limpvix/review',
            '{{amount}}' => 'R$ 250,00',
            '{{professional_phone}}' => '(27) 99999-9999',
            '{{order_id}}' => '#12345',
            '{{payment_status}}' => 'Aprovado',
        ];

        return str_replace(array_keys($mockData), array_values($mockData), $template);
    }
}
