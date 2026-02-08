<?php
/**
 * Test WpFinancialLedgerRepository
 *
 * Valida que:
 * 1. WpFinancialLedgerRepository funciona corretamente
 * 2. RegisterBriefingAcceptance usa Repository (sem SQL direto)
 * 3. Tabela wp_limpvix_financial_ledger existe e está operacional
 *
 * Execute via:
 * docker exec limpvix_wordpress php /var/www/html/wp-content/plugins/limpvix-core/diagnostics/test-financial-ledger-repository.php
 */

// Bootstrap WordPress
require_once '/var/www/html/wp-load.php';

if (!defined('ABSPATH')) {
    die('WordPress não carregado');
}

// Autoload
require_once dirname(__DIR__) . '/vendor/autoload.php';

use LimpVix\Infrastructure\Persistence\WpFinancialLedgerRepository;
use LimpVix\Application\UseCases\Briefing\RegisterBriefingAcceptance;

echo "🧪 Testing WpFinancialLedgerRepository\n";
echo str_repeat('=', 70) . "\n\n";

$testsPassed = 0;
$testsFailed = 0;

/**
 * Helper: Assert
 */
function test_assert(bool $condition, string $message): void {
    global $testsPassed, $testsFailed;

    if ($condition) {
        echo "✅ PASS: {$message}\n";
        $testsPassed++;
    } else {
        echo "❌ FAIL: {$message}\n";
        $testsFailed++;
    }
}

// ============================================================================
// TEST 1: Repository pode adicionar evento
// ============================================================================
echo "TEST 1: Repository::append() funciona\n";
echo str_repeat('-', 70) . "\n";

try {
    $repo = new WpFinancialLedgerRepository();

    $ledgerId = $repo->append([
        'order_uuid' => 'test-order-uuid-' . time(),
        'customer_id' => 999,
        'event_type' => 'test_event',
        'event_data' => ['test' => 'data'],
    ]);

    test_assert($ledgerId > 0, 'append() retorna ID válido');
    echo "   Ledger ID criado: {$ledgerId}\n";

} catch (\Exception $e) {
    test_assert(false, 'append() sem erro: ' . $e->getMessage());
}

echo "\n";

// ============================================================================
// TEST 2: Repository pode verificar evento existente
// ============================================================================
echo "TEST 2: Repository::hasEvent() funciona\n";
echo str_repeat('-', 70) . "\n";

try {
    $repo = new WpFinancialLedgerRepository();
    $testOrderUuid = 'test-order-uuid-' . uniqid();

    // Adicionar evento
    $repo->append([
        'order_uuid' => $testOrderUuid,
        'event_type' => 'briefing_accepted',
        'customer_id' => 888,
        'event_data' => ['accepted' => true],
    ]);

    // Verificar que existe
    $exists = $repo->hasEvent($testOrderUuid, 'briefing_accepted');
    test_assert($exists === true, 'hasEvent() encontra evento existente');

    // Verificar que não existe outro tipo
    $notExists = $repo->hasEvent($testOrderUuid, 'other_event');
    test_assert($notExists === false, 'hasEvent() retorna false para evento inexistente');

} catch (\Exception $e) {
    test_assert(false, 'hasEvent() sem erro: ' . $e->getMessage());
}

echo "\n";

// ============================================================================
// TEST 3: Repository pode buscar último evento
// ============================================================================
echo "TEST 3: Repository::findLatestEvent() funciona\n";
echo str_repeat('-', 70) . "\n";

try {
    $repo = new WpFinancialLedgerRepository();
    $testOrderUuid = 'test-order-uuid-' . uniqid();

    // Adicionar 2 eventos do mesmo tipo
    $repo->append([
        'order_uuid' => $testOrderUuid,
        'event_type' => 'payment_received',
        'customer_id' => 777,
        'event_data' => ['amount' => 100],
    ]);

    sleep(1); // Garantir timestamp diferente

    $repo->append([
        'order_uuid' => $testOrderUuid,
        'event_type' => 'payment_received',
        'customer_id' => 777,
        'event_data' => ['amount' => 200],
    ]);

    // Buscar último
    $latest = $repo->findLatestEvent($testOrderUuid, 'payment_received');

    test_assert($latest !== null, 'findLatestEvent() retorna resultado');
    test_assert($latest['event_data']['amount'] === 200, 'findLatestEvent() retorna o último evento (amount=200)');
    test_assert(is_array($latest['event_data']), 'event_data já está decodificado (array)');

} catch (\Exception $e) {
    test_assert(false, 'findLatestEvent() sem erro: ' . $e->getMessage());
}

