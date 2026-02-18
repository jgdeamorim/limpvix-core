<?php
/**
 * Plugin Name: LimpVix Core
 * Plugin URI: https://limpvix.com.br
 * Description: Motor de negócios da plataforma LimpVix — agendamentos, execuções, pagamentos EFI Bank e gestão de profissionais nativos.
 * Version: 0.2.0
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * Requires Plugins: woocommerce
 * Author: LimpVix
 * Author URI: https://limpvix.com.br
 * License: Proprietary
 * Text Domain: limpvix-core
 * Domain Path: /languages
 *
 * @package LimpVix\Core
 *
 * PRINCÍPIOS FUNDAMENTAIS:
 * - Execuções nativas via wp_limpvix_executions
 * - Profissionais nativos via wp_limpvix_professionals
 * - Pagamentos via EFI Bank (PIX Cash-In e Cash-Out)
 * - Feature Flags controlam TUDO
 * - DDD leve + Clean Architecture
 */

defined('ABSPATH') || exit;

// Constantes do plugin
define('LIMPVIX_VERSION', '0.2.0');
define('LIMPVIX_PLUGIN_FILE', __FILE__);
define('LIMPVIX_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('LIMPVIX_PLUGIN_URL', plugin_dir_url(__FILE__));
define('LIMPVIX_PLUGIN_BASENAME', plugin_basename(__FILE__));

// Autoloader via Composer (se disponível)
if (file_exists(LIMPVIX_PLUGIN_DIR . 'vendor/autoload.php')) {
    require_once LIMPVIX_PLUGIN_DIR . 'vendor/autoload.php';
} else {
    // Autoloader manual (fallback)
    spl_autoload_register(function($class) {
        // Apenas classes do namespace LimpVix
        if (strpos($class, 'LimpVix\\') !== 0) {
            return;
        }

        // Converter namespace para caminho
        // LimpVix\Core\Kernel → src/Core/Kernel.php
        $relativePath = str_replace('LimpVix\\', '', $class);
        $relativePath = str_replace('\\', DIRECTORY_SEPARATOR, $relativePath);
        $file = LIMPVIX_PLUGIN_DIR . 'src' . DIRECTORY_SEPARATOR . $relativePath . '.php';

        if (file_exists($file)) {
            require_once $file;
        }
    });
}

/**
 * Registra cron schedules customizados ANTES do plugins_loaded
 *
 * IMPORTANTE: O filtro cron_schedules precisa ser registrado ANTES de plugins_loaded
 * para garantir que os schedules estejam disponíveis quando o WordPress precisar deles.
 */
add_filter('cron_schedules', function($schedules) {
    $schedules['limpvix_five_minutes'] = [
        'interval' => 300, // 5 minutos
        'display' => __('A cada 5 minutos (LimpVix)', 'limpvix'),
    ];
    $schedules['every_15_minutes'] = [
        'interval' => 900, // 15 minutos
        'display' => __('A cada 15 minutos (LimpVix)', 'limpvix'),
    ];
    return $schedules;
});

/**
 * Inicializa o plugin quando WordPress estiver pronto
 *
 * RESPONSABILIDADE:
 * - Inicializar Kernel
 * - Inicializar módulos (Admin, Communication, Adapters)
 * - Registrar hooks de ativação/desativação
 */
function limpvix_core_init() {
    // Plugin deve carregar SEMPRE para mostrar menu admin
    // Alertas de dependências são mostrados em Configurações > Dependências

    // Inicializar Kernel (bootstrap do sistema)
    if (class_exists('LimpVix\\Core\\Kernel')) {
        LimpVix\Core\Kernel::getInstance()->boot();
    }

    // NOTA: AdminBootstrap é inicializado via Kernel->boot() -> Hooks->register()
    // NÃO inicializar aqui para evitar duplicação de menus e conteúdo
    // if (is_admin() && class_exists('LimpVix\\Admin\\Bootstrap\\AdminBootstrap')) {
    //     $adminBootstrap = new LimpVix\Admin\Bootstrap\AdminBootstrap();
    //     $adminBootstrap->boot();
    // }

    // Inicializar módulo de Comunicação
    if (class_exists('LimpVix\\Infrastructure\\Communication\\CommunicationBootstrap')) {
        LimpVix\Infrastructure\Communication\CommunicationBootstrap::boot();
    }

    // Inicializar módulo de Adapters (IMPORTANTE: boot() não é estático)
    if (class_exists('LimpVix\\Infrastructure\\Adapters\\AdapterBootstrap')) {
        $adapterBootstrap = new LimpVix\Infrastructure\Adapters\AdapterBootstrap();
        $adapterBootstrap->boot();
    }
}
add_action('plugins_loaded', 'limpvix_core_init', 20);

/**
 * Hook de ativação
 *
 * RESPONSABILIDADE:
 * - Criar tabelas customizadas via MigrationManager
 * - Configurar valores padrão
 * - Verificar requisitos
 */
register_activation_hook(__FILE__, function() {
    // Verificar versão PHP
    if (version_compare(PHP_VERSION, '7.4', '<')) {
        deactivate_plugins(LIMPVIX_PLUGIN_BASENAME);
        wp_die('LimpVix Core requer PHP 7.4 ou superior.');
    }

    // Verificar se WooCommerce está ativo
    if (!is_plugin_active('woocommerce/woocommerce.php')) {
        deactivate_plugins(LIMPVIX_PLUGIN_BASENAME);
        wp_die('LimpVix Core requer que o WooCommerce esteja instalado e ativo. O WooCommerce gerencia o fluxo de pagamento dos clientes.');
    }

    // Executar migrations automaticamente via MigrationManager
    if (class_exists('LimpVix\\Infrastructure\\Database\\MigrationRunner')) {
        $migrationRunner = new LimpVix\Infrastructure\Database\MigrationRunner();
        $result = $migrationRunner->runPending();

        // Check if there were any failures
        if (!empty($result['failed'])) {
            $errorMessage = 'Erro ao executar migrations:<br>';
            foreach ($result['failed'] as $error) {
                $errorMessage .= sprintf(
                    '- <strong>%s</strong>: %s<br>',
                    $error['migration'],
                    $error['error']
                );
            }
            wp_die($errorMessage);
        }

        // Log de sucesso
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[LimpVix] Migrations: %d executed, %d skipped', $result['executed'], $result['skipped']));
        }
    }

    // Registrar custom user roles
    if (class_exists('LimpVix\\Core\\UserRoles')) {
        LimpVix\Core\UserRoles::register();
    }

    // Inicializar módulo de Comunicação na ativação
    if (class_exists('LimpVix\\Infrastructure\\Communication\\CommunicationBootstrap')) {
        LimpVix\Infrastructure\Communication\CommunicationBootstrap::onActivation();
    }

    // Inicializar opções padrão (idempotente — não sobrescreve valor já configurado pelo admin)
    if (class_exists('LimpVix\\Infrastructure\\Configuration\\PlatformFeeConfig')) {
        LimpVix\Infrastructure\Configuration\PlatformFeeConfig::initializeDefault();
    }

    // NOTA: UserRoles::unregister() removido do activation hook.
    // Roles devem ser desregistradas apenas no deactivation hook.

    // flush_rewrite_rules();
});

/**
 * Hook de desativação
 *
 * RESPONSABILIDADE:
 * - Limpar transients
 * - Preservar dados (NÃO deletar tabelas)
 */
register_deactivation_hook(__FILE__, function() {
    // Limpar cache
    delete_transient('limpvix_feature_flags');

    // Desativar módulo de Comunicação
    if (class_exists('LimpVix\\Infrastructure\\Communication\\CommunicationBootstrap')) {
        LimpVix\Infrastructure\Communication\CommunicationBootstrap::onDeactivation();
    }


    // flush_rewrite_rules();
});

/**
 * Helper: Log de eventos
 */
function limpvix_log_event($event_type, $data = []) {
    do_action('limpvix_log_event', $event_type, $data);

    // Por enquanto, apenas error_log
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log(sprintf(
            '[LIMPVIX] %s: %s',
            $event_type,
            json_encode($data, JSON_UNESCAPED_UNICODE)
        ));
    }
}
