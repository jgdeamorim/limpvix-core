<?php
/**
 * NVoipSettings - Configurações do NVoip OTP (Sprint 9)
 *
 * Gerencia configuração e status da integração com NVoip para verificação de telefone via OTP
 * API: https://nvoip.docs.apiary.io
 *
 * @package LimpVix\Admin\Settings
 * @since Sprint 9
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class NVoipSettings
{
    /**
     * Chave da opção no WordPress
     */
    private const OPTION_KEY = 'limpvix_nvoip_settings';

    /**
     * Verificar se NVoip está conectado
     *
     * @return bool
     */
    public static function isConnected(): bool
    {
        $settings = self::getSettings();
        return !empty($settings['api_key'])
            && !empty($settings['user_token'])
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
            'enabled' => true,
            'api_key' => '',
            'user_token' => '',
            'default_number' => '+552720183484',
            'enable_otp' => true,
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
            'user_token' => sanitize_text_field($settings['user_token'] ?? ''),
            'default_number' => sanitize_text_field($settings['default_number'] ?? '+552720183484'),
            'enable_otp' => !empty($settings['enable_otp']),
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
                NVoip OTP - Verificação de Telefone
            </h3>

            <?php if ($isConnected): ?>
                <div class="limpvix-alert limpvix-alert-success">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-yes"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">✅ NVoip Configurado e Ativo</div>
                        <p>API Key: <code><?php echo esc_html(substr($settings['api_key'], 0, 8) . '***'); ?></code></p>
                        <p>Número Remetente: <strong><?php echo esc_html($settings['default_number']); ?></strong></p>
                        <p>OTP: <strong><?php echo $settings['enable_otp'] ? 'Habilitado' : 'Desabilitado'; ?></strong></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="limpvix-alert limpvix-alert-warning">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">⚠️ NVoip Não Configurado</div>
                        <p>Configure abaixo para habilitar verificação de telefone via OTP (WhatsApp/SMS).</p>
                    </div>
                </div>
            <?php endif; ?>

            <div class="limpvix-alert limpvix-alert-info" style="margin-top: 16px;">
                <div class="limpvix-alert-icon">
                    <span class="dashicons dashicons-shield"></span>
                </div>
                <div class="limpvix-alert-content">
                    <div class="limpvix-alert-title">🔐 OBRIGATÓRIO para ações críticas</div>
                    <p style="margin: 0.25rem 0 0 0;">
                        Verificação de telefone é <strong>requerida</strong> para: aceitar offers, criar contratos, criar briefings.
                        <br>OTP enviado via <strong>WhatsApp</strong> (fallback automático para SMS se necessário).
                    </p>
                </div>
            </div>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="limpvix-form">
                <input type="hidden" name="action" value="limpvix_save_nvoip_settings">
                <?php wp_nonce_field('limpvix_save_nvoip_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="nvoip_enabled">Habilitar NVoip OTP</label>
                        </th>
                        <td>
                            <label class="limpvix-toggle">
                                <input type="checkbox"
                                       id="nvoip_enabled"
                                       name="nvoip_settings[enabled]"
                                       value="1"
                                       <?php checked($settings['enabled']); ?>>
                                <span class="limpvix-toggle-slider"></span>
                            </label>
                            <p class="description">
                                Quando habilitado, o sistema enviará códigos OTP via WhatsApp/SMS para verificação de telefone.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="nvoip_api_key">API Key *</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="nvoip_api_key"
                                   name="nvoip_settings[api_key]"
                                   value="<?php echo esc_attr($settings['api_key']); ?>"
                                   class="regular-text"
                                   placeholder="sua-api-key"
                                   required>
                            <p class="description">
                                Sua API Key do NVoip (obtenha em <a href="https://nvoip.com.br" target="_blank">nvoip.com.br</a>)
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="nvoip_user_token">User Token *</label>
                        </th>
                        <td>
                            <input type="password"
                                   id="nvoip_user_token"
                                   name="nvoip_settings[user_token]"
                                   value="<?php echo esc_attr($settings['user_token']); ?>"
                                   class="regular-text"
                                   placeholder="••••••••••••••••••••••••••••••••"
                                   required>
                            <p class="description">
                                Token de autenticação (mantenha em segredo)
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="nvoip_default_number">Número Remetente</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="nvoip_default_number"
                                   name="nvoip_settings[default_number]"
                                   value="<?php echo esc_attr($settings['default_number']); ?>"
                                   class="regular-text"
                                   placeholder="+552720183484">
                            <p class="description">
                                Número registrado na NVoip (formato: +55DDXXXXXXXXX)
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="nvoip_enable_otp">Habilitar Verificação OTP</label>
                        </th>
                        <td>
                            <label class="limpvix-toggle">
                                <input type="checkbox"
                                       id="nvoip_enable_otp"
                                       name="nvoip_settings[enable_otp]"
                                       value="1"
                                       <?php checked($settings['enable_otp']); ?>>
                                <span class="limpvix-toggle-slider"></span>
                            </label>
                            <p class="description">
                                <strong style="color: #d32f2f;">⚠️ Desabilitar bloqueia ações críticas!</strong>
                                <br>Usuários não poderão aceitar offers, criar contratos ou briefings sem verificação de telefone.
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="limpvix-settings-help" style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #1e40af;">ℹ️ Como obter credenciais NVoip</h4>
                    <ol style="margin: 0; padding-left: 20px; color: #1f2937;">
                        <li>Acesse <a href="https://nvoip.com.br" target="_blank">NVoip</a></li>
                        <li>Faça login ou crie uma conta</li>
                        <li>No painel, acesse a seção de API</li>
                        <li>Copie:
                            <ul>
                                <li><strong>API Key</strong></li>
                                <li><strong>User Token</strong></li>
                            </ul>
                        </li>
                        <li>Configure seu número de WhatsApp Business na NVoip</li>
                    </ol>
                    <p style="margin: 12px 0 0 0; color: #1f2937;">
                        📚 Documentação completa: <a href="https://nvoip.docs.apiary.io" target="_blank">https://nvoip.docs.apiary.io</a>
                    </p>
                </div>

                <div style="background: #fff3cd; border-left: 4px solid #ffc107; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #856404;">🔒 Ações que requerem verificação de telefone</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #856404;">
                        <li><strong>Aceitar Offers</strong> - Profissional deve ter telefone verificado</li>
                        <li><strong>Criar Contratos</strong> - Cliente/Admin deve ter telefone verificado</li>
                        <li><strong>Criar Briefings</strong> - Cliente deve ter telefone verificado</li>
                    </ul>
                    <p style="margin: 12px 0 0 0; color: #856404;">
                        <strong>Segurança:</strong> Rate limiting de 3 envios por hora + proteção contra brute force (5 tentativas, bloqueio de 15 minutos)
                    </p>
                </div>

                <div style="background: #e8f5e9; border-left: 4px solid #4caf50; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #2e7d32;">✅ Fluxo de Verificação</h4>
                    <ol style="margin: 0; padding-left: 20px; color: #2e7d32;">
                        <li>Usuário solicita OTP via endpoint <code>/wp-json/limpvix/v1/phone-verification/send</code></li>
                        <li>Sistema envia código de 6 dígitos via <strong>WhatsApp</strong> (fallback automático para SMS)</li>
                        <li>Código válido por <strong>10 minutos</strong></li>
                        <li>Usuário insere código via <code>/wp-json/limpvix/v1/phone-verification/verify</code></li>
                        <li>Se correto: <code>phone_verified = true</code> na tabela <code>wp_limpvix_user_verifications</code></li>
                    </ol>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        💾 Salvar Configurações NVoip
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
        add_action('admin_post_limpvix_save_nvoip_settings', [__CLASS__, 'handleSaveSettings']);
    }

    /**
     * Handler: Salvar configurações
     */
    public static function handleSaveSettings(): void
    {
        check_admin_referer('limpvix_save_nvoip_settings');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $settings = $_POST['nvoip_settings'] ?? [];

        if (self::saveSettings($settings)) {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'tab' => 'conexoes',
                'message' => 'nvoip_saved'
            ], admin_url('admin.php'));
        } else {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'tab' => 'conexoes',
                'message' => 'error'
            ], admin_url('admin.php'));
        }

        wp_redirect($redirect);
        exit;
    }
}
