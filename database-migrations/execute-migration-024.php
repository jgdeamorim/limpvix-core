<?php
/**
 * Execute Migration 024: Add Manual Payout Fields
 *
 * Run this file directly in browser: /wp-content/plugins/limpvix-core/database-migrations/execute-migration-024.php
 *
 * Purpose: Add audit trail fields for manual payouts (GAP C)
 */

// Load WordPress
require_once __DIR__ . '/../../../../wp-load.php';

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized. Only administrators can run migrations.');
}

global $wpdb;
$wpdb->show_errors();

echo '<h1>🔧 Migration 024: Add Manual Payout Fields</h1>';
echo '<p><strong>Purpose:</strong> Add audit trail fields for manual payouts (GAP C - ManualPayout para Admin)</p>';
echo '<hr>';

// Read SQL file
$sql_file = __DIR__ . '/024_add_manual_payout_fields.sql';

if (!file_exists($sql_file)) {
    echo '<p style="color:red;">❌ ERROR: SQL file not found: ' . $sql_file . '</p>';
    exit;
}

$sql_content = file_get_contents($sql_file);

// Remove comments and split by semicolon
$sql_content = preg_replace('/--.*$/m', '', $sql_content); // Remove single-line comments
$sql_content = preg_replace('/\/\*.*?\*\//s', '', $sql_content); // Remove multi-line comments

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
    echo '<h3 style="color: green;">✅ Migration 024 completed successfully!</h3>';
} else {
    echo '<h3 style="color: orange;">⚠️ Migration 024 completed with ' . $error_count . ' error(s).</h3>';
    echo '<p>Check the logs above for details. Some errors (like "column already exists") can be safely ignored if you\'re re-running the migration.</p>';
}

// Verification
echo '<hr>';
echo '<h2>🔍 Verification</h2>';

// Check if columns exist
$columns_to_check = [
    'is_manual',
    'manual_reason',
    'created_by',
    'approved_by',
    'approved_manually_at',
    'requires_approval'
];

echo '<h3>wp_limpvix_payouts columns:</h3>';
echo '<ul>';
foreach ($columns_to_check as $col) {
    $query = $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
        DB_NAME,
        $wpdb->prefix . 'limpvix_payouts',
        $col
    );
    $exists = $wpdb->get_var($query);

    if ($exists) {
        echo '<li style="color: green;">✅ <code>' . $col . '</code> exists</li>';
    } else {
        echo '<li style="color: red;">❌ <code>' . $col . '</code> missing</li>';
    }
}
echo '</ul>';

// Check if audit trail table exists
$audit_table = $wpdb->prefix . 'limpvix_payout_audit_trail';
$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$audit_table'");

if ($table_exists) {
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table");
    echo '<p style="color: green;">✅ Table <code>wp_limpvix_payout_audit_trail</code> exists (rows: ' . $count . ')</p>';
} else {
    echo '<p style="color: red;">❌ Table <code>wp_limpvix_payout_audit_trail</code> does not exist</p>';
}

// Check status enum
$status_column_type = $wpdb->get_var(
    "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = '" . DB_NAME . "'
     AND TABLE_NAME = '{$wpdb->prefix}limpvix_payouts'
     AND COLUMN_NAME = 'status'"
);

echo '<h3>Status ENUM values:</h3>';
echo '<pre>' . htmlspecialchars($status_column_type) . '</pre>';

if (strpos($status_column_type, 'manual_pending') !== false) {
    echo '<p style="color: green;">✅ <code>manual_pending</code> status added successfully</p>';
} else {
    echo '<p style="color: red;">❌ <code>manual_pending</code> status not found in ENUM</p>';
}

echo '<hr>';
echo '<p><a href="' . admin_url('admin.php?page=limpvix-settings&tab=dependencias') . '">← Back to Settings</a></p>';
