<?php
/**
 * Apply Migration 010: CREATE limpvix_financial_ledger
 *
 * ATENÇÃO: Execute via:
 * docker exec limpvix_wordpress php /var/www/html/wp-content/plugins/limpvix-core/diagnostics/apply-migration-010.php
 */

// Bootstrap WordPress
require_once '/var/www/html/wp-load.php';

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

global $wpdb;

echo "🔧 Aplicando Migration 010: limpvix_financial_ledger\n";
echo str_repeat('-', 60) . "\n\n";

// Ler SQL file
$sqlFile = dirname(__DIR__) . '/database-migrations/010_create_financial_ledger_table.sql';

if (!file_exists($sqlFile)) {
    die("❌ Migration file não encontrado: {$sqlFile}\n");
}

$sql = file_get_contents($sqlFile);

// Remover comentários SQL (linhas iniciando com --)
$lines = explode("\n", $sql);
$cleanLines = array_filter($lines, function($line) {
    $trimmed = trim($line);
    return !empty($trimmed) && !str_starts_with($trimmed, '--');
});
$sql = implode("\n", $cleanLines);

// Separar statements (por CREATE TABLE e INSERT INTO)
$statements = [];
$currentStatement = '';

foreach ($cleanLines as $line) {
    $currentStatement .= $line . "\n";

    // Se linha termina com ; e não está dentro de CREATE TABLE, é fim de statement
    if (str_contains($line, ';') &&
        (str_contains($currentStatement, 'INSERT INTO') ||
         str_contains($currentStatement, 'ON DUPLICATE KEY'))) {
        $statements[] = trim($currentStatement);
        $currentStatement = '';
    }
}

// CREATE TABLE sempre é um statement completo
if (str_contains($sql, 'CREATE TABLE')) {
    preg_match('/CREATE TABLE.*?ENGINE=InnoDB[^;]*/s', $sql, $matches);
    if (!empty($matches[0])) {
        array_unshift($statements, $matches[0]);
    }
}

echo "📋 Statements a executar: " . count($statements) . "\n\n";

$success = true;

foreach ($statements as $i => $statement) {
    $statement = trim($statement);
    if (empty($statement)) continue;

    echo "Executando statement " . ($i + 1) . "...\n";

    // Usar dbDelta para CREATE TABLE
    if (str_contains($statement, 'CREATE TABLE')) {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $result = dbDelta($statement);

        if (!empty($result)) {
            echo "✅ dbDelta executado:\n";
            foreach ($result as $table => $status) {
                echo "   - {$table}: {$status}\n";
            }
        } else {
            echo "⚠️  dbDelta não retornou resultado\n";
        }
    }
    // Usar query direto para INSERT
    else if (str_contains($statement, 'INSERT')) {
        $result = $wpdb->query($statement);

        if ($result !== false) {
            echo "✅ INSERT executado (affected rows: {$result})\n";
        } else {
            echo "❌ Erro no INSERT: " . $wpdb->last_error . "\n";
            $success = false;
        }
    }

    echo "\n";
}

// Verificar se tabela foi criada
$tableName = $wpdb->prefix . 'limpvix_financial_ledger';
$tableExists = $wpdb->get_var("SHOW TABLES LIKE '{$tableName}'") === $tableName;

echo str_repeat('-', 60) . "\n";

if ($tableExists) {
    echo "✅ Tabela {$tableName} criada com sucesso!\n\n";

    // Mostrar estrutura
    $columns = $wpdb->get_results("DESCRIBE {$tableName}", ARRAY_A);
    echo "📊 Estrutura da tabela:\n";
    foreach ($columns as $col) {
        echo sprintf("   - %-20s %-20s %s\n",
            $col['Field'],
            $col['Type'],
            $col['Key'] ? "[{$col['Key']}]" : ''
        );
    }

    echo "\n✅ Migration 010 aplicada com SUCESSO!\n";
} else {
    echo "❌ Tabela {$tableName} NÃO foi criada!\n";
    echo "❌ Migration 010 FALHOU!\n";
    exit(1);
}