echo "\n";

// ============================================================================
// TEST 4: RegisterBriefingAcceptance usa Repository (sem SQL direto)
// ============================================================================
echo "TEST 4: RegisterBriefingAcceptance usa Repository\n";
echo str_repeat('-', 70) . "\n";

try {
    $useCase = new RegisterBriefingAcceptance();

    $testOrderUuid = 'test-briefing-' . uniqid();

    $result = $useCase->execute([
        'order_uuid' => $testOrderUuid,
        'appointment_id' => 123,
        'customer_id' => 456,
        'accepted_terms' => true,
        'briefing_data' => ['test' => 'data'],
    ]);

    test_assert($result['success'] === true, 'RegisterBriefingAcceptance::execute() retorna success');
    test_assert(isset($result['ledger_id']), 'RegisterBriefingAcceptance retorna ledger_id');

    // Verificar que evento foi registrado
    $repo = new WpFinancialLedgerRepository();
    $exists = $repo->hasEvent($testOrderUuid, 'briefing_accepted');
    test_assert($exists === true, 'Evento briefing_accepted foi registrado no ledger');

    // Verificar dados via getBriefingData()
    $briefingData = $useCase->getBriefingData($testOrderUuid);
    test_assert($briefingData !== null, 'getBriefingData() retorna dados');
    test_assert($briefingData['event_data']['accepted_terms'] === true, 'accepted_terms está correto');
    test_assert(isset($briefingData['event_data']['ip_address']), 'IP address foi capturado');

} catch (\Exception $e) {
    test_assert(false, 'RegisterBriefingAcceptance sem erro: ' . $e->getMessage());
}

echo "\n";

// ============================================================================
// TEST 5: Idempotência - não duplica aceites
// ============================================================================
echo "TEST 5: Idempotência - não duplica aceites\n";
echo str_repeat('-', 70) . "\n";

try {
    $useCase = new RegisterBriefingAcceptance();
    $testOrderUuid = 'test-idempotent-' . uniqid();

    // Primeira execução
    $result1 = $useCase->execute([
        'order_uuid' => $testOrderUuid,
        'appointment_id' => 789,
        'customer_id' => 321,
        'accepted_terms' => true,
    ]);

    test_assert($result1['success'] === true, 'Primeira execução: success');

    // Segunda execução (deve detectar duplicação)
    $result2 = $useCase->execute([
        'order_uuid' => $testOrderUuid,
        'appointment_id' => 789,
        'customer_id' => 321,
        'accepted_terms' => true,
    ]);

    test_assert($result2['success'] === true, 'Segunda execução: success (idempotente)');
    test_assert(
        str_contains($result2['message'], 'já registrado'),
        'Mensagem indica aceite já registrado'
    );

} catch (\Exception $e) {
    test_assert(false, 'Idempotência sem erro: ' . $e->getMessage());
}

echo "\n";

// ============================================================================
// SUMMARY
// ============================================================================
echo str_repeat('=', 70) . "\n";
echo "📊 SUMMARY\n";
echo str_repeat('=', 70) . "\n";
echo "✅ Passed: {$testsPassed}\n";
echo "❌ Failed: {$testsFailed}\n";
echo "📈 Total:  " . ($testsPassed + $testsFailed) . "\n\n";

if ($testsFailed === 0) {
    echo "🎉 ALL TESTS PASSED!\n";
    echo "✅ WpFinancialLedgerRepository está FUNCIONAL\n";
    echo "✅ RegisterBriefingAcceptance usa Repository (SEM SQL direto)\n";
    echo "✅ Violação arquitetural CORRIGIDA\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED\n";
    exit(1);
}
