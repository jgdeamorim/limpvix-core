<?php
/**
 * Script temporário para executar migration 010
 * Usar: docker exec limpvix_wordpress php /var/www/html/wp-content/plugins/limpvix-core/run-migration-010.php
 */

// Carregar WordPress
require_once('/var/www/html/wp-load.php');

global $wpdb;

// Ler migration
$sqlFile = __DIR__ . '/database-migrations/010_create_professionals_module.sql';
$sql = file_get_contents($sqlFile);

// Remover comentários e dividir em statements
$sql = preg_replace('/^--.*$/m', '', $sql);
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);
$statements = array_filter(array_map('trim', explode(';', $sql)));

echo "🚀 Executando Migration 010...\n";
echo "Total de statements: " . count($statements) . "\n\n";

$success = 0;
$errors = 0;

foreach ($statements as $i => $statement) {
    if (empty($statement)) continue;
    
    echo "Statement " . ($i + 1) . ": ";
    
    // Executar
    $result = $wpdb->query($statement);
    
    if ($result === false) {
        echo "❌ ERRO\n";
        echo "Erro: " . $wpdb->last_error . "\n";
        echo "SQL: " . substr($statement, 0, 100) . "...\n\n";
        $errors++;
    } else {
        echo "✅ OK\n";
        $success++;
    }
}

echo "\n📊 Resultado:\n";
echo "✅ Sucesso: $success\n";
echo "❌ Erros: $errors\n";

if ($errors === 0) {
    echo "\n🎉 Migration 010 executada com sucesso!\n";
    exit(0);
} else {
    echo "\n⚠️ Migration 010 com erros!\n";
    exit(1);
}
