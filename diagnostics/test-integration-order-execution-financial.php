<?php
/**
 * Integration Tests: Order + Execution + Financial (Sprint 1 - Dia 4)
 *
 * OBJETIVO: Validar integração completa dos 3 aggregates
 * - Order State Machine
 * - Execution State Machine (com Geo + SLA)
 * - Financial State Machine
 *
 * REGRA DE OURO:
 * Payout SÓ acontece se Execution::VALIDATED
 *
 * COBERTURA OBRIGATÓRIA:
 * ❌ Payout sem Execution → BLOQUEADO
 * ❌ Execution CHECKED_OUT mas não VALIDATED → BLOQUEADO
 * ✅ Execution VALIDATED + SLA OK → PAYOUT OK
 * ✅ Execution VALIDATED + SLA VIOLADO → PAYOUT OK (auditável)
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Application\UseCases\Order\CompleteServiceWithPayout;
use LimpVix\Domain\Order\Order;
use LimpVix\Domain\Order\Enums\OrderStatusEnum;
use LimpVix\Domain\Finance\Financial;
use LimpVix\Domain\Finance\Enums\FinancialStatusEnum;
use LimpVix\Domain\Finance\FinancialStatus;
use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\Evidence;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;

$testsPassed = 0;
$testsFailed = 0;

function test(string $name, callable $fn): void {
    global $testsPassed, $testsFailed;
    try {
        $fn();
        echo "✅ $name\n";
        $testsPassed++;
    } catch (Exception $e) {
        echo "❌ $name\n";
        echo "   Error: " . $e->getMessage() . "\n";
        $testsFailed++;
    }
}

echo "=== INTEGRATION TESTS: ORDER + EXECUTION + FINANCIAL ===\n\n";

// ========================================
// BLOQUEIOS CRÍTICOS
// ========================================

test('BLOCKED: Payout without Execution VALIDATED (CRITICAL)', function() {
    // Setup: Order in IN_EXECUTION, Financial in HELD, Execution in CHECKED_OUT (NÃO VALIDATED)
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'order-001',
        1,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'order-001',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    // Execution NOT VALIDATED (apenas CHECKED_OUT)
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-001', 'order-001', 1, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation);
    $execution->startExecution();
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    $execution->checkOut($serviceLocation, $evidence);
    
    assert($execution->getStatus() === ExecutionStatusEnum::CHECKED_OUT);
    
    // Tentar payout
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    assert($result->isFail());
    assert(str_contains($result->error(), 'Execution must be VALIDATED'));
});

test('BLOCKED: Payout with Execution CREATED (not even checked-in)', function() {
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'order-002',
        2,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'order-002',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    // Execution ainda CREATED
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-002', 'order-002', 2, $scheduled, $serviceLocation);
    
    assert($execution->getStatus() === ExecutionStatusEnum::CREATED);
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    assert($result->isFail());
    assert(str_contains($result->error(), 'Execution must be VALIDATED'));
});

test('BLOCKED: Payout with Execution IN_EXECUTION (not completed)', function() {
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'order-003',
        3,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'order-003',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    // Execution apenas IN_EXECUTION
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-003', 'order-003', 3, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation);
    $execution->startExecution();
    
    assert($execution->getStatus() === ExecutionStatusEnum::IN_EXECUTION);
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    assert($result->isFail());
    assert(str_contains($result->error(), 'Execution must be VALIDATED'));
});

// ========================================
// HAPPY PATHS
// ========================================

test('ALLOWED: Payout with Execution VALIDATED + no SLA violations (HAPPY PATH)', function() {
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'order-010',
        10,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'order-010',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    // Execution completamente VALIDATED
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-010', 'order-010', 10, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation); // No SLA violations
    $execution->startExecution();
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    $execution->checkOut($serviceLocation, $evidence);
    $execution->validate();
    
    assert($execution->getStatus() === ExecutionStatusEnum::VALIDATED);
    assert(!$execution->hasSlaViolations());
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    assert($result->isOk());
    $data = $result->value();
    assert($data['order_status'] === 'completed');
    assert($data['financial_status'] === 'payout_authorized');
    assert($data['payout_authorized'] === true);
});

test('ALLOWED: Payout with Execution VALIDATED + SLA violations (AUDITABLE)', function() {
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'order-011',
        11,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'order-011',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    // Execution VALIDATED mas COM SLA violations
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-011', 'order-011', 11, $scheduled, $serviceLocation);
    
    // Check-in longe (SLA violation)
    $farGeo = new GeoLocation(-23.6505, -46.7333);
    $execution->checkIn($farGeo);
    assert($execution->hasSlaViolations()); // Violation detected
    
    // Continuar fluxo
    $execution->startExecution();
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    $execution->checkOut($serviceLocation, $evidence);
    $execution->validate();
    
    assert($execution->getStatus() === ExecutionStatusEnum::VALIDATED);
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    // Payout permitido MESMO COM SLA violations
    assert($result->isOk());
    $data = $result->value();
    assert($data['payout_authorized'] === true);
    assert(count($data['sla_violations']) > 0); // Violations auditáveis
});

// ========================================
// EDGE CASES
// ========================================

test('BLOCKED: Order not IN_EXECUTION (wrong state)', function() {
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'order-020',
        20,
        OrderStatusEnum::CONFIRMED, // NOT IN_EXECUTION
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'order-020',
        FinancialStatusEnum::HELD,
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::CONFIRMED
    );
    
    // Execution VALIDATED (ok)
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-020', 'order-020', 20, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation);
    $execution->startExecution();
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    $execution->checkOut($serviceLocation, $evidence);
    $execution->validate();
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    // Order não pode transicionar de CONFIRMED → COMPLETED
    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot complete order'));
});

test('BLOCKED: Financial not HELD (wrong state)', function() {
    $financialStatusLegacy = FinancialStatus::PAID();
    $order = new Order(
        'order-021',
        21,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        100.0
    );
    
    $financial = new Financial(
        'order-021',
        FinancialStatusEnum::CAPTURED, // NOT HELD
        100.0,
        15.0,
        85.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    // Execution VALIDATED (ok)
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-021', 'order-021', 21, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation);
    $execution->startExecution();
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    $execution->checkOut($serviceLocation, $evidence);
    $execution->validate();
    
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    // Order completa, mas Financial falha ao autorizar payout
    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot authorize payout'));
});

// ========================================
// INTEGRATION: Full Flow
// ========================================

test('INTEGRATION: Complete flow Order + Execution + Financial (END-TO-END)', function() {
    // 1. Setup inicial
    $financialStatusLegacy = FinancialStatus::HELD();
    $order = new Order(
        'order-100',
        100,
        OrderStatusEnum::IN_EXECUTION,
        $financialStatusLegacy,
        200.0
    );
    
    $financial = new Financial(
        'order-100',
        FinancialStatusEnum::HELD,
        200.0,
        30.0,
        170.0,
        OrderStatusEnum::IN_EXECUTION
    );
    
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $execution = Execution::create('exec-100', 'order-100', 100, $scheduled, $serviceLocation);
    
    // 2. Fluxo completo de Execution
    $execution->checkIn($serviceLocation);
    assert($execution->getStatus() === ExecutionStatusEnum::CHECKED_IN);
    
    $execution->startExecution();
    assert($execution->getStatus() === ExecutionStatusEnum::IN_EXECUTION);
    
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    $execution->checkOut($serviceLocation, $evidence);
    assert($execution->getStatus() === ExecutionStatusEnum::CHECKED_OUT);
    
    $execution->validate();
    assert($execution->getStatus() === ExecutionStatusEnum::VALIDATED);
    
    // 3. Completar Order + Autorizar Payout
    $useCase = new CompleteServiceWithPayout();
    $result = $useCase->execute($order, $financial, $execution);
    
    assert($result->isOk());
    $data = $result->value();
    
    // Verificar todos aggregates
    assert($data['order_status'] === 'completed');
    assert($data['financial_status'] === 'payout_authorized');
    assert($data['execution_status'] === 'validated');
    assert($data['payout_authorized'] === true);
});

// ========================================
// RESULTS
// ========================================

echo "\n=== RESULTS ===\n";
echo "✅ Passed: $testsPassed\n";
echo "❌ Failed: $testsFailed\n";
echo "📊 Total: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n🎉 ALL INTEGRATION TESTS PASSED!\n";
    exit(0);
} else {
    echo "\n💥 SOME TESTS FAILED!\n";
    exit(1);
}
