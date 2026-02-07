<?php
/**
 * LimpVixSettingsPage - Configurações Centrais do LimpVix Core
 *
 * RESPONSABILIDADE:
 * - Menu principal: LimpVix (com ícone)
 * - Página de configurações gerais
 * - Configuração Firebase (Project ID, API Key, etc)
 * - Feature Flags ativas
 * - Informações do sistema
 *
 * @package LimpVix\Infrastructure\Admin\Pages
 * @since 0.2.0
 */

namespace LimpVix\Infrastructure\Admin\Pages;

defined('ABSPATH') || exit;

class LimpVixSettingsPage
{
    private const PAGE_SLUG = 'limpvix';
    private const OPTION_GROUP = 'limpvix_settings';

    public function register(): void
    {
        add_action('admin_menu', [$this, 'addMenu']);
        add_action('admin_init', [$this, 'registerSettings']);
    }

    public function addMenu(): void
    {
        // Menu principal LimpVix
        add_menu_page(
            'LimpVix Core',
            'LimpVix',
            'manage_options',
            self::PAGE_SLUG,
            [$this, 'render'],
            'dashicons-admin-generic',
            30
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
        register_setting(self::OPTION_GROUP, 'limpvix_firebase_project_id');
        register_setting(self::OPTION_GROUP, 'limpvix_firebase_api_key');
        register_setting(self::OPTION_GROUP, 'limpvix_firebase_auth_domain');
    }

    public function render(): void
    {
        if (isset($_POST['submit']) && check_admin_referer('limpvix_settings')) {
            $this->handleSave();
        }

        $firebaseProjectId = get_option('limpvix_firebase_project_id', '');
        $firebaseApiKey = get_option('limpvix_firebase_api_key', '');
        $firebaseAuthDomain = get_option('limpvix_firebase_auth_domain', '');

        ?>
        <div class="wrap">
            <h1>⚙️ Configurações LimpVix Core</h1>

            <?php if (isset($_GET['updated'])): ?>
                <div class="notice notice-success is-dismissible">
                    <p>✅ Configurações salvas com sucesso!</p>
                </div>
            <?php endif; ?>

            <form method="post" action="">
                <?php wp_nonce_field('limpvix_settings'); ?>

                <!-- Firebase Configuration -->
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

                    <div style="background:#f0f6fc;border-left:4px solid #0073aa;padding:15px;margin-top:20px">
                        <strong>📖 Como configurar Firebase:</strong>
                        <ol style="margin:10px 0 0 20px">
                            <li>Acesse <a href="https://console.firebase.google.com" target="_blank">Firebase Console</a></li>
                            <li>Crie um projeto ou selecione um existente</li>
                            <li>Vá em "Authentication" → "Sign-in method"</li>
                            <li>Ative "Phone" como método de autenticação</li>
                            <li>Em "Project Settings", copie o Project ID e API Key</li>
                            <li>Cole os valores nos campos acima</li>
                        </ol>
                    </div>

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

                <p class="submit">
                    <button type="submit" name="submit" class="button button-primary button-large">
                        💾 Salvar Configurações
                    </button>
                </p>
            </form>
        </div>
        <?php
    }

    private function handleSave(): void
    {
        update_option('limpvix_firebase_project_id', sanitize_text_field($_POST['firebase_project_id']));
        update_option('limpvix_firebase_api_key', sanitize_text_field($_POST['firebase_api_key']));
        update_option('limpvix_firebase_auth_domain', sanitize_text_field($_POST['firebase_auth_domain']));

        // Também definir constante dinamicamente via wp-config override
        if (!empty($_POST['firebase_project_id']) && !defined('LIMPVIX_FIREBASE_PROJECT_ID')) {
            // Adicionar ao wp-config.php programaticamente (via filter)
            add_filter('pre_option_limpvix_firebase_project_id', function() {
                return get_option('limpvix_firebase_project_id');
            });
        }

        wp_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&updated=1'));
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
}
