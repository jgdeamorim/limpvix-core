<?php
/**
 * PHPUnit Integration Tests Bootstrap
 *
 * Carrega WordPress REAL para testes de integração
 * SEM MOCKS - Usa banco de dados real
 *
 * @package LimpVix\Tests\Integration
 * @since 0.12.0
 */

echo "🔄 Starting Integration Tests Bootstrap...\n";

// Composer autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
    echo "✅ Composer autoloader loaded\n";
}

// Determine WordPress root directory
$wp_root_path = dirname(dirname(dirname(dirname(__DIR__))));

echo "🔍 Looking for WordPress in: $wp_root_path\n";

// Load WordPress
$wp_load_path = $wp_root_path . '/wp-load.php';

if (!file_exists($wp_load_path)) {
    die("❌ WordPress not found at: $wp_load_path\n");
}

echo "📂 Loading WordPress from: $wp_load_path\n";

// Load WordPress without starting session or loading plugins
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['SERVER_NAME'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

require_once $wp_load_path;

// Verify wpdb is loaded
global $wpdb;

if (!$wpdb) {
    die("❌ wpdb not available. WordPress may not be loaded correctly.\n");
}

echo "✅ wpdb available: " . get_class($wpdb) . "\n";
echo "✅ Database prefix: {$wpdb->prefix}\n";
echo "✅ Database name: " . DB_NAME . "\n";

// Test database connection
$result = $wpdb->get_var("SELECT 1");
if ($result != 1) {
    die("❌ Database connection failed\n");
}

echo "✅ Database connection OK\n";

// Check if contracts table exists
$table = $wpdb->prefix . 'limpvix_contracts';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

if (!$table_exists) {
    echo "⚠️  WARNING: Table $table does not exist. Tests may fail.\n";
} else {
    echo "✅ Table $table exists\n";
}

echo "✅ Integration tests ready - REAL DATABASE MODE\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
