<?php

namespace LimpVix\Admin\Settings\Tabs;

use LimpVix\Admin\Settings\FirebaseSettings;
use LimpVix\Admin\Settings\GoogleBusinessSettings;
use LimpVix\Admin\Settings\NVoipSettings;
use LimpVix\Admin\Settings\MercadoPagoDetector;

defined('ABSPATH') || exit;

class ConexoesTab implements SettingsTabInterface
{
    public function getSlug(): string { return 'conexoes'; }
    public function getLabel(): string { return 'Conexoes'; }
    public function getIcon(): string { return '🔌'; }

    public function handleSave(): void
    {
        // Processar salvamento Twilio OTP
        if (isset($_POST['limpvix_save_twilio_settings'])) {
            \LimpVix\Admin\Settings\TwilioSettings::save();
        }

        // Processar salvamento Exato Digital
        if (isset($_POST['limpvix_save_exato_settings']) && check_admin_referer('limpvix_exato_settings')) {
            update_option('limpvix_exato_api_key',  sanitize_text_field($_POST['exato_api_key']  ?? ''));
            update_option('limpvix_exato_token',    sanitize_text_field($_POST['exato_token']    ?? ''));
            update_option('limpvix_exato_endpoint', esc_url_raw($_POST['exato_endpoint']         ?? 'https://api.exatodigital.com.br/v1'));
            wp_redirect(add_query_arg(['page' => 'limpvix-settings', 'tab' => 'conexoes', 'updated' => '1'], admin_url('admin.php')));
            exit;
        }
    }

    public function render(): void
    {
        // Detectar status das conexões
        $firebaseConfigured = !empty(get_option('limpvix_firebase_api_key'));
        $googleBusinessConfigured = !empty(get_option('limpvix_google_business_api_key'));
        $nvoipConfigured = !empty(get_option('limpvix_nvoip_api_key'));
        $ppidConfigured = !empty(get_option('limpvix_ppid_api_key'));
        $twilioConfigured = !empty(get_option('limpvix_twilio_account_sid')) && !empty(get_option('limpvix_twilio_auth_token'));
        $exatoConfigured = !empty(get_option('limpvix_exato_api_key')) && !empty(get_option('limpvix_exato_token'));

        // Contar conexões ativas
        $totalConnections = 6;
        $activeConnections = 0;
        if ($firebaseConfigured) $activeConnections++;
        if ($googleBusinessConfigured) $activeConnections++;
        if ($nvoipConfigured) $activeConnections++;
        if ($ppidConfigured) $activeConnections++;
        if ($twilioConfigured) $activeConnections++;
        if ($exatoConfigured) $activeConnections++;

        // Determinar provider OTP ativo
        $activeOtpProvider = 'Nenhum';
        if ($twilioConfigured && !$nvoipConfigured) {
            $activeOtpProvider = 'Twilio';
        } elseif ($nvoipConfigured && !$twilioConfigured) {
            $activeOtpProvider = 'NVoip';
        } elseif ($twilioConfigured && $nvoipConfigured) {
            $activeOtpProvider = get_option('limpvix_active_sms_provider') === 'twilio' ? 'Twilio' : 'NVoip';
        }

        ?>
        <!-- Hero Card -->
        <div class="limpvix-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; margin-bottom: 20px; border: none; box-shadow: 0 10px 40px rgba(102, 126, 234, 0.3);">
            <div style="padding: 30px;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 20px;">
                    <div>
                        <h1 style="color: white; margin: 0 0 10px 0; font-size: 28px; font-weight: 600;">
                            🔌 Conexões & Integrações
                        </h1>
                        <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 16px;">
                            Gerencie todas as integrações externas do sistema LimpVix
                        </p>
                    </div>
                    <div style="text-align: right;">
                        <div style="background: rgba(255, 255, 255, 0.2); backdrop-filter: blur(10px); padding: 15px 25px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.3);">
                            <div style="font-size: 32px; font-weight: 700; color: white; line-height: 1;">
                                <?php echo $activeConnections; ?>/<?php echo $totalConnections; ?>
                            </div>
                            <div style="font-size: 12px; color: rgba(255, 255, 255, 0.8); margin-top: 5px; font-weight: 500;">
                                Serviços Ativos
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quick Stats Grid -->
                <div style="display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-top: 25px;">
                    <!-- Firebase -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2); text-align: center;">
                        <div style="font-size: 28px; margin-bottom: 6px;">🔥</div>
                        <div style="font-size: 13px; font-weight: 600; color: white; margin-bottom: 4px;">Firebase Auth</div>
                        <div style="font-size: 11px; color: rgba(255, 255, 255, 0.8);">
                            <?php echo $firebaseConfigured ? '<span style="color: #4ade80;">✓ Configurado</span>' : '<span style="color: #fbbf24;">⚠ Pendente</span>'; ?>
                        </div>
                    </div>

