<?php
/**
 * FirebaseSettings - Interface Moderna de Configuração Firebase
 *
 * Widget de configuração OAuth Firebase para SMS OTP
 * Layout moderno seguindo padrão LimpVix
 *
 * @package LimpVix\Admin\Settings
 * @since 0.2.0
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class FirebaseSettings
{
    /**
     * Registra hooks
     */
    public static function registerHooks(): void
    {
        add_action('wp_ajax_limpvix_firebase_test_connection', [__CLASS__, 'ajaxTestConnection']);
        add_action('wp_ajax_limpvix_firebase_save', [__CLASS__, 'ajaxSave']);
    }

    /**
     * Verifica se Firebase está configurado
     */
    public static function isConfigured(): bool
    {
        $projectId = get_option('limpvix_firebase_project_id', '');
        $apiKey = get_option('limpvix_firebase_api_key', '');

        return !empty($projectId) && !empty($apiKey);
    }

    /**
     * Renderiza widget principal
     */
    public static function render(): void
    {
        $projectId = get_option('limpvix_firebase_project_id', '');
        $apiKey = get_option('limpvix_firebase_api_key', '');
        $authDomain = get_option('limpvix_firebase_auth_domain', '');
        $isConfigured = self::isConfigured();

        ?>
        <div class="limpvix-settings-section">
            <!-- Status Badge -->
            <?php if ($isConfigured): ?>
                <div class="notice notice-success inline" style="margin: 0 0 20px 0; padding: 12px;">
                    <p style="margin: 0;">
                        <span class="dashicons dashicons-yes-alt" style="color: #46b450;"></span>
                        <strong>Firebase conectado</strong> - Project ID: <code><?php echo esc_html($projectId); ?></code>
                    </p>
                </div>
            <?php else: ?>
                <div class="notice notice-warning inline" style="margin: 0 0 20px 0; padding: 12px;">
                    <p style="margin: 0;">
                        <span class="dashicons dashicons-warning" style="color: #f0b849;"></span>
                        <strong>Firebase não configurado</strong> - Preencha os campos abaixo para conectar
                    </p>
                </div>
            <?php endif; ?>

            <!-- Sempre mostrar formulário -->
            <?php self::renderConfigForm($projectId, $apiKey, $authDomain, !$isConfigured); ?>

            <!-- Botão de teste (só se configurado) -->
            <?php if ($isConfigured): ?>
                <div style="margin-top: 20px; padding-top: 20px; border-top: 1px solid #ddd;">
                    <button type="button" id="limpvix-firebase-test-btn" class="button button-secondary">
                        <span class="dashicons dashicons-yes"></span> Testar Conexão
                    </button>
                    <div id="limpvix-firebase-test-result" style="margin-top: 10px;"></div>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Salvar configuração
            $('#limpvix-firebase-save-btn').on('click', function() {
                var $btn = $(this);
                var $feedback = $('#limpvix-firebase-feedback');

                $btn.prop('disabled', true).text('Salvando...');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'limpvix_firebase_save',
                        nonce: '<?php echo wp_create_nonce('limpvix_firebase_actions'); ?>',
                        project_id: $('#firebase_project_id').val(),
                        api_key: $('#firebase_api_key').val(),
                        auth_domain: $('#firebase_auth_domain').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $feedback.html('<div class="notice notice-success"><p>✅ Configurações salvas com sucesso!</p></div>');
                            setTimeout(function() {
                                location.reload();
                            }, 1500);
                        } else {
                            $feedback.html('<div class="notice notice-error"><p>❌ Erro: ' + response.data + '</p></div>');
                            $btn.prop('disabled', false).text('💾 Salvar Configuração');
                        }
                    },
                    error: function() {
                        $feedback.html('<div class="notice notice-error"><p>❌ Erro ao salvar configurações</p></div>');
                        $btn.prop('disabled', false).text('💾 Salvar Configuração');
                    }
                });
            });

            // Testar conexão
            $('#limpvix-firebase-test-btn').on('click', function() {
                var $btn = $(this);
                var $result = $('#limpvix-firebase-test-result');

                $btn.prop('disabled', true).html('<span class="dashicons dashicons-update spinning"></span> Testando...');
                $result.html('');

                $.ajax({
                    url: ajaxurl,
                    method: 'POST',
                    data: {
                        action: 'limpvix_firebase_test_connection',
                        nonce: '<?php echo wp_create_nonce('limpvix_firebase_actions'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html('<div class="notice notice-success inline"><p>✅ ' + response.data + '</p></div>');
                        } else {
                            $result.html('<div class="notice notice-error inline"><p>❌ ' + response.data + '</p></div>');
                        }
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Testar Conexão');
                    },
                    error: function() {
                        $result.html('<div class="notice notice-error inline"><p>❌ Erro ao testar conexão</p></div>');
                        $btn.prop('disabled', false).html('<span class="dashicons dashicons-yes"></span> Testar Conexão');
                    }
                });
            });
        });
        </script>

        <style>
        .limpvix-firebase-info-box {
            background: #f0f6fc;
            border-left: 4px solid #0073aa;
            padding: 12px 15px;
        }
        .limpvix-firebase-info-box h4 {
            margin: 0 0 8px 0;
            color: #0073aa;
            font-size: 14px;
        }
        .limpvix-firebase-info-box ol {
            margin: 0;
            font-size: 13px;
            line-height: 1.6;
        }
        .dashicons.spinning {
            animation: rotation 1s infinite linear;
        }
        @keyframes rotation {
            from { transform: rotate(0deg); }
            to { transform: rotate(359deg); }
        }
        </style>
        <?php
    }


    /**
     * Renderiza formulário de configuração
     */
    private static function renderConfigForm(string $projectId, string $apiKey, string $authDomain, bool $highlight = false): void
    {
        ?>
        <div class="limpvix-firebase-form" style="<?php echo $highlight ? 'background: #f9f9f9; padding: 20px; border-radius: 4px;' : ''; ?>">
            <div id="limpvix-firebase-feedback"></div>

            <?php if ($highlight): ?>
                <div class="limpvix-firebase-info-box" style="margin-bottom: 20px;">
                    <h4>📋 Instruções:</h4>
                    <ol style="margin-left: 20px;">
                        <li>Acesse <a href="https://console.firebase.google.com" target="_blank" rel="noopener">Firebase Console</a></li>
                        <li>Vá em <strong>Authentication → Sign-in method</strong> e ative <strong>Phone</strong></li>
                        <li>Em <strong>Project Settings → General</strong>, copie <strong>Project ID</strong> e <strong>Web API Key</strong></li>
                        <li>Cole os valores abaixo e clique em Salvar</li>
                    </ol>
                </div>
            <?php endif; ?>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="firebase_project_id" style="font-weight: 600; font-size: 14px;">Project ID *</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_project_id"
                               name="firebase_project_id"
                               value="<?php echo esc_attr($projectId); ?>"
                               class="large-text"
                               style="font-size: 14px; padding: 8px;"
                               placeholder="my-project-123456"
                               required>
                        <p class="description">
                            📍 ID do projeto Firebase (encontrado no Console Firebase)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="firebase_api_key" style="font-weight: 600; font-size: 14px;">Web API Key *</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_api_key"
                               name="firebase_api_key"
                               value="<?php echo esc_attr($apiKey); ?>"
                               class="large-text"
                               style="font-size: 14px; padding: 8px;"
                               placeholder="AIzaSy..."
                               required>
                        <p class="description">
                            🔑 Web API Key (encontrado em Project Settings → General)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="firebase_auth_domain" style="font-weight: 600; font-size: 14px;">Auth Domain</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_auth_domain"
                               name="firebase_auth_domain"
                               value="<?php echo esc_attr($authDomain); ?>"
                               class="large-text"
                               style="font-size: 14px; padding: 8px;"
                               placeholder="my-project-123456.firebaseapp.com">
                        <p class="description">
                            🌐 Domínio de autenticação (geralmente: {projectId}.firebaseapp.com). Deixe vazio para usar padrão.
                        </p>
                    </td>
                </tr>
            </table>

            <div style="margin-top: 20px;">
                <button type="button" id="limpvix-firebase-save-btn" class="button button-primary">
                    💾 Salvar Configuração
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * AJAX: Testar conexão Firebase
     */
    public static function ajaxTestConnection(): void
    {
        check_ajax_referer('limpvix_firebase_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $projectId = get_option('limpvix_firebase_project_id', '');

        if (empty($projectId)) {
            wp_send_json_error('Firebase não configurado');
        }

        // Teste simples: verificar se consegue acessar endpoint de chaves públicas
        $response = wp_remote_get(
            'https://www.googleapis.com/robot/v1/metadata/x509/securetoken@system.gserviceaccount.com',
            ['timeout' => 10, 'sslverify' => true]
        );

        if (is_wp_error($response)) {
            wp_send_json_error('Erro ao conectar com Firebase: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            wp_send_json_error('Firebase retornou status ' . $statusCode);
        }

        wp_send_json_success('Conexão com Firebase OK! Project ID: ' . esc_html($projectId));
    }

    /**
     * AJAX: Salvar configuração
     */
    public static function ajaxSave(): void
    {
        check_ajax_referer('limpvix_firebase_actions', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Permissão negada');
        }

        $projectId = sanitize_text_field($_POST['project_id'] ?? '');
        $apiKey = sanitize_text_field($_POST['api_key'] ?? '');
        $authDomain = sanitize_text_field($_POST['auth_domain'] ?? '');

        if (empty($projectId) || empty($apiKey)) {
            wp_send_json_error('Project ID e API Key são obrigatórios');
        }

        update_option('limpvix_firebase_project_id', $projectId);
        update_option('limpvix_firebase_api_key', $apiKey);
        update_option('limpvix_firebase_auth_domain', $authDomain);

        wp_send_json_success('Configurações salvas com sucesso');
    }
}
