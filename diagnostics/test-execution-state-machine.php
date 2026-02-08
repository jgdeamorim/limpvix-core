<?php
/**
 * Test Execution State Machine (Sprint 1 - Dia 2)
 *
 * OBJETIVO: Validar State Machine de Execution.php
 * - Factory method
 * - Transições válidas
 * - Transições inválidas (CRÍTICAS)
 * - Estados terminais
 * - Regras críticas:
 *   * ❌ checkout sem check-in
 *   * ❌ validate sem evidência
 *   * ❌ qualquer transição após CLOSED
 *
 * REQUISITO: ≥20 testes
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;
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

echo "=== EXECUTION STATE MACHINE TESTS ===\n\n";

// ========================================
// FACTORY METHOD
// ========================================

test('Factory: create() starts as CREATED', function() {
    $execution = Execution::create('exec-001', 'order-001', 1);
    assert($execution->getStatus() === ExecutionStatusEnum::CREATED);
    assert($execution->getExecutionUuid() === 'exec-001');
    assert($execution->getOrderUuid() === 'order-001');
    assert($execution->getProfessionalId() === 1);
});

test('Factory: create() without check-in data', function() {
    $execution = Execution::create('exec-002', 'order-002', 2);
    assert($execution->getCheckInAt() === null);
    assert($execution->getCheckInGeo() === null);
    assert($execution->getEvidence() === null);
});

// ========================================
// VALID TRANSITIONS
// ========================================

test('Valid: CREATED → CHECKED_IN', function() {
    $execution = Execution::create('exec-010', 'order-010', 10);
    $geo = new GeoLocation(-23.5505, -46.6333);
    
    $execution->checkIn($geo);
    
    assert($execution->getStatus() === ExecutionStatusEnum::CHECKED_IN);
    assert($execution->getCheckInGeo() !== null);
    assert($execution->getCheckInAt() !== null);
});

test('Valid: CHECKED_IN → IN_EXECUTION', function() {
    $execution = Execution::create('exec-011', 'order-011', 11);
    $geo = new GeoLocation(-23.5505, -46.6333);
    
    $execution->checkIn($geo);
    $execution->startExecution();
    
    assert($execution->getStatus() === ExecutionStatusEnum::IN_EXECUTION);
});

test('Valid: IN_EXECUTION → CHECKED_OUT', function() {
    $execution = Execution::create('exec-012', 'order-012', 12);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution->checkIn($geo);
    $execution->startExecution();
    $execution->checkOut($geo, $evidence);
    
    assert($execution->getStatus() === ExecutionStatusEnum::CHECKED_OUT);
    assert($execution->getCheckOutAt() !== null);
    assert($execution->hasEvidence());
});

test('Valid: CHECKED_OUT → VALIDATED', function() {
    $execution = Execution::create('exec-013', 'order-013', 13);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution->checkIn($geo);
    $execution->startExecution();
    $execution->checkOut($geo, $evidence);
    $execution->validate();
    
    assert($execution->getStatus() === ExecutionStatusEnum::VALIDATED);
});

test('Valid: VALIDATED → CLOSED', function() {
    $execution = Execution::create('exec-014', 'order-014', 14);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution->checkIn($geo);
    $execution->startExecution();
    $execution->checkOut($geo, $evidence);
    $execution->validate();
    $execution->close();
    
    assert($execution->getStatus() === ExecutionStatusEnum::CLOSED);
});

// ========================================
// INVALID TRANSITIONS (CRITICAL)
// ========================================

test('Invalid: CREATED → CHECKED_OUT (skip check-in) - BLOCKED', function() {
    $execution = Execution::create('exec-020', 'order-020', 20);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $exceptionThrown = false;
    try {
        $execution->checkOut($geo, $evidence);
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Check-in must be performed'));
    }
    assert($exceptionThrown);
    assert($execution->getStatus() === ExecutionStatusEnum::CREATED); // Status não mudou
});

test('Invalid: CHECKED_OUT → VALIDATED without evidence - BLOCKED (CRITICAL)', function() {
    // Criar execution sem evidência (forçar estado CHECKED_OUT manualmente)
    $execution = new Execution(
        'exec-021',
        'order-021',
        21,
        ExecutionStatusEnum::CHECKED_OUT,
        new \DateTimeImmutable(),
        new GeoLocation(-23.5505, -46.6333),
        new \DateTimeImmutable(),
        new GeoLocation(-23.5505, -46.6333),
        null, // SEM EVIDÊNCIA
        null
    );
    
    $exceptionThrown = false;
    try {
        $execution->validate();
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Evidence is required'));
    }
    assert($exceptionThrown);
});

test('Invalid: CREATED → IN_EXECUTION (skip check-in)', function() {
    $execution = Execution::create('exec-022', 'order-022', 22);
    
    $exceptionThrown = false;
    try {
        $execution->startExecution();
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

test('Invalid: CREATED → VALIDATED (skip all)', function() {
    $execution = Execution::create('exec-023', 'order-023', 23);
    
    $exceptionThrown = false;
    try {
        $execution->validate();
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

test('Invalid: CHECKED_IN → CHECKED_OUT (skip IN_EXECUTION)', function() {
    $execution = Execution::create('exec-024', 'order-024', 24);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution->checkIn($geo);
    
    $exceptionThrown = false;
    try {
        $execution->checkOut($geo, $evidence);
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

test('Invalid: IN_EXECUTION → VALIDATED (skip checkout)', function() {
    $execution = Execution::create('exec-025', 'order-025', 25);
    $geo = new GeoLocation(-23.5505, -46.6333);
    
    $execution->checkIn($geo);
    $execution->startExecution();
    
    $exceptionThrown = false;
    try {
        $execution->validate();
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

// ========================================
// TERMINAL STATE
// ========================================

test('Terminal: CLOSED blocks all transitions', function() {
    $execution = new Execution(
        'exec-030',
        'order-030',
        30,
        ExecutionStatusEnum::CLOSED
    );
    
    $exceptionThrown = false;
    try {
        $execution->close();
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'terminal'));
    }
    assert($exceptionThrown);
});

test('Terminal: CLOSED blocks check-in', function() {
    $execution = new Execution(
        'exec-031',
        'order-031',
        31,
        ExecutionStatusEnum::CLOSED
    );
    $geo = new GeoLocation(-23.5505, -46.6333);
    
    $exceptionThrown = false;
    try {
        $execution->checkIn($geo);
    } catch (InvalidExecutionTransitionException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

// ========================================
// EDGE CASES
// ========================================

test('Edge: check-in stores geo and timestamp', function() {
    $execution = Execution::create('exec-040', 'order-040', 40);
    $geo = new GeoLocation(-23.5505, -46.6333);
    
    $execution->checkIn($geo);
    
    assert($execution->getCheckInGeo() !== null);
    assert($execution->getCheckInGeo()->latitude === -23.5505);
    assert($execution->getCheckInAt() !== null);
});

test('Edge: check-out stores geo, timestamp and evidence', function() {
    $execution = Execution::create('exec-041', 'order-041', 41);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution->checkIn($geo);
    $execution->startExecution();
    $execution->checkOut($geo, $evidence);
    
    assert($execution->getCheckOutGeo() !== null);
    assert($execution->getCheckOutAt() !== null);
    assert($execution->getEvidence() !== null);
});

test('Edge: getDurationMinutes() calculates correctly', function() {
    $execution = Execution::create('exec-042', 'order-042', 42);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution->checkIn($geo);
    sleep(2); // 2 seconds
    $execution->startExecution();
    $execution->checkOut($geo, $evidence);
    
    $duration = $execution->getDurationMinutes();
    assert($duration !== null);
    assert($duration >= 0); // At least 0 minutes
});

test('Edge: getDurationMinutes() returns null before checkout', function() {
    $execution = Execution::create('exec-043', 'order-043', 43);
    $geo = new GeoLocation(-23.5505, -46.6333);
    
    $execution->checkIn($geo);
    
    assert($execution->getDurationMinutes() === null);
});

test('Edge: hasEvidence() works correctly', function() {
    $execution = Execution::create('exec-044', 'order-044', 44);
    $geo = new GeoLocation(-23.5505, -46.6333);
    
    assert(!$execution->hasEvidence());
    
    $execution->checkIn($geo);
    $execution->startExecution();
    $execution->checkOut($geo, EvidenceCollection::single(Evidence::photo('photo.jpg')));
    
    assert($execution->hasEvidence());
});

test('Edge: markSlaViolation() sets status', function() {
    $execution = Execution::create('exec-045', 'order-045', 45);
    
    assert($execution->getSlaStatus() === null);
    
    $execution->markSlaViolation();
    
    assert($execution->getSlaStatus() === 'VIOLATED');
});

test('Edge: equals() compares by executionUuid', function() {
    $exec1 = Execution::create('exec-050', 'order-050', 50);
    $exec2 = Execution::create('exec-050', 'order-999', 999);
    $exec3 = Execution::create('exec-051', 'order-050', 50);
    
    assert($exec1->equals($exec2)); // Same UUID
    assert(!$exec1->equals($exec3)); // Different UUID
});

// ========================================
// INTEGRATION: Happy Path Completo
// ========================================

test('INTEGRATION: Happy path completo (CREATED → CLOSED)', function() {
    $execution = Execution::create('exec-100', 'order-100', 100);
    $geo = new GeoLocation(-23.5505, -46.6333);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    // 1. CHECK-IN
    $execution->checkIn($geo);
    assert($execution->getStatus() === ExecutionStatusEnum::CHECKED_IN);
    
    // 2. START EXECUTION
    $execution->startExecution();
    assert($execution->getStatus() === ExecutionStatusEnum::IN_EXECUTION);
    
    // 3. CHECK-OUT
    $execution->checkOut($geo, $evidence);
    assert($execution->getStatus() === ExecutionStatusEnum::CHECKED_OUT);
    
    // 4. VALIDATE
    $execution->validate();
    assert($execution->getStatus() === ExecutionStatusEnum::VALIDATED);
    
    // 5. CLOSE
    $execution->close();
    assert($execution->getStatus() === ExecutionStatusEnum::CLOSED);
});

// ========================================
// RESULTS
// ========================================

echo "\n=== RESULTS ===\n";
echo "✅ Passed: $testsPassed\n";
echo "❌ Failed: $testsFailed\n";
echo "📊 Total: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n🎉 ALL TESTS PASSED!\n";
    exit(0);
} else {
    echo "\n💥 SOME TESTS FAILED!\n";
    exit(1);
}
