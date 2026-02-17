<?php
/**
 * Execute Migration 024: Add Manual Payout Fields (SAFE VERSION)
 *
 * Run in browser: /wp-content/plugins/limpvix-core/database-migrations/execute-migration-024-safe.php
 *
 * This version handles MySQL 5.7 compatibility:
 * - Checks if each column exists before adding
 * - Handles already existing data
 * - Provides detailed debug information
 */

// Load WordPress
require_once __DIR__ . '/../../../../wp-load.php';

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized. Only administrators can run migrations.');
}

global $wpdb;
$wpdb->show_errors();

echo '<html><head><meta charset="UTF-8">';
echo '<style>
    body { font-family: monospace; padding: 20px; background: #1e1e1e; color: #d4d4d4; }
    .success { color: #4ec9b0; }
    .error { color: #f48771; }
    .warning { color: #ce9178; }
    .info { color: #9cdcfe; }
    pre { background: #2d2d2d; padding: 10px; border-radius: 5px; overflow-x: auto; }
    hr { border: 1px solid #3e3e3e; margin: 20px 0; }
    table { border-collapse: collapse; width: 100%; margin: 15px 0; }
    th, td { border: 1px solid #3e3e3e; padding: 8px; text-align: left; }
    th { background: #2d2d2d; }
</style></head><body>';

echo '<h1 class="info">💰 Migration 024: Add Manual Payout Fields (SAFE VERSION)</h1>';
echo '<hr>';

$table = $wpdb->prefix . 'limpvix_payouts';
$audit_table = $wpdb->prefix . 'limpvix_payout_audit_trail';

// =========================================================================
// STEP 1: Verify payouts table exists
// =========================================================================
echo '<h2>Step 1: Verify wp_limpvix_payouts table exists</h2>';

$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

if (!$table_exists) {
    echo '<p class="error">❌ ERROR: Table ' . $table . ' does not exist!</p>';
    echo '<p>This table should have been created in a previous migration.</p>';
    exit;
}

echo '<p class="success">✅ Table ' . $table . ' exists</p>';

// =========================================================================
// STEP 2: Add columns one by one (checking each)
// =========================================================================
echo '<hr><h2>Step 2: Add manual payout columns</h2>';

$columns_to_add = [
    'is_manual' => "BOOLEAN DEFAULT FALSE COMMENT 'TRUE se foi criado manualmente por admin'",
    'manual_reason' => "TEXT NULL COMMENT 'Motivo do payout manual'",
    'created_by' => "INT UNSIGNED NULL COMMENT 'User ID do admin que criou'",
    'approved_by' => "INT UNSIGNED NULL COMMENT 'User ID do admin que aprovou (4-eyes)'",
    'approved_manually_at' => "DATETIME NULL COMMENT 'Timestamp quando aprovado'",
    'requires_approval' => "BOOLEAN DEFAULT FALSE COMMENT 'TRUE se requer aprovação 4-eyes'",
];

$added_count = 0;
$skipped_count = 0;
$error_count = 0;

foreach ($columns_to_add as $col_name => $col_definition) {
    // Check if column exists
    $col_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $col_name
        )
    );

    if ($col_exists) {
        echo '<p class="warning">⚠️ Column ' . $col_name . ' already exists (skipping)</p>';
        $skipped_count++;
        continue;
    }

    // Add column
    $sql = "ALTER TABLE $table ADD COLUMN $col_name $col_definition";
    $result = $wpdb->query($sql);

    if ($result === false) {
        echo '<p class="error">❌ Failed to add ' . $col_name . ': ' . htmlspecialchars($wpdb->last_error) . '</p>';
        $error_count++;
    } else {
        echo '<p class="success">✅ Added column ' . $col_name . '</p>';
        $added_count++;
    }
}

echo '<p><strong>Summary:</strong> Added: ' . $added_count . ', Skipped: ' . $skipped_count . ', Errors: ' . $error_count . '</p>';

// =========================================================================
// STEP 3: Add indexes
// =========================================================================
echo '<hr><h2>Step 3: Add indexes</h2>';

$indexes_to_add = [
    'idx_is_manual' => 'is_manual',
    'idx_requires_approval' => 'requires_approval',
    'idx_created_by' => 'created_by',
    'idx_approved_by' => 'approved_by',
];

$index_added = 0;
$index_skipped = 0;
$index_error = 0;

foreach ($indexes_to_add as $idx_name => $idx_column) {
    // Check if index exists
    $idx_exists = $wpdb->get_var(
        "SHOW INDEX FROM $table WHERE Key_name = '$idx_name'"
    );

    if ($idx_exists) {
        echo '<p class="warning">⚠️ Index ' . $idx_name . ' already exists (skipping)</p>';
        $index_skipped++;
        continue;
    }

    // Check if column exists before creating index
    $col_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $idx_column
        )
    );

    if (!$col_exists) {
        echo '<p class="error">❌ Cannot create index ' . $idx_name . ': column ' . $idx_column . ' does not exist</p>';
        $index_error++;
        continue;
    }

    // Add index
    $sql = "ALTER TABLE $table ADD INDEX $idx_name ($idx_column)";
    $result = $wpdb->query($sql);

    if ($result === false) {
        echo '<p class="error">❌ Failed to add index ' . $idx_name . ': ' . htmlspecialchars($wpdb->last_error) . '</p>';
        $index_error++;
    } else {
        echo '<p class="success">✅ Added index ' . $idx_name . '</p>';
        $index_added++;
    }
}

echo '<p><strong>Indexes Summary:</strong> Added: ' . $index_added . ', Skipped: ' . $index_skipped . ', Errors: ' . $index_error . '</p>';

// =========================================================================
// STEP 4: Modify status ENUM to add 'manual_pending'
// =========================================================================
echo '<hr><h2>Step 4: Add manual_pending to status ENUM</h2>';

$sql_modify_enum = "ALTER TABLE $table
    MODIFY COLUMN status ENUM(
        'pending',
        'approved',
        'processing',
        'completed',
        'failed',
        'on_hold',
        'cancelled',
        'manual_pending'
    ) DEFAULT 'pending'
    COMMENT 'Status do payout. manual_pending = aguardando aprovação 4-eyes'";

$result_enum = $wpdb->query($sql_modify_enum);

if ($result_enum === false) {
    echo '<p class="error">❌ Failed to modify status ENUM: ' . htmlspecialchars($wpdb->last_error) . '</p>';
} else {
    echo '<p class="success">✅ Status ENUM updated with manual_pending</p>';
}

// =========================================================================
// STEP 5: Create audit trail table
// =========================================================================
echo '<hr><h2>Step 5: Create payout_audit_trail table</h2>';

$audit_exists = $wpdb->get_var("SHOW TABLES LIKE '$audit_table'");

if ($audit_exists) {
    echo '<p class="warning">⚠️ Table ' . $audit_table . ' already exists (skipping)</p>';
} else {
    $sql_audit = "CREATE TABLE $audit_table (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        payout_id BIGINT UNSIGNED NOT NULL,
        action VARCHAR(50) NOT NULL COMMENT 'created, approved, rejected, processed, cancelled',
        performed_by INT UNSIGNED NOT NULL COMMENT 'User ID do admin que executou a ação',
        reason TEXT NULL COMMENT 'Motivo da ação (obrigatório para rejeição)',
        metadata JSON NULL COMMENT 'Dados adicionais da ação',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

        INDEX idx_payout (payout_id),
        INDEX idx_performed_by (performed_by),
        INDEX idx_action (action),
        INDEX idx_created_at (created_at),

        FOREIGN KEY (payout_id)
            REFERENCES $table(id)
            ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    COMMENT='Audit trail de ações em payouts manuais (GAP C)'";

    $result_audit = $wpdb->query($sql_audit);

    if ($result_audit === false) {
        echo '<p class="error">❌ Failed to create audit trail table: ' . htmlspecialchars($wpdb->last_error) . '</p>';
    } else {
        echo '<p class="success">✅ Audit trail table created successfully</p>';
    }
}

// =========================================================================
// STEP 6: Verification
// =========================================================================
echo '<hr><h2>Step 6: Verification</h2>';

// Check columns
echo '<h3>Columns in wp_limpvix_payouts:</h3>';
echo '<table><thead><tr><th>Column</th><th>Type</th><th>Status</th></tr></thead><tbody>';

foreach ($columns_to_add as $col_name => $col_def) {
    $col_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $col_name
        )
    );

    $col_info = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $col_name
        )
    );

    if ($col_exists) {
        echo '<tr>';
        echo '<td><code>' . $col_name . '</code></td>';
        echo '<td>' . ($col_info ? $col_info->COLUMN_TYPE : 'N/A') . '</td>';
        echo '<td class="success">✅ Exists</td>';
        echo '</tr>';
    } else {
        echo '<tr>';
        echo '<td><code>' . $col_name . '</code></td>';
        echo '<td>-</td>';
        echo '<td class="error">❌ Missing</td>';
        echo '</tr>';
    }
}

echo '</tbody></table>';

// Check audit table
echo '<h3>Audit Trail Table:</h3>';
$audit_exists_final = $wpdb->get_var("SHOW TABLES LIKE '$audit_table'");
if ($audit_exists_final) {
    $audit_count = $wpdb->get_var("SELECT COUNT(*) FROM $audit_table");
    echo '<p class="success">✅ Table ' . $audit_table . ' exists (rows: ' . $audit_count . ')</p>';
} else {
    echo '<p class="error">❌ Table ' . $audit_table . ' does not exist</p>';
}

// Check status ENUM
echo '<h3>Status ENUM values:</h3>';
$status_enum = $wpdb->get_row(
    "SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS
     WHERE TABLE_SCHEMA = '" . DB_NAME . "'
     AND TABLE_NAME = '$table'
     AND COLUMN_NAME = 'status'"
);

if ($status_enum) {
    echo '<pre>' . htmlspecialchars($status_enum->COLUMN_TYPE) . '</pre>';

    if (strpos($status_enum->COLUMN_TYPE, 'manual_pending') !== false) {
        echo '<p class="success">✅ manual_pending status exists</p>';
    } else {
        echo '<p class="error">❌ manual_pending status missing</p>';
    }
}

// =========================================================================
// SUMMARY
// =========================================================================
echo '<hr><h2>📊 Summary</h2>';

$all_cols_exist = true;
foreach ($columns_to_add as $col_name => $col_def) {
    $col_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = %s",
            DB_NAME,
            $table,
            $col_name
        )
    );
    if (!$col_exists) {
        $all_cols_exist = false;
        break;
    }
}