                    <!-- Google Business -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2); text-align: center;">
                        <div style="font-size: 28px; margin-bottom: 6px;">🏢</div>
                        <div style="font-size: 13px; font-weight: 600; color: white; margin-bottom: 4px;">Google Business</div>
                        <div style="font-size: 11px; color: rgba(255, 255, 255, 0.8);">
                            <?php echo $googleBusinessConfigured ? '<span style="color: #4ade80;">✓ Configurado</span>' : '<span style="color: #fbbf24;">⚠ Pendente</span>'; ?>
                        </div>
                    </div>

                    <!-- PPID KYC -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2); text-align: center;">
                        <div style="font-size: 28px; margin-bottom: 6px;">🔐</div>
                        <div style="font-size: 13px; font-weight: 600; color: white; margin-bottom: 4px;">PPID KYC</div>
                        <div style="font-size: 11px; color: rgba(255, 255, 255, 0.8);">
                            <?php echo $ppidConfigured ? '<span style="color: #4ade80;">✓ Configurado</span>' : '<span style="color: #fbbf24;">⚠ Pendente</span>'; ?>
                        </div>
                    </div>

                    <!-- Exato Digital -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2); text-align: center;">
                        <div style="font-size: 28px; margin-bottom: 6px;">🕵️</div>
                        <div style="font-size: 13px; font-weight: 600; color: white; margin-bottom: 4px;">Exato Digital</div>
                        <div style="font-size: 11px; color: rgba(255, 255, 255, 0.8);">
                            <?php echo $exatoConfigured ? '<span style="color: #4ade80;">✓ Configurado</span>' : '<span style="color: #fbbf24;">⚠ Pendente</span>'; ?>
                        </div>
                    </div>

                    <!-- OTP Provider Ativo -->
                    <div style="background: rgba(255, 255, 255, 0.15); backdrop-filter: blur(10px); padding: 16px; border-radius: 12px; border: 1px solid rgba(255, 255, 255, 0.2); text-align: center;">
                        <div style="font-size: 28px; margin-bottom: 6px;">📱</div>
                        <div style="font-size: 13px; font-weight: 600; color: white; margin-bottom: 4px;">OTP Provider</div>
                        <div style="font-size: 11px; color: rgba(255, 255, 255, 0.8);">
                            <strong><?php echo $activeOtpProvider; ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Grid 1: Firebase + Google Meu Negócio -->
        <div class="limpvix-grid limpvix-grid-2">
            <!-- Firebase Authentication -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #f59e0b 0%, #dc2626 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        🔥 Firebase Authentication
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        SMS OTP para verificação de telefone
                    </p>
                    <div style="margin-top: 12px; display: inline-block; padding: 6px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <?php echo $firebaseConfigured ? '✓ Configurado' : '⚠ Não Configurado'; ?>
                    </div>
                </div>
                <div class="limpvix-card-body">
                    <?php FirebaseSettings::render(); ?>
                </div>
            </div>

            <!-- Google Meu Negócio -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #4285f4 0%, #ea4335 50%, #fbbc04 75%, #34a853 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        🔍 Google Meu Negócio
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        Integração para convites de avaliação
                    </p>
                    <div style="margin-top: 12px; display: inline-block; padding: 6px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <?php echo $googleBusinessConfigured ? '✓ Configurado' : '⚠ Não Configurado'; ?>
                    </div>
                </div>
                <div class="limpvix-card-body">
                    <?php GoogleBusinessSettings::render(); ?>
                </div>
            </div>
        </div>

        <!-- Grid 2: NVoip + PPID -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- NVoip OTP Settings -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #06b6d4 0%, #3b82f6 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        📞 NVoip OTP Provider
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        SMS, WhatsApp e Voz para OTP
                    </p>
                    <div style="margin-top: 12px; display: inline-block; padding: 6px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <?php echo $nvoipConfigured ? '✓ Configurado' : '⚠ Não Configurado'; ?>
                    </div>
                </div>
                <div class="limpvix-card-body">
                    <?php NVoipSettings::render(); ?>
                </div>
            </div>

            <!-- PPID KYC Settings -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #8b5cf6 0%, #ec4899 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        🔐 PPID KYC Biométrico
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        Verificação de identidade com OCR + Liveness + Face Match
                    </p>
                    <div style="margin-top: 12px; display: inline-block; padding: 6px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <?php echo $ppidConfigured ? '✓ Configurado' : '⚠ Não Configurado'; ?>
                    </div>
                </div>
                <div class="limpvix-card-body">
                    <?php \LimpVix\Admin\Settings\PPIDSettings::render(); ?>
                </div>
            </div>
        </div>

        <!-- Grid 3: Twilio OTP + Teste Manual -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <!-- Twilio OTP Provider -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #ef4444 0%, #b91c1c 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        📲 Twilio OTP Provider
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        SMS, WhatsApp e Chamada de Voz
                    </p>
                    <div style="margin-top: 12px; display: inline-block; padding: 6px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <?php echo $twilioConfigured ? '✓ Configurado' : '⚠ Não Configurado'; ?>
                    </div>
                </div>
                <div class="limpvix-card-body">
                    <?php \LimpVix\Admin\Settings\TwilioSettings::render(); ?>
                </div>
            </div>

            <!-- Teste Manual de SMS OTP -->
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #10b981 0%, #059669 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        🧪 Teste Manual de SMS OTP
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.9); margin: 0; font-size: 14px;">
                        Envie OTP de teste para qualquer telefone
                    </p>
                </div>
                <div class="limpvix-card-body">
                    <p><strong>Envie um código OTP de teste para qualquer telefone:</strong></p>

                    <table class="form-table" style="margin-top: 15px;">
                        <tr>
                            <th style="width: 180px;">
                                <label for="test_otp_phone">Telefone:</label>
                            </th>
                            <td>
                                <input type="text"
                                       id="test_otp_phone"
                                       class="regular-text"
                                       placeholder="+5527999999999"
                                       value="+5527999652302">
                                <p class="description">Formato: +55 (DDD) NÚMERO</p>
                            </td>
                        </tr>
                        <tr>
                            <th>
                                <label for="test_otp_channel">Canal:</label>
                            </th>
                            <td>
                                <select id="test_otp_channel" class="regular-text">
                                    <option value="sms">SMS</option>
                                    <option value="whatsapp">WhatsApp</option>
                                    <option value="call">Chamada de Voz</option>
                                </select>
                            </td>
                        </tr>
                    </table>

                    <p style="margin-top: 20px;">
                        <button type="button" id="btn-test-otp-send" class="button button-primary button-large">
                            📤 Enviar Código de Teste
                        </button>
                    </p>

                    <!-- Resultado do Teste -->
                    <div id="test-otp-result" style="margin-top: 20px;"></div>

                    <div style="margin-top: 20px; padding: 12px; background: #f0f9ff; border-left: 4px solid #3b82f6; border-radius: 4px;">
                        <h4 style="margin-top: 0; color: #1e40af;">💡 Como Funciona</h4>
                        <ol style="margin: 8px 0; color: #1e40af;">
                            <li>Digite o telefone no formato internacional (+55...)</li>
                            <li>Escolha o canal (SMS, WhatsApp ou Voz)</li>
                            <li>Clique em "Enviar Código de Teste"</li>
                            <li>Aguarde 5-10 segundos</li>
                            <li>Verifique o código recebido no telefone</li>
                        </ol>
                        <p style="margin: 8px 0 0 0; color: #1e40af;">
                            <strong>Custo:</strong> ~$0.05/SMS | ~$0.005/WhatsApp
                        </p>
                    </div>
                </div>
            </div>

            <script>
            jQuery(document).ready(function($) {
                $('#btn-test-otp-send').on('click', function() {
                    var btn = $(this);
                    var phone = $('#test_otp_phone').val().trim();
                    var channel = $('#test_otp_channel').val();
                    var resultDiv = $('#test-otp-result');

                    // Validação básica
                    if (!phone || phone.length < 10) {
                        resultDiv.html(
                            '<div class="notice notice-error"><p>' +
                            '❌ <strong>Erro:</strong> Digite um telefone válido no formato +55XXXXXXXXXXX' +
                            '</p></div>'
                        );
                        return;
                    }

                    // Disable button
                    btn.prop('disabled', true).text('⏳ Enviando...');
                    resultDiv.html(
                        '<div class="notice notice-info"><p>' +
                        '📡 Enviando código OTP via ' + channel.toUpperCase() + ' para ' + phone + '...' +
                        '</p></div>'
                    );

                    // AJAX call to test endpoint
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'limpvix_test_otp_send',
                            phone: phone,
                            channel: channel,
                            _wpnonce: '<?php echo wp_create_nonce("test_otp_send"); ?>'
                        },
                        success: function(response) {
                            if (response.success) {
                                resultDiv.html(
                                    '<div class="notice notice-success"><p>' +
                                    '✅ <strong>OTP enviado com sucesso!</strong><br>' +
                                    'Telefone: ' + phone + '<br>' +
                                    'Canal: ' + channel.toUpperCase() + '<br>' +
                                    'Key: ' + (response.data.key || 'N/A').substring(0, 30) + '...<br>' +
                                    '<br><strong>Verifique o telefone em 5-10 segundos!</strong>' +
                                    '</p></div>'
                                );
                            } else {
                                resultDiv.html(
                                    '<div class="notice notice-error"><p>' +
                                    '❌ <strong>Erro ao enviar:</strong><br>' +
                                    (response.data && response.data.message ? response.data.message : 'Erro desconhecido') +
                                    '</p></div>'
                                );
                            }
                        },
                        error: function(xhr) {
                            var error = 'Erro de conexão';
                            try {
                                var resp = JSON.parse(xhr.responseText);
                                error = resp.data && resp.data.message ? resp.data.message : xhr.statusText;
                            } catch(e) {
                                error = xhr.statusText || error;
                            }

                            resultDiv.html(
                                '<div class="notice notice-error"><p>' +
                                '❌ <strong>Falha na requisição:</strong><br>' +
                                error +
                                '</p></div>'
                            );
                        },
                        complete: function() {
                            btn.prop('disabled', false).text('📤 Enviar Código de Teste');
                        }
                    });
                });
            });
            </script>
        </div>

        <!-- Grid 4: Exato Digital Background Check -->
        <div class="limpvix-grid limpvix-grid-2" style="margin-top: 20px;">
            <div class="limpvix-card">
                <div class="limpvix-card-header" style="background: linear-gradient(135deg, #0f172a 0%, #1e3a5f 100%); color: white; border-radius: 8px 8px 0 0; padding: 20px; border: none;">
                    <h3 style="color: white; margin: 0 0 8px 0; font-size: 20px; font-weight: 600;">
                        🕵️ Exato Digital — Background Check
                    </h3>
                    <p style="color: rgba(255, 255, 255, 0.85); margin: 0; font-size: 14px;">
                        Consulta de antecedentes criminais e restrições (LGPD Art. 7)
                    </p>
                    <div style="margin-top: 12px; display: inline-block; padding: 6px 12px; background: rgba(255, 255, 255, 0.2); border-radius: 20px; font-size: 12px; font-weight: 600;">
                        <?php echo $exatoConfigured ? '✓ Configurado' : '⚠ Não Configurado'; ?>
                    </div>
                </div>
                <div class="limpvix-card-body">
                    <?php if ($exatoConfigured): ?>
                        <div class="limpvix-alert limpvix-alert-success" style="margin-bottom: 20px;">
                            <span class="dashicons dashicons-yes-alt"></span>
                            <div>
                                <strong>✅ Exato Digital conectado</strong>
                                <p style="margin: 4px 0 0 0; font-size: 13px;">
                                    Background check real ativo. Provider mock desativado.
                                </p>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="limpvix-alert limpvix-alert-warning" style="margin-bottom: 20px;">
                            <span class="dashicons dashicons-warning"></span>
                            <div>
                                <strong>⚠️ Modo Mock Ativo</strong>
                                <p style="margin: 4px 0 0 0; font-size: 13px;">
                                    Sem credenciais, o MockBackgroundProvider aprova automaticamente.
                                    Configure abaixo para ativar o provider real.
                                </p>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="post" action="">
                        <?php wp_nonce_field('limpvix_exato_settings'); ?>
                        <table class="form-table">
                            <tr>
                                <th scope="row">
                                    <label for="exato_api_key">API Key *</label>
                                </th>
                                <td>
                                    <input type="password"
                                           id="exato_api_key"
                                           name="exato_api_key"
                                           value="<?php echo esc_attr(get_option('limpvix_exato_api_key', '')); ?>"
                                           class="regular-text"
                                           autocomplete="new-password"
                                           placeholder="Sua API Key da Exato Digital">
                                    <p class="description">Chave de API fornecida pela <a href="https://exatodigital.com.br" target="_blank">Exato Digital</a></p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="exato_token">Token *</label>
                                </th>
                                <td>
                                    <input type="password"
                                           id="exato_token"
                                           name="exato_token"
                                           value="<?php echo esc_attr(get_option('limpvix_exato_token', '')); ?>"
                                           class="regular-text"
                                           autocomplete="new-password"
                                           placeholder="Token de autenticação">
                                    <p class="description">Token de autenticação (mantenha secreto)</p>
                                </td>
                            </tr>
                            <tr>
                                <th scope="row">
                                    <label for="exato_endpoint">Endpoint</label>
                                </th>
                                <td>
                                    <input type="url"
                                           id="exato_endpoint"
                                           name="exato_endpoint"
                                           value="<?php echo esc_attr(get_option('limpvix_exato_endpoint', 'https://api.exatodigital.com.br/v1')); ?>"
                                           class="regular-text">
                                    <p class="description">URL base da API Exato Digital</p>
                                </td>
                            </tr>
                        </table>

                        <div style="margin: 16px 0; padding: 12px 16px; background: #f0f9ff; border-left: 4px solid #3b82f6; border-radius: 4px; font-size: 13px;">
                            <strong>🔒 LGPD Art. 7:</strong> Cada consulta requer consentimento explícito do profissional
                            registrado em <code>wp_limpvix_consent_records</code> (imutável).
                        </div>

                        <p>
                            <input type="hidden" name="limpvix_save_exato_settings" value="1">
                            <button type="submit" class="button button-primary">
                                💾 Salvar Exato Digital
                            </button>
                            <?php if ($exatoConfigured): ?>
                                <span style="margin-left: 10px; color: #15803d; font-size: 13px;">✅ Credenciais salvas</span>
                            <?php endif; ?>
                        </p>
                    </form>

                    <div class="limpvix-card" style="margin-top: 16px; background: #f8fafc; border-left: 4px solid #0f172a;">
                        <div class="limpvix-card-body" style="padding: 14px 18px;">
                            <h4 style="margin: 0 0 10px 0; font-size: 13px;">📘 Endpoints Utilizados</h4>
                            <table class="widefat" style="background: white; font-size: 12px;">
                                <tr>
                                    <td><strong>Antecedentes criminais</strong></td>
                                    <td><code>/v1/criminal/check</code></td>
                                </tr>
                                <tr>
                                    <td><strong>Restrições financeiras</strong></td>
                                    <td><code>/v1/financial/restrictions</code></td>
                                </tr>
                                <tr>
                                    <td><strong>Documentação</strong></td>
                                    <td><a href="https://docs.exatodigital.com.br" target="_blank">📖 Acessar Docs</a></td>
                                </tr>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Coluna vazia para manter alinhamento no grid de 2 -->
            <div></div>
        </div>
        <?php
    }

    private function getMercadoPagoConfigStatus(): array
    {
        // Verifica se WooCommerce MercadoPago está conectado (usa MercadoPagoDetector)
        $wcMPConnected = class_exists('LimpVix\\Admin\\Settings\\MercadoPagoDetector')
            && MercadoPagoDetector::isOfficialPluginConnected();

        // Credenciais sincronizadas do WooCommerce (para pagamentos de clientes)
        $status = get_option('limpvix_mp_status', []);
        $environment = $status['environment'] ?? 'test';
        $tokenKey = $environment === 'test' ? 'limpvix_mp_access_token_test' : 'limpvix_mp_access_token_prod';
        $keyKey = $environment === 'test' ? 'limpvix_mp_public_key_test' : 'limpvix_mp_public_key_prod';

        $accessToken = get_option($tokenKey);
        $publicKey = get_option($keyKey);

        // Credenciais OAuth LimpVix (para payouts profissionais MP→MP)
        $clientId = get_option('limpvix_mercadopago_client_id');
        $clientSecret = get_option('limpvix_mercadopago_client_secret');

        $platformConfigured = $wcMPConnected || (!empty($accessToken) && !empty($publicKey));
        $oauthConfigured = !empty($clientId) && !empty($clientSecret);
        $fullyConfigured = $platformConfigured && $oauthConfigured;

        $missing = [];
        if (!$platformConfigured) {
            if (!$wcMPConnected) {
                $missing[] = 'WooCommerce MP não conectado';
            }
            if (empty($accessToken)) {
                $missing[] = 'Access Token';
            }
            if (empty($publicKey)) {
                $missing[] = 'Public Key';
            }
        }
        if (!$oauthConfigured) {
            if (empty($clientId)) {
                $missing[] = 'Client ID (OAuth Profissionais)';
            }
            if (empty($clientSecret)) {
                $missing[] = 'Client Secret (OAuth Profissionais)';
            }
        }

        return [
            'platform_configured' => $platformConfigured,
            'oauth_configured' => $oauthConfigured,
            'fully_configured' => $fullyConfigured,
            'wc_mp_connected' => $wcMPConnected,
            'environment' => $environment,
            'status_icon' => $fullyConfigured ? '✓' : '⚠',
            'status_text' => $fullyConfigured
                ? 'Configurado e Ativo'
                : ($platformConfigured
                    ? 'Configuração Parcial (Falta OAuth para Profissionais)'
                    : ($wcMPConnected
                        ? 'WooCommerce MP OK - Configure OAuth Profissionais'
                        : 'Conecte WooCommerce MercadoPago')),
            'status_color' => $fullyConfigured ? '#4ade80' : '#fbbf24',
            'missing' => $missing,
        ];
    }
}
