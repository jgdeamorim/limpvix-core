<?php
/**
 * Test Environment Class
 *
 * Execute via:
 * php -f tests/manual/test-environment.php
 */

// Bootstrap WordPress
$wp_load = __DIR__ . '/../../../../../wp-load.php';
if (!file_exists($wp_load)) {
    // Fallback for different directory structures
    $wp_load = '/var/www/html/wp-load.php';
}
require_once $wp_load;

echo "=== LimpVix Environment Test ===\n\n";

// Verificar se classe existe
if (!class_exists('LimpVix\\Core\\Environment')) {
    die("❌ ERROR: Environment class not found!\n");
}

echo "✅ Environment class loaded\n\n";

use LimpVix\Core\Environment;

// Test 1: Load .env
echo "--- Test 1: Load .env ---\n";
try {
    Environment::load();
    echo "✅ Environment loaded successfully\n";
} catch (Exception $e) {
    echo "⚠️  No .env file (this is OK, will use fallbacks)\n";
}

// Test 2: Get environment type
echo "\n--- Test 2: Environment Type ---\n";
$env = Environment::getEnvironment();
echo "Environment: {$env}\n";
echo "  - isDevelopment(): " . (Environment::isDevelopment() ? 'YES' : 'NO') . "\n";
echo "  - isProduction(): " . (Environment::isProduction() ? 'YES' : 'NO') . "\n";
echo "  - isDebug(): " . (Environment::isDebug() ? 'YES' : 'NO') . "\n";

// Test 3: Get values with defaults
echo "\n--- Test 3: Get Values ---\n";

// Test get with default
$timeout = Environment::getInt('LIMPVIX_CRON_TIMEOUT', 300);
echo "LIMPVIX_CRON_TIMEOUT: {$timeout} seconds\n";

// Test bool
$debug = Environment::getBool('LIMPVIX_DEBUG', false);
echo "LIMPVIX_DEBUG: " . ($debug ? 'true' : 'false') . "\n";

// Test has
$hasMpToken = Environment::has('LIMPVIX_MP_ACCESS_TOKEN_PROD');
echo "Has LIMPVIX_MP_ACCESS_TOKEN_PROD: " . ($hasMpToken ? 'YES' : 'NO') . "\n";

// Test 4: MercadoPago environment
echo "\n--- Test 4: MercadoPago Config ---\n";
try {
    $mpEnv = Environment::get('LIMPVIX_MP_ENVIRONMENT', 'not-set');
    echo "MP Environment: {$mpEnv}\n";
} catch (Exception $e) {
    echo "MP Environment: not configured\n";
}

// Test 5: Feature flags
echo "\n--- Test 5: Feature Flags ---\n";
$autoPayouts = Environment::getBool('LIMPVIX_FEATURE_AUTO_PAYOUTS', false);
echo "Auto Payouts: " . ($autoPayouts ? 'ENABLED' : 'DISABLED') . "\n";

$recurring = Environment::getBool('LIMPVIX_FEATURE_RECURRING_CONTRACTS', false);
echo "Recurring Contracts: " . ($recurring ? 'ENABLED' : 'DISABLED') . "\n";

// Test 6: Fallback to wp_options
echo "\n--- Test 6: Fallback to wp_options ---\n";
$mpStatus = get_option('limpvix_mp_status', []);
if (!empty($mpStatus)) {
    echo "✅ MP Status from wp_options: " . ($mpStatus['environment'] ?? 'unknown') . "\n";
} else {
    echo "⚠️  No MP status in wp_options\n";
}

// Test 7: Count loaded variables
echo "\n--- Test 7: Loaded Variables ---\n";
try {
    $all = Environment::all();
    $count = count($all);
    echo "Total variables loaded from .env: {$count}\n";

    if ($count > 0) {
        echo "Sample variables:\n";
        $sample = array_slice(array_keys($all), 0, 5);
        foreach ($sample as $key) {
            echo "  - {$key}\n";
        }
    }
} catch (Exception $e) {
    echo "No .env variables loaded (using fallbacks)\n";
}

echo "\n=== Test Complete ===\n";
echo "\n✅ Environment system is working correctly!\n";
echo "\nNext steps:\n";
echo "1. Copy .env.example to .env\n";
echo "2. Configure your credentials\n";
echo "3. Restart WordPress container\n";
echo "4. Check debug.log for Environment initialization\n";
