<?php
/**
 * TwilioSettings - Configurações do Twilio SMS
 *
 * Gerencia configuração e status da integração com Twilio
 *
 * @package LimpVix\Admin\Settings
 * @since 0.1.5
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class TwilioSettings
{
    /**
     * Chave da opção no WordPress
     */
    private const OPTION_KEY = 'limpvix_twilio_settings';

    /**
     * Verificar se Twilio está conectado
     *
     * @return bool
     */
    public static function isConnected(): bool
    {
        $settings = self::getSettings();
        return !empty($settings['account_sid'])
            && !empty($settings['auth_token'])
            && !empty($settings['from_number'])
            && !empty($settings['enabled']);
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
            'account_sid' => '',
            'auth_token' => '',
            'from_number' => '',
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
            'account_sid' => sanitize_text_field($settings['account_sid'] ?? ''),
            'auth_token' => sanitize_text_field($settings['auth_token'] ?? ''),
            'from_number' => sanitize_text_field($settings['from_number'] ?? ''),
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
                <span class="dashicons dashicons-smartphone"></span>
                Twilio SMS
            </h3>

            <?php if ($isConnected): ?>
                <div class="limpvix-alert limpvix-alert-success">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-yes"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">Twilio Conectado</div>
                        <p>Account SID: <code><?php echo esc_html(substr($settings['account_sid'], 0, 8) . '***'); ?></code></p>
                        <p>From Number: <strong><?php echo esc_html($settings['from_number']); ?></strong></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="limpvix-alert limpvix-alert-warning">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">Twilio Não Configurado</div>
                        <p>Configure abaixo para habilitar envio de SMS.</p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="limpvix-form">
                <input type="hidden" name="action" value="limpvix_save_twilio_settings">
                <?php wp_nonce_field('limpvix_save_twilio_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="twilio_enabled">Habilitar Twilio SMS</label>
                        </th>
                        <td>
                            <label class="limpvix-toggle">
                                <input type="checkbox"
                                       id="twilio_enabled"
                                       name="twilio_settings[enabled]"
                                       value="1"
                                       <?php checked($settings['enabled']); ?>>
                                <span class="limpvix-toggle-slider"></span>
                            </label>
                            <p class="description">
                                Quando habilitado, o sistema enviará SMS via Twilio para templates configurados.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="twilio_account_sid">Account SID *</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="twilio_account_sid"
                                   name="twilio_settings[account_sid]"
                                   value="<?php echo esc_attr($settings['account_sid']); ?>"
                                   class="regular-text"
                                   placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
                                   required>
                            <p class="description">
                                Seu Account SID do Twilio (começa com "AC")
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="twilio_auth_token">Auth Token *</label>
                        </th>
                        <td>
                            <input type="password"
                                   id="twilio_auth_token"
                                   name="twilio_settings[auth_token]"
                                   value="<?php echo esc_attr($settings['auth_token']); ?>"
                                   class="regular-text"
                                   placeholder="********************************"
                                   required>
                            <p class="description">
                                Seu Auth Token do Twilio (mantenha em segredo)
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="twilio_from_number">From Number *</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="twilio_from_number"
                                   name="twilio_settings[from_number]"
                                   value="<?php echo esc_attr($settings['from_number']); ?>"
                                   class="regular-text"
                                   placeholder="+5527999999999"
                                   required>
                            <p class="description">
                                Número do Twilio que enviará os SMS (formato: +5527999999999)
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="limpvix-settings-help" style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #1e40af;">ℹ️ Como obter credenciais Twilio</h4>
                    <ol style="margin: 0; padding-left: 20px; color: #1f2937;">
                        <li>Acesse <a href="https://console.twilio.com/" target="_blank">Twilio Console</a></li>
                        <li>Faça login ou crie uma conta gratuita</li>
                        <li>No dashboard, copie:
                            <ul>
                                <li><strong>Account SID</strong> (começa com AC...)</li>
                                <li><strong>Auth Token</strong> (clique em "Show" para revelar)</li>
                            </ul>
                        </li>
                        <li>Em "Phone Numbers" → "Manage" → "Active numbers", copie seu número</li>
                    </ol>
                </div>

                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #856404;">⚠️ Templates que usam SMS</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #856404;">
                        <li><strong>C1.3</strong> - Feedback D+7 (3ª tentativa)</li>
                        <li><strong>P1</strong> - Serviço Concluído (Profissional)</li>
                        <li><strong>P2</strong> - Pagamento Autorizado (Profissional)</li>
                        <li><strong>P3</strong> - Pagamento em Análise (Profissional)</li>
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
        add_action('admin_post_limpvix_save_twilio_settings', [__CLASS__, 'handleSaveSettings']);
    }

    /**
     * Handler: Salvar configurações
     */
    public static function handleSaveSettings(): void
    {
        check_admin_referer('limpvix_save_twilio_settings');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $settings = $_POST['twilio_settings'] ?? [];

        if (self::saveSettings($settings)) {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'section' => 'twilio',
                'message' => 'twilio_saved'
            ], admin_url('admin.php'));
        } else {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'section' => 'twilio',
                'message' => 'error'
            ], admin_url('admin.php'));
        }

        wp_redirect($redirect);
        exit;
    }
}
