<?php
/**
 * MessageTemplatesPage - Página de Gerenciamento de Templates de Mensagens
 *
 * Permite ao admin:
 * - Ver templates canônicos
 * - Editar templates customizados
 * - Criar novos fluxos
 * - Ativar/desativar notificações
 * - Testar envios
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 */

namespace LimpVix\Infrastructure\Admin\Pages;

use LimpVix\Domain\Communication\MessageTemplates;
use LimpVix\Infrastructure\Admin\UI\UIComponents;

defined('ABSPATH') || exit;

final class MessageTemplatesPage
{
    /**
     * Registrar página no WordPress
     */
    public static function register(): void
    {
        // Prioridade 25 para registrar DEPOIS de CommunicationSettingsPage (20)
        add_action('admin_menu', [__CLASS__, 'addMenu'], 25);
        add_action('admin_init', [__CLASS__, 'registerSettings']);
        add_action('admin_post_limpvix_save_template', [__CLASS__, 'handleSaveTemplate']);
        add_action('admin_post_limpvix_test_template', [__CLASS__, 'handleTestTemplate']);
        add_action('admin_post_limpvix_save_flows', [__CLASS__, 'handleSaveFlows']);
        add_action('wp_ajax_limpvix_delete_template', [__CLASS__, 'handleDeleteTemplate']);
        add_action('admin_enqueue_scripts', [__CLASS__, 'enqueueScripts']);
    }

    /**
     * Enqueue scripts e styles
     */
    public static function enqueueScripts(string $hook): void
    {
        if ($hook !== 'limpvix-finance_page_limpvix-templates') {
            return;
        }

        wp_enqueue_script(
            'limpvix-message-templates',
            plugins_url('assets/js/message-templates.js', LIMPVIX_PLUGIN_FILE),
            ['jquery'],
            '1.0.0',
            true
        );

        wp_localize_script('limpvix-message-templates', 'limpvixAdmin', [
            'nonce' => wp_create_nonce('limpvix_ajax'),
            'ajaxurl' => admin_url('admin-ajax.php'),
        ]);

        // Adicionar estilos inline para modal
        wp_add_inline_style('limpvix-admin-ui', self::getModalStyles());
    }

