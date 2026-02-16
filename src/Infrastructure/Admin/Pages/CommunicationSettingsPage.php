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
        // NVoip OTP (Sprint 9)
        register_setting('limpvix_communication', 'limpvix_nvoip_api_key');
        register_setting('limpvix_communication', 'limpvix_nvoip_user_token');
        register_setting('limpvix_communication', 'limpvix_nvoip_default_number');
        register_setting('limpvix_communication', 'limpvix_nvoip_enable_otp');

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
            update_option('limpvix_nvoip_api_key', sanitize_text_field($_POST['limpvix_nvoip_api_key'] ?? ''));
            update_option('limpvix_nvoip_user_token', sanitize_text_field($_POST['limpvix_nvoip_user_token'] ?? ''));
            update_option('limpvix_nvoip_default_number', sanitize_text_field($_POST['limpvix_nvoip_default_number'] ?? ''));
            update_option('limpvix_nvoip_enable_otp', isset($_POST['limpvix_nvoip_enable_otp']));

            echo '<div class="notice notice-success is-dismissible"><p><strong>Configurações salvas com sucesso!</strong></p></div>';
        }

        $commActive = get_option('limpvix_comm_active', true);
        $notifyStaff = get_option('limpvix_notify_staff_enabled', true);

        // NVoip OTP
        $nvoipApiKey = get_option('limpvix_nvoip_api_key', '');
        $nvoipUserToken = get_option('limpvix_nvoip_user_token', '');
        $nvoipDefaultNumber = get_option('limpvix_nvoip_default_number', '+552720183484');
        $nvoipEnableOtp = get_option('limpvix_nvoip_enable_otp', true);
        $nvoipConnected = !empty($nvoipApiKey) && !empty($nvoipUserToken);

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
                        <div class="limpvix-stat-label">NVoip OTP (WhatsApp/SMS)</div>
                        <div class="limpvix-stat-value">
                            <?php if ($nvoipConnected): ?>
                                <span class="limpvix-badge limpvix-badge-success">Configurado</span>
                            <?php else: ?>
                                <span class="limpvix-badge limpvix-badge-warning">Não configurado</span>
                            <?php endif; ?>
                        </div>
                </div>

                <div class="limpvix-stat-box">
                    <div class="limpvix-stat-icon <?php echo $dialogConnected ? 'limpvix-stat-icon-success' : 'limpvix-stat-icon-danger'; ?>">
                        <span class="dashicons dashicons-whatsapp"></span>
                    </div>
                    <div class="limpvix-stat-content">
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
                    <!-- NVoip OTP Card (Sprint 9) -->
                    <div class="limpvix-card <?php echo $nvoipConnected ? 'limpvix-card-success' : ''; ?>">
                        <div class="limpvix-card-header">
                            <h3>
                                <span class="dashicons dashicons-smartphone"></span>
                                NVoip OTP - Verificação de Telefone
                            </h3>
                            <p>Configurações para OTP via WhatsApp/SMS (Sprint 9)</p>
                        </div>
                        <div class="limpvix-card-body">
                            <div class="limpvix-alert limpvix-alert-info" style="margin-bottom: 1.5rem;">
                                <div class="limpvix-alert-icon">
                                    <span class="dashicons dashicons-shield"></span>
                                </div>
                                <div class="limpvix-alert-content">
                                    <strong>🔐 OBRIGATÓRIO para ações críticas</strong>
                                    <p style="margin: 0.25rem 0 0 0; font-size: 0.875rem;">
                                        Verificação de telefone é <strong>requerida</strong> para: aceitar offers, criar contratos, criar briefings
                                    </p>
                                </div>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">API Key *</label>
                                <input
                                    type="text"
                                    name="limpvix_nvoip_api_key"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($nvoipApiKey); ?>"
                                    placeholder="sua-api-key"
                                    required
                                >
                                <span class="limpvix-form-help">
                                    Obtenha em <a href="https://nvoip.com.br" target="_blank">nvoip.com.br</a>
                                </span>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">User Token *</label>
                                <input
                                    type="password"
                                    name="limpvix_nvoip_user_token"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($nvoipUserToken); ?>"
                                    placeholder="••••••••••••••••••••••••••••••••"
                                    required
                                >
                                <span class="limpvix-form-help">
                                    Token de autenticação (mantenha em segredo)
                                </span>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-label">Número Remetente</label>
                                <input
                                    type="text"
                                    name="limpvix_nvoip_default_number"
                                    class="limpvix-form-input"
                                    value="<?php echo esc_attr($nvoipDefaultNumber); ?>"
                                    placeholder="+552720183484"
                                >
                                <span class="limpvix-form-help">
                                    Número registrado na NVoip (formato: +55DDXXXXXXXXX)
                                </span>
                            </div>

                            <div class="limpvix-form-group">
                                <label class="limpvix-form-checkbox">
                                    <input
                                        type="checkbox"
                                        name="limpvix_nvoip_enable_otp"
                                        value="1"
                                        <?php checked($nvoipEnableOtp, true); ?>
                                    >
                                    <span>Habilitar verificação de telefone via OTP</span>
                                </label>
                                <span class="limpvix-form-help">
                                    <strong style="color: #d32f2f;">⚠️ Desabilitar bloqueia ações críticas!</strong>
                                </span>
                            </div>
                        </div>
                        <?php if ($nvoipConnected): ?>
                        <div class="limpvix-card-footer">
                            <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">Configurado e pronto</span>
                            <a href="https://nvoip.docs.apiary.io" target="_blank" class="limpvix-btn limpvix-btn-sm limpvix-btn-outline">
                                📚 Documentação
                            </a>
                        </div>
                        <?php else: ?>
                        <div class="limpvix-card-footer">
                            <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">Não configurado</span>
                            <span class="limpvix-form-help">Complete os campos obrigatórios para ativar</span>
                        </div>
                        <?php endif; ?>
                    </div>
            </form>
        </div>
        <?php
    }
}
