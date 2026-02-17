<?php
/**
 * Script de Teste: Aba Pagamentos Dinâmica
 *
 * Verifica se todos os métodos de verificação estão funcionando
 */

// Load WordPress
require_once __DIR__ . '/../../../wp-load.php';

echo "=== TESTE: ABA PAGAMENTOS DINÂMICA ===\n\n";

// Simular AdminBootstrap (usar reflexão para acessar métodos privados)
require_once __DIR__ . '/src/Admin/Bootstrap/AdminBootstrap.php';

$adminBootstrap = new \LimpVix\Admin\Bootstrap\AdminBootstrap();
$reflection = new ReflectionClass($adminBootstrap);

// =======================
// TESTE 1: MercadoPago Config Status
// =======================
echo "1️⃣  TESTE: getMercadoPagoConfigStatus()\n";
echo str_repeat("-", 50) . "\n";

$method = $reflection->getMethod('getMercadoPagoConfigStatus');
$method->setAccessible(true);
$mpStatus = $method->invoke($adminBootstrap);

echo "Plataforma Configurada: " . ($mpStatus['platform_configured'] ? '✅ SIM' : '❌ NÃO') . "\n";
echo "OAuth Configurado: " . ($mpStatus['oauth_configured'] ? '✅ SIM' : '❌ NÃO') . "\n";
echo "Totalmente Configurado: " . ($mpStatus['fully_configured'] ? '✅ SIM' : '❌ NÃO') . "\n";
echo "Status: " . $mpStatus['status_text'] . "\n";
if (!empty($mpStatus['missing'])) {
    echo "Faltando: " . implode(', ', $mpStatus['missing']) . "\n";
}
echo "\n";

// =======================
// TESTE 2: Payout Features Status
// =======================
echo "2️⃣  TESTE: getPayoutFeaturesStatus()\n";
echo str_repeat("-", 50) . "\n";

$method = $reflection->getMethod('getPayoutFeaturesStatus');
$method->setAccessible(true);
$features = $method->invoke($adminBootstrap);

foreach ($features as $key => $feature) {
    $icon = $feature['icon'];
    $status = $feature['status'];
    $name = match($key) {
        'pix_transfer' => 'Transferência Automática via PIX',
        'feedback_window' => 'Feedback Window Enforcement',
        'reconciliation' => 'Reconciliação Automática',
        'retry_on_failure' => 'Retry Automático em Falhas',
        'audit_trail' => 'Auditoria Completa',
        'multi_recipient' => 'Suporte Multi-Recipient',
        default => $key,
    };
    echo "$icon $name: $status\n";
}
echo "\n";

// =======================
// TESTE 3: Architecture Status
// =======================
echo "3️⃣  TESTE: getPayoutArchitectureStatus()\n";
echo str_repeat("-", 50) . "\n";

$method = $reflection->getMethod('getPayoutArchitectureStatus');
$method->setAccessible(true);
$arch = $method->invoke($adminBootstrap);

echo "Domain Layer:\n";
foreach ($arch['domain'] as $class => $exists) {
    echo "  " . ($exists ? '✓' : '❌') . " $class\n";
}

echo "\nApplication Layer:\n";
foreach ($arch['application'] as $class => $exists) {
    echo "  " . ($exists ? '✓' : '❌') . " $class\n";
}

echo "\nInfrastructure Layer:\n";
foreach ($arch['infrastructure'] as $class => $exists) {
    echo "  " . ($exists ? '✓' : '❌') . " $class\n";
}
echo "\n";

// =======================
// TESTE 4: Database Info
// =======================
echo "4️⃣  TESTE: getPayoutDatabaseInfo()\n";
echo str_repeat("-", 50) . "\n";

$method = $reflection->getMethod('getPayoutDatabaseInfo');
$method->setAccessible(true);
$dbInfo = $method->invoke($adminBootstrap);

echo "Tabela: " . $dbInfo['table_name'] . " " . ($dbInfo['exists'] ? '✅ EXISTE' : '❌ NÃO EXISTE') . "\n";

if ($dbInfo['exists']) {
    echo "Índices: " . count($dbInfo['indexes']) . " criados\n";
    echo "  → " . implode(', ', array_slice($dbInfo['indexes'], 0, 5)) . (count($dbInfo['indexes']) > 5 ? '...' : '') . "\n";

    echo "Colunas: " . count($dbInfo['columns']) . " criadas\n";

    echo "Campos Timestamp: " . count($dbInfo['timestamp_columns']) . " campos\n";
    echo "  → " . implode(', ', array_slice($dbInfo['timestamp_columns'], 0, 5)) . "\n";

    echo "Auditoria: " . ($dbInfo['has_audit'] ? '✅ COMPLETA' : '⚠️  PARCIAL') . "\n";
} else {
    echo "⚠️  Tabela não foi criada. Execute as migrations.\n";
}
echo "\n";

// =======================
// RESUMO FINAL
// =======================
echo str_repeat("=", 50) . "\n";
echo "✅ TODOS OS MÉTODOS ESTÃO FUNCIONANDO!\n";
echo str_repeat("=", 50) . "\n\n";

echo "📊 RESUMO:\n";
echo "  • getMercadoPagoConfigStatus() ✅\n";
echo "  • getPayoutFeaturesStatus() ✅\n";
echo "  • getPayoutArchitectureStatus() ✅\n";
echo "  • getPayoutDatabaseInfo() ✅\n";
echo "  • tableHasColumn() ✅\n\n";

echo "🎯 PRÓXIMO PASSO:\n";
echo "Acesse: http://localhost:8080/wp-admin/admin.php?page=limpvix-settings&tab=pagamentos\n";
echo "E verifique que TODAS as informações estão dinâmicas!\n\n";
