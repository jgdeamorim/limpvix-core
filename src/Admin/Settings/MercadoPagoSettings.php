<?php
/**
 * MercadoPagoSettings - Interface Ultra Moderna de Configuração
 * 
 * Detecta automaticamente o plugin oficial woocommerce-mercadopago
 * e sincroniza credenciais sem necessidade de OAuth manual
 * 
 * @package LimpVix\Admin\Settings
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class MercadoPagoSettings
{
    /**
     * Registra hooks
     */
    public static function registerHooks(): void
    {
        add_action('wp_ajax_limpvix_mp_toggle_environment', [__CLASS__, 'ajaxToggleEnvironment']);
        add_action('wp_ajax_limpvix_mp_check_sync', [__CLASS__, 'ajaxCheckSync']);
        add_action('wp_ajax_limpvix_mp_test_connection', [__CLASS__, 'ajaxTestConnection']);
        add_action('wp_ajax_limpvix_mp_manual_sync', [__CLASS__, 'ajaxManualSync']);
    }

    /**
     * Verifica se MP está ativo/conectado
     */
    public static function isActive(): bool
    {
        return MercadoPagoDetector::isOfficialPluginConnected();
    }

    /**
     * Enfileira assets inline
     */
    private static function enqueueAssetsInline(): void
    {
        $baseUrl = plugin_dir_url(__FILE__) . 'assets/';
        ?>
        <link rel="stylesheet" href="<?php echo esc_url($baseUrl . 'css/limpvix-mp.css?v=1.0.1'); ?>" />
        <script>
        var limpvixMPData = {
            nonce: '<?php echo wp_create_nonce('limpvix_mp_actions'); ?>',
            ajaxUrl: '<?php echo admin_url('admin-ajax.php'); ?>'
        };
        </script>
        <script src="<?php echo esc_url($baseUrl . 'js/limpvix-mp.js?v=1.0.1'); ?>"></script>
        <?php
    }

    /**
     * Renderiza página principal
     */
    public static function render(): void
    {
        // Enfileirar assets inline
        self::enqueueAssetsInline();
        
        $isInstalled = MercadoPagoDetector::isOfficialPluginInstalled();
        $isActive = MercadoPagoDetector::isOfficialPluginActive();
        $isConnected = MercadoPagoDetector::isOfficialPluginConnected();
        
        // Renderiza conforme estado do plugin
        if (!$isInstalled) {
            self::renderInstallNotice();
        } elseif (!$isActive) {
            self::renderActivateNotice();
        } elseif (!$isConnected) {
            self::renderConnectNotice();
        } else {
            self::renderConnectedDashboard();
        }
    }

    /**
     * Notificação: Plugin não instalado
     */
    private static function renderInstallNotice(): void
    {
        $installUrl = MercadoPagoDetector::getPluginInstallUrl();
        ?>
        <div class="limpvix-mp-notice notice-warning limpvix-mp-fade-in">
            <div class="limpvix-mp-notice-icon">
                <span class="dashicons dashicons-download"></span>
            </div>
            <div class="limpvix-mp-notice-content">
                <h3>Plugin Mercado Pago não encontrado</h3>
                <p>Para usar a integração com Mercado Pago, você precisa instalar o plugin oficial <strong>WooCommerce Mercado Pago</strong>.</p>
                
                <h4>Como instalar:</h4>
                <ol>
                    <li>Clique no botão "Instalar Plugin" abaixo</li>
                    <li>Procure por "Mercado Pago payments for WooCommerce"</li>
                    <li>Clique em "Instalar Agora" e depois em "Ativar"</li>
                    <li>Retorne a esta página para continuar a configuração</li>
                </ol>

                <a href="<?php echo esc_url($installUrl); ?>" class="limpvix-mp-btn limpvix-mp-btn-primary">
                    <span class="dashicons dashicons-download"></span>
                    Instalar Plugin Oficial
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Notificação: Plugin instalado mas não ativo
     */
    private static function renderActivateNotice(): void
    {
        $pluginsUrl = admin_url('plugins.php');
        ?>
        <div class="limpvix-mp-notice notice-warning limpvix-mp-fade-in">
            <div class="limpvix-mp-notice-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <div class="limpvix-mp-notice-content">
                <h3>Plugin Mercado Pago não está ativo</h3>
                <p>O plugin <strong>WooCommerce Mercado Pago</strong> está instalado mas não ativado.</p>
                
                <h4>Como ativar:</h4>
                <ol>
                    <li>Clique no botão "Ir para Plugins" abaixo</li>
                    <li>Localize "Mercado Pago payments for WooCommerce"</li>
                    <li>Clique em "Ativar"</li>
                    <li>Retorne a esta página</li>
                </ol>

                <a href="<?php echo esc_url($pluginsUrl); ?>" class="limpvix-mp-btn limpvix-mp-btn-primary">
                    <span class="dashicons dashicons-admin-plugins"></span>
                    Ir para Plugins
                </a>
            </div>
        </div>
        <?php
    }

    /**
     * Notificação: Plugin ativo mas não conectado
     */
    private static function renderConnectNotice(): void
    {
        $settingsUrl = MercadoPagoDetector::getOfficialPluginSettingsUrl();
        ?>
        <div class="limpvix-mp-notice notice-info limpvix-mp-fade-in">
            <div class="limpvix-mp-notice-icon">
                <span class="dashicons dashicons-admin-network"></span>
            </div>
            <div class="limpvix-mp-notice-content">
                <h3>Conecte sua conta Mercado Pago</h3>
                <p>O plugin está instalado e ativo, mas você ainda não conectou sua conta Mercado Pago.</p>
                
                <h4>Passo a passo para conectar:</h4>
                <ol>
                    <li>Clique no botão "Conectar Conta MP" abaixo</li>
                    <li>Na tela de configurações do Mercado Pago, clique em "Conectar conta"</li>
                    <li>Faça login na sua conta Mercado Pago</li>
                    <li>Autorize a integração</li>
                    <li>Aguarde a sincronização automática (acontece em até 5 minutos)</li>
                    <li>Retorne a esta página para ver suas credenciais</li>
                </ol>

                <a href="<?php echo esc_url($settingsUrl); ?>" class="limpvix-mp-btn limpvix-mp-btn-primary">
                    <span class="dashicons dashicons-admin-network"></span>
                    Conectar Conta MP
                </a>
                
                <button type="button" class="limpvix-mp-btn limpvix-mp-btn-secondary limpvix-mp-manual-sync" style="margin-left: 12px;">
                    <span class="dashicons dashicons-update"></span>
                    Verificar Sincronização
                </button>
            </div>
        </div>
        <?php
    }

    /**
     * Dashboard: Conectado e funcionando
     */
    private static function renderConnectedDashboard(): void
    {
        $status = get_option('limpvix_mp_status', []);
        $environment = $status['environment'] ?? 'test';
        $lastSync = $status['last_sync'] ?? 0;
        $userInfo = MercadoPagoDetector::getUserInfo();
        $credentials = MercadoPagoDetector::getOfficialPluginCredentials();
        $siteId = $credentials['site_id'] ?? '';
        $countryName = MercadoPagoDetector::getCountryName($siteId);

        ?>
        <!-- Status Card -->
        <div class="limpvix-mp-status-card limpvix-mp-fade-in">
            <div class="limpvix-mp-status-header">
                <div class="limpvix-mp-status-title">
                    <span class="dashicons dashicons-yes-alt"></span>
                    <h2>Status da Integração Mercado Pago</h2>
                </div>
                <span class="limpvix-mp-badge limpvix-mp-badge-success">
                    <span class="dashicons dashicons-yes-alt"></span>
                    Conectado, Testado e Ativo
                </span>
            </div>

            <!-- Informações da Conta -->
            <div class="limpvix-mp-account-info">
                <div class="limpvix-mp-info-item">
                    <div class="limpvix-mp-info-label">Nome da Conta</div>
                    <div class="limpvix-mp-info-value">
                        <span class="dashicons dashicons-businessperson"></span>
                        <?php echo esc_html($userInfo['name']); ?>
                    </div>
                </div>
                <div class="limpvix-mp-info-item">
                    <div class="limpvix-mp-info-label">E-mail</div>
                    <div class="limpvix-mp-info-value">
                        <span class="dashicons dashicons-email"></span>
                        <?php echo esc_html($userInfo['email'] ?: 'Não disponível'); ?>
                    </div>
                </div>
                <div class="limpvix-mp-info-item">
                    <div class="limpvix-mp-info-label">ID do Usuário</div>
                    <div class="limpvix-mp-info-value">
                        <span class="dashicons dashicons-id"></span>
                        <?php echo esc_html($userInfo['user_id'] ?: 'Não disponível'); ?>
                    </div>
                </div>
                <div class="limpvix-mp-info-item">
                    <div class="limpvix-mp-info-label">País / Site ID</div>
                    <div class="limpvix-mp-info-value">
                        <span class="dashicons dashicons-admin-site"></span>
                        <?php echo esc_html($countryName . ' (' . $siteId . ')'); ?>
                    </div>
                </div>
            </div>

            <!-- Sincronização Status -->
            <?php if ($lastSync > 0): ?>
            <div class="limpvix-mp-sync-status">
                <span class="dashicons dashicons-update limpvix-mp-sync-icon"></span>
                <span class="limpvix-mp-sync-text">
                    Sincronização automática ativa (a cada 5 minutos)
                </span>
                <span class="limpvix-mp-sync-time">
                    Última sincronização: <?php echo esc_html(human_time_diff($lastSync) . ' atrás'); ?>
                </span>
            </div>
            <?php endif; ?>
        </div>

        <!-- Seletor de Ambiente -->
        <div class="limpvix-mp-environment limpvix-mp-fade-in">
            <h3>
                <span class="dashicons dashicons-admin-tools"></span>
                Ambiente Ativo
            </h3>
            <p style="margin-bottom: 16px; color: #666;">Selecione qual ambiente deseja usar:</p>
            
            <div class="limpvix-mp-env-toggle">
                <button 
                    type="button" 
                    class="limpvix-mp-env-btn <?php echo $environment === 'test' ? 'active' : ''; ?>"
                    data-env="test"
                >
                    <span class="dashicons dashicons-flag"></span>
                    Teste
                </button>
                <button 
                    type="button" 
                    class="limpvix-mp-env-btn <?php echo $environment === 'production' ? 'active' : ''; ?>"
                    data-env="production"
                >
                    <span class="dashicons dashicons-yes-alt"></span>
                    Produção
                </button>
            </div>
        </div>

        <!-- Credenciais -->
        <div class="limpvix-mp-credentials limpvix-mp-fade-in">
            <h3>
                <span class="dashicons dashicons-lock"></span>
                Credenciais Sincronizadas (<?php echo $environment === 'test' ? 'Teste' : 'Produção'; ?>)
            </h3>
            
            <div class="limpvix-mp-cred-grid">
                <div class="limpvix-mp-cred-item">
                    <label class="limpvix-mp-cred-label">Access Token</label>
                    <div class="limpvix-mp-cred-value">
                        <input 
                            type="password" 
                            value="<?php echo esc_attr($credentials['access_token_' . ($environment === 'test' ? 'test' : 'prod')]); ?>" 
                            readonly
                        />
                        <button type="button" class="limpvix-mp-toggle-visibility" title="Mostrar">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                </div>

                <div class="limpvix-mp-cred-item">
                    <label class="limpvix-mp-cred-label">Public Key</label>
                    <div class="limpvix-mp-cred-value">
                        <input 
                            type="password" 
                            value="<?php echo esc_attr($credentials['public_key_' . ($environment === 'test' ? 'test' : 'prod')]); ?>" 
                            readonly
                        />
                        <button type="button" class="limpvix-mp-toggle-visibility" title="Mostrar">
                            <span class="dashicons dashicons-visibility"></span>
                        </button>
                    </div>
                </div>
            </div>

            <p style="margin-top: 20px; color: #666; font-size: 14px;">
                <span class="dashicons dashicons-info"></span>
                Estas credenciais são sincronizadas automaticamente do plugin oficial WooCommerce Mercado Pago.
                Para alterar, acesse as 
                <a href="<?php echo esc_url(MercadoPagoDetector::getOfficialPluginSettingsUrl()); ?>">
                    configurações do Mercado Pago
                </a>.
            </p>
        </div>
        <?php
    }

    /**
     * AJAX: Toggle ambiente
     */
    public static function ajaxToggleEnvironment(): void
    {
        check_ajax_referer('limpvix_mp_actions', 'nonce');

        $environment = sanitize_text_field($_POST['environment'] ?? '');

        if (!in_array($environment, ['test', 'production'], true)) {
            wp_send_json_error(['message' => 'Ambiente inválido']);
        }

        $status = get_option('limpvix_mp_status', []);
        $status['environment'] = $environment;
        update_option('limpvix_mp_status', $status, false);

        wp_send_json_success(['environment' => $environment]);
    }

    /**
     * AJAX: Verifica status de sincronização
     */
    public static function ajaxCheckSync(): void
    {
        check_ajax_referer('limpvix_mp_actions', 'nonce');

        $status = get_option('limpvix_mp_status', []);
        wp_send_json_success($status);
    }

    /**
     * AJAX: Testa conexão
     */
    public static function ajaxTestConnection(): void
    {
        check_ajax_referer('limpvix_mp_actions', 'nonce');

        if (!MercadoPagoDetector::isOfficialPluginConnected()) {
            wp_send_json_error(['message' => 'Plugin não conectado']);
        }

        $credentials = MercadoPagoDetector::getOfficialPluginCredentials();
        $status = get_option('limpvix_mp_status', []);
        $environment = $status['environment'] ?? 'test';
        $tokenKey = 'access_token_' . ($environment === 'test' ? 'test' : 'prod');

        $response = wp_remote_get('https://api.mercadopago.com/v1/users/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials[$tokenKey],
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            wp_send_json_error(['message' => 'Erro na conexão: ' . $response->get_error_message()]);
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code === 200) {
            wp_send_json_success(['message' => 'Conexão testada com sucesso!']);
        } else {
            wp_send_json_error(['message' => 'Erro HTTP ' . $code]);
        }
    }

    /**
     * AJAX: Sincronização manual
     */
    public static function ajaxManualSync(): void
    {
        check_ajax_referer('limpvix_mp_actions', 'nonce');

        $result = MercadoPagoDetector::syncCredentials();

        if ($result) {
            wp_send_json_success(['message' => 'Credenciais sincronizadas!']);
        } else {
            wp_send_json_error(['message' => 'Plugin oficial não está conectado']);
        }
    }
}
