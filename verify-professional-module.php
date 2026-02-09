<?php
require_once('/var/www/html/wp-load.php');
global $wpdb;

echo "🔍 VERIFICAÇÃO DO PROFESSIONAL MODULE\n";
echo "=====================================\n\n";

$tables = [
    'wp_limpvix_professionals',
    'wp_limpvix_professional_allocations_history',
    'wp_limpvix_contract_offers',
    'wp_limpvix_professional_score_history',
    'wp_limpvix_contracts'
];

$allExist = true;

foreach ($tables as $table) {
    $exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    if ($exists) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM $table");
        echo "✅ $table (registros: $count)\n";
    } else {
        echo "❌ $table NÃO EXISTE!\n";
        $allExist = false;
    }
}

echo "\n";

// Verificar campos novos em wp_limpvix_contracts
echo "📋 Campos adicionados em wp_limpvix_contracts:\n";
$fields = ['allocated_professional_id', 'allocation_status', 'allocation_attempts'];
foreach ($fields as $field) {
    $exists = $wpdb->get_var("SHOW COLUMNS FROM wp_limpvix_contracts LIKE '$field'");
    echo ($exists ? "✅ " : "❌ ") . "$field\n";
}

echo "\n";

// Verificar campos novos em wp_limpvix_briefings
echo "📋 Campos adicionados em wp_limpvix_briefings:\n";
$fields = ['is_recurrent', 'recurrence_type', 'recurrence_duration'];
foreach ($fields as $field) {
    $exists = $wpdb->get_var("SHOW COLUMNS FROM wp_limpvix_briefings LIKE '$field'");
    echo ($exists ? "✅ " : "❌ ") . "$field\n";
}

echo "\n";

if ($allExist) {
    echo "🎉 MIGRATION 010 CONCLUÍDA COM SUCESSO!\n";
    echo "Professional Module está pronto para uso.\n";
} else {
    echo "⚠️ Algumas tabelas estão faltando!\n";
}