    /**
     * Estilos para modal
     */
    private static function getModalStyles(): string
    {
        return '
            .limpvix-modal-overlay {
                position: fixed;
                top: 0;
                left: 0;
                right: 0;
                bottom: 0;
                background: rgba(0,0,0,0.5);
                z-index: 999999;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .limpvix-modal {
                background: white;
                max-width: 600px;
                width: 90%;
                border-radius: 8px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            }
            .limpvix-modal-header {
                padding: 20px;
                border-bottom: 1px solid #ddd;
                display: flex;
                justify-content: space-between;
                align-items: center;
            }
            .limpvix-modal-close {
                background: none;
                border: none;
                font-size: 28px;
                cursor: pointer;
                color: #999;
            }
            .limpvix-modal-close:hover {
                color: #333;
            }
            .limpvix-modal-body {
                padding: 20px;
            }
            .preview-phone {
                background: #f5f5f5;
                border: 2px solid #ddd;
                border-radius: 20px;
                padding: 30px 20px;
                max-width: 400px;
                margin: 0 auto;
            }
            .preview-message {
                background: white;
                padding: 15px;
                border-radius: 10px;
                font-size: 14px;
                line-height: 1.6;
                box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            }
        ';
    }

    /**
     * Adicionar submenu
     */
    public static function addMenu(): void
    {
        // Submenu de Comunicação (não de limpvix-finance)
        add_submenu_page(
            'limpvix-communication',  // Parent é Comunicação, não Finance
            'Templates de Mensagens',
            'Templates',
            'manage_options',
            'limpvix-templates',
            [__CLASS__, 'render']
        );
    }

    /**
     * Registrar configurações
     */
    public static function registerSettings(): void
    {
        // Templates customizados
        register_setting('limpvix_templates', 'limpvix_custom_templates', [
            'type' => 'array',
            'default' => [],
        ]);

        // Fluxos ativos/inativos
        register_setting('limpvix_templates', 'limpvix_active_flows', [
            'type' => 'array',
            'default' => [
                'C1' => true,  // Feedback
                'C2' => true,  // Feedback negativo (manual)
                'C3' => true,  // Google Review
                'P1' => true,  // Staff completed
                'P2' => true,  // Staff authorized
                'P3' => true,  // Staff review
            ],
        ]);

        // Configurações de timing
        register_setting('limpvix_templates', 'limpvix_feedback_timing', [
            'type' => 'array',
            'default' => [
                'attempt_1_hours' => 24,  // D+1
                'attempt_2_hours' => 72,  // D+3
                'attempt_3_hours' => 168, // D+7
                'max_attempts' => 3,
            ],
        ]);
    }

    /**
     * Renderizar página
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        $activeTab = $_GET['tab'] ?? 'canonical';
        $customTemplates = get_option('limpvix_custom_templates', []);
        $activeFlows = get_option('limpvix_active_flows', []);
        $feedbackTiming = get_option('limpvix_feedback_timing', []);

        ?>
        <div class="wrap limpvix-admin">
            <?php UIComponents::header('Templates de Mensagens', [
                [
                    'label' => '+ Novo Template',
                    'url' => admin_url('admin.php?page=limpvix-templates&tab=new'),
                    'class' => 'primary'
                ],
                [
                    'label' => '📖 Documentação',
                    'url' => '#',
                    'class' => 'secondary',
                    'onclick' => 'alert("Ver documentação em /docs/TEMPLATES_CANONICOS_MENSAGENS.md")'
                ]
            ]); ?>

            <!-- Tabs -->
            <div class="limpvix-tabs">
                <a href="?page=limpvix-templates&tab=canonical"
                   class="limpvix-tab <?php echo $activeTab === 'canonical' ? 'active' : ''; ?>">
                    📋 Templates Canônicos
                </a>
                <a href="?page=limpvix-templates&tab=custom"
                   class="limpvix-tab <?php echo $activeTab === 'custom' ? 'active' : ''; ?>">
                    ✏️ Templates Customizados (<?php echo count($customTemplates); ?>)
                </a>
                <a href="?page=limpvix-templates&tab=flows"
                   class="limpvix-tab <?php echo $activeTab === 'flows' ? 'active' : ''; ?>">
                    🔄 Gerenciar Fluxos
                </a>
                <a href="?page=limpvix-templates&tab=test"
                   class="limpvix-tab <?php echo $activeTab === 'test' ? 'active' : ''; ?>">
                    🧪 Testar Envios
                </a>
            </div>

            <div class="limpvix-tab-content">
                <?php
                switch ($activeTab) {
                    case 'canonical':
                        self::renderCanonicalTab();
                        break;
                    case 'custom':
                        self::renderCustomTab($customTemplates);
                        break;
                    case 'flows':
                        self::renderFlowsTab($activeFlows, $feedbackTiming);
                        break;
                    case 'test':
                        self::renderTestTab();
                        break;
                    case 'new':
                        self::renderNewTemplateTab();
                        break;
                    case 'edit':
                        $templateId = $_GET['template'] ?? '';
                        self::renderEditTemplateTab($templateId, $customTemplates);
                        break;
                }
                ?>
            </div>
        </div>
        <?php
    }

    /**
     * Tab: Templates Canônicos
     */
    private static function renderCanonicalTab(): void
    {
        $templates = self::getCanonicalTemplatesInfo();

        UIComponents::portletStart('Templates Canônicos do Sistema',
            'Templates validados para produção (LGPD + CDC compliant)');

        ?>
        <div class="limpvix-alert info" style="margin-bottom: 20px;">
            <strong>ℹ️ Templates Canônicos</strong><br>
            Estes templates são parte do core do sistema e não podem ser editados diretamente.
            Para customizar, crie um template customizado que sobrescreve o canônico.
        </div>

        <table class="limpvix-table">
            <thead>
                <tr>
                    <th>Fluxo</th>
                    <th>Nome</th>
                    <th>Tipo</th>
                    <th>Canal</th>
                    <th>Variáveis</th>
                    <th>Status</th>
                    <th>Ações</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($templates as $template): ?>
                <tr>
                    <td>
                        <?php UIComponents::badge($template['flow_id'],
                            $template['recipient_type'] === 'customer' ? 'info' : 'secondary'); ?>
                    </td>
                    <td>
                        <strong><?php echo esc_html($template['name']); ?></strong><br>
                        <small><?php echo esc_html($template['description']); ?></small>
                    </td>
                    <td><?php echo esc_html($template['template_type']); ?></td>
                    <td><?php echo esc_html($template['channel']); ?></td>
                    <td>
                        <code style="font-size: 11px;">
                            <?php echo esc_html(implode(', ', $template['variables'])); ?>
                        </code>
                    </td>
                    <td>
                        <?php if ($template['requires_approval']): ?>
                            <?php UIComponents::badge('Requer Aprovação', 'warning'); ?>
                        <?php else: ?>
                            <?php UIComponents::badge('Pronto', 'success'); ?>
                        <?php endif; ?>
                    </td>
                    <td>
                        <button type="button" class="button button-small"
                                onclick="viewTemplatePreview('<?php echo esc_js($template['id']); ?>')">
                            👁️ Visualizar
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <?php
        UIComponents::portletEnd();
    }

    /**
     * Tab: Templates Customizados
     */
    private static function renderCustomTab(array $customTemplates): void
    {
        UIComponents::portletStart('Meus Templates Customizados',
            'Templates criados por você que sobrescrevem os canônicos');

        if (empty($customTemplates)) {
            ?>
            <div class="limpvix-alert info">
                <strong>Nenhum template customizado ainda</strong><br>
                Clique em "+ Novo Template" para criar seu primeiro template customizado.
            </div>
            <?php
        } else {
            ?>
            <table class="limpvix-table">
                <thead>
                    <tr>
                        <th>Nome</th>
                        <th>Substitui</th>
                        <th>Canal</th>
                        <th>Status</th>
                        <th>Criado</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customTemplates as $id => $template): ?>
                    <tr>
                        <td>
                            <strong><?php echo esc_html($template['name']); ?></strong>
                        </td>
                        <td><?php echo esc_html($template['overrides'] ?? 'N/A'); ?></td>
                        <td><?php echo esc_html($template['channel']); ?></td>
                        <td>
                            <?php if ($template['active']): ?>
                                <?php UIComponents::badge('Ativo', 'success'); ?>
                            <?php else: ?>
                                <?php UIComponents::badge('Inativo', 'default'); ?>
                            <?php endif; ?>
                        </td>
                        <td><?php echo esc_html($template['created_at']); ?></td>
                        <td>
                            <a href="?page=limpvix-templates&tab=edit&template=<?php echo esc_attr($id); ?>"
                               class="button button-small">✏️ Editar</a>
                            <button type="button" class="button button-small"
                                    onclick="deleteTemplate('<?php echo esc_js($id); ?>')">
                                🗑️ Deletar
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php
        }

        UIComponents::portletEnd();
    }

