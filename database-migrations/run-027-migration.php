<?php
// Security: Block direct HTTP access (allow CLI only)
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    die('Direct access not allowed.');
}

/**
 * Migration 027 — Payout Dual-Mode Fields
 * Executar: docker exec limpvix_wordpress_clean php /var/www/html/wp-content/plugins/limpvix-core/database-migrations/run-027-migration.php
 */
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_URI'] = '/';
require dirname(__DIR__, 4) . '/wp-load.php';

global $wpdb;

$ok  = 0;
$skipped = 0;
$errors  = 0;

function run_sql(string $label, string $sql): void {
    global $wpdb, $ok, $skipped, $errors;
    $wpdb->hide_errors();
    $result = $wpdb->query($sql);
    $err    = $wpdb->last_error;
    if ($result === false && !empty($err)) {
        // Ignorar se coluna/índice já existe
        if (
            stripos($err, 'Duplicate column name') !== false ||
            stripos($err, 'Duplicate key name') !== false ||
            stripos($err, 'already exists') !== false
        ) {
            echo "  ℹ️  SKIP (já existe): {$label}\n";
            $skipped++;
        } else {
            echo "  ❌ ERRO [{$label}]: {$err}\n";
            $errors++;
        }
    } else {
        echo "  ✅ OK: {$label}\n";
        $ok++;
    }
}

$profs   = $wpdb->prefix . 'limpvix_professionals';
$payouts = $wpdb->prefix . 'limpvix_payouts';

echo "\n=== Migration 027: Payout Dual-Mode Fields ===\n\n";

echo "--- wp_limpvix_professionals: campos OAuth MP ---\n";

run_sql('preferred_payout_method', "ALTER TABLE `{$profs}` ADD COLUMN `preferred_payout_method` ENUM('mp_oauth','pix_manual') NOT NULL DEFAULT 'pix_manual' AFTER `pix_key_type`");

run_sql('mp_oauth_status', "ALTER TABLE `{$profs}` ADD COLUMN `mp_oauth_status` ENUM('connected','expired','revoked','not_connected') NOT NULL DEFAULT 'not_connected' AFTER `preferred_payout_method`");

run_sql('mp_access_token', "ALTER TABLE `{$profs}` ADD COLUMN `mp_access_token` TEXT DEFAULT NULL AFTER `mp_oauth_status`");

run_sql('mp_refresh_token', "ALTER TABLE `{$profs}` ADD COLUMN `mp_refresh_token` TEXT DEFAULT NULL AFTER `mp_access_token`");

run_sql('mp_user_id', "ALTER TABLE `{$profs}` ADD COLUMN `mp_user_id` VARCHAR(100) DEFAULT NULL AFTER `mp_refresh_token`");

run_sql('mp_oauth_connected_at', "ALTER TABLE `{$profs}` ADD COLUMN `mp_oauth_connected_at` DATETIME DEFAULT NULL AFTER `mp_user_id`");

run_sql('mp_oauth_expires_at', "ALTER TABLE `{$profs}` ADD COLUMN `mp_oauth_expires_at` DATETIME DEFAULT NULL AFTER `mp_oauth_connected_at`");

run_sql('INDEX idx_prof_payout_method', "ALTER TABLE `{$profs}` ADD INDEX `idx_prof_payout_method` (`preferred_payout_method`)");

run_sql('INDEX idx_prof_mp_oauth_status', "ALTER TABLE `{$profs}` ADD INDEX `idx_prof_mp_oauth_status` (`mp_oauth_status`)");

echo "\n--- wp_limpvix_payouts: método, retry e controle PIX ---\n";

run_sql('payout_method', "ALTER TABLE `{$payouts}` ADD COLUMN `payout_method` ENUM('mp_oauth','pix_manual') NOT NULL DEFAULT 'pix_manual' AFTER `status`");

run_sql('retry_count', "ALTER TABLE `{$payouts}` ADD COLUMN `retry_count` TINYINT UNSIGNED NOT NULL DEFAULT 0 AFTER `payout_method`");

run_sql('max_retries', "ALTER TABLE `{$payouts}` ADD COLUMN `max_retries` TINYINT UNSIGNED NOT NULL DEFAULT 3 AFTER `retry_count`");

run_sql('manually_marked_paid_by', "ALTER TABLE `{$payouts}` ADD COLUMN `manually_marked_paid_by` INT UNSIGNED DEFAULT NULL AFTER `max_retries`");

run_sql('manually_marked_paid_at', "ALTER TABLE `{$payouts}` ADD COLUMN `manually_marked_paid_at` DATETIME DEFAULT NULL AFTER `manually_marked_paid_by`");

run_sql('manual_payment_proof', "ALTER TABLE `{$payouts}` ADD COLUMN `manual_payment_proof` TEXT DEFAULT NULL AFTER `manually_marked_paid_at`");

run_sql('hold_until', "ALTER TABLE `{$payouts}` ADD COLUMN `hold_until` DATETIME DEFAULT NULL AFTER `manual_payment_proof`");

run_sql('INDEX idx_payout_method', "ALTER TABLE `{$payouts}` ADD INDEX `idx_payout_method` (`payout_method`)");

run_sql('INDEX idx_payout_hold_until', "ALTER TABLE `{$payouts}` ADD INDEX `idx_payout_hold_until` (`hold_until`)");

echo "\n=== RESULTADO: {$ok} OK | {$skipped} skip | {$errors} erros ===\n\n";
