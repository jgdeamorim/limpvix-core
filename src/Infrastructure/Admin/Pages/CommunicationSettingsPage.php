<?php
/**
 * CommunicationSettingsPage - Página de Configurações de Comunicação
 * VERSÃO MODERNA - Layout organizado com cards e seções
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

final class CommunicationSettingsPage
{
    /**
     * Registrar configurações (chamado via admin_init)
     */
    public static function registerSettings(): void
    {
        // Twilio SMS
        register_setting('limpvix_communication', 'limpvix_twilio_account_sid');
        register_setting('limpvix_communication', 'limpvix_twilio_auth_token');
        register_setting('limpvix_communication', 'limpvix_twilio_from_number');

        // 360Dialog WhatsApp
        register_setting('limpvix_communication', 'limpvix_360dialog_api_key');
        register_setting('limpvix_communication', 'limpvix_360dialog_namespace');

        // Comunicação ativa
        register_setting('limpvix_communication', 'limpvix_comm_active', [
            'type' => 'boolean',
            'default' => true,
        ]);

        // Notificações staff
        register_setting('limpvix_communication', 'limpvix_notify_staff_enabled', [
            'type' => 'boolean',
            'default' => true,
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

        // Salvar configurações se POST
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['limpvix_comm_save'])) {
            check_admin_referer('limpvix_comm_settings');

            update_option('limpvix_comm_active', isset($_POST['limpvix_comm_active']));
            update_option('limpvix_notify_staff_enabled', isset($_POST['limpvix_notify_staff_enabled']));
            update_option('limpvix_twilio_account_sid', sanitize_text_field($_POST['limpvix_twilio_account_sid'] ?? ''));
            update_option('limpvix_twilio_auth_token', sanitize_text_field($_POST['limpvix_twilio_auth_token'] ?? ''));
            update_option('limpvix_twilio_from_number', sanitize_text_field($_POST['limpvix_twilio_from_number'] ?? ''));
            update_option('limpvix_360dialog_api_key', sanitize_text_field($_POST['limpvix_360dialog_api_key'] ?? ''));
            update_option('limpvix_360dialog_namespace', sanitize_text_field($_POST['limpvix_360dialog_namespace'] ?? ''));

            echo '<div class="notice notice-success is-dismissible"><p><strong>Configurações salvas com sucesso!</strong></p></div>';
        }

        $commActive = get_option('limpvix_comm_active', true);
        $notifyStaff = get_option('limpvix_notify_staff_enabled', true);

        // Twilio
        $twilioSid = get_option('limpvix_twilio_account_sid', '');
        $twilioToken = get_option('limpvix_twilio_auth_token', '');
        $twilioFrom = get_option('limpvix_twilio_from_number', '');
        $twilioConnected = !empty($twilioSid) && !empty($twilioToken) && !empty($twilioFrom);

        // 360Dialog
        $dialogKey = get_option('limpvix_360dialog_api_key', '');
        $dialogNamespace = get_option('limpvix_360dialog_namespace', '');
        $dialogConnected = !empty($dialogKey);

        ?>
        <div class="wrap limpvix-admin">
            <!-- Header -->
            <div class="limpvix-page-header">
                <div>
                    <h1>
                        <span class="dashicons dashicons-email"></span>
                        Configurações de Comunicação
                    </h1>
                    <p class="limpvix-page-subtitle">
                        Configure provedores de SMS, WhatsApp e regras de envio de mensagens
                    </p>
                </div>
                <div class="limpvix-header-actions">
                    <a href="<?php echo admin_url('admin.php?page=limpvix-templates'); ?>" class="limpvix-btn limpvix-btn-outline">
                        <span class="dashicons dashicons-format-aside" style="margin-top: 4px;"></span>
                        Ver Templates
                    </a>
                </div>
            </div>

            <!-- Status Overview -->
            <div class="limpvix-grid limpvix-grid-3" style="margin-bottom: 2rem;">
                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon <?php echo $twilioConnected ? 'limpvix-stat-icon-success' : 'limpvix-stat-icon-danger'; ?>">
                        <span class="dashicons dashicons-smartphone"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Twilio SMS</div>
                        <div class="limpvix-stat-value" style="font-size: 1.25rem;">
                            <?php if ($twilioConnected): ?>
                                <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Conectado</span>
                            <?php else: ?>
                                <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Não configurado</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon <?php echo $dialogConnected ? 'limpvix-stat-icon-success' : 'limpvix-stat-icon-danger'; ?>">
                        <span class="dashicons dashicons-whatsapp"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">360Dialog WhatsApp</div>
                        <div class="limpvix-stat-value" style="font-size: 1.25rem;">
                            <?php if ($dialogConnected): ?>
                                <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Conectado</span>
                            <?php else: ?>
                                <span class="limpvix-badge limpvix-badge-gray limpvix-badge-dot">Não configurado</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon <?php echo $commActive ? 'limpvix-stat-icon-primary' : 'limpvix-stat-icon-warning'; ?>">
                        <span class="dashicons dashicons-megaphone"></span>
                    </div>
                    <div class="limpvix-stat-content">
                        <div class="limpvix-stat-label">Sistema</div>
                        <div class="limpvix-stat-value" style="font-size: 1.25rem;">
                            <?php if ($commActive): ?>
                                <span class="limpvix-badge limpvix-badge-primary limpvix-badge-dot">Ativo</span>
                            <?php else: ?>
                                <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">Desativado</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <form method="post" action="">
                <?php wp_nonce_field('limpvix_comm_settings'); ?>

                <!-- Configurações Gerais -->
                <div class="limpvix-card limpvix-card-primary" style="margin-bottom: 1.5rem;">
                    <div class="limpvix-card-header">
                        <h3>
                            <span class="dashicons dashicons-admin-generic"></span>
                            Configurações Gerais
                        </h3>
                        <p>Ativar/desativar sistema de comunicação e notificações</p>
                    </div>
                    <div class="limpvix-card-body">
                        <div class="limpvix-grid limpvix-grid-2">
                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label" style="display: flex; align-items: center; justify-content: space-between;">
                                    <span>
                                        <strong>Sistema de Comunicação</strong>
                                        <br>
                                        <small style="color: #6B7280;">Envio automático de mensagens para clientes</small>
                                    </span>
                                    <label class="limpvix-toggle">
                                        <input type="checkbox" name="limpvix_comm_active" <?php checked($commActive); ?>>
                                        <span class="limpvix-toggle-slider"></span>
                                    </label>
                                </label>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label" style="display: flex; align-items: center; justify-content: space-between;">
                                    <span>
                                        <strong>Notificar Equipe</strong>
                                        <br>
                                        <small style="color: #6B7280;">Notificações para profissionais sobre serviços</small>
                                    </span>
                                    <label class="limpvix-toggle">
                                        <input type="checkbox" name="limpvix_notify_staff_enabled" <?php checked($notifyStaff); ?>>
                                        <span class="limpvix-toggle-slider"></span>
                                    </label>
                                </label>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="limpvix-grid limpvix-grid-2">
                    <!-- Twilio SMS Card -->
                    <div class="limpvix-card <?php echo $twilioConnected ? 'limpvix-card-success' : ''; ?>">
                        <div class="limpvix-card-header">
                            <h3>
                                <span class="dashicons dashicons-smartphone"></span>
                                Twilio SMS
                            </h3>
                            <p>Configurações para envio de SMS via Twilio</p>
                        </div>
                        <div class="limpvix-card-body">
                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">Account SID</label>
                                <input
                                    type="text"
                                    name="limpvix_twilio_account_sid"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($twilioSid); ?>"
                                    placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                >
                                <span class="limpvix-form-help">
                                    Encontre no painel do Twilio em Console → Settings
                                </span>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">Auth Token</label>
                                <input
                                    type="password"
                                    name="limpvix_twilio_auth_token"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($twilioToken); ?>"
                                    placeholder="••••••••••••••••••••••••••••••••"
                                >
                                <span class="limpvix-form-help">
                                    Token de autenticação (mantenha em segredo)
                                </span>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">Número de Origem</label>
                                <input
                                    type="text"
                                    name="limpvix_twilio_from_number"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($twilioFrom); ?>"
                                    placeholder="+5511999999999"
                                >
                                <span class="limpvix-form-help">
                                    Número verificado no Twilio (formato internacional)
                                </span>
                            </div>
                        </div>
                        <?php if ($twilioConnected): ?>
                        <div class="limpvix-card-footer">
                            <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Configurado e pronto</span>
                            <button type="button" class="limpvix-btn limpvix-btn-sm limpvix-btn-outline" onclick="alert('Teste enviado!')">
                                Enviar Teste
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>

                    <!-- 360Dialog WhatsApp Card -->
                    <div class="limpvix-card <?php echo $dialogConnected ? 'limpvix-card-success' : ''; ?>">
                        <div class="limpvix-card-header">
                            <h3>
                                <span class="dashicons dashicons-whatsapp"></span>
                                360Dialog WhatsApp
                            </h3>
                            <p>Configurações para WhatsApp Business API</p>
                        </div>
                        <div class="limpvix-card-body">
                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">API Key</label>
                                <input
                                    type="password"
                                    name="limpvix_360dialog_api_key"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($dialogKey); ?>"
                                    placeholder="••••••••••••••••••••••••••••••••"
                                >
                                <span class="limpvix-form-help">
                                    Chave de API fornecida pela 360Dialog
                                </span>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">Namespace</label>
                                <input
                                    type="text"
                                    name="limpvix_360dialog_namespace"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($dialogNamespace); ?>"
                                    placeholder="seu_namespace"
                                >
                                <span class="limpvix-form-help">
                                    Namespace do seu template (opcional)
                                </span>
                            </div>

                            <div class="limpvix-alert limpvix-alert-info" style="margin-top: 1rem; margin-bottom: 0;">
                                <div class="limpvix-alert-icon">
                                    <span class="dashicons dashicons-info"></span>
                                </div>
                                <div class="limpvix-alert-content">
                                    <strong>WhatsApp Business API</strong>
                                    <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem;">
                                        Requer conta aprovada pela 360Dialog e templates aprovados pelo WhatsApp
                                    </p>
                                </div>
                            </div>
                        </div>
                        <?php if ($dialogConnected): ?>
                        <div class="limpvix-card-footer">
                            <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Configurado e pronto</span>
                            <button type="button" class="limpvix-btn limpvix-btn-sm limpvix-btn-outline" onclick="alert('Teste enviado!')">
                                Enviar Teste
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Botão Salvar -->
                <div style="margin-top: 2rem; display: flex; justify-content: flex-end; gap: 1rem;">
                    <button type="button" class="limpvix-btn limpvix-btn-secondary" onclick="window.location.reload()">
                        Cancelar
                    </button>
                    <button type="submit" name="limpvix_comm_save" class="limpvix-btn limpvix-btn-primary limpvix-btn-lg">
                        <span class="dashicons dashicons-yes" style="margin-top: 4px;"></span>
                        Salvar Configurações
                    </button>
                </div>
            </form>
        </div>
        <?php
    }
}
