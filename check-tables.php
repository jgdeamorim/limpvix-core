<?php
require_once('/var/www/html/wp-load.php');
global $wpdb;
$tables = $wpdb->get_results("SHOW TABLES LIKE 'wp_limpvix_contracts'", ARRAY_N);
if (count($tables) > 0) {
    echo "✅ Tabela wp_limpvix_contracts EXISTE\n";
} else {
    echo "❌ Tabela wp_limpvix_contracts NÃO EXISTE\n";
}