    /**
     * Tab: Gerenciar Fluxos
     */
    private static function renderFlowsTab(array $activeFlows, array $feedbackTiming): void
    {
        UIComponents::portletStart('Configuração de Fluxos',
            'Ativar/desativar fluxos de mensagens e configurar timings');

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('limpvix_save_flows'); ?>
            <input type="hidden" name="action" value="limpvix_save_flows">

            <h3>🧑‍💼 Fluxos para Clientes</h3>
            <table class="form-table">
                <tr>
                    <th>C1 - Solicitação de Feedback</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active_flows[C1]" value="1"
                                   <?php checked($activeFlows['C1'] ?? true); ?>>
                            Ativo
                        </label>
                        <p class="description">
                            Enviar lembretes de feedback após conclusão do serviço (D+1, D+3, D+7)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>C2 - Feedback Negativo (≤3⭐)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active_flows[C2]" value="1"
                                   <?php checked($activeFlows['C2'] ?? true); ?> disabled>
                            Sempre Ativo (Bloqueio de Segurança)
                        </label>
                        <p class="description">
                            ⚠️ Este fluxo BLOQUEIA mensagens automáticas e requer contato manual.
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>C3 - Convite Google Review (5⭐)</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active_flows[C3]" value="1"
                                   <?php checked($activeFlows['C3'] ?? true); ?>>
                            Ativo
                        </label>
                        <p class="description">
                            Enviar convite para avaliar no Google após feedback 5 estrelas
                        </p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top: 30px;">👷 Fluxos para Profissionais</h3>
            <table class="form-table">
                <tr>
                    <th>P1 - Serviço Concluído</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active_flows[P1]" value="1"
                                   <?php checked($activeFlows['P1'] ?? true); ?>>
                            Ativo
                        </label>
                        <p class="description">
                            Notificar profissional quando serviço é concluído
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>P2 - Pagamento Autorizado</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active_flows[P2]" value="1"
                                   <?php checked($activeFlows['P2'] ?? true); ?>>
                            Ativo
                        </label>
                        <p class="description">
                            Notificar profissional quando pagamento é autorizado
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>P3 - Pagamento em Análise</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active_flows[P3]" value="1"
                                   <?php checked($activeFlows['P3'] ?? true); ?>>
                            Ativo
                        </label>
                        <p class="description">
                            Notificar profissional quando pagamento está em análise manual
                        </p>
                    </td>
                </tr>
            </table>

            <h3 style="margin-top: 30px;">⏱️ Configuração de Timing (Feedback)</h3>
            <table class="form-table">
                <tr>
                    <th>Tentativa 1 (D+X)</th>
                    <td>
                        <input type="number" name="feedback_timing[attempt_1_hours]"
                               value="<?php echo esc_attr($feedbackTiming['attempt_1_hours'] ?? 24); ?>"
                               min="1" max="168" style="width: 80px;">
                        horas após conclusão do serviço
                    </td>
                </tr>
                <tr>
                    <th>Tentativa 2 (D+X)</th>
                    <td>
                        <input type="number" name="feedback_timing[attempt_2_hours]"
                               value="<?php echo esc_attr($feedbackTiming['attempt_2_hours'] ?? 72); ?>"
                               min="1" max="168" style="width: 80px;">
                        horas após conclusão do serviço
                    </td>
                </tr>
                <tr>
                    <th>Tentativa 3 (D+X)</th>
                    <td>
                        <input type="number" name="feedback_timing[attempt_3_hours]"
                               value="<?php echo esc_attr($feedbackTiming['attempt_3_hours'] ?? 168); ?>"
                               min="1" max="336" style="width: 80px;">
                        horas após conclusão do serviço
                    </td>
                </tr>
                <tr>
                    <th>Máximo de Tentativas</th>
                    <td>
                        <select name="feedback_timing[max_attempts]">
                            <option value="1" <?php selected($feedbackTiming['max_attempts'] ?? 3, 1); ?>>1 tentativa</option>
                            <option value="2" <?php selected($feedbackTiming['max_attempts'] ?? 3, 2); ?>>2 tentativas</option>
                            <option value="3" <?php selected($feedbackTiming['max_attempts'] ?? 3, 3); ?>>3 tentativas</option>
                        </select>
                    </td>
                </tr>
            </table>

            <?php submit_button('💾 Salvar Configurações'); ?>
        </form>
        <?php

        UIComponents::portletEnd();
    }

