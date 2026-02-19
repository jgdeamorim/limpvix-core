<?php
/**
 * Run Migration 005: Create wp_limpvix_executions table
 * Sprint 1 - Dia 5
 */

// Security: Block direct HTTP access (allow CLI only)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Direct access not allowed.');
}

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

global $wpdb;

echo "=== MIGRATION 005: CREATE EXECUTIONS TABLE ===\n\n";

$sql = file_get_contents(__DIR__ . '/005_create_executions_table.sql');

// Remove comments e split por statement
$statements = array_filter(
    array_map('trim', explode(';', $sql)),
    fn($s) => !empty($s) && !str_starts_with($s, '--')
);

$success = true;
foreach ($statements as $statement) {
    if (empty($statement)) continue;

    echo "Executing: " . substr($statement, 0, 50) . "...\n";
    $result = $wpdb->query($statement);

    if ($result === false) {
        echo "❌ ERROR: " . $wpdb->last_error . "\n";
        $success = false;
        break;
    } else {
        echo "✅ Success\n";
    }
}

if ($success) {
    echo "\n🎉 MIGRATION 005 COMPLETED SUCCESSFULLY!\n";

    // Verificar tabela criada
    $table_name = $wpdb->prefix . 'limpvix_executions';
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

    if ($table_exists) {
        echo "\n✅ Table $table_name verified\n";

        // Mostrar estrutura
        $columns = $wpdb->get_results("DESCRIBE $table_name");
        echo "\nTable structure:\n";
        foreach ($columns as $col) {
            echo "  - {$col->Field} ({$col->Type})\n";
        }
    }

    exit(0);
} else {
    echo "\n💥 MIGRATION 005 FAILED!\n";
    exit(1);
}
