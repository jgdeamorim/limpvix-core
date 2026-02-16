<?php
/**
 * Mercado Pago Detector
 *
 * Detecta e captura credenciais do plugin oficial WooCommerce Mercado Pago
 * Evita duplicação de configurações e aproveita o plugin mantido pelo MP
 *
 * @package LimpVix
 */

namespace LimpVix\Admin\Settings;

if (!defined('ABSPATH')) {
    exit;
}

class MercadoPagoDetector
{
    private const OFFICIAL_PLUGIN_PATH = 'woocommerce-mercadopago/woocommerce-mercadopago.php';

    /**
     * Verifica se plugin oficial está instalado
     */
    public static function isOfficialPluginInstalled(): bool
    {
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $all_plugins = get_plugins();
        return isset($all_plugins[self::OFFICIAL_PLUGIN_PATH]);
    }

    /**
     * Verifica se plugin oficial está ativo
     */
    public static function isOfficialPluginActive(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        return is_plugin_active(self::OFFICIAL_PLUGIN_PATH);
    }

    /**
     * Verifica se plugin oficial está conectado (tem credenciais)
     */
    public static function isOfficialPluginConnected(): bool
    {
        $tokenProd = get_option('_mp_access_token_prod', '');
        $tokenTest = get_option('_mp_access_token_test', '');

        return !empty($tokenProd) && !empty($tokenTest);
    }

    /**
     * Captura credenciais do plugin oficial
     */
    public static function getOfficialPluginCredentials(): array
    {
        return [
            'access_token_prod' => get_option('_mp_access_token_prod', ''),
            'public_key_prod' => get_option('_mp_public_key_prod', ''),
            'access_token_test' => get_option('_mp_access_token_test', ''),
            'public_key_test' => get_option('_mp_public_key_test', ''),
            'site_id' => strtoupper(get_option('_site_id_v1', '')),
            'collector_id' => get_option('_collector_id_v1', ''),
            'client_id' => get_option('_mp_client_id', ''),
        ];
    }

    /**
     * Sincroniza credenciais do plugin oficial para LimpVix
     */
    public static function syncCredentials(): bool
    {
        if (!self::isOfficialPluginConnected()) {
            return false;
        }

        $credentials = self::getOfficialPluginCredentials();

        // Valida se tem todas as credenciais necessárias
        if (
            empty($credentials['access_token_prod']) ||
            empty($credentials['access_token_test']) ||
            empty($credentials['public_key_prod']) ||
            empty($credentials['public_key_test'])
        ) {
            return false;
        }

        // Salva com prefixo limpvix
        update_option('limpvix_mp_access_token_prod', $credentials['access_token_prod'], false);
        update_option('limpvix_mp_public_key_prod', $credentials['public_key_prod'], false);
        update_option('limpvix_mp_access_token_test', $credentials['access_token_test'], false);
        update_option('limpvix_mp_public_key_test', $credentials['public_key_test'], false);

        // Salva informações adicionais
        if (!empty($credentials['site_id'])) {
            update_option('limpvix_mp_site_id', $credentials['site_id'], false);
        }
        if (!empty($credentials['collector_id'])) {
            update_option('limpvix_mp_collector_id', $credentials['collector_id'], false);
        }
        if (!empty($credentials['client_id'])) {
            update_option('limpvix_mp_client_id', $credentials['client_id'], false);
        }

        // Atualiza status
        $currentStatus = get_option('limpvix_mp_status', []);
        $status = array_merge($currentStatus, [
            'connected' => true,
            'source' => 'official_plugin',
            'last_sync' => time(),
        ]);

        // Mantém environment se já existir
        if (!isset($status['environment'])) {
            $status['environment'] = 'test';
        }

        update_option('limpvix_mp_status', $status, false);

        // Limpa cache de informações do usuário
        delete_transient('limpvix_mp_official_user_info');

        return true;
    }

