<?php
/**
 * Run CreateFeedbackTable Migration
 *
 * PURPOSE:
 * - Execute migration 025 - Create feedback table
 * - Validate table structure
 * - Verify constraints
 *
 * USAGE:
 * docker exec limpvix_wordpress_clean php /var/www/html/wp-content/plugins/limpvix-core/run_feedback_migration.php
 */

require_once('/var/www/html/wp-load.php');

echo "=== RUNNING FEEDBACK TABLE MIGRATION ===" . PHP_EOL . PHP_EOL;

try {
    $migration = new \LimpVix\Infrastructure\Database\Migrations\CreateFeedbackTable();
    
    echo "Migration: " . $migration->getName() . PHP_EOL;
    echo "Version: " . $migration->getVersion() . PHP_EOL . PHP_EOL;
    
    echo "Executing migration..." . PHP_EOL;
    $migration->up();
    
    echo "✅ Migration executed successfully!" . PHP_EOL . PHP_EOL;
    
    // Verify table was created
    global $wpdb;
    $tableName = $wpdb->prefix . 'limpvix_feedback';
    
    $exists = $wpdb->get_var("SHOW TABLES LIKE '{$tableName}'") === $tableName;
    
    if ($exists) {
        echo "✅ Table {$tableName} confirmed in database" . PHP_EOL . PHP_EOL;
        
        // Show table structure
        echo "Table structure:" . PHP_EOL;
        $columns = $wpdb->get_results("DESCRIBE {$tableName}");
        foreach ($columns as $column) {
            echo "  - {$column->Field} ({$column->Type})";
            if ($column->Key === 'PRI') echo " [PRIMARY KEY]";
            if ($column->Key === 'UNI') echo " [UNIQUE]";
            if ($column->Key === 'MUL') echo " [INDEX]";
            echo PHP_EOL;
        }
        
        echo PHP_EOL . "Indexes:" . PHP_EOL;
        $indexes = $wpdb->get_results("SHOW INDEX FROM {$tableName}");
        $indexGroups = [];
        foreach ($indexes as $index) {
            $indexGroups[$index->Key_name][] = $index->Column_name;
        }
        foreach ($indexGroups as $indexName => $columns) {
            echo "  - {$indexName}: " . implode(', ', $columns) . PHP_EOL;
        }
        
    } else {
        echo "❌ ERROR: Table was not created!" . PHP_EOL;
    }
    
} catch (\Exception $e) {
    echo "❌ Migration failed: " . $e->getMessage() . PHP_EOL;
    echo "Stack trace: " . $e->getTraceAsString() . PHP_EOL;
}
