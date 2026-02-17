<?php
/**
 * Execute ALL GAPs Migrations (023, 024, 025)
 *
 * Run in browser: /wp-content/plugins/limpvix-core/database-migrations/execute-all-gaps-migrations.php
 *
 * Executes:
 * - Migration 023: Professional Documents Table (GAP A)
 * - Migration 024: Manual Payout Fields (GAP C)
 * - Migration 025: Service Catalog Required Skills (GAP D)
 */

// Load WordPress
require_once __DIR__ . '/../../../../wp-load.php';

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized. Only administrators can run migrations.');
}

global $wpdb;
$wpdb->show_errors();

echo '<html><head><meta charset="UTF-8"><title>Execute GAPs Migrations</title>';
echo '<style>
    body { font-family: Arial, sans-serif; max-width: 1200px; margin: 20px auto; padding: 20px; }
    h1 { color: #333; border-bottom: 3px solid #0073aa; padding-bottom: 10px; }
    h2 { color: #0073aa; margin-top: 30px; }
    .success { color: green; background: #e7f5e7; padding: 10px; border-left: 4px solid green; margin: 10px 0; }
    .error { color: red; background: #ffe7e7; padding: 10px; border-left: 4px solid red; margin: 10px 0; }
    .info { color: #0073aa; background: #e7f3ff; padding: 10px; border-left: 4px solid #0073aa; margin: 10px 0; }
    .migration-block { background: #f9f9f9; padding: 15px; margin: 20px 0; border-radius: 5px; }
    table { width: 100%; border-collapse: collapse; margin: 15px 0; }
    th, td { border: 1px solid #ddd; padding: 12px; text-align: left; }
    th { background: #0073aa; color: white; }
    tr:nth-child(even) { background: #f5f5f5; }
    pre { background: #f5f5f5; padding: 10px; overflow-x: auto; border-radius: 3px; }
    .status-ok { color: green; font-weight: bold; }
    .status-pending { color: orange; font-weight: bold; }
</style></head><body>';

echo '<h1>🚀 Execute ALL GAPs Migrations (023, 024, 025)</h1>';
echo '<p><strong>Purpose:</strong> Execute all pending migrations for GAPs A, C, D</p>';
echo '<hr>';

// Track overall stats
$totalMigrations = 3;
$successfulMigrations = 0;
$failedMigrations = 0;

// =============================================================================
// MIGRATION 023: Professional Documents Table (GAP A)
// =============================================================================

echo '<div class="migration-block">';
echo '<h2>📄 Migration 023: Professional Documents Table (GAP A - Document Upload/KYC)</h2>';

$sql_file_023 = __DIR__ . '/023_create_professional_documents_table.sql';

if (!file_exists($sql_file_023)) {
    echo '<div class="error">❌ ERROR: SQL file not found: ' . $sql_file_023 . '</div>';
    $failedMigrations++;
} else {
    $sql_content_023 = file_get_contents($sql_file_023);

    // Remove comments
    $sql_content_023 = preg_replace('/--.*$/m', '', $sql_content_023);
    $sql_content_023 = preg_replace('/\/\*.*?\*\//s', '', $sql_content_023);

    $statements_023 = array_filter(
        array_map('trim', explode(';', $sql_content_023)),
        function($stmt) {
            return !empty($stmt) && strlen($stmt) > 5;
        }
    );

    echo '<p><strong>Statements to execute:</strong> ' . count($statements_023) . '</p>';

    $success_count_023 = 0;
    $error_count_023 = 0;

    foreach ($statements_023 as $i => $statement) {
        $statement_num = $i + 1;
        $result = $wpdb->query($statement);

        if ($result === false) {
            echo '<div class="error">Statement ' . $statement_num . ' FAILED: ' . htmlspecialchars($wpdb->last_error) . '</div>';
            $error_count_023++;
        } else {
            $success_count_023++;
        }
    }

    echo '<div class="' . ($error_count_023 === 0 ? 'success' : 'info') . '">';
    echo '📊 <strong>Migration 023 Results:</strong> ';
    echo $success_count_023 . ' success, ' . $error_count_023 . ' errors';
    echo '</div>';

    if ($error_count_023 === 0) {
        $successfulMigrations++;
    } else {
        $failedMigrations++;
    }

    // Verify table created
    $table_023 = $wpdb->prefix . 'limpvix_professional_documents';
    $table_exists_023 = $wpdb->get_var("SHOW TABLES LIKE '$table_023'");

    if ($table_exists_023) {
        echo '<div class="success">✅ Table <code>' . $table_023 . '</code> exists</div>';

        // Show column count
        $columns_023 = $wpdb->get_results("SHOW COLUMNS FROM $table_023");
        echo '<p>Table has <strong>' . count($columns_023) . '</strong> columns</p>';
    } else {
        echo '<div class="error">❌ Table <code>' . $table_023 . '</code> NOT created</div>';
    }
}

echo '</div>';

// =============================================================================
// MIGRATION 024: Manual Payout Fields (GAP C)
// =============================================================================

echo '<div class="migration-block">';
echo '<h2>💰 Migration 024: Manual Payout Fields (GAP C - ManualPayout para Admin)</h2>';

$sql_file_024 = __DIR__ . '/024_add_manual_payout_fields.sql';

if (!file_exists($sql_file_024)) {
    echo '<div class="error">❌ ERROR: SQL file not found: ' . $sql_file_024 . '</div>';
    $failedMigrations++;
} else {
    $sql_content_024 = file_get_contents($sql_file_024);

    // Remove comments
    $sql_content_024 = preg_replace('/--.*$/m', '', $sql_content_024);
    $sql_content_024 = preg_replace('/\/\*.*?\*\//s', '', $sql_content_024);

    $statements_024 = array_filter(
        array_map('trim', explode(';', $sql_content_024)),
        function($stmt) {
            return !empty($stmt) && strlen($stmt) > 5;
        }
    );

    echo '<p><strong>Statements to execute:</strong> ' . count($statements_024) . '</p>';

    $success_count_024 = 0;
    $error_count_024 = 0;

    foreach ($statements_024 as $i => $statement) {
        $statement_num = $i + 1;
        $result = $wpdb->query($statement);

        if ($result === false) {
            echo '<div class="error">Statement ' . $statement_num . ' FAILED: ' . htmlspecialchars($wpdb->last_error) . '</div>';
            $error_count_024++;
        } else {
            $success_count_024++;
        }
    }

    echo '<div class="' . ($error_count_024 === 0 ? 'success' : 'info') . '">';
    echo '📊 <strong>Migration 024 Results:</strong> ';
    echo $success_count_024 . ' success, ' . $error_count_024 . ' errors';
    echo '</div>';

    if ($error_count_024 === 0) {
        $successfulMigrations++;
    } else {
        $failedMigrations++;
    }

    // Verify columns added and table created
    $table_payouts = $wpdb->prefix . 'limpvix_payouts';
    $table_audit = $wpdb->prefix . 'limpvix_payout_audit_trail';

    // Check payouts table columns
    $manual_columns = ['is_manual', 'manual_reason', 'created_by', 'approved_by'];
    $existing_cols = $wpdb->get_results("SHOW COLUMNS FROM $table_payouts WHERE Field IN ('" . implode("','", $manual_columns) . "')");

    echo '<p><strong>Manual payout columns:</strong> ' . count($existing_cols) . '/' . count($manual_columns) . ' found</p>';

    // Check audit trail table
    $audit_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_audit'");
    if ($audit_exists) {
        echo '<div class="success">✅ Audit trail table <code>' . $table_audit . '</code> exists</div>';
    } else {
        echo '<div class="error">❌ Audit trail table NOT created</div>';
    }
}

echo '</div>';

// =============================================================================
// MIGRATION 025: Service Catalog Required Skills (GAP D)
// =============================================================================

echo '<div class="migration-block">';
echo '<h2>🛠️ Migration 025: Service Catalog Required Skills (GAP D - Database-Driven Mapping)</h2>';

$sql_file_025 = __DIR__ . '/025_add_service_catalog_required_skills.sql';

if (!file_exists($sql_file_025)) {
    echo '<div class="error">❌ ERROR: SQL file not found: ' . $sql_file_025 . '</div>';
    $failedMigrations++;
} else {
    $sql_content_025 = file_get_contents($sql_file_025);

    // Remove comments
    $sql_content_025 = preg_replace('/--.*$/m', '', $sql_content_025);
    $sql_content_025 = preg_replace('/\/\*.*?\*\//s', '', $sql_content_025);

    $statements_025 = array_filter(
        array_map('trim', explode(';', $sql_content_025)),
        function($stmt) {
            return !empty($stmt) && strlen($stmt) > 5;
        }
    );

    echo '<p><strong>Statements to execute:</strong> ' . count($statements_025) . '</p>';

    $success_count_025 = 0;
    $error_count_025 = 0;

    foreach ($statements_025 as $i => $statement) {
        $statement_num = $i + 1;
        $result = $wpdb->query($statement);

        if ($result === false) {
            echo '<div class="error">Statement ' . $statement_num . ' FAILED: ' . htmlspecialchars($wpdb->last_error) . '</div>';
            $error_count_025++;
        } else {
            $success_count_025++;
        }
    }

    echo '<div class="' . ($error_count_025 === 0 ? 'success' : 'info') . '">';
    echo '📊 <strong>Migration 025 Results:</strong> ';
    echo $success_count_025 . ' success, ' . $error_count_025 . ' errors';
    echo '</div>';

    if ($error_count_025 === 0) {
        $successfulMigrations++;
    } else {
        $failedMigrations++;
    }

    // Verify column added
    $table_catalog = $wpdb->prefix . 'limpvix_service_catalog';
    $column_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'required_skills'",
            DB_NAME,
            $table_catalog
        )
    );

    if ($column_exists) {
        echo '<div class="success">✅ Column <code>required_skills</code> exists in <code>' . $table_catalog . '</code></div>';

        // Show services with skills
        $services = $wpdb->get_results(
            "SELECT service_code, display_name, required_skills FROM $table_catalog ORDER BY service_code",
            ARRAY_A
        );

        if ($services) {
            echo '<h3>Services with Required Skills:</h3>';
            echo '<table>';
            echo '<thead><tr><th>Service Code</th><th>Display Name</th><th>Required Skills</th></tr></thead><tbody>';

            foreach ($services as $service) {
                echo '<tr>';
                echo '<td><code>' . htmlspecialchars($service['service_code']) . '</code></td>';
                echo '<td>' . htmlspecialchars($service['display_name']) . '</td>';

                if (!empty($service['required_skills'])) {
                    $skills = json_decode($service['required_skills'], true);
                    if ($skills) {
                        echo '<td class="status-ok">✅ ' . implode(', ', array_map('htmlspecialchars', $skills)) . '</td>';
                    } else {
                        echo '<td class="status-pending">⚠️ Invalid JSON</td>';
                    }
                } else {
                    echo '<td class="status-pending">❌ NULL (not populated)</td>';
                }

                echo '</tr>';
            }

            echo '</tbody></table>';
        }
    } else {
        echo '<div class="error">❌ Column <code>required_skills</code> NOT found in <code>' . $table_catalog . '</code></div>';
    }
}

echo '</div>';

// =============================================================================
// FINAL SUMMARY
// =============================================================================

echo '<hr>';
echo '<h2>📊 Final Summary</h2>';

echo '<table>';
echo '<tr><th>Metric</th><th>Value</th></tr>';
echo '<tr><td>Total Migrations</td><td>' . $totalMigrations . '</td></tr>';
echo '<tr><td>Successful</td><td class="status-ok">' . $successfulMigrations . '</td></tr>';
echo '<tr><td>Failed</td><td>' . $failedMigrations . '</td></tr>';
echo '</table>';

if ($failedMigrations === 0) {
    echo '<div class="success">';
    echo '<h3>✅ ALL MIGRATIONS COMPLETED SUCCESSFULLY!</h3>';
    echo '<p>All GAPs (A, C, D) migrations have been executed and verified.</p>';
    echo '</div>';
} else {
    echo '<div class="error">';
    echo '<h3>⚠️ Some Migrations Failed</h3>';
    echo '<p>Review the errors above. Some errors (like "column already exists") can be safely ignored if re-running migrations.</p>';
    echo '</div>';
}

echo '<hr>';
echo '<p><a href="' . admin_url('admin.php?page=limpvix-settings') . '">← Back to LimpVix Settings</a></p>';
echo '<p><strong>Date Executed:</strong> ' . date('Y-m-d H:i:s') . '</p>';

echo '</body></html>';
