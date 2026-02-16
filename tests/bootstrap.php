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
if (!function_exists('wp_generate_uuid4')) {
    function wp_generate_uuid4() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff), mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
        );
    }
}

// Mock wpdb class for testing
// WordPress constants
define('OBJECT', 'OBJECT');
define('ARRAY_A', 'ARRAY_A');
define('ARRAY_N', 'ARRAY_N');

if (!class_exists('wpdb')) {
    class wpdb {
        public $prefix = 'wp_';
        public $insert_id = 0;
        
        public function prepare($query, ...$args) {
            return $query;
        }
        
        public function get_var($query) {
            return null;
        }
        
        public function get_row($query, $output = OBJECT) {
            return null;
        }
        
        public function get_results($query, $output = OBJECT) {
            return [];
        }
        
        public function query($query) {
            return false;
        }
        
        public function insert($table, $data, $format = null) {
            $this->insert_id = rand(1, 1000);
            return 1;
        }
        
        public function update($table, $data, $where, $format = null, $where_format = null) {
            return 1;
        }
        
        public function delete($table, $where, $where_format = null) {
            return 1;
        }
    }
}
if (!function_exists('do_action')) {
    function do_action($hook_name, ...$args) {
        // Mock do_action - does nothing in tests
        return;
    }
}
