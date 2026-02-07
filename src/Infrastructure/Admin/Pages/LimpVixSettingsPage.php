<?php
/**
 * LimpVixSettingsPage - Configurações Centrais do LimpVix Core
 *
 * RESPONSABILIDADE:
 * - Menu principal: LimpVix (position 3, abaixo de Dashboard)
 * - Sistema de abas: Conexões | Briefing
 * - Aba Conexões: Firebase, Google Meu Negócio, Twilio, 360Dialog
 * - Aba Briefing: Tabela m², fatores tempo, buffer, preço
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class LimpVixSettingsPage
{
    private const PAGE_SLUG = 'limpvix';
    private const OPTION_GROUP_CONNECTIONS = 'limpvix_connections';
    private const OPTION_GROUP_BRIEFING = 'limpvix_briefing_settings';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        // Menu principal LimpVix (abaixo de Dashboard)
        add_menu_page(
            'LimpVix Core',
            'LimpVix',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-admin-generic',
            3
        );

        // Submenu Configurações (mesmo slug do menu principal)
        add_submenu_page(
            self::PAGE_SLUG,
            'Configurações LimpVix',
            'Configurações',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render']
        );
    }

    public function registerSettings(): void
    {
        // Aba Conexões
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_firebase_project_id');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_firebase_api_key');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_firebase_auth_domain');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_google_mybusiness_api_key');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_twilio_account_sid');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_twilio_auth_token');
        register_setting(self::OPTION_GROUP_CONNECTIONS, 'limpvix_360dialog_api_key');

        // Aba Briefing
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_m2_table');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_time_factors');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_buffer_minutes');
        register_setting(self::OPTION_GROUP_BRIEFING, 'limpvix_briefing_price_per_m2');
    }

    public function render(): void
    {
        // Determinar aba ativa
        $activeTab = isset($_GET['tab']) ? sanitize_key($_GET['tab']) : 'connections';

        // Processar salvamento
        if (isset($_POST['submit']) && check_admin_referer('limpvix_settings_' . $activeTab)) {
            $this->handleSave($activeTab);
        }

        ?>
        <div class="wrap">
            <h1>⚙️ Configurações LimpVix Core</h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Configurações salvas com sucesso!</p>
                </div>
            <?php endif; ?>

            <!-- Sistema de Abas -->
            <h2 class="nav-tab-wrapper">
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=connections"
                   class="nav-tab <?php echo $activeTab === 'connections' ? 'nav-tab-active' : ''; ?>">
                    🔌 Conexões
                </a>
                <a href="?page=<?php echo self::PAGE_SLUG; ?>&tab=briefing"
                   class="nav-tab <?php echo $activeTab === 'briefing' ? 'nav-tab-active' : ''; ?>">
                    📋 Briefing
                </a>
            </h2>

            <form method="post" action="">
                <?php wp_nonce_field('limpvix_settings_' . $activeTab); ?>

                <?php if ($activeTab === 'connections'): ?>
                    <?php $this->renderConnectionsTab(); ?>
                <?php elseif ($activeTab === 'briefing'): ?>
                    <?php $this->renderBriefingTab(); ?>
                <?php endif; ?>

                <p class="submit">
                    <button type="submit" name="submit" class="button button-primary button-large">
                        💾 Salvar Configurações
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private function renderConnectionsTab(): void
    {
        $firebaseProjectId = get_option('limpvix_firebase_project_id', '');
        $firebaseApiKey = get_option('limpvix_firebase_api_key', '');
        $firebaseAuthDomain = get_option('limpvix_firebase_auth_domain', '');
        $googleApiKey = get_option('limpvix_google_mybusiness_api_key', '');
        $twilioSid = get_option('limpvix_twilio_account_sid', '');
        $twilioToken = get_option('limpvix_twilio_auth_token', '');
        $dialog360ApiKey = get_option('limpvix_360dialog_api_key', '');
        ?>

        <!-- Firebase Authentication -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>🔥 Firebase Authentication (SMS OTP)</h2>
            <p>Configure as credenciais do Firebase para verificação de telefone via SMS:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="firebase_project_id">Project ID *</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_project_id"
                               name="firebase_project_id"
                               value="<?php echo esc_attr($firebaseProjectId); ?>"
                               class="regular-text"
                               placeholder="my-project-123456">
                        <p class="description">
                            ID do projeto Firebase (encontrado no Console Firebase)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="firebase_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text"
                               id="firebase_api_key"
                               name="firebase_api_key"
                               value="<?php echo esc_attr($firebaseApiKey); ?>"
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
                               value="<?php echo esc_attr($firebaseAuthDomain); ?>"
                               class="regular-text"
                               placeholder="my-project-123456.firebaseapp.com">
                        <p class="description">
                            Domínio de autenticação (geralmente: {projectId}.firebaseapp.com)
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($firebaseProjectId)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>Firebase configurado!</strong> Project ID: <code><?php echo esc_html($firebaseProjectId); ?></code>
                </div>
            <?php else: ?>
                <div style="background:#fff3cd;border-left:4px solid #ffc107;padding:15px;margin-top:15px">
                    ⚠️ <strong>Firebase não configurado.</strong> O módulo Briefing funcionará, mas a verificação de telefone não estará disponível.
                </div>
            <?php endif; ?>
        </div>

        <!-- Google Meu Negócio -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>🏢 Google Meu Negócio</h2>
            <p>Integração com Google My Business para gestão de avaliações e postagens:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="google_mybusiness_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text"
                               id="google_mybusiness_api_key"
                               name="google_mybusiness_api_key"
                               value="<?php echo esc_attr($googleApiKey); ?>"
                               class="regular-text"
                               placeholder="AIza...">
                        <p class="description">
                            Google Cloud API Key com Google My Business API ativada
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($googleApiKey)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>Google Meu Negócio configurado!</strong>
                </div>
            <?php else: ?>
                <div style="background:#f8f9fa;border-left:4px solid #6c757d;padding:15px;margin-top:15px">
                    ℹ️ <strong>Opcional.</strong> Configure quando precisar da integração.
                </div>
            <?php endif; ?>
        </div>

        <!-- Twilio -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>📱 Twilio (SMS/WhatsApp)</h2>
            <p>Envio de SMS e mensagens WhatsApp via Twilio:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="twilio_account_sid">Account SID</label>
                    </th>
                    <td>
                        <input type="text"
                               id="twilio_account_sid"
                               name="twilio_account_sid"
                               value="<?php echo esc_attr($twilioSid); ?>"
                               class="regular-text"
                               placeholder="AC...">
                        <p class="description">
                            Account SID (encontrado no Console Twilio)
                        </p>
                    </td>
                </tr>
                <tr>
                    <th scope="row">
                        <label for="twilio_auth_token">Auth Token</label>
                    </th>
                    <td>
                        <input type="password"
                               id="twilio_auth_token"
                               name="twilio_auth_token"
                               value="<?php echo esc_attr($twilioToken); ?>"
                               class="regular-text">
                        <p class="description">
                            Auth Token (mantenha secreto)
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($twilioSid)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>Twilio configurado!</strong>
                </div>
            <?php else: ?>
                <div style="background:#f8f9fa;border-left:4px solid #6c757d;padding:15px;margin-top:15px">
                    ℹ️ <strong>Opcional.</strong> Configure quando precisar de SMS/WhatsApp.
                </div>
            <?php endif; ?>
        </div>

        <!-- 360Dialog -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>💬 360Dialog (WhatsApp Business API)</h2>
            <p>WhatsApp Business API oficial via 360Dialog:</p>

            <table class="form-table">
                <tr>
                    <th scope="row">
                        <label for="360dialog_api_key">API Key</label>
                    </th>
                    <td>
                        <input type="text"
                               id="360dialog_api_key"
                               name="360dialog_api_key"
                               value="<?php echo esc_attr($dialog360ApiKey); ?>"
                               class="regular-text">
                        <p class="description">
                            API Key fornecida pela 360Dialog
                        </p>
                    </td>
                </tr>
            </table>

            <?php if (!empty($dialog360ApiKey)): ?>
                <div style="background:#d4edda;border-left:4px solid #28a745;padding:15px;margin-top:15px">
                    ✅ <strong>360Dialog configurado!</strong>
                </div>
            <?php else: ?>
                <div style="background:#f8f9fa;border-left:4px solid #6c757d;padding:15px;margin-top:15px">
                    ℹ️ <strong>Opcional.</strong> Configure quando precisar de WhatsApp Business API.
                </div>
            <?php endif; ?>
        </div>

        <!-- System Info -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>ℹ️ Informações do Sistema</h2>
            <table class="form-table">
                <tr>
                    <th>Plugin Version:</th>
                    <td><code>0.2.0</code></td>
                </tr>
                <tr>
                    <th>WordPress Version:</th>
                    <td><code><?php echo get_bloginfo('version'); ?></code></td>
                </tr>
                <tr>
                    <th>PHP Version:</th>
                    <td><code><?php echo PHP_VERSION; ?></code></td>
                </tr>
                <tr>
                    <th>Feature Flags:</th>
                    <td>
                        <?php $this->renderFeatureFlags(); ?>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    private function renderBriefingTab(): void
    {
        $m2Table = $this->getM2Table();
        $timeFactors = $this->getTimeFactors();
        $bufferMinutes = get_option('limpvix_briefing_buffer_minutes', 30);
        $pricePerM2 = get_option('limpvix_briefing_price_per_m2', 15.00);
        ?>

        <!-- Tabela m² -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>📏 Tabela de m² por Cômodo</h2>
            <p>Valores usados para cálculo de área estimada do briefing:</p>
            <table class="form-table">
                <tr>
                    <th>Quarto:</th>
                    <td><input type="number" name="m2_bedroom" value="<?php echo esc_attr($m2Table['bedroom']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th>Banheiro:</th>
                    <td><input type="number" name="m2_bathroom" value="<?php echo esc_attr($m2Table['bathroom']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th>Sala:</th>
                    <td><input type="number" name="m2_living_room" value="<?php echo esc_attr($m2Table['living_room']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th>Cozinha:</th>
                    <td><input type="number" name="m2_kitchen" value="<?php echo esc_attr($m2Table['kitchen']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th>Escritório:</th>
                    <td><input type="number" name="m2_office" value="<?php echo esc_attr($m2Table['office']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
                <tr>
                    <th>Área Externa:</th>
                    <td><input type="number" name="m2_external_area" value="<?php echo esc_attr($m2Table['external_area']); ?>" step="0.1" class="small-text"> m²</td>
                </tr>
            </table>
        </div>

        <!-- Fatores de Tempo -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>⏱️ Fatores de Tempo por Tipo de Limpeza</h2>
            <p>Multiplicadores aplicados ao tempo base (ex: 0.40 = +40% de tempo):</p>
            <table class="form-table">
                <tr>
                    <th>Limpeza Pesada:</th>
                    <td>
                        <input type="number" name="time_factor_limpeza_pesada" value="<?php echo esc_attr($timeFactors['limpeza_pesada']); ?>" step="0.01" class="small-text">
                        <span class="description">(+40% padrão)</span>
                    </td>
                </tr>
                <tr>
                    <th>Pós-Obra:</th>
                    <td>
                        <input type="number" name="time_factor_pos_obra" value="<?php echo esc_attr($timeFactors['pos_obra']); ?>" step="0.01" class="small-text">
                        <span class="description">(+70% padrão)</span>
                    </td>
                </tr>
                <tr>
                    <th>Pré-Mudança:</th>
                    <td>
                        <input type="number" name="time_factor_pre_mudanca" value="<?php echo esc_attr($timeFactors['pre_mudanca']); ?>" step="0.01" class="small-text">
                        <span class="description">(+30% padrão)</span>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Outros Parâmetros -->
        <div style="background:#fff;border:1px solid #ccd0d4;padding:20px;margin:20px 0">
            <h2>⚙️ Outros Parâmetros</h2>
            <table class="form-table">
                <tr>
                    <th>Buffer Operacional:</th>
                    <td>
                        <input type="number" name="buffer_minutes" value="<?php echo esc_attr($bufferMinutes); ?>" class="small-text"> minutos
                        <p class="description">Tempo adicional para deslocamento e preparação</p>
                    </td>
                </tr>
                <tr>
                    <th>Preço por m²:</th>
                    <td>
                        R$ <input type="number" name="price_per_m2" value="<?php echo esc_attr($pricePerM2); ?>" step="0.01" class="small-text">
                        <p class="description">Valor base para cálculo de preço</p>
                    </td>
                </tr>
            </table>
        </div>
        <?php
    }

    private function handleSave(string $tab): void
    {
        if ($tab === 'connections') {
            update_option('limpvix_firebase_project_id', sanitize_text_field($_POST['firebase_project_id'] ?? ''));
            update_option('limpvix_firebase_api_key', sanitize_text_field($_POST['firebase_api_key'] ?? ''));
            update_option('limpvix_firebase_auth_domain', sanitize_text_field($_POST['firebase_auth_domain'] ?? ''));
            update_option('limpvix_google_mybusiness_api_key', sanitize_text_field($_POST['google_mybusiness_api_key'] ?? ''));
            update_option('limpvix_twilio_account_sid', sanitize_text_field($_POST['twilio_account_sid'] ?? ''));
            update_option('limpvix_twilio_auth_token', sanitize_text_field($_POST['twilio_auth_token'] ?? ''));
            update_option('limpvix_360dialog_api_key', sanitize_text_field($_POST['360dialog_api_key'] ?? ''));
        } elseif ($tab === 'briefing') {
            // Salvar tabela m²
            $m2Table = [
                'bedroom' => (float) ($_POST['m2_bedroom'] ?? 12),
                'bathroom' => (float) ($_POST['m2_bathroom'] ?? 4),
                'living_room' => (float) ($_POST['m2_living_room'] ?? 20),
                'kitchen' => (float) ($_POST['m2_kitchen'] ?? 10),
                'office' => (float) ($_POST['m2_office'] ?? 10),
                'external_area' => (float) ($_POST['m2_external_area'] ?? 25)
            ];
            update_option('limpvix_briefing_m2_table', $m2Table);

            // Salvar fatores de tempo
            $timeFactors = [
                'limpeza_pesada' => (float) ($_POST['time_factor_limpeza_pesada'] ?? 0.40),
                'pos_obra' => (float) ($_POST['time_factor_pos_obra'] ?? 0.70),
                'pre_mudanca' => (float) ($_POST['time_factor_pre_mudanca'] ?? 0.30)
            ];
            update_option('limpvix_briefing_time_factors', $timeFactors);

            // Salvar buffer
            update_option('limpvix_briefing_buffer_minutes', (int) ($_POST['buffer_minutes'] ?? 30));

            // Salvar preço por m²
            update_option('limpvix_briefing_price_per_m2', (float) ($_POST['price_per_m2'] ?? 15.00));
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $tab . '&updated=1'));
        exit;
    }

    private function renderFeatureFlags(): void
    {
        $featureFlags = new \LimpVix\Core\FeatureFlags();
        $flags = [
            'core_enabled' => 'Core Enabled',
            'briefing_enabled' => 'Módulo Briefing',
            'financial_workflow' => 'Financial Workflow',
            'admin_interface' => 'Admin Interface'
        ];

        echo '<ul style="margin:0">';
        foreach ($flags as $flag => $label) {
            $enabled = $featureFlags->isEnabled($flag);
            $icon = $enabled ? '✅' : '❌';
            $color = $enabled ? 'green' : 'gray';
            echo sprintf(
                '<li style="color:%s">%s <strong>%s</strong></li>',
                $color,
                $icon,
                esc_html($label)
            );
        }
        echo '</ul>';
    }

    private function getM2Table(): array
    {
        $defaults = [
            'bedroom' => 12,
            'bathroom' => 4,
            'living_room' => 20,
            'kitchen' => 10,
            'office' => 10,
            'external_area' => 25
        ];

        return get_option('limpvix_briefing_m2_table', $defaults);
    }

    private function getTimeFactors(): array
    {
        $defaults = [
            'limpeza_pesada' => 0.40,
            'pos_obra' => 0.70,
            'pre_mudanca' => 0.30
        ];

        return get_option('limpvix_briefing_time_factors', $defaults);
    }
}
