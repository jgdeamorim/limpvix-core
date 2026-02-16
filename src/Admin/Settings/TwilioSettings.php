<?php
/**
 * Twilio OTP Settings with Connection Validation
 *
 * @package LimpVix\Admin\Settings
 * @since Sprint 9 - OTP Verification (Twilio)
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

final class TwilioSettings
{
    /**
     * Register WordPress hooks
     */
    public static function registerHooks(): void
    {
        add_action('wp_ajax_limpvix_test_otp_send', [self::class, 'handleTestOtpSend']);
    }

    /**
     * AJAX handler for test OTP send
     */
    public static function handleTestOtpSend(): void
    {
        check_ajax_referer('test_otp_send', '_wpnonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Acesso negado']);
        }

        // Sanitize phone preserving + sign
        $phone = isset($_POST['phone']) ? wp_strip_all_tags(trim($_POST['phone'])) : '';
        $channel = sanitize_text_field($_POST['channel'] ?? 'sms');

        if (empty($phone)) {
            wp_send_json_error(['message' => 'Telefone não fornecido']);
        }

        // Add + if missing
        if (!str_starts_with($phone, '+')) {
            $phone = '+' . $phone;
        }

        // Validate phone format (basic)
        if (!preg_match('/^\+[0-9]{10,15}$/', $phone)) {
            wp_send_json_error(['message' => 'Formato de telefone inválido. Use: +55XXXXXXXXXXX']);
        }

        // Check if Twilio is configured
        if (!self::isConnected()) {
            wp_send_json_error(['message' => 'Twilio não configurado. Configure as credenciais primeiro.']);
        }

        try {
            // Get Twilio provider
            $provider = new \LimpVix\Infrastructure\SMS\TwilioOtpProvider();

            // Send OTP
            $key = $provider->send($phone, $channel);

            wp_send_json_success([
                'message' => 'OTP enviado com sucesso!',
                'key' => $key,
                'phone' => $phone,
                'channel' => $channel,
            ]);
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => sprintf('Erro ao enviar OTP: %s', $e->getMessage()),
            ]);
        }
    }

    public static function render(): void
    {
        $settings = self::getSettings();
        $isConnected = self::isConnected();
        $error = isset($_GET['twilio_error']) ? sanitize_text_field($_GET['twilio_error']) : '';
        $success = isset($_GET['twilio_success']) ? true : false;
        ?>
        <div class="limpvix-card-header">
            <h3>
                <span class="dashicons dashicons-admin-network"></span>
                📞 Twilio OTP
            </h3>
            <p>SMS, WhatsApp e Voice OTP via Twilio Verify API</p>
        </div>

        <?php if ($error): ?>
            <div class="notice notice-error" style="margin: 15px 0;">
                <p><strong>❌ Erro ao testar conexão:</strong><br><?php echo esc_html($error); ?></p>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="notice notice-success" style="margin: 15px 0;">
                <p><strong>✅ Conexão testada com sucesso!</strong><br>Credenciais salvas e funcionando.</p>
            </div>
        <?php endif; ?>

        <form method="post" action="">
            <?php wp_nonce_field('limpvix_twilio_settings'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="twilio_account_sid">Account SID:</label></th>
                    <td>
                        <input type="text" name="twilio_account_sid" id="twilio_account_sid"
                               value="<?php echo esc_attr($settings['account_sid']); ?>"
                               class="regular-text" placeholder="ACxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" required>
                        <p class="description">
                            Encontre em: <a href="https://console.twilio.com" target="_blank">Twilio Console</a> → Account
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="twilio_auth_token">Auth Token:</label></th>
                    <td>
                        <input type="password" name="twilio_auth_token" id="twilio_auth_token"
                               value="<?php echo esc_attr($settings['auth_token']); ?>"
                               class="regular-text" required>
                        <p class="description">Click "Show" no Twilio Console</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="twilio_verify_service_sid">Verify Service SID:</label></th>
                    <td>
                        <input type="text" name="twilio_verify_service_sid" id="twilio_verify_service_sid"
                               value="<?php echo esc_attr($settings['service_sid']); ?>"
                               class="regular-text" placeholder="VAxxxxxxxxxxxxxxxxxxxxxxxxxxxxx" required>
                        <p class="description">
                            Crie em: <a href="https://console.twilio.com/us1/develop/verify/services" target="_blank">Verify Services</a>
                        </p>
                    </td>
                </tr>
                <tr>
                    <th><label for="twilio_enabled">Ativar OTP:</label></th>
                    <td>
                        <label>
                            <input type="checkbox" name="twilio_enabled" id="twilio_enabled" value="1"
                                   <?php checked($settings['enabled'], true); ?>>
                            Habilitar Twilio OTP
                        </label>
                    </td>
                </tr>
            </table>

            <div style="margin: 20px 0; padding: 12px; background: <?php echo $isConnected ? '#d1fae5' : '#fee2e2'; ?>; border-left: 4px solid <?php echo $isConnected ? '#10b981' : '#ef4444'; ?>; border-radius: 4px;">
                <p style="margin: 0; color: <?php echo $isConnected ? '#065f46' : '#991b1b'; ?>;">
                    <?php if ($isConnected): ?>
                        ✅ <strong>Conectado</strong> - Pronto para usar
                    <?php else: ?>
                        ❌ <strong>Não configurado</strong>
                    <?php endif; ?>
                </p>
            </div>

            <div style="margin-top: 20px; padding: 12px; background: #fef3c7; border-left: 4px solid #f59e0b; border-radius: 4px;">
                <h4 style="margin-top: 0; color: #92400e;">⚡ Validação Automática</h4>
                <p style="margin: 0; color: #92400e;">
                    Ao clicar em "Salvar", o sistema testa a conexão com Twilio.<br>
                    <strong>Credenciais só são salvas se o teste passar.</strong>
                </p>
            </div>

            <p class="submit" style="margin-top: 20px;">
                <button type="submit" name="limpvix_save_twilio_settings" class="button button-primary button-large">
                    💾 Testar e Salvar Credenciais
                </button>
            </p>
        </form>
        <?php
    }

    public static function getSettings(): array
    {
        return [
            'account_sid' => get_option('limpvix_twilio_account_sid', ''),
            'auth_token' => get_option('limpvix_twilio_auth_token', ''),
            'service_sid' => get_option('limpvix_twilio_verify_service_sid', ''),
            'enabled' => (bool) get_option('limpvix_twilio_enabled', false),
        ];
    }

    public static function isConnected(): bool
    {
        $settings = self::getSettings();
        return !empty($settings['account_sid'])
            && !empty($settings['auth_token'])
            && !empty($settings['service_sid'])
            && $settings['enabled'];
    }

    private static function testConnection(string $accountSid, string $authToken, string $serviceSid): array
    {
        $url = sprintf('https://verify.twilio.com/v2/Services/%s', $serviceSid);

        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Basic ' . base64_encode("{$accountSid}:{$authToken}"),
                'Accept' => 'application/json',
            ],
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'error' => sprintf('Erro de conexão: %s', $response->get_error_message()),
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode === 200) {
            return ['success' => true, 'error' => null];
        }

        $errorMessages = [
            401 => 'Account SID ou Auth Token inválidos',
            404 => 'Verify Service SID não encontrado',
            403 => 'Acesso negado. Verifique permissões',
            429 => 'Muitas requisições. Tente novamente em alguns minutos',
        ];

        return [
            'success' => false,
            'error' => $errorMessages[$statusCode] ?? sprintf('Erro HTTP %d: %s', $statusCode, $body['message'] ?? 'Erro desconhecido'),
        ];
    }

    public static function save(): void
    {
        check_admin_referer('limpvix_twilio_settings');

        if (!current_user_can('manage_options')) {
            wp_die('Acesso negado');
        }

        $accountSid = sanitize_text_field($_POST['twilio_account_sid'] ?? '');
        $authToken = sanitize_text_field($_POST['twilio_auth_token'] ?? '');
        $serviceSid = sanitize_text_field($_POST['twilio_verify_service_sid'] ?? '');
        $enabled = !empty($_POST['twilio_enabled']);

        $testResult = self::testConnection($accountSid, $authToken, $serviceSid);

        if (!$testResult['success']) {
            $redirectUrl = add_query_arg([
                'page' => 'limpvix-settings',
                'tab' => 'conexoes',
                'twilio_error' => urlencode($testResult['error']),
            ], admin_url('admin.php'));
            wp_redirect($redirectUrl);
            exit;
        }

        update_option('limpvix_twilio_account_sid', $accountSid);
        update_option('limpvix_twilio_auth_token', $authToken);
        update_option('limpvix_twilio_verify_service_sid', $serviceSid);
        update_option('limpvix_twilio_enabled', $enabled);

        $redirectUrl = add_query_arg([
            'page' => 'limpvix-settings',
            'tab' => 'conexoes',
            'twilio_success' => '1',
        ], admin_url('admin.php'));
        wp_redirect($redirectUrl);
        exit;
    }
}

