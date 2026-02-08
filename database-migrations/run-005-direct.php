<?php
/**
 * Run Migration 005: Create wp_limpvix_executions table (Direct execution)
 * Sprint 1 - Dia 5
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

global $wpdb;

echo "=== MIGRATION 005: CREATE EXECUTIONS TABLE (DIRECT) ===\n\n";

$table_name = $wpdb->prefix . 'limpvix_executions';

$sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    execution_uuid VARCHAR(36) NOT NULL UNIQUE,
    order_uuid VARCHAR(36) NOT NULL,
    professional_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(50) NOT NULL DEFAULT 'created',
    scheduled_start_time DATETIME NULL,
    service_location JSON NULL,
    geofence_radius_meters INT NOT NULL DEFAULT 150,
    check_in_at DATETIME NULL,
    check_in_geo JSON NULL,
    check_out_at DATETIME NULL,
    check_out_geo JSON NULL,
    evidence JSON NULL,
    sla_violations JSON NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_execution_uuid (execution_uuid),
    INDEX idx_order_uuid (order_uuid),
    INDEX idx_professional_id (professional_id),
    INDEX idx_status (status),
    INDEX idx_scheduled_start_time (scheduled_start_time)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
dbDelta($sql);

// Verificar criação
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'");

if ($table_exists) {
    echo "✅ Table $table_name created successfully!\n\n";

    // Mostrar estrutura
    $columns = $wpdb->get_results("DESCRIBE $table_name");
    echo "Table structure:\n";
    foreach ($columns as $col) {
        echo "  - {$col->Field} ({$col->Type})\n";
    }

    echo "\n🎉 MIGRATION 005 COMPLETED!\n";
    exit(0);
} else {
    echo "❌ ERROR: Table $table_name was not created\n";
    echo "Last error: " . $wpdb->last_error . "\n";
    exit(1);
}
