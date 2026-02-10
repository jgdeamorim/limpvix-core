<?php
/**
 * Migration Runner: 015 - Add Feedback Window Tracking
 *
 * Execute via:
 * php -f database-migrations/run-015-migration.php
 *
 * Or from WordPress root:
 * wp eval-file database-migrations/run-015-migration.php
 *
 * @package LimpVix
 * @since 0.1.3
 */

// Bootstrap WordPress
$wp_load = __DIR__ . '/../wp-load.php';
if (!file_exists($wp_load)) {
    // Fallback for different directory structures
    $wp_load = '/var/www/html/wp-load.php';
}

if (!file_exists($wp_load)) {
    die("❌ ERROR: Cannot find wp-load.php\n");
}

require_once $wp_load;

echo "=== LimpVix Migration 015: Add Feedback Window Tracking ===\n\n";

global $wpdb;

// Read SQL file
$sql_file = __DIR__ . '/015_add_feedback_window_tracking.sql';

if (!file_exists($sql_file)) {
    die("❌ ERROR: Migration file not found: {$sql_file}\n");
}

$sql = file_get_contents($sql_file);

// Remove comments and split into statements
$lines = explode("\n", $sql);
$statements = [];
$current_statement = '';

foreach ($lines as $line) {
    $line = trim($line);

    // Skip empty lines and comments
    if (empty($line) || str_starts_with($line, '--')) {
        continue;
    }

    $current_statement .= ' ' . $line;

    // Check if statement is complete (ends with ;)
    if (str_ends_with($line, ';')) {
        $statements[] = trim($current_statement);
        $current_statement = '';
    }
}

// Execute each statement
$executed = 0;
$errors = [];

foreach ($statements as $statement) {
    // Skip verification queries (SELECT/SHOW)
    if (preg_match('/^\s*(SELECT|SHOW)/i', $statement)) {
        continue;
    }

    echo "Executing: " . substr($statement, 0, 80) . "...\n";

    $result = $wpdb->query($statement);

    if ($result === false) {
        $error = $wpdb->last_error;

        // Check if column already exists (safe to ignore)
        if (str_contains($error, "Duplicate column name 'feedback_window_expires_at'")) {
            echo "  ⚠️  Column already exists (skipping)\n";
            continue;
        }

        // Check if index already exists (safe to ignore)
        if (str_contains($error, "Duplicate key name 'idx_feedback_window_expires'")) {
            echo "  ⚠️  Index already exists (skipping)\n";
            continue;
        }

        // Other errors are critical
        $errors[] = [
            'statement' => $statement,
            'error' => $error
        ];
        echo "  ❌ ERROR: {$error}\n";
    } else {
        echo "  ✅ Success\n";
        $executed++;
    }
}

echo "\n=== Migration Summary ===\n";
echo "Statements executed: {$executed}\n";
echo "Errors: " . count($errors) . "\n";

if (count($errors) > 0) {
    echo "\n❌ Migration completed with errors:\n";
    foreach ($errors as $error) {
        echo "  - " . substr($error['statement'], 0, 80) . "...\n";
        echo "    Error: {$error['error']}\n";
    }
    exit(1);
}

echo "\n✅ Migration 015 completed successfully!\n\n";

// Verification
echo "=== Verification ===\n";

// Check wp_limpvix_executions column
$result = $wpdb->get_row("
    SELECT
        COLUMN_NAME,
        COLUMN_TYPE,
        IS_NULLABLE,
        COLUMN_COMMENT
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '{$wpdb->prefix}limpvix_executions'
      AND COLUMN_NAME = 'feedback_window_expires_at'
");

if ($result) {
    echo "✅ Column added to wp_limpvix_executions:\n";
    echo "   Type: {$result->COLUMN_TYPE}\n";
    echo "   Nullable: {$result->IS_NULLABLE}\n";
    echo "   Comment: {$result->COLUMN_COMMENT}\n";
} else {
    echo "❌ Column NOT found in wp_limpvix_executions\n";
}

// Check wp_limpvix_structured_feedbacks column
$result = $wpdb->get_row("
    SELECT
        COLUMN_NAME,
        COLUMN_TYPE,
        IS_NULLABLE
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = '{$wpdb->prefix}limpvix_structured_feedbacks'
      AND COLUMN_NAME = 'feedback_window_expires_at'
");

if ($result) {
    echo "✅ Column added to wp_limpvix_structured_feedbacks\n";
} else {
    echo "❌ Column NOT found in wp_limpvix_structured_feedbacks\n";
}

// Check index
$result = $wpdb->get_row("
    SHOW INDEX FROM {$wpdb->prefix}limpvix_executions
    WHERE Key_name = 'idx_feedback_window_expires'
");

if ($result) {
    echo "✅ Index idx_feedback_window_expires created\n";
} else {
    echo "❌ Index idx_feedback_window_expires NOT found\n";
}

echo "\n=== Migration 015 Complete ===\n";
echo "\nNext steps:\n";
echo "1. Deploy Execution aggregate changes (feedbackWindowExpiresAt property)\n";
echo "2. Deploy CheckFeedbackWindowStatus use case\n";
echo "3. Update ProcessTimerExpired to check feedback window\n";
echo "4. Test with sample orders\n";
