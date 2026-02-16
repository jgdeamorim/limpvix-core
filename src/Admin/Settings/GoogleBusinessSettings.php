<?php
/**
 * GoogleBusinessSettings - Configurações do Google Meu Negócio
 *
 * Gerencia configuração e status da integração com Google My Business
 *
 * @package LimpVix\Admin\Settings
 * @since 0.1.5
 */

namespace LimpVix\Admin\Settings;

defined('ABSPATH') || exit;

class GoogleBusinessSettings
{
    /**
     * Chave da opção no WordPress
     */
    private const OPTION_KEY = 'limpvix_google_business_settings';

    /**
     * Verificar se Google Business está conectado
     *
     * @return bool
     */
    public static function isConnected(): bool
    {
        $settings = self::getSettings();
        return !empty($settings['place_id']) && !empty($settings['enabled']);
    }

    /**
     * Obter Place ID configurado
     *
     * @return string|null
     */
    public static function getPlaceId(): ?string
    {
        $settings = self::getSettings();
        return $settings['place_id'] ?? null;
    }

    /**
     * Obter access token (para futuras integrações avançadas)
     *
     * @return string|null
     */
    public static function getValidAccessToken(): ?string
    {
        $settings = self::getSettings();

        // Por enquanto, retorna null pois não estamos usando OAuth
        // O Place ID é suficiente para gerar URLs de review
        return $settings['access_token'] ?? null;
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
            'place_id' => '',
            'business_name' => '',
            'access_token' => '',
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
            'place_id' => sanitize_text_field($settings['place_id'] ?? ''),
            'business_name' => sanitize_text_field($settings['business_name'] ?? ''),
            'access_token' => sanitize_text_field($settings['access_token'] ?? ''),
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
                <span class="dashicons dashicons-google"></span>
                Google Meu Negócio
            </h3>

            <?php if ($isConnected): ?>
                <div class="limpvix-alert limpvix-alert-success">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-yes"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">Google Business Conectado</div>
                        <p>Estabelecimento: <strong><?php echo esc_html($settings['business_name'] ?: 'Não informado'); ?></strong></p>
                        <p>Place ID: <code><?php echo esc_html($settings['place_id']); ?></code></p>
                    </div>
                </div>
            <?php else: ?>
                <div class="limpvix-alert limpvix-alert-warning">
                    <div class="limpvix-alert-icon">
                        <span class="dashicons dashicons-warning"></span>
                    </div>
                    <div class="limpvix-alert-content">
                        <div class="limpvix-alert-title">Google Business Não Configurado</div>
                        <p>Configure abaixo para habilitar convites de avaliação no Google.</p>
                    </div>
                </div>
            <?php endif; ?>

            <form method="post" action="<?php echo admin_url('admin-post.php'); ?>" class="limpvix-form">
                <input type="hidden" name="action" value="limpvix_save_google_business_settings">
                <?php wp_nonce_field('limpvix_save_google_business_settings'); ?>

                <table class="form-table">
                    <tr>
                        <th scope="row">
                            <label for="google_business_enabled">Habilitar Google Reviews</label>
                        </th>
                        <td>
                            <label class="limpvix-toggle">
                                <input type="checkbox"
                                       id="google_business_enabled"
                                       name="google_business_settings[enabled]"
                                       value="1"
                                       <?php checked($settings['enabled']); ?>>
                                <span class="limpvix-toggle-slider"></span>
                            </label>
                            <p class="description">
                                Quando habilitado, clientes que derem 5⭐ receberão convite para avaliar no Google.
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="google_business_name">Nome do Estabelecimento</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="google_business_name"
                                   name="google_business_settings[business_name]"
                                   value="<?php echo esc_attr($settings['business_name']); ?>"
                                   class="regular-text">
                            <p class="description">
                                Nome do seu estabelecimento no Google (ex: "LimpVix - Limpeza Profissional")
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="google_place_id">Place ID *</label>
                        </th>
                        <td>
                            <input type="text"
                                   id="google_place_id"
                                   name="google_business_settings[place_id]"
                                   value="<?php echo esc_attr($settings['place_id']); ?>"
                                   class="regular-text"
                                   placeholder="ChIJ..."
                                   required>
                            <p class="description">
                                <strong>Como encontrar seu Place ID:</strong><br>
                                1. Acesse <a href="https://developers.google.com/maps/documentation/places/web-service/place-id" target="_blank">Place ID Finder</a><br>
                                2. Pesquise seu estabelecimento no mapa<br>
                                3. Copie o Place ID (começa com "ChIJ")
                            </p>
                        </td>
                    </tr>
                </table>

                <div class="limpvix-settings-help" style="background: #f0f9ff; border-left: 4px solid #3b82f6; padding: 16px; margin: 20px 0; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0; color: #1e40af;">ℹ️ Como funciona</h4>
                    <ul style="margin: 0; padding-left: 20px; color: #1f2937;">
                        <li>Cliente avalia o serviço com 5⭐</li>
                        <li>Sistema dispara <strong>Fluxo C3</strong> automaticamente</li>
                        <li>Cliente recebe link personalizado via WhatsApp</li>
                        <li>Ao clicar, é direcionado para avaliar no Google</li>
                    </ul>
                </div>

                <p class="submit">
                    <button type="submit" class="button button-primary">
                        💾 Salvar Configurações
                    </button>
                </p>
            </form>

            <?php if ($isConnected): ?>
                <div style="margin-top: 20px; padding: 16px; background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 4px;">
                    <h4 style="margin: 0 0 12px 0;">🔗 Link de Teste</h4>
                    <p>Teste seu link de review:</p>
                    <code style="display: block; padding: 12px; background: white; border: 1px solid #ddd; border-radius: 4px; word-break: break-all;">
                        https://search.google.com/local/writereview?placeid=<?php echo esc_html($settings['place_id']); ?>
                    </code>
                    <p>
                        <a href="https://search.google.com/local/writereview?placeid=<?php echo esc_attr($settings['place_id']); ?>"
                           target="_blank"
                           class="button">
                            👁️ Visualizar Link de Review
                        </a>
                    </p>
                </div>
            <?php endif; ?>
        </div>
        <?php
    }

    /**
     * Registrar hooks
     */
    public static function registerHooks(): void
    {
        add_action('admin_post_limpvix_save_google_business_settings', [__CLASS__, 'handleSaveSettings']);
    }

    /**
     * Handler: Salvar configurações
     */
    public static function handleSaveSettings(): void
    {
        check_admin_referer('limpvix_save_google_business_settings');

        if (!current_user_can('manage_options')) {
            wp_die('Sem permissão');
        }

        $settings = $_POST['google_business_settings'] ?? [];

        if (self::saveSettings($settings)) {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'section' => 'google-business',
                'message' => 'google_business_saved'
            ], admin_url('admin.php'));
        } else {
            $redirect = add_query_arg([
                'page' => 'limpvix-settings',
                'section' => 'google-business',
                'message' => 'error'
            ], admin_url('admin.php'));
        }

        wp_redirect($redirect);
        exit;
    }
}
