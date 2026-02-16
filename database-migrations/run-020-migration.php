<?php
/**
 * Migration Runner 020 - Add KYC Fields to Professionals
 *
 * Execute: php run-020-migration.php
 */

// Load WordPress
require_once __DIR__ . '/../../../../wp-load.php';

global $wpdb;

echo "========================================\n";
echo "Migration 020: Add KYC Fields\n";
echo "========================================\n\n";

// Read SQL file
$sqlFile = __DIR__ . '/020_add_kyc_fields.sql';

if (!file_exists($sqlFile)) {
    die("❌ ERRO: Arquivo SQL não encontrado: {$sqlFile}\n");
}

$sql = file_get_contents($sqlFile);

// Remove comments and split by semicolon
$sql = preg_replace('/--.*$/m', '', $sql);
$sql = preg_replace('/\/\*.*?\*\//s', '', $sql);

// Execute SQL
echo "📝 Executando migration 020...\n\n";

try {
    $result = $wpdb->query($sql);
    
    if ($result === false) {
        throw new Exception($wpdb->last_error);
    }
    
    echo "✅ Migration 020 executada com sucesso!\n\n";
    
    // Verify columns were added
    echo "🔍 Verificando campos adicionados...\n\n";
    
    $columns = $wpdb->get_results(
        "SHOW COLUMNS FROM {$wpdb->prefix}limpvix_professionals WHERE Field LIKE 'kyc_%'"
    );
    
    if (empty($columns)) {
        throw new Exception("Nenhum campo KYC encontrado após migration!");
    }
    
    echo "Campos KYC adicionados:\n";
    foreach ($columns as $column) {
        echo "  ✓ {$column->Field} ({$column->Type})\n";
    }
    
    echo "\n✅ Migration 020 concluída!\n";
    echo "\nTotal de campos KYC: " . count($columns) . "\n";
    
} catch (Exception $e) {
    echo "❌ ERRO ao executar migration:\n";
    echo $e->getMessage() . "\n";
    exit(1);
}

echo "\n========================================\n";
echo "PRÓXIMOS PASSOS:\n";
echo "========================================\n";
echo "1. Verificar campos no banco de dados\n";
echo "2. Atualizar Professional entity com métodos KYC\n";
echo "3. Criar Use Cases (ProcessKYC, UploadDocument)\n";
echo "4. Criar Admin UI para gerenciamento KYC\n";
echo "5. Criar REST API para upload de documentos\n";
echo "========================================\n";
