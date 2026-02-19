<?php
namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

/**
 * PPID KYC Settings Widget
 *
 * Configurações para integração com PPID (KYC biométrico)
 * - OCR de documentos (RG/CNH)
 * - Liveness detection (prova de vida)
 * - Face match (comparação facial)
 */
class PPIDSettings
{
    /**
     * Render PPID settings widget
     */
    public static function render(): void
    {
        $email = get_option('limpvix_ppid_email', '');
        $senha = get_option('limpvix_ppid_senha', '');
        $enabled = get_option('limpvix_ppid_enabled', false);
        $saldo = get_transient('limpvix_ppid_saldo');
        $last_check = get_transient('limpvix_ppid_last_check');

        $isConfigured = !empty($email) && !empty($senha);
        ?>

        <!-- Status Badge -->
        <div style="margin-bottom: 20px;">
            <?php if ($enabled && $isConfigured): ?>
                <span class="limpvix-badge limpvix-badge-success limpvix-badge-dot">
                    ✅ KYC Ativo
                </span>
                <?php if ($saldo !== false): ?>
                    <span class="limpvix-badge limpvix-badge-info" style="margin-left: 8px;">
                        💰 Créditos: <?php echo esc_html(number_format($saldo, 2)); ?>
                    </span>
                <?php endif; ?>
            <?php elseif ($isConfigured): ?>
                <span class="limpvix-badge limpvix-badge-warning limpvix-badge-dot">
                    ⚠️ Configurado mas inativo
                </span>
            <?php else: ?>
                <span class="limpvix-badge limpvix-badge-error limpvix-badge-dot">
                    ❌ Não configurado
                </span>
            <?php endif; ?>

            <?php if ($last_check): ?>
                <span style="color: #6b7280; font-size: 12px; margin-left: 8px;">
                    Última verificação: <?php echo esc_html(human_time_diff($last_check, time())); ?> atrás
                </span>
            <?php endif; ?>
        </div>

        <!-- Alert: Importância KYC -->
        <?php if (!$enabled): ?>
            <div class="limpvix-alert limpvix-alert-warning" style="margin-bottom: 20px;">
                <span class="dashicons dashicons-warning"></span>
                <div>
                    <strong>⚠️ KYC Obrigatório para Produção</strong>
                    <p style="margin: 8px 0 0 0;">
                        Sem verificação biométrica, há <strong>risco jurídico elevado</strong> ao permitir
                        profissionais não verificados entrarem em residências de clientes.
                        KYC é fundamental para compliance e proteção da plataforma.
                    </p>
                </div>
            </div>
        <?php endif; ?>

        <!-- Configuration Form -->
        <form method="post" action="options.php" id="ppid-settings-form">
            <?php settings_fields('limpvix_ppid_settings'); ?>

            <table class="form-table">
                <!-- Enable/Disable -->
                <tr>
                    <th scope="row">
                        <label for="ppid_enabled">Ativar KYC Biométrico</label>
                    </th>
                    <td>
                        <label class="limpvix-toggle">
                            <input type="checkbox"
                                   name="limpvix_ppid_enabled"
                                   id="ppid_enabled"
                                   value="1"
                                   <?php checked($enabled, true); ?>>
                            <span class="limpvix-toggle-slider"></span>
                        </label>
                        <p class="description">
                            Quando ativo, profissionais precisam passar por verificação biométrica antes de aceitar serviços.
                        </p>
                    </td>
                </tr>

                <!-- Mock Mode -->
                <tr>
                    <th scope="row">
                        <label for="ppid_use_mock">Modo de Operação</label>
                    </th>
                    <td>
                        <select name="limpvix_ppid_use_mock" id="ppid_use_mock">
                            <option value="1" <?php selected(get_option('limpvix_ppid_use_mock', true), true); ?>>
                                🧪 Mock (Desenvolvimento) - Simula API sem consumir créditos
                            </option>
                            <option value="0" <?php selected(get_option('limpvix_ppid_use_mock', true), false); ?>>
                                🌐 Real (Produção) - Usa API PPID real
                            </option>
                        </select>
                        <p class="description">
                            <strong>Mock:</strong> Simula respostas da API PPID sem conectar ao servidor real. Ideal para desenvolvimento e testes.<br>
                            <strong>Real:</strong> Conecta à API PPID real e consome créditos. Use apenas em produção com credenciais válidas.
                        </p>
                    </td>
                </tr>

                <!-- Email -->
                <tr>
                    <th scope="row">
                        <label for="ppid_email">Email da Conta PPID *</label>
                    </th>
                    <td>
                        <input type="email"
                               name="limpvix_ppid_email"
                               id="ppid_email"
                               value="<?php echo esc_attr($email); ?>"
                               class="regular-text"
                               placeholder="seu@email.com"
                               required>
                        <p class="description">
                            Email usado para login na plataforma PPID.
                        </p>
                    </td>
                </tr>

                <!-- Senha -->
                <tr>
                    <th scope="row">
                        <label for="ppid_senha">Senha da Conta PPID *</label>
                    </th>
                    <td>
                        <input type="password"
                               name="limpvix_ppid_senha"
                               id="ppid_senha"
                               value="<?php echo esc_attr($senha); ?>"
                               class="regular-text"
                               placeholder="••••••••"
                               autocomplete="off">
                        <button type="button"
                                class="button"
                                onclick="document.getElementById('ppid_senha').type = document.getElementById('ppid_senha').type === 'password' ? 'text' : 'password'">
                            👁️ Mostrar
                        </button>
                        <p class="description">
                            Senha da sua conta PPID. Armazenada de forma segura.
                        </p>
                    </td>
                </tr>

                <!-- Test Connection Button -->
                <tr>
                    <th scope="row">Testar Conexão</th>
                    <td>
                        <button type="button"
                                id="ppid-test-connection"
                                class="button button-secondary"
                                <?php echo !$isConfigured ? 'disabled' : ''; ?>>
                            🔄 Testar Conexão e Verificar Saldo
                        </button>
                        <div id="ppid-test-result" style="margin-top: 12px;"></div>
                        <p class="description">
                            Testa autenticação e verifica saldo de créditos disponíveis.
                        </p>
                    </td>
                </tr>
            </table>

            <!-- API Info Box -->
            <div class="limpvix-card" style="margin-top: 20px; background: #f8fafc; border-left: 4px solid #3b82f6;">
                <div class="limpvix-card-body">
                    <h4 style="margin-top: 0;">📘 Informações da API PPID</h4>

                    <table class="widefat" style="background: white;">
                        <tr>
                            <td><strong>Base URL</strong></td>
                            <td><code>https://api.ppid.com.br</code></td>
                        </tr>
                        <tr>
                            <td><strong>Autenticação</strong></td>
                            <td>JWT Bearer Token (renovado automaticamente)</td>
                        </tr>
                        <tr>
                            <td><strong>Endpoints Utilizados</strong></td>
                            <td>
                                • <code>/api/auth/login</code> - Login<br>
                                • <code>/api/ocr/consultar</code> - OCR de documentos<br>
                                • <code>/api/liveness/consultar</code> - Prova de vida<br>
                                • <code>/api/facematch/consultar</code> - Comparação facial<br>
                                • <code>/api/classification/consultar</code> - Classificação de documento
                            </td>
                        </tr>
                        <tr>
                            <td><strong>Consumo de Créditos</strong></td>
                            <td>Cada chamada de API consome créditos da sua conta PPID</td>
                        </tr>
                        <tr>
                            <td><strong>Documentação</strong></td>
                            <td>
                                <a href="https://api.ppid.com.br/docs" target="_blank">
                                    📖 Acessar Documentação Completa
                                </a>
                            </td>
                        </tr>
                    </table>

                    <div style="margin-top: 16px; padding: 12px; background: #fef3c7; border-left: 3px solid #f59e0b; border-radius: 4px;">
                        <strong>💡 Dica:</strong> Monitore o saldo de créditos regularmente.
                        Configure alertas para saldo baixo em Configurações > Notificações.
                    </div>
                </div>
            </div>

            <?php submit_button('💾 Salvar Configurações PPID', 'primary', 'submit', true); ?>
        </form>

        <!-- JavaScript for Test Connection -->
        <script>
        jQuery(document).ready(function($) {
            $('#ppid-test-connection').on('click', function() {
                const $btn = $(this);
                const $result = $('#ppid-test-result');

                $btn.prop('disabled', true).text('⏳ Testando conexão...');
                $result.html('');

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'limpvix_test_ppid_connection',
                        nonce: '<?php echo wp_create_nonce('limpvix_ppid_test'); ?>',
                        email: $('#ppid_email').val(),
                        senha: $('#ppid_senha').val()
                    },
                    success: function(response) {
                        if (response.success) {
                            $result.html(`
                                <div class="limpvix-alert limpvix-alert-success">
                                    <span class="dashicons dashicons-yes-alt"></span>
                                    <div>
                                        <strong>✅ Conexão bem-sucedida!</strong>
                                        <p style="margin: 8px 0 0 0;">
                                            ${response.data.message}<br>
                                            <strong>Saldo:</strong> ${response.data.saldo} créditos<br>
                                            <strong>Nome:</strong> ${response.data.nome}
                                        </p>
                                    </div>
                                </div>
                            `);
                        } else {
                            $result.html(`
                                <div class="limpvix-alert limpvix-alert-error">
                                    <span class="dashicons dashicons-warning"></span>
                                    <div>
                                        <strong>❌ Erro na conexão</strong>
                                        <p style="margin: 8px 0 0 0;">${response.data.message}</p>
                                    </div>
                                </div>
                            `);
                        }
                    },
                    error: function() {
                        $result.html(`
                            <div class="limpvix-alert limpvix-alert-error">
                                <span class="dashicons dashicons-warning"></span>
                                <div>
                                    <strong>❌ Erro de rede</strong>
                                    <p style="margin: 8px 0 0 0;">Não foi possível conectar ao servidor.</p>
                                </div>
                            </div>
                        `);
                    },
                    complete: function() {
                        $btn.prop('disabled', false).text('🔄 Testar Conexão e Verificar Saldo');
                    }
                });
            });
        });
        </script>

        <?php
    }

    /**
     * Register settings
     */
    public static function register(): void
    {
        register_setting('limpvix_ppid_settings', 'limpvix_ppid_email', [
            'type' => 'string',
            'sanitize_callback' => 'sanitize_email',
            'default' => '',
        ]);

        register_setting('limpvix_ppid_settings', 'limpvix_ppid_senha', [
            'type' => 'string',
            'sanitize_callback' => function($value) {
                $sanitized = sanitize_text_field($value);
                if (empty($sanitized)) {
                    return '';
                }
                return \LimpVix\Infrastructure\Security\TokenEncryption::encryptSafe($sanitized);
            },
            'default' => '',
        ]);

        register_setting('limpvix_ppid_settings', 'limpvix_ppid_enabled', [
            'type' => 'boolean',
            'default' => false,
        ]);
        register_setting("limpvix_ppid_settings", "limpvix_ppid_use_mock", [            "type" => "boolean",            "default" => true,        ]);
    }
}