    /**
     * Tab: Testar Envios
     */
    private static function renderTestTab(): void
    {
        UIComponents::portletStart('Testar Envio de Templates',
            'Enviar mensagens de teste para validar templates');

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('limpvix_test_template'); ?>
            <input type="hidden" name="action" value="limpvix_test_template">

            <table class="form-table">
                <tr>
                    <th>Template</th>
                    <td>
                        <select name="template_type" id="test_template_type" required>
                            <option value="">Selecione um template</option>
                            <option value="client_feedback_d1">C1.1 - Feedback D+1</option>
                            <option value="client_feedback_d3">C1.2 - Feedback D+3</option>
                            <option value="client_feedback_d6">C1.3 - Feedback D+6</option>
                            <option value="google_review">C3 - Google Review</option>
                            <option value="staff_completed">P1 - Serviço Concluído</option>
                            <option value="staff_authorized">P2 - Pagamento Autorizado</option>
                            <option value="staff_review">P3 - Pagamento em Análise</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Telefone de Teste</th>
                    <td>
                        <input type="text" name="test_phone" placeholder="+5527999999999"
                               pattern="\+55[0-9]{10,11}" required style="width: 300px;">
                        <p class="description">
                            Formato: +55 + DDD + número (ex: +5527999999999)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Canal</th>
                    <td>
                        <label>
                            <input type="radio" name="test_channel" value="sms" checked> SMS (Twilio)
                        </label>
                        <label style="margin-left: 20px;">
                            <input type="radio" name="test_channel" value="whatsapp"> WhatsApp (360Dialog)
                        </label>
                    </td>
                </tr>
            </table>

            <div class="limpvix-alert warning">
                <strong>⚠️ Atenção:</strong> O envio de teste consome créditos reais do provedor (Twilio/360Dialog).
            </div>

            <?php submit_button('📤 Enviar Teste'); ?>
        </form>

        <!-- Preview do Template -->
        <div id="template-preview" style="display:none; margin-top: 30px; padding: 20px; background: #f9f9f9; border: 1px solid #ddd;">
            <h3>Preview da Mensagem</h3>
            <div id="preview-content" style="white-space: pre-wrap; font-family: monospace; font-size: 13px;"></div>
        </div>

        <script>
        document.getElementById('test_template_type').addEventListener('change', function() {
            const previews = {
                'client_feedback_d1': `Olá João! 👋

O serviço realizado ontem atendeu suas expectativas?

Sua avaliação ajuda a Limpvix a manter a qualidade ⭐
Leva menos de 1 minuto:

👉 Avaliar agora: https://limpvix.com.br/feedback?order=xxx

Equipe Limpvix`,
                'staff_authorized': `Boa notícia, Maria! ✅

O pagamento do serviço Limpeza Residencial foi AUTORIZADO.

💰 Valor: R$ 150,00
📆 Repasse previsto conforme cronograma.

Equipe Limpvix`
            };

            const preview = previews[this.value];
            if (preview) {
                document.getElementById('template-preview').style.display = 'block';
                document.getElementById('preview-content').textContent = preview;
            } else {
                document.getElementById('template-preview').style.display = 'none';
            }
        });
        </script>
        <?php

        UIComponents::portletEnd();
    }

