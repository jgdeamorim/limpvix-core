<?php
// Bootstrap WordPress
$wp_load = dirname(__FILE__) . '/../../../../wp-load.php';
require_once $wp_load;

global $wpdb;

echo "=== MIGRATION 019: Professional Skills Table ===\n\n";

// Ler arquivo SQL
$sql_file = __DIR__ . '/019_create_professional_skills_table.sql';
$sql = file_get_contents($sql_file);

// Remover comentários
$sql = preg_replace('/^--.*$/m', '', $sql);
$sql = preg_replace('/^\s*[\r\n]/m', '', $sql);

// Executar
$result = $wpdb->query($sql);

if ($result === false) {
    echo "❌ ERROR: " . $wpdb->last_error . "\n";
    exit(1);
}

echo "✅ Migration executed successfully!\n";

// Verificar tabela
$table = $wpdb->prefix . 'limpvix_professional_skills';
$exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");

if ($exists) {
    echo "✅ Table $table created!\n";
    $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
    echo "📊 Total rows: $count\n";
} else {
    echo "❌ Table not found!\n";
}
