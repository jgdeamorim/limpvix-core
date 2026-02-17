<?php
/**
 * Run Migration 023: Create Professional Documents Table
 *
 * Usage: wp eval-file database-migrations/run-023-migration.php
 * Or call from admin dashboard migration runner
 */

defined('ABSPATH') || exit;

global $wpdb;

$migration_file = __DIR__ . '/023_create_professional_documents_table.sql';
$migration_number = '023';
$migration_name = 'create_professional_documents_table';

// Check if migration already ran
$table_name = $wpdb->prefix . 'limpvix_professional_documents';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

if ($table_exists) {
    echo "⚠️  Migration 023 already executed - table $table_name exists\n";
    echo "✅ Skipping migration\n";
    return;
}

echo "🚀 Running Migration 023: Create Professional Documents Table\n";
echo "================================================\n\n";

// Read SQL file
if (!file_exists($migration_file)) {
    echo "❌ Error: Migration file not found: $migration_file\n";
    return;
}

$sql = file_get_contents($migration_file);

// Replace wp_ prefix with actual prefix
$sql = str_replace('wp_limpvix_', $wpdb->prefix . 'limpvix_', $sql);

// Execute SQL
try {
    // Disable foreign key checks temporarily (in case references don't exist yet)
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

    $result = $wpdb->query($sql);

    // Re-enable foreign key checks
    $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

    if ($result === false) {
        echo "❌ Migration failed!\n";
        echo "Error: " . $wpdb->last_error . "\n";
        return;
    }

    echo "✅ Table created successfully: $table_name\n\n";

    // Verify table structure
    $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");

    echo "📋 Table Structure:\n";
    echo "-------------------\n";
    foreach ($columns as $column) {
        echo "  - {$column->Field} ({$column->Type})\n";
    }

    echo "\n📊 Indexes:\n";
    echo "-----------\n";
    $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
    $unique_indexes = [];
    foreach ($indexes as $index) {
        if (!isset($unique_indexes[$index->Key_name])) {
            $unique_indexes[$index->Key_name] = $index->Column_name;
        } else {
            $unique_indexes[$index->Key_name] .= ', ' . $index->Column_name;
        }
    }
    foreach ($unique_indexes as $index_name => $columns) {
        echo "  - $index_name ($columns)\n";
    }

    echo "\n✅ Migration 023 completed successfully!\n";
    echo "================================================\n";

} catch (Exception $e) {
    echo "❌ Exception during migration: " . $e->getMessage() . "\n";
}