    /**
     * Tab: Novo Template
     */
    private static function renderNewTemplateTab(): void
    {
        UIComponents::portletStart('Criar Novo Template Customizado',
            'Templates customizados sobrescrevem os canônicos quando ativos');

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('limpvix_save_template'); ?>
            <input type="hidden" name="action" value="limpvix_save_template">

            <table class="form-table">
                <tr>
                    <th>Nome do Template *</th>
                    <td>
                        <input type="text" name="template_name" required
                               style="width: 100%; max-width: 500px;"
                               placeholder="Ex: Feedback Personalizado para VIPs">
                    </td>
                </tr>
                <tr>
                    <th>Substitui Template Canônico</th>
                    <td>
                        <select name="overrides_template">
                            <option value="">Nenhum (novo fluxo)</option>
                            <option value="client_feedback_d1">C1.1 - Feedback D+1</option>
                            <option value="client_feedback_d3">C1.2 - Feedback D+3</option>
                            <option value="client_feedback_d6">C1.3 - Feedback D+6</option>
                            <option value="google_review">C3 - Google Review</option>
                            <option value="staff_completed">P1 - Serviço Concluído</option>
                            <option value="staff_authorized">P2 - Pagamento Autorizado</option>
                            <option value="staff_review">P3 - Pagamento em Análise</option>
                        </select>
                        <p class="description">
                            Se selecionado, este template será usado no lugar do canônico
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Canal de Envio *</th>
                    <td>
                        <select name="channel" required>
                            <option value="sms">SMS (Twilio)</option>
                            <option value="whatsapp">WhatsApp (360Dialog)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th>Conteúdo da Mensagem *</th>
                    <td>
                        <textarea name="template_content" required
                                  style="width: 100%; height: 200px; font-family: monospace;"
                                  placeholder="Olá {{customer_name}}! &#10;&#10;Sua mensagem aqui..."></textarea>
                        <p class="description">
                            <strong>Variáveis disponíveis:</strong><br>
                            <code>{{customer_name}}</code> - Nome do cliente<br>
                            <code>{{staff_name}}</code> - Nome do profissional<br>
                            <code>{{service_name}}</code> - Nome do serviço<br>
                            <code>{{feedback_url}}</code> - URL de feedback<br>
                            <code>{{google_review_url}}</code> - URL Google Review<br>
                            <code>{{amount}}</code> - Valor do serviço
                        </p>
                    </td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active" value="1" checked>
                            Ativar este template imediatamente
                        </label>
                    </td>
                </tr>
            </table>

            <div class="limpvix-alert info">
                <strong>💡 Dica:</strong> Para templates WhatsApp, você precisa criar e aprovar o template
                no 360Dialog antes de usar. Templates SMS não precisam de aprovação prévia.
            </div>

            <?php submit_button('💾 Salvar Template'); ?>
        </form>
        <?php

        UIComponents::portletEnd();
    }

