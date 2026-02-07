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
            <?php if ($isConfigured): ?>
                <?php self::renderConnectedState($projectId, $apiKey, $authDomain); ?>
            <?php else: ?>
                <?php self::renderConfigureState($projectId, $apiKey, $authDomain); ?>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            // Toggle edição
            $('#limpvix-firebase-edit-btn').on('click', function() {
                $('.limpvix-firebase-readonly').hide();
                $('.limpvix-firebase-form').show();
                $(this).hide();
            });

            // Cancelar edição
            $('#limpvix-firebase-cancel-btn').on('click', function() {
                $('.limpvix-firebase-form').hide();
                $('.limpvix-firebase-readonly').show();
                $('#limpvix-firebase-edit-btn').show();
            });

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
        .limpvix-firebase-readonly { margin-bottom: 20px; }
        .limpvix-firebase-form { display: none; margin-bottom: 20px; }
        .limpvix-firebase-info-box {
            background: #f0f6fc;
            border-left: 4px solid #0073aa;
            padding: 15px;
            margin: 15px 0;
        }
        .limpvix-firebase-info-box h4 {
            margin: 0 0 10px 0;
            color: #0073aa;
        }
        .limpvix-firebase-info-box ol {
            margin: 10px 0 0 20px;
        }
        .limpvix-firebase-status {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 8px 16px;
            border-radius: 4px;
            font-weight: 500;
        }
        .limpvix-firebase-status.connected {
            background: #d4edda;
            color: #155724;
        }
        .limpvix-firebase-status.disconnected {
            background: #fff3cd;
            color: #856404;
        }
        .limpvix-firebase-credential {
            font-family: monospace;
            background: #f5f5f5;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 13px;
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
     * Renderiza estado CONECTADO
     */
    private static function renderConnectedState(string $projectId, string $apiKey, string $authDomain): void
    {
        ?>
        <div class="limpvix-firebase-readonly">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                <div>
                    <span class="limpvix-firebase-status connected">
                        <span class="dashicons dashicons-yes-alt"></span>
                        Firebase Configurado
                    </span>
                </div>
                <button type="button" id="limpvix-firebase-edit-btn" class="button">
                    <span class="dashicons dashicons-edit"></span> Editar Configuração
                </button>
            </div>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">Project ID:</th>
                    <td>
                        <code class="limpvix-firebase-credential"><?php echo esc_html($projectId); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">API Key:</th>
                    <td>
                        <code class="limpvix-firebase-credential"><?php echo esc_html(substr($apiKey, 0, 20) . '...'); ?></code>
                    </td>
                </tr>
                <tr>
                    <th scope="row">Auth Domain:</th>
                    <td>
                        <code class="limpvix-firebase-credential"><?php echo esc_html($authDomain ?: $projectId . '.firebaseapp.com'); ?></code>
                    </td>
                </tr>
            </table>

            <div style="margin-top: 20px;">
                <button type="button" id="limpvix-firebase-test-btn" class="button button-secondary">
                    <span class="dashicons dashicons-yes"></span> Testar Conexão
                </button>
                <div id="limpvix-firebase-test-result" style="margin-top: 10px;"></div>
            </div>
        </div>
        <?php
        self::renderConfigForm($projectId, $apiKey, $authDomain);
    }

    /**
     * Renderiza estado NÃO CONFIGURADO
     */
    private static function renderConfigureState(string $projectId, string $apiKey, string $authDomain): void
    {
        ?>
        <div style="margin-bottom: 20px;">
            <span class="limpvix-firebase-status disconnected">
                <span class="dashicons dashicons-warning"></span>
                Firebase não configurado
            </span>
        </div>

        <div class="limpvix-firebase-info-box">
            <h4>🔥 Como configurar Firebase Authentication:</h4>
            <ol>
                <li>Acesse <a href="https://console.firebase.google.com" target="_blank" rel="noopener">Firebase Console</a></li>
                <li>Crie um projeto ou selecione um existente</li>
                <li>Vá em <strong>Authentication → Sign-in method</strong></li>
                <li>Ative <strong>Phone</strong> como método de autenticação</li>
                <li>Em <strong>Project Settings → General</strong>, copie:
                    <ul>
                        <li>Project ID</li>
                        <li>Web API Key</li>
                    </ul>
                </li>
                <li>Cole os valores nos campos abaixo e salve</li>
            </ol>
        </div>

        <?php
        self::renderConfigForm($projectId, $apiKey, $authDomain, true);
    }

    /**
     * Renderiza formulário de configuração
     */
    private static function renderConfigForm(string $projectId, string $apiKey, string $authDomain, bool $visible = false): void
    {
        ?>
        <div class="limpvix-firebase-form" style="<?php echo $visible ? '' : 'display:none;'; ?>">
            <div id="limpvix-firebase-feedback"></div>

            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row">
                        <label for="firebase_project_id">Project ID *</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_project_id"
                               name="firebase_project_id"
                               value="<?php echo esc_attr($projectId); ?>"
                               class="regular-text"
                               placeholder="my-project-123456">
                        <p class="description">
                            ID do projeto Firebase (encontrado no Console Firebase)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="firebase_api_key">Web API Key *</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_api_key"
                               name="firebase_api_key"
                               value="<?php echo esc_attr($apiKey); ?>"
                               class="regular-text"
                               placeholder="AIzaSy...">
                        <p class="description">
                            Web API Key (encontrado em Project Settings → General)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="firebase_auth_domain">Auth Domain</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_auth_domain"
                               name="firebase_auth_domain"
                               value="<?php echo esc_attr($authDomain); ?>"
                               class="regular-text"
                               placeholder="my-project-123456.firebaseapp.com">
                        <p class="description">
                            Domínio de autenticação (geralmente: {projectId}.firebaseapp.com). Deixe vazio para usar padrão.
                        </p>
                    </td>
                </tr>
            </table>

            <div style="margin-top: 20px;">
                <button type="button" id="limpvix-firebase-save-btn" class="button button-primary">
                    💾 Salvar Configuração
                </button>
                <?php if (self::isConfigured()): ?>
                    <button type="button" id="limpvix-firebase-cancel-btn" class="button" style="margin-left: 10px;">
                        Cancelar
                    </button>
                <?php endif; ?>
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