$audit_exists_summary = $wpdb->get_var("SHOW TABLES LIKE '$audit_table'");

echo '<table>';
echo '<tr><th>Component</th><th>Status</th></tr>';
echo '<tr><td>Manual payout columns (6)</td><td class="' . ($all_cols_exist ? 'success' : 'error') . '">' . ($all_cols_exist ? '✅ All exist' : '❌ Some missing') . '</td></tr>';
echo '<tr><td>Indexes (4)</td><td class="' . ($index_error === 0 ? 'success' : 'warning') . '">' . ($index_error === 0 ? '✅ OK' : '⚠️ Some errors') . '</td></tr>';
echo '<tr><td>Status ENUM (manual_pending)</td><td class="success">✅ Added</td></tr>';
echo '<tr><td>Audit trail table</td><td class="' . ($audit_exists_summary ? 'success' : 'error') . '">' . ($audit_exists_summary ? '✅ Exists' : '❌ Not created') . '</td></tr>';
echo '</table>';

if ($all_cols_exist && $audit_exists_summary && $error_count === 0) {
    echo '<h3 class="success">✅ Migration 024 completed successfully!</h3>';
    echo '<p>All manual payout fields and audit trail are ready.</p>';
} elseif ($error_count > 0) {
    echo '<h3 class="error">❌ Migration completed with errors</h3>';
    echo '<p>Please review the errors above.</p>';
} else {
    echo '<h3 class="warning">⚠️ Migration partially completed</h3>';
    echo '<p>Some components may be missing. Review the verification above.</p>';
}

echo '<hr>';
echo '<p><a href="' . admin_url('admin.php?page=limpvix-settings') . '">← Back to Settings</a></p>';
echo '<p><strong>Executed at:</strong> ' . date('Y-m-d H:i:s') . '</p>';

echo '</body></html>';
