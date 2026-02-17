<?php
/**
 * Execute Migration 023 directly via web browser
 *
 * Access: http://localhost:8080/wp-content/plugins/limpvix-core/database-migrations/execute-migration-023.php
 *
 * Security: Only accessible by admin users
 */

// Load WordPress
require_once __DIR__ . '/../../../../wp-load.php';

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Access denied. Admin privileges required.');
}

?>
<!DOCTYPE html>
<html>
<head>
    <title>LimpVix - Migration 023</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            padding: 20px;
            background: #f0f0f1;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        h1 {
            color: #1d2327;
            border-bottom: 2px solid #2271b1;
            padding-bottom: 10px;
        }
        .success {
            background: #d7fae0;
            border-left: 4px solid #00a32a;
            padding: 15px;
            margin: 15px 0;
        }
        .warning {
            background: #fcf3cf;
            border-left: 4px solid #dba617;
            padding: 15px;
            margin: 15px 0;
        }
        .error {
            background: #fcdbdb;
            border-left: 4px solid #d63638;
            padding: 15px;
            margin: 15px 0;
        }
        pre {
            background: #f6f7f7;
            padding: 15px;
            border-radius: 4px;
            overflow-x: auto;
        }
        .btn {
            background: #2271b1;
            color: white;
            border: none;
            padding: 10px 20px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn:hover {
            background: #135e96;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🗄️ Migration 023: Create Professional Documents Table</h1>

<?php
global $wpdb;

$table_name = $wpdb->prefix . 'limpvix_professional_documents';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_name'") === $table_name;

if ($table_exists) {
    echo '<div class="warning">';
    echo '<strong>⚠️ Migration already executed</strong><br>';
    echo "Table <code>$table_name</code> already exists. Skipping migration.";
    echo '</div>';
} else {
    echo '<div class="success">';
    echo '<strong>🚀 Executing migration...</strong>';
    echo '</div>';

    // Read SQL file
    $migration_file = __DIR__ . '/023_create_professional_documents_table.sql';

    if (!file_exists($migration_file)) {
        echo '<div class="error">❌ Error: Migration file not found</div>';
    } else {
        $sql = file_get_contents($migration_file);

        // Replace wp_ prefix
        $sql = str_replace('wp_limpvix_', $wpdb->prefix . 'limpvix_', $sql);

        // Disable foreign key checks temporarily
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 0');

        $result = $wpdb->query($sql);

        // Re-enable foreign key checks
        $wpdb->query('SET FOREIGN_KEY_CHECKS = 1');

        if ($result === false) {
            echo '<div class="error">';
            echo '<strong>❌ Migration failed!</strong><br>';
            echo 'Error: ' . htmlspecialchars($wpdb->last_error);
            echo '</div>';
        } else {
            echo '<div class="success">';
            echo '<strong>✅ Table created successfully!</strong><br>';
            echo "Table: <code>$table_name</code>";
            echo '</div>';

            // Show table structure
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_name");

            echo '<h3>📋 Table Structure</h3>';
            echo '<pre>';
            foreach ($columns as $column) {
                echo sprintf("%-30s %s\n", $column->Field, $column->Type);
            }
            echo '</pre>';

            // Show indexes
            echo '<h3>📊 Indexes</h3>';
            $indexes = $wpdb->get_results("SHOW INDEX FROM $table_name");
            $unique_indexes = [];
            foreach ($indexes as $index) {
                if (!isset($unique_indexes[$index->Key_name])) {
                    $unique_indexes[$index->Key_name] = [$index->Column_name];
                } else {
                    $unique_indexes[$index->Key_name][] = $index->Column_name;
                }
            }
            echo '<pre>';
            foreach ($unique_indexes as $index_name => $columns) {
                echo sprintf("%-30s %s\n", $index_name, implode(', ', $columns));
            }
            echo '</pre>';
        }
    }
}

echo '<p><a href="' . admin_url('admin.php?page=limpvix-settings&tab=dependencias') . '" class="btn">← Voltar para Settings</a></p>';
?>

    </div>
</body>
</html>