    /**
     * Tab: Editar Template
     */
    private static function renderEditTemplateTab(string $templateId, array $customTemplates): void
    {
        $template = $customTemplates[$templateId] ?? null;

        if (!$template) {
            echo '<div class="notice notice-error"><p>Template não encontrado.</p></div>';
            return;
        }

        UIComponents::portletStart('Editar Template: ' . esc_html($template['name']));

        ?>
        <form method="post" action="<?php echo admin_url('admin-post.php'); ?>">
            <?php wp_nonce_field('limpvix_save_template'); ?>
            <input type="hidden" name="action" value="limpvix_save_template">
            <input type="hidden" name="template_id" value="<?php echo esc_attr($templateId); ?>">

            <table class="form-table">
                <tr>
                    <th>Nome do Template</th>
                    <td>
                        <input type="text" name="template_name" required
                               value="<?php echo esc_attr($template['name']); ?>"
                               style="width: 100%; max-width: 500px;">
                    </td>
                </tr>
                <tr>
                    <th>Conteúdo da Mensagem</th>
                    <td>
                        <textarea name="template_content" required
                                  style="width: 100%; height: 200px; font-family: monospace;"><?php
                            echo esc_textarea($template['content']);
                        ?></textarea>
                    </td>
                </tr>
                <tr>
                    <th>Status</th>
                    <td>
                        <label>
                            <input type="checkbox" name="active" value="1"
                                   <?php checked($template['active']); ?>>
                            Template ativo
                        </label>
                    </td>
                </tr>
            </table>

            <?php submit_button('💾 Atualizar Template'); ?>
        </form>
        <?php

        UIComponents::portletEnd();
    }

