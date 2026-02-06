<?php
/**
 * Health Check Endpoint (APENAS PARA DEBUG)
 *
 * USAR APENAS EM DESENVOLVIMENTO!
 *
 * Acesso via browser:
 * http://localhost:8080/wp-content/plugins/limpvix-core/health-check.php
 *
 * Ou via WP-CLI:
 * wp eval-file wp-content/plugins/limpvix-core/health-check.php
 *
 * @package LimpVix\Core
 */

// Carregar WordPress
$wp_load_path = dirname(dirname(dirname(dirname(__FILE__)))) . '/wp-load.php';

if (!file_exists($wp_load_path)) {
    die('WordPress não encontrado. Execute via WP-CLI: wp eval-file health-check.php');
}

require_once $wp_load_path;

// Verificar se está em modo debug
if (!defined('WP_DEBUG') || !WP_DEBUG) {
    wp_die('Health check disponível apenas com WP_DEBUG = true');
}

// Executar health check
try {
    $kernel = \LimpVix\Core\Kernel::getInstance();
    $health = $kernel->healthCheck();

    // Output formatado
    header('Content-Type: application/json');
    echo json_encode([
        'status' => 'ok',
        'timestamp' => date('Y-m-d H:i:s'),
        'health' => $health
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (\Exception $e) {
    header('Content-Type: application/json');
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ], JSON_PRETTY_PRINT);
}
