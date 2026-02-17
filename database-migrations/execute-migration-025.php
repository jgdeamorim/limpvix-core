<?php
/**
 * Execute Migration 025: Add Required Skills to Service Catalog
 *
 * Run this file directly in browser: /wp-content/plugins/limpvix-core/database-migrations/execute-migration-025.php
 *
 * Purpose: Move service → required_skills mapping from hardcoded PHP to database (GAP D)
 */

// Load WordPress
require_once __DIR__ . '/../../../../wp-load.php';

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized. Only administrators can run migrations.');
}

global $wpdb;
$wpdb->show_errors();

echo '<h1>🔧 Migration 025: Add Required Skills to Service Catalog</h1>';
echo '<p><strong>Purpose:</strong> Move service → required_skills mapping from hardcoded PHP to database (GAP D)</p>';
echo '<hr>';

// Read SQL file
$sql_file = __DIR__ . '/025_add_service_catalog_required_skills.sql';

if (!file_exists($sql_file)) {
    echo '<p style="color:red;">❌ ERROR: SQL file not found: ' . $sql_file . '</p>';
    exit;
}

$sql_content = file_get_contents($sql_file);

// Remove comments and split by semicolon
$sql_content = preg_replace('/--.*$/m', '', $sql_content);
$sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content);

$statements = array_filter(
    array_map('trim', explode(';', $sql_content)),
    function($stmt) {
        return !empty($stmt) && strlen($stmt) > 5;
    }
);

echo '<h2>📋 Statements to Execute</h2>';
echo '<ol>';
foreach ($statements as $stmt) {
    $preview = strlen($stmt) > 80 ? substr($stmt, 0, 80) . '...' : $stmt;
    echo '<li><code>' . htmlspecialchars($preview) . '</code></li>';
}
echo '</ol>';
echo '<hr>';

// Execute each statement
echo '<h2>▶️ Execution Log</h2>';
$success_count = 0;
$error_count = 0;

foreach ($statements as $i => $statement) {
    $statement_num = $i + 1;

    echo '<div style="margin-bottom: 15px; padding: 10px; background: #f5f5f5; border-left: 4px solid #ccc;">';
    echo '<strong>Statement ' . $statement_num . ':</strong><br>';
    echo '<pre style="font-size: 11px; overflow-x: auto;">' . htmlspecialchars($statement) . '</pre>';

    $result = $wpdb->query($statement);

    if ($result === false) {
        echo '<p style="color: red;">❌ <strong>ERROR:</strong> ' . htmlspecialchars($wpdb->last_error) . '</p>';
        $error_count++;
    } else {
        echo '<p style="color: green;">✅ <strong>SUCCESS</strong>';
        if (is_numeric($result) && $result > 0) {
            echo ' (' . $result . ' rows affected)';
        }
        echo '</p>';
        $success_count++;
    }

    echo '</div>';
}

// Summary
echo '<hr>';
echo '<h2>📊 Summary</h2>';
echo '<table border="1" cellpadding="10" style="border-collapse: collapse;">';
echo '<tr><th>Total Statements</th><td>' . count($statements) . '</td></tr>';
echo '<tr><th>Success</th><td style="color: green;">' . $success_count . '</td></tr>';
echo '<tr><th>Errors</th><td style="color: red;">' . $error_count . '</td></tr>';
echo '</table>';

if ($error_count === 0) {
    echo '<h3 style="color: green;">✅ Migration 025 completed successfully!</h3>';
} else {
    echo '<h3 style="color: orange;">⚠️ Migration 025 completed with ' . $error_count . ' error(s).</h3>';
    echo '<p>Some errors (like "column already exists") can be safely ignored if you\'re re-running the migration.</p>';
}

// Verification
echo '<hr>';
echo '<h2>🔍 Verification</h2>';

// Check if column exists
$table = $wpdb->prefix . 'limpvix_service_catalog';
$column_exists = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'required_skills'",
        DB_NAME,
        $table
    )
);

if ($column_exists) {
    echo '<p style="color: green;">✅ Column <code>required_skills</code> exists in <code>' . $table . '</code></p>';
} else {
    echo '<p style="color: red;">❌ Column <code>required_skills</code> NOT found in <code>' . $table . '</code></p>';
}

// Show current services with skills
echo '<h3>Current Services with Required Skills:</h3>';
$services = $wpdb->get_results(
    "SELECT service_code, display_name, required_skills FROM {$table} ORDER BY service_code",
    ARRAY_A
);

if ($services) {
    echo '<table border="1" cellpadding="8" style="border-collapse: collapse; width: 100%;">';
    echo '<thead><tr>';
    echo '<th>Service Code</th>';
    echo '<th>Display Name</th>';
    echo '<th>Required Skills</th>';
    echo '</tr></thead><tbody>';

    foreach ($services as $service) {
        echo '<tr>';
        echo '<td><code>' . htmlspecialchars($service['service_code']) . '</code></td>';
        echo '<td>' . htmlspecialchars($service['display_name']) . '</td>';

        if (!empty($service['required_skills'])) {
            $skills = json_decode($service['required_skills'], true);
            if ($skills) {
                echo '<td style="color: green;">✅ ' . implode(', ', array_map('htmlspecialchars', $skills)) . '</td>';
            } else {
                echo '<td style="color: orange;">⚠️ Invalid JSON</td>';
            }
        } else {
            echo '<td style="color: red;">❌ NULL (not populated)</td>';
        }

        echo '</tr>';
    }

    echo '</tbody></table>';
} else {
    echo '<p style="color: orange;">⚠️ No services found in table</p>';
}

echo '<hr>';
echo '<p><a href="' . admin_url('admin.php?page=limpvix-settings&tab=dependencias') . '">← Back to Settings</a></p>';
echo '<p><a href="' . admin_url('admin.php?page=limpvix-service-catalog') . '">→ Go to Service Catalog Admin</a></p>';
