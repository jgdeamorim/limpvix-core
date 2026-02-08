<?php
/**
 * Test Execution Geo + SLA Validations (Sprint 1 - Dia 3)
 *
 * OBJETIVO: Validar geofence, time window e SLA tracking
 * - TimeWindow (janela temporal)
 * - SlaViolation (registro de violações)
 * - Execution com validações Geo + SLA
 * - Detecção automática de violações
 *
 * REQUISITO: ≥15 testes
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\Evidence;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Execution\ValueObjects\TimeWindow;
use LimpVix\Domain\Execution\ValueObjects\SlaViolation;

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

echo "=== EXECUTION GEO + SLA TESTS ===\n\n";

// ========================================
// TIME WINDOW
// ========================================

test('TimeWindow: creates with valid scheduled time', function() {
    $scheduled = new \DateTimeImmutable('2026-02-10 09:00:00');
    $window = new TimeWindow($scheduled, 60);
    
    assert($window->scheduledTime->format('H:i') === '09:00');
    assert($window->startTime->format('H:i') === '08:00');
    assert($window->endTime->format('H:i') === '10:00');
});

test('TimeWindow: isWithin() detects time inside window', function() {
    $scheduled = new \DateTimeImmutable('2026-02-10 09:00:00');
    $window = new TimeWindow($scheduled, 60);
    
    $inside = new \DateTimeImmutable('2026-02-10 08:30:00');
    $outside = new \DateTimeImmutable('2026-02-10 11:00:00');
    
    assert($window->isWithin($inside));
    assert(!$window->isWithin($outside));
});

test('TimeWindow: calculateDelayMinutes() works correctly', function() {
    $scheduled = new \DateTimeImmutable('2026-02-10 09:00:00');
    $window = new TimeWindow($scheduled, 60);
    
    $onTime = new \DateTimeImmutable('2026-02-10 09:00:00');
    $late = new \DateTimeImmutable('2026-02-10 09:15:00');
    $early = new \DateTimeImmutable('2026-02-10 08:45:00');
    
    assert($window->calculateDelayMinutes($onTime) === 0);
    assert($window->calculateDelayMinutes($late) === 15);
    assert($window->calculateDelayMinutes($early) === -15);
});

test('TimeWindow: rejects negative window', function() {
    $scheduled = new \DateTimeImmutable('2026-02-10 09:00:00');
    
    $exceptionThrown = false;
    try {
        new TimeWindow($scheduled, -10);
    } catch (\InvalidArgumentException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

// ========================================
// SLA VIOLATION
// ========================================

test('SlaViolation: lateCheckIn() factory works', function() {
    $violation = SlaViolation::lateCheckIn(30);
    assert($violation->reason === SlaViolation::REASON_LATE_CHECKIN);
    assert($violation->metadata['delay_minutes'] === 30);
    assert($violation->isLateCheckIn());
});

test('SlaViolation: outOfGeofence() factory works', function() {
    $violation = SlaViolation::outOfGeofence(250.5);
    assert($violation->reason === SlaViolation::REASON_OUT_OF_GEOFENCE);
    assert($violation->metadata['distance_meters'] === 250.5);
    assert($violation->isOutOfGeofence());
});

test('SlaViolation: earlyCheckIn() factory works', function() {
    $violation = SlaViolation::earlyCheckIn(-45);
    assert($violation->reason === SlaViolation::REASON_EARLY_CHECKIN);
    assert($violation->metadata['early_minutes'] === 45); // abs value
});

test('SlaViolation: rejects empty reason', function() {
    $exceptionThrown = false;
    try {
        new SlaViolation('', new \DateTimeImmutable());
    } catch (\InvalidArgumentException $e) {
        $exceptionThrown = true;
    }
    assert($exceptionThrown);
});

// ========================================
// EXECUTION WITH GEO + SLA
// ========================================

test('Execution: checkIn() within geofence (no SLA violation)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    
    $execution = Execution::create('exec-001', 'order-001', 1, $scheduled, $serviceLocation);
    
    // Check-in próximo (~50m)
    $checkInGeo = new GeoLocation(-23.5510, -46.6335);
    $execution->checkIn($checkInGeo);
    
    assert(!$execution->hasSlaViolations());
    assert($execution->getCheckInGeo() !== null);
});

test('Execution: checkIn() outside geofence (SLA violation detected)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    
    $execution = Execution::create('exec-002', 'order-002', 2, $scheduled, $serviceLocation);
    
    // Check-in longe (~10km)
    $checkInGeo = new GeoLocation(-23.6505, -46.7333);
    $execution->checkIn($checkInGeo);
    
    assert($execution->hasSlaViolations());
    $violations = $execution->getSlaViolations();
    assert(count($violations) === 1);
    assert($violations[0]->isOutOfGeofence());
});

test('Execution: checkIn() within time window (no SLA violation)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $timeWindow = new TimeWindow($scheduled, 60);
    
    $execution = Execution::create('exec-003', 'order-003', 3, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation, $timeWindow);
    
    assert(!$execution->hasSlaViolations());
});

test('Execution: checkIn() late (SLA violation detected)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable('2020-01-01 09:00:00'); // Passado
    $timeWindow = new TimeWindow($scheduled, 60);
    
    $execution = Execution::create('exec-004', 'order-004', 4, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation, $timeWindow);
    
    assert($execution->hasSlaViolations());
    $violations = $execution->getSlaViolations();
    assert(count($violations) === 1);
    assert($violations[0]->isLateCheckIn());
});

test('Execution: checkIn() early (SLA violation detected)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable('2030-01-01 09:00:00'); // Futuro
    $timeWindow = new TimeWindow($scheduled, 60);
    
    $execution = Execution::create('exec-005', 'order-005', 5, $scheduled, $serviceLocation);
    $execution->checkIn($serviceLocation, $timeWindow);
    
    assert($execution->hasSlaViolations());
    $violations = $execution->getSlaViolations();
    assert(count($violations) >= 1); // Early check-in
});

test('Execution: validate() works with SLA violations (allowed)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution = Execution::create('exec-006', 'order-006', 6, $scheduled, $serviceLocation);
    
    // Check-in fora da geofence (SLA violation)
    $farGeo = new GeoLocation(-23.6505, -46.7333);
    $execution->checkIn($farGeo);
    
    assert($execution->hasSlaViolations());
    
    // Continuar fluxo normal
    $execution->startExecution();
    $execution->checkOut($serviceLocation, $evidence);
    
    // Validate deve funcionar mesmo com SLA violations
    $execution->validate();
    assert($execution->getStatus()->value === 'validated');
});

test('Execution: multiple SLA violations detected', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable('2020-01-01 09:00:00'); // Passado
    $timeWindow = new TimeWindow($scheduled, 60);
    
    $execution = Execution::create('exec-007', 'order-007', 7, $scheduled, $serviceLocation);
    
    // Check-in longe E atrasado (2 violations)
    $farGeo = new GeoLocation(-23.6505, -46.7333);
    $execution->checkIn($farGeo, $timeWindow);
    
    assert($execution->hasSlaViolations());
    $violations = $execution->getSlaViolations();
    assert(count($violations) === 2); // Geofence + Late
});

test('Execution: getSlaViolations() returns empty array initially', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    
    $execution = Execution::create('exec-008', 'order-008', 8, $scheduled, $serviceLocation);
    
    assert(!$execution->hasSlaViolations());
    assert(count($execution->getSlaViolations()) === 0);
});

// ========================================
// INTEGRATION: Happy Path com Geo + SLA
// ========================================

test('INTEGRATION: Happy path with geofence and time window (no violations)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable();
    $timeWindow = new TimeWindow($scheduled, 60);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution = Execution::create('exec-100', 'order-100', 100, $scheduled, $serviceLocation);
    
    // Check-in no local correto e no horário
    $execution->checkIn($serviceLocation, $timeWindow);
    assert(!$execution->hasSlaViolations());
    
    // Fluxo normal
    $execution->startExecution();
    $execution->checkOut($serviceLocation, $evidence);
    $execution->validate();
    $execution->close();
    
    assert($execution->getStatus()->value === 'closed');
    assert(!$execution->hasSlaViolations());
});

test('INTEGRATION: Complete flow with SLA violations (still closes)', function() {
    $serviceLocation = new GeoLocation(-23.5505, -46.6333);
    $scheduled = new \DateTimeImmutable('2020-01-01 09:00:00'); // Passado
    $timeWindow = new TimeWindow($scheduled, 60);
    $evidence = EvidenceCollection::single(Evidence::photo('photo.jpg'));
    
    $execution = Execution::create('exec-101', 'order-101', 101, $scheduled, $serviceLocation);
    
    // Check-in atrasado (SLA violation)
    $execution->checkIn($serviceLocation, $timeWindow);
    assert($execution->hasSlaViolations());
    
    // Mas fluxo continua normalmente
    $execution->startExecution();
    $execution->checkOut($serviceLocation, $evidence);
    $execution->validate(); // Permitido mesmo com SLA violations
    $execution->close();
    
    assert($execution->getStatus()->value === 'closed');
    assert($execution->hasSlaViolations()); // Violations persistem
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