    /**
     * Obtém informações do usuário MP via API
     */
    public static function getUserInfo(): array
    {
        $credentials = self::getOfficialPluginCredentials();

        if (empty($credentials['access_token_prod'])) {
            return [
                'name' => 'Conta Mercado Pago',
                'email' => '',
                'user_id' => '',
            ];
        }

        $transient_key = 'limpvix_mp_official_user_info';
        $cached = get_transient($transient_key);

        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get('https://api.mercadopago.com/v1/users/me', [
            'headers' => [
                'Authorization' => 'Bearer ' . $credentials['access_token_prod'],
            ],
            'timeout' => 10,
        ]);

        if (is_wp_error($response)) {
            return [
                'name' => 'Conta Mercado Pago',
                'email' => '',
                'user_id' => '',
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return [
                'name' => 'Conta Mercado Pago',
                'email' => '',
                'user_id' => '',
            ];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        $userInfo = [
            'name' => $data['nickname'] ?? $data['first_name'] ?? 'Conta Mercado Pago',
            'email' => $data['email'] ?? '',
            'user_id' => $data['id'] ?? '',
        ];

        set_transient($transient_key, $userInfo, HOUR_IN_SECONDS);

        return $userInfo;
    }

    /**
     * Obtém país/site_id mapeado
     */
    public static function getCountryName(string $siteId): string
    {
        $countries = [
            'MLA' => 'Argentina',
            'MLB' => 'Brasil',
            'MLC' => 'Chile',
            'MCO' => 'Colômbia',
            'MLM' => 'México',
            'MPE' => 'Perú',
            'MLU' => 'Uruguai',
        ];

        return $countries[$siteId] ?? 'País não identificado';
    }

    /**
     * Registra hooks para sincronização automática
     */
    public static function registerSyncHooks(): void
    {
        // Sincroniza quando credenciais do plugin oficial mudarem
        add_action('update_option__mp_access_token_prod', [self::class, 'onCredentialsUpdate']);
        add_action('update_option__mp_access_token_test', [self::class, 'onCredentialsUpdate']);
        add_action('update_option__mp_public_key_prod', [self::class, 'onCredentialsUpdate']);
        add_action('update_option__mp_public_key_test', [self::class, 'onCredentialsUpdate']);

        // Agenda verificação periódica
        add_action('init', [self::class, 'schedulePeriodicSync']);
        add_action('limpvix_mp_periodic_sync', [self::class, 'periodicSync']);

        // Registra intervalo personalizado
        add_filter('cron_schedules', [self::class, 'addCustomCronSchedule']);
    }

    /**
     * Callback quando credenciais são atualizadas no plugin oficial
     */
    public static function onCredentialsUpdate(): void
    {
        if (self::isOfficialPluginConnected()) {
            self::syncCredentials();
        }
    }

    /**
     * Agenda sincronização periódica
     */
    public static function schedulePeriodicSync(): void
    {
        if (!wp_next_scheduled('limpvix_mp_periodic_sync')) {
            wp_schedule_event(time(), 'limpvix_five_minutes', 'limpvix_mp_periodic_sync');
        }
    }

    /**
     * Executa sincronização periódica
     */
    public static function periodicSync(): void
    {
        if (self::isOfficialPluginActive() && self::isOfficialPluginConnected()) {
            self::syncCredentials();
        } else {
            // Plugin foi desativado ou desconectado
            $status = get_option('limpvix_mp_status', []);
            if (isset($status['connected']) && $status['connected'] === true) {
                $status['connected'] = false;
                $status['disconnected_at'] = time();
                $status['disconnected_reason'] = 'official_plugin_unavailable';
                update_option('limpvix_mp_status', $status, false);
            }
        }
    }

    /**
     * Adiciona intervalo personalizado ao cron
     */
    public static function addCustomCronSchedule($schedules): array
    {
        $schedules['limpvix_five_minutes'] = [
            'interval' => 300, // 5 minutos
            'display' => __('A cada 5 minutos (LimpVix)', 'limpvix'),
        ];
        return $schedules;
    }

    /**
     * Obtém URL do plugin oficial
     */
    public static function getOfficialPluginSettingsUrl(): string
    {
        return admin_url('admin.php?page=mercadopago-settings');
    }

    /**
     * Obtém URL para instalar plugin oficial
     */
    public static function getPluginInstallUrl(): string
    {
        return admin_url('plugin-install.php?s=mercado+pago+woocommerce&tab=search&type=term');
    }

    /**
     * Limpa cache e dados de sincronização
     */
    public static function clearCache(): void
    {
        delete_transient('limpvix_mp_official_user_info');
    }

    /**
     * Desagenda sincronização periódica
     */
    public static function unscheduleSyncCron(): void
    {
        $timestamp = wp_next_scheduled('limpvix_mp_periodic_sync');
        if ($timestamp) {
            wp_unschedule_event($timestamp, 'limpvix_mp_periodic_sync');
        }
    }
}
