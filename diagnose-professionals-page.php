<?php
/**
 * Diagnose Professionals Page Issues
 *
 * Run in browser: /wp-content/plugins/limpvix-core/diagnose-professionals-page.php
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

// Security check
if (!current_user_can('manage_options')) {
    wp_die('Unauthorized');
}

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

echo '<h1 class="info">🔍 Diagnose Professionals Page</h1>';
echo '<hr>';

// ========================================
// 1. Check if ProfessionalBootstrap exists
// ========================================
echo '<h2>1. ProfessionalBootstrap Class</h2>';

if (class_exists('LimpVix\\Core\\ProfessionalBootstrap')) {
    echo '<p class="success">✅ Class exists</p>';
} else {
    echo '<p class="error">❌ Class NOT found</p>';
}

// ========================================
// 2. Check if ProfessionalManagementPage exists
// ========================================
echo '<hr><h2>2. ProfessionalManagementPage Class</h2>';

if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ProfessionalManagementPage')) {
    echo '<p class="success">✅ Class exists</p>';
} else {
    echo '<p class="error">❌ Class NOT found</p>';
}

// ========================================
// 3. Check WordPress menu registration
// ========================================
echo '<hr><h2>3. WordPress Menu Registration</h2>';

global $menu, $submenu;

echo '<h3>Main Menu Items:</h3>';
echo '<pre>';
if (isset($menu)) {
    foreach ($menu as $item) {
        if (is_array($item) && strpos($item[2], 'limpvix') !== false) {
            echo 'Slug: ' . $item[2] . ' | Title: ' . $item[0] . "\n";
        }
    }
} else {
    echo 'No menu items found';
}
echo '</pre>';

echo '<h3>Submenu Items under limpvix-finance:</h3>';
echo '<pre>';
if (isset($submenu['limpvix-finance'])) {
    foreach ($submenu['limpvix-finance'] as $item) {
        echo 'Slug: ' . $item[2] . ' | Title: ' . $item[0] . "\n";
    }
} else {
    echo 'No submenu items found for limpvix-finance';
}
echo '</pre>';

// ========================================
// 4. Check database tables
// ========================================
echo '<hr><h2>4. Database Tables</h2>';

global $wpdb;

$tables_to_check = [
    'limpvix_professionals',
    'limpvix_service_catalog',
    'limpvix_payouts',
    'limpvix_professional_documents',
    'limpvix_payout_audit_trail',
];

foreach ($tables_to_check as $table_name) {
    $full_table_name = $wpdb->prefix . $table_name;
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$full_table_name'");

    if ($exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $full_table_name");
        echo '<p class="success">✅ ' . $full_table_name . ' exists (rows: ' . $count . ')</p>';
    } else {
        echo '<p class="error">❌ ' . $full_table_name . ' NOT found</p>';
    }
}

// ========================================
// 5. Check if required_skills column exists
// ========================================
echo '<hr><h2>5. Service Catalog required_skills Column</h2>';

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
    echo '<p class="success">✅ Column required_skills exists</p>';

    // Check if services have skills
    $services_with_skills = $wpdb->get_var(
        "SELECT COUNT(*) FROM $table WHERE required_skills IS NOT NULL"
    );

    echo '<p>Services with skills populated: ' . $services_with_skills . '</p>';
} else {
    echo '<p class="error">❌ Column required_skills NOT found</p>';
    echo '<p class="warning">⚠️ This may cause fatal errors when accessing professionals page</p>';
}

// ========================================
// 6. Try to instantiate ProfessionalManagementPage
// ========================================
echo '<hr><h2>6. Try to Instantiate ProfessionalManagementPage</h2>';

try {
    if (class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ProfessionalManagementPage')) {
        $page = new LimpVix\Infrastructure\Admin\Pages\ProfessionalManagementPage();
        echo '<p class="success">✅ Successfully instantiated</p>';
        echo '<p>Page slug: limpvix-professionals</p>';
    } else {
        echo '<p class="error">❌ Class not found</p>';
    }
} catch (\Exception $e) {
    echo '<p class="error">❌ Error: ' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<pre>' . htmlspecialchars($e->getTraceAsString()) . '</pre>';
}

// ========================================
// 7. Check PHP errors in debug log
// ========================================
echo '<hr><h2>7. Recent PHP Errors (debug.log)</h2>';

$debug_log = WP_CONTENT_DIR . '/debug.log';

if (file_exists($debug_log)) {
    $lines = file($debug_log);
    $recent_errors = array_slice($lines, -30);

    echo '<pre>';
    foreach ($recent_errors as $line) {
        if (stripos($line, 'error') !== false || stripos($line, 'fatal') !== false) {
            echo '<span class="error">' . htmlspecialchars($line) . '</span>';
        } else {
            echo htmlspecialchars($line);
        }
    }
    echo '</pre>';
} else {
    echo '<p class="warning">⚠️ debug.log not found (WP_DEBUG may be disabled)</p>';
}

// ========================================
// 8. Test direct access to page
// ========================================
echo '<hr><h2>8. Test Direct Access</h2>';

echo '<p>Try accessing the page directly:</p>';
echo '<p><a href="' . admin_url('admin.php?page=limpvix-professionals') . '" target="_blank">';
echo 'Open Professionals Page';
echo '</a></p>';

// ========================================
// SUMMARY
// ========================================
echo '<hr><h2>📊 Summary</h2>';

$issues = [];

if (!class_exists('LimpVix\\Infrastructure\\Admin\\Pages\\ProfessionalManagementPage')) {
    $issues[] = 'ProfessionalManagementPage class not found';
}

if (!isset($submenu['limpvix-finance']) || !in_array('limpvix-professionals', array_column($submenu['limpvix-finance'], 2))) {
    $issues[] = 'Page not registered in WordPress menu';
}

if (!$column_exists) {
    $issues[] = 'required_skills column missing in service_catalog table';
}

if (empty($issues)) {
    echo '<p class="success">✅ No issues detected</p>';
    echo '<p>The page should be accessible. If you still see errors, check the debug.log above.</p>';
} else {
    echo '<p class="error">❌ Issues detected:</p>';
    echo '<ul>';
    foreach ($issues as $issue) {
        echo '<li class="error">' . $issue . '</li>';
    }
    echo '</ul>';
}

echo '<hr>';
echo '<p><strong>Executed at:</strong> ' . date('Y-m-d H:i:s') . '</p>';

echo '</body></html>';
