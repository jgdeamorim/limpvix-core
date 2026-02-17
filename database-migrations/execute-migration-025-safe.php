<?php
/**
 * Execute Migration 025: Add Required Skills to Service Catalog (SAFE VERSION)
 *
 * Run in browser: /wp-content/plugins/limpvix-core/database-migrations/execute-migration-025-safe.php
 *
 * This version handles all edge cases:
 * - Checks if column exists before adding
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
</style></head><body>';

echo '<h1 class="info">🔧 Migration 025: Add Required Skills (SAFE VERSION)</h1>';
echo '<hr>';

$table = $wpdb->prefix . 'limpvix_service_catalog';

// =========================================================================
// STEP 1: Verify table exists
// =========================================================================
echo '<h2>Step 1: Verify table exists</h2>';

$table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

if (!$table_exists) {
    echo '<p class="error">❌ ERROR: Table ' . $table . ' does not exist!</p>';
    echo '<p>This table should have been created in migration 009.</p>';
    echo '<p>Please run migration 009 first.</p>';
    exit;
}

echo '<p class="success">✅ Table ' . $table . ' exists</p>';

// =========================================================================
// STEP 2: Check if column already exists
// =========================================================================
echo '<hr><h2>Step 2: Check if required_skills column exists</h2>';

$column_exists = $wpdb->get_var(
    $wpdb->prepare(
        "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
         WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'required_skills'",
        DB_NAME,
        $table
    )
);

if ($column_exists) {
    echo '<p class="warning">⚠️ Column required_skills already exists</p>';
    echo '<p>Will skip column creation and proceed to data population.</p>';
} else {
    echo '<p class="info">ℹ️ Column required_skills does not exist yet</p>';

    // =========================================================================
    // STEP 3: Add column
    // =========================================================================
    echo '<hr><h2>Step 3: Add required_skills column</h2>';

    $sql = "ALTER TABLE $table
            ADD COLUMN required_skills JSON NULL
            COMMENT 'Array de skills necessárias para executar este serviço'";

    echo '<pre>' . htmlspecialchars($sql) . '</pre>';

    $result = $wpdb->query($sql);

    if ($result === false) {
        echo '<p class="error">❌ Failed to add column: ' . htmlspecialchars($wpdb->last_error) . '</p>';
        exit;
    }

    echo '<p class="success">✅ Column added successfully</p>';

    // Verify column was created
    $column_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND COLUMN_NAME = 'required_skills'",
            DB_NAME,
            $table
        )
    );

    if (!$column_exists) {
        echo '<p class="error">❌ Column creation verification failed!</p>';
        exit;
    }

    echo '<p class="success">✅ Column creation verified</p>';
}

// =========================================================================
// STEP 4: Show current table structure
// =========================================================================
echo '<hr><h2>Step 4: Current table structure</h2>';

$columns = $wpdb->get_results("SHOW COLUMNS FROM $table");

echo '<pre>';
foreach ($columns as $col) {
    $is_new = ($col->Field === 'required_skills') ? ' <-- NEW' : '';
    echo $col->Field . ' | ' . $col->Type . $is_new . "\n";
}
echo '</pre>';

// =========================================================================
// STEP 5: Check existing data
// =========================================================================
echo '<hr><h2>Step 5: Current services</h2>';

$services = $wpdb->get_results(
    "SELECT service_code, display_name, required_skills FROM $table ORDER BY service_code",
    ARRAY_A
);

if (empty($services)) {
    echo '<p class="warning">⚠️ No services found in table</p>';
    echo '<p>This is unusual. Table may be empty.</p>';
} else {
    echo '<p class="info">Found ' . count($services) . ' services:</p>';
    echo '<pre>';
    foreach ($services as $service) {
        $skills = $service['required_skills']
            ? json_decode($service['required_skills'], true)
            : null;

        $skills_str = $skills ? implode(', ', $skills) : 'NULL';

        echo sprintf(
            "%-30s | %-40s | %s\n",
            $service['service_code'],
            $service['display_name'],
            $skills_str
        );
    }
    echo '</pre>';
}

// =========================================================================
// STEP 6: Populate required_skills
// =========================================================================
echo '<hr><h2>Step 6: Populate required_skills for services</h2>';

$updates = [
    'residential_standard' => ['limpeza_residencial'],
    'residential_pre_move' => ['limpeza_residencial', 'limpeza_pesada'],
    'residential_post_construction' => ['limpeza_residencial', 'limpeza_pesada', 'limpeza_pos_obra'],
    'commercial_standard' => ['limpeza_comercial'],
    'commercial_pre_move' => ['limpeza_comercial', 'manutencao_piso'],
    'commercial_post_construction' => ['limpeza_comercial', 'manutencao_piso', 'limpeza_pos_obra'],
];

$updated_count = 0;
$skipped_count = 0;
$error_count = 0;

foreach ($updates as $service_code => $skills) {
    // Check if service exists
    $service_exists = $wpdb->get_var(
        $wpdb->prepare(
            "SELECT service_code FROM $table WHERE service_code = %s",
            $service_code
        )
    );

    if (!$service_exists) {
        echo '<p class="warning">⚠️ Service ' . $service_code . ' not found (skipping)</p>';
        $skipped_count++;
        continue;
    }

    // Update skills
    $skills_json = json_encode($skills);

    $result = $wpdb->update(
        $table,
        ['required_skills' => $skills_json],
        ['service_code' => $service_code],
        ['%s'],
        ['%s']
    );

    if ($result === false) {
        echo '<p class="error">❌ Failed to update ' . $service_code . ': ' . htmlspecialchars($wpdb->last_error) . '</p>';
        $error_count++;
    } elseif ($result === 0) {
        echo '<p class="info">ℹ️ ' . $service_code . ' - no changes (already has same skills)</p>';
        $updated_count++; // Count as success
    } else {
        echo '<p class="success">✅ ' . $service_code . ' - updated with ' . implode(', ', $skills) . '</p>';
        $updated_count++;
    }
}

// =========================================================================
// STEP 7: Final verification
// =========================================================================
echo '<hr><h2>Step 7: Final verification</h2>';

$services_after = $wpdb->get_results(
    "SELECT service_code, display_name, required_skills FROM $table ORDER BY service_code",
    ARRAY_A
);

$populated_count = 0;
$null_count = 0;

echo '<table border="1" cellpadding="8" style="border-collapse: collapse; width: 100%;">';
echo '<thead><tr style="background: #2d2d2d;">';
echo '<th>Service Code</th><th>Display Name</th><th>Required Skills</th><th>Status</th>';
echo '</tr></thead><tbody>';

foreach ($services_after as $service) {
    $skills = $service['required_skills']
        ? json_decode($service['required_skills'], true)
        : null;

    $has_skills = !empty($skills);

    if ($has_skills) {
        $populated_count++;
        $status = '<span class="success">✅ Populated</span>';
        $skills_display = implode(', ', $skills);
    } else {
        $null_count++;
        $status = '<span class="warning">❌ NULL</span>';
        $skills_display = '<em>NULL</em>';
    }

    echo '<tr>';
    echo '<td><code>' . htmlspecialchars($service['service_code']) . '</code></td>';
    echo '<td>' . htmlspecialchars($service['display_name']) . '</td>';
    echo '<td>' . $skills_display . '</td>';
    echo '<td>' . $status . '</td>';
    echo '</tr>';
}

echo '</tbody></table>';

// =========================================================================
// SUMMARY
// =========================================================================
echo '<hr><h2>📊 Summary</h2>';

echo '<table border="1" cellpadding="10" style="border-collapse: collapse;">';
echo '<tr><th>Metric</th><th>Value</th></tr>';
echo '<tr><td>Total services in table</td><td>' . count($services_after) . '</td></tr>';
echo '<tr><td>Services with skills populated</td><td class="success">' . $populated_count . '</td></tr>';
echo '<tr><td>Services with NULL skills</td><td class="warning">' . $null_count . '</td></tr>';
echo '<tr><td>Updates attempted</td><td>' . count($updates) . '</td></tr>';
echo '<tr><td>Updates successful</td><td class="success">' . $updated_count . '</td></tr>';
echo '<tr><td>Updates skipped</td><td class="warning">' . $skipped_count . '</td></tr>';
echo '<tr><td>Errors</td><td class="error">' . $error_count . '</td></tr>';
echo '</table>';

if ($error_count === 0 && $populated_count >= 6) {
    echo '<h3 class="success">✅ Migration 025 completed successfully!</h3>';
    echo '<p>At least 6 services now have required_skills populated.</p>';
} elseif ($error_count > 0) {
    echo '<h3 class="error">❌ Migration completed with errors</h3>';
    echo '<p>Please review the errors above.</p>';
} else {
    echo '<h3 class="warning">⚠️ Migration completed but some services not populated</h3>';
    echo '<p>This may be expected if your service_catalog has different service codes.</p>';
}

echo '<hr>';
echo '<p><a href="' . admin_url('admin.php?page=limpvix-service-catalog') . '">→ Go to Service Catalog Page</a></p>';
echo '<p><strong>Executed at:</strong> ' . date('Y-m-d H:i:s') . '</p>';

echo '</body></html>';