    /**
     * Handler: Salvar template
     */
    public static function handleSaveTemplate(): void
    {
        check_admin_referer('limpvix_save_template');

        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        $customTemplates = get_option('limpvix_custom_templates', []);
        $templateId = $_POST['template_id'] ?? uniqid('tpl_');

        $customTemplates[$templateId] = [
            'name' => sanitize_text_field($_POST['template_name']),
            'overrides' => sanitize_text_field($_POST['overrides_template'] ?? ''),
            'channel' => sanitize_text_field($_POST['channel'] ?? 'sms'),
            'content' => wp_kses_post($_POST['template_content']),
            'active' => isset($_POST['active']),
            'created_at' => $customTemplates[$templateId]['created_at'] ?? current_time('mysql'),
            'updated_at' => current_time('mysql'),
        ];

        update_option('limpvix_custom_templates', $customTemplates);

        wp_redirect(add_query_arg([
            'page' => 'limpvix-templates',
            'tab' => 'custom',
            'message' => 'saved'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handler: Testar template
     */
    public static function handleTestTemplate(): void
    {
        check_admin_referer('limpvix_test_template');

        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        $templateType = sanitize_text_field($_POST['template_type']);
        $testPhone = sanitize_text_field($_POST['test_phone']);
        $channel = sanitize_text_field($_POST['test_channel']);

        // Gerar mensagem de teste
        $message = MessageTemplates::get($templateType, 1, [
            'customer_name' => 'João Teste',
            'staff_name' => 'Maria Teste',
            'service_name' => 'Limpeza Residencial Teste',
            'feedback_url' => 'https://limpvix.com.br/feedback?test=1',
            'google_review_url' => 'https://g.page/r/test',
            'amount' => 150.00,
        ]);

        $sent = false;

        if ($channel === 'whatsapp') {
            // Enviar via WhatsApp (implementar)
            $sent = self::sendTestWhatsApp($testPhone, $message);
        } else {
            // Enviar via SMS
            $sent = self::sendTestSMS($testPhone, $message);
        }

        wp_redirect(add_query_arg([
            'page' => 'limpvix-templates',
            'tab' => 'test',
            'message' => $sent ? 'sent' : 'error'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Enviar SMS de teste
     */
    private static function sendTestSMS(string $phone, string $message): bool
    {
        if (!class_exists('LimpVix\\Infrastructure\\Communication\\Providers\\TwilioSmsProvider')) {
            return false;
        }

        $repository = new \LimpVix\Infrastructure\Communication\Repositories\MessageRepository();
        $provider = new \LimpVix\Infrastructure\Communication\Providers\TwilioSmsProvider($repository);

        if (!$provider->isConfigured()) {
            return false;
        }

        $result = $provider->send($phone, $message, 'TEST-' . time(), 0, 0);

        return $result['success'] ?? false;
    }

    /**
     * Enviar WhatsApp de teste
     */
    private static function sendTestWhatsApp(string $phone, string $message): bool
    {
        // Implementar quando provider estiver pronto
        return false;
    }

    /**
     * Handler: Salvar configurações de fluxos
     */
    public static function handleSaveFlows(): void
    {
        check_admin_referer('limpvix_save_flows');

        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        $activeFlows = $_POST['active_flows'] ?? [];
        $feedbackTiming = $_POST['feedback_timing'] ?? [];

        // C2 sempre ativo (não pode ser desativado)
        $activeFlows['C2'] = true;

        update_option('limpvix_active_flows', $activeFlows);
        update_option('limpvix_feedback_timing', $feedbackTiming);

        wp_redirect(add_query_arg([
            'page' => 'limpvix-templates',
            'tab' => 'flows',
            'message' => 'saved'
        ], admin_url('admin.php')));
        exit;
    }

    /**
     * Handler AJAX: Deletar template customizado
     */
    public static function handleDeleteTemplate(): void
    {
        check_ajax_referer('limpvix_ajax', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado']);
        }

        $templateId = sanitize_text_field($_POST['template_id'] ?? '');

        if (empty($templateId)) {
            wp_send_json_error(['message' => 'ID inválido']);
        }

        $customTemplates = get_option('limpvix_custom_templates', []);

        if (!isset($customTemplates[$templateId])) {
            wp_send_json_error(['message' => 'Template não encontrado']);
        }

        unset($customTemplates[$templateId]);
        update_option('limpvix_custom_templates', $customTemplates);

        wp_send_json_success(['message' => 'Template deletado']);
    }

    /**
     * Obter informações dos templates canônicos
     */
    private static function getCanonicalTemplatesInfo(): array
    {
        return [
            [
                'id' => 'client_feedback_d1',
                'flow_id' => 'C1.1',
                'name' => 'Feedback D+1',
                'description' => 'Primeiro lembrete de feedback após conclusão do serviço',
                'template_type' => 'Feedback',
                'recipient_type' => 'customer',
                'channel' => 'WhatsApp prioritário',
                'variables' => ['customer_name', 'feedback_url'],
                'requires_approval' => true,
            ],
            [
                'id' => 'client_feedback_d3',
                'flow_id' => 'C1.2',
                'name' => 'Feedback D+3',
                'description' => 'Segundo lembrete de feedback',
                'template_type' => 'Feedback',
                'recipient_type' => 'customer',
                'channel' => 'WhatsApp ou SMS',
                'variables' => ['customer_name', 'feedback_url'],
                'requires_approval' => true,
            ],
            [
                'id' => 'client_feedback_d6',
                'flow_id' => 'C1.3',
                'name' => 'Feedback D+6/D+7',
                'description' => 'Último lembrete de feedback',
                'template_type' => 'Feedback',
                'recipient_type' => 'customer',
                'channel' => 'SMS',
                'variables' => ['customer_name', 'feedback_url'],
                'requires_approval' => false,
            ],
            [
                'id' => 'client_feedback_negative',
                'flow_id' => 'C2',
                'name' => 'Feedback Negativo (≤3⭐)',
                'description' => 'BLOQUEIO - Nenhuma mensagem automática',
                'template_type' => 'Bloqueio',
                'recipient_type' => 'customer',
                'channel' => 'NENHUM',
                'variables' => [],
                'requires_approval' => false,
            ],
            [
                'id' => 'google_review',
                'flow_id' => 'C3',
                'name' => 'Convite Google Review',
                'description' => 'Convite para avaliar no Google após 5 estrelas',
                'template_type' => 'Reputação',
                'recipient_type' => 'customer',
                'channel' => 'WhatsApp ou SMS',
                'variables' => ['customer_name', 'google_review_url'],
                'requires_approval' => true,
            ],
            [
                'id' => 'staff_completed',
                'flow_id' => 'P1',
                'name' => 'Serviço Concluído',
                'description' => 'Notificar profissional sobre conclusão do serviço',
                'template_type' => 'Notificação',
                'recipient_type' => 'staff',
                'channel' => 'SMS',
                'variables' => ['staff_name', 'service_name'],
                'requires_approval' => false,
            ],
            [
                'id' => 'staff_authorized',
                'flow_id' => 'P2',
                'name' => 'Pagamento Autorizado',
                'description' => 'Notificar profissional sobre autorização de pagamento',
                'template_type' => 'Financeiro',
                'recipient_type' => 'staff',
                'channel' => 'SMS',
                'variables' => ['staff_name', 'service_name', 'amount'],
                'requires_approval' => false,
            ],
            [
                'id' => 'staff_review',
                'flow_id' => 'P3',
                'name' => 'Pagamento em Análise',
                'description' => 'Notificar profissional sobre análise manual de pagamento',
                'template_type' => 'Financeiro',
                'recipient_type' => 'staff',
                'channel' => 'SMS',
                'variables' => ['staff_name', 'service_name'],
                'requires_approval' => false,
            ],
        ];
    }
}
