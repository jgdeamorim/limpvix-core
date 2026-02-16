<?php
/**
 * DialogSettings - Configurações do 360Dialog WhatsApp
 *
 * Gerencia configuração e status da integração com 360Dialog
 *
 * @package LimpVix\Admin\Settings
 * @since 0.1.5
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class DialogSettings
{
    /**
     * Chave da opção no WordPress
     */
    private const OPTION_KEY = 'limpvix_360dialog_settings';

    /**
     * Verificar se 360Dialog está conectado
     *
     * @return bool
     */
    public static function isConnected(): bool
    {
        $settings = self::getSettings();
        return !empty($settings['api_key']) && !empty($settings['enabled']);
    }

    /**
     * Obter todas as configurações
     *
     * @return array
     */
    public static function getSettings(): array
    {
        return get_option(self::OPTION_KEY, [
            'enabled' => false,
            'api_key' => '',
            'namespace' => '',
            'last_updated' => null,
        ]);
    }

    /**
     * Salvar configurações
     *
     * @param array $settings
     * @return bool
     */
    public static function saveSettings(array $settings): bool
    {
        $current = self::getSettings();

        $updated = array_merge($current, [
            'enabled' => !empty($settings['enabled']),
            'api_key' => sanitize_text_field($settings['api_key'] ?? ''),
            'namespace' => sanitize_text_field($settings['namespace'] ?? ''),
            'last_updated' => current_time('mysql'),
        ]);

        return update_option(self::OPTION_KEY, $updated);
    }

    /**
     * Renderizar página de configurações
     */
    public static function render(): void
    {
        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $settings = self::getSettings();
        $isConnected = self::isConnected();

        ?>
        <div class="limpvix-settings-section">
            <h3>
                <span class="dashicons dashicons-whatsapp"></span>
                360Dialog WhatsApp
            </h3>

            <?php if ($isConnected): ?>
                <div class="limpvix-alert limpvix-alert-success">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-yes"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">360Dialog Conectado</div>
                        <p>API Key: <code><?php echo esc_html(substr($settings['api_key'], 0, 12) . '***'); ?></code></p>
                        <?php if (!empty($settings['namespace'])): ?>
                            <p>Namespace: <strong><?php echo esc_html($settings['namespace']); ?></strong></p>
                        <?php endif; ?>
                    </div>
                </div>
            <?php else: ?>
                <div class="limpvix-alert limpvix-alert-warning">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">360Dialog Não Configurado</div>
                        <p>Configure abaixo para habilitar envio de WhatsApp via 360Dialog.</p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="limpvix-form">
                <input type="hidden" name="action" value="limpvix_save_360dialog_settings">
                <?php wp_nonce_field('limpvix_save_360dialog_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="dialog_enabled">Habilitar 360Dialog WhatsApp</label>
                        </th>
                        <td>
                            <label class="limpvix-toggle">
                                <input type="checkbox"
                                       id="dialog_enabled"
                                       name="dialog_settings[enabled]"
                                       value="1"
                                       <?php checked($settings['enabled']); ?>>
                                <span class="limpvix-toggle-slider"></span>
                            </label>
                            <p class="description">
                                Quando habilitado, o sistema enviará mensagens via WhatsApp Business API.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="dialog_api_key">API Key (D360-API-KEY) *</label>
                        </th>
                        <td>
                            <input type="password"
                                   id="dialog_api_key"
                                   name="dialog_settings[api_key]"
                                   value="<?php echo esc_attr($settings['api_key']); ?>"
                                   class="large-text"
                                   placeholder="****************************************"
                                   required>
                            <p class="description">
                                Sua API Key do 360Dialog (D360-API-KEY) - mantenha em segredo
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="dialog_namespace">Namespace (Opcional)</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="dialog_namespace"
                                   name="dialog_settings[namespace]"
                                   value="<?php echo esc_attr($settings['namespace']); ?>"
                                   class="regular-text"
                                   placeholder="seu_namespace">
                            <p class="description">
                                Namespace para templates aprovados (deixe vazio se não usar templates)
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="limpvix-settings-help" style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #1e40af;">ℹ️ Como obter credenciais 360Dialog</h4>
                    <ol style="margin: 0; padding-left: 20px; color: #1f2937;">
                        <li>Acesse <a href="https://hub.360dialog.com/" target="_blank">360Dialog Hub</a></li>
                        <li>Faça login ou crie uma conta</li>
                        <li>No menu lateral, vá em <strong>"API Keys"</strong></li>
                        <li>Clique em <strong>"Create API Key"</strong></li>
                        <li>Dê um nome (ex: "LimpVix Production")</li>
                        <li>Copie a API Key gerada (começa com um hash longo)</li>
                        <li>Cole aqui no campo "API Key"</li>
                    </ol>
                </div>

                <div style="background: #f0fdf4; border-left: 4px solid #10b981; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #065f46;">✅ Templates que usam WhatsApp</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #065f46;">
                        <li><strong>C1.1</strong> - Feedback D+1 (1ª tentativa)</li>
                        <li><strong>C1.2</strong> - Feedback D+3 (2ª tentativa)</li>
                        <li><strong>C3</strong> - Convite Google Review (5⭐)</li>
                    </ul>
                </div>

                <div style="background: #fef3c7; border-left: 4px solid #f59e0b; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #92400e;">⚠️ Requisitos WhatsApp Business API</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #92400e;">
                        <li>Número de telefone verificado no WhatsApp Business</li>
                        <li>Conta 360Dialog ativa e aprovada</li>
                        <li>Templates de mensagem aprovados pelo WhatsApp (se aplicável)</li>
                        <li>Conformidade com políticas do WhatsApp Business</li>
                    </ul>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        💾 Salvar Configurações
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    /**
     * Registrar hooks
     */
    public static function registerHooks(): void
    {
        add_action('admin_post_limpvix_save_360dialog_settings', [__CLASS__, 'handleSaveSettings']);
    }

    /**
     * Handler: Salvar configurações
     */
    public static function handleSaveSettings(): void
    {
        check_admin_referer('limpvix_save_360dialog_settings');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $settings = $_POST['dialog_settings'] ?? [];

        if (self::saveSettings($settings)) {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'section' => '360dialog',
                'message' => 'dialog_saved'
            ], admin_url('admin.php'));
        } else {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'section' => '360dialog',
                'message' => 'error'
            ], admin_url('admin.php'));
        }

        wp_redirect($redirect);
        exit;
    }
}
