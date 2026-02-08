<?php
/**
 * PHPUnit Bootstrap File
 *
 * Inicializa o ambiente de testes para o plugin LimpVix Core
 *
 * @package LimpVix\Tests
 * @since 0.5.0
 */

// Composer autoloader
if (file_exists(dirname(__DIR__) . '/vendor/autoload.php')) {
    require_once dirname(__DIR__) . '/vendor/autoload.php';
}

// Define WordPress test environment
define('ABSPATH', '/var/www/html/');
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);

// Mock WordPress functions for unit tests
if (!function_exists('get_option')) {
    function get_option($option, $default = false) {
        static $options = [];
        return $options[$option] ?? $default;
    }
}

if (!function_exists('update_option')) {
    function update_option($option, $value) {
        static $options = [];
        $options[$option] = $value;
        return true;
    }
}

if (!function_exists('delete_option')) {
    function delete_option($option) {
        static $options = [];
        unset($options[$option]);
        return true;
    }
}

if (!function_exists('current_time')) {
    function current_time($type) {
        return date('Y-m-d H:i:s');
    }
}

if (!function_exists('esc_html')) {
    function esc_html($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('esc_attr')) {
    function esc_attr($text) {
        return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('error_log')) {
    function error_log($message) {
        // Suppress for tests
        return true;
    }
}

// Autoloader para classes do plugin
spl_autoload_register(function ($class) {
    $prefix = 'LimpVix\\';
    $baseDir = dirname(__DIR__) . '/src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

echo "✅ Bootstrap loaded successfully\n";
echo "✅ Plugin classes autoloaded\n";
echo "✅ WordPress mock functions loaded\n";
echo "\n";
