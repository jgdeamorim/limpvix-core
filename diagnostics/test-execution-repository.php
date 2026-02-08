<?php
/**
 * Test WpExecutionRepository (Sprint 1 - Dia 5)
 *
 * OBJETIVO: Validar persistência do Execution Aggregate
 * - Save → Load → Equality
 * - Re-hidratação idempotente
 * - Serialização/deserialização de Value Objects
 * - Sem dependência de Booknetic
 *
 * REQUISITO: ≥10 testes
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\Evidence;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Execution\ValueObjects\SlaViolation;
use LimpVix\Domain\Execution\ValueObjects\TimeWindow;
use LimpVix\Infrastructure\Persistence\WpExecutionRepository;

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

echo "=== EXECUTION REPOSITORY TESTS ===\n\n";

$repo = new WpExecutionRepository();

// ========================================
// BASIC SAVE/LOAD
// ========================================

test('Save and Load: Basic Execution (CREATED status)', function() use ($repo) {
    $uuid = 'test-exec-1';
    $orderUuid = 'test-order-1';
    $professionalId = 123;

    $execution = new Execution(
        $uuid,
        $orderUuid,
        $professionalId,
        ExecutionStatusEnum::CREATED
    );

    $repo->save($execution);
    $loaded = $repo->findByUuid($uuid);

    assert($loaded !== null);
    assert($loaded->getExecutionUuid() === $uuid);
    assert($loaded->getOrderUuid() === $orderUuid);
    assert($loaded->getProfessionalId() === $professionalId);
    assert($loaded->getStatus() === ExecutionStatusEnum::CREATED);

    $repo->delete($uuid);
});

test('Save and Load: Execution with GeoLocation', function() use ($repo) {
    $uuid = 'test-exec-2';
    $orderUuid = 'test-order-2';
    $professionalId = 456;

    $serviceLocation = new GeoLocation(-20.3155, -40.3128);

    $execution = new Execution(
        $uuid,
        $orderUuid,
        $professionalId,
        ExecutionStatusEnum::CREATED,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        $serviceLocation
    );

    $repo->save($execution);
    $loaded = $repo->findByUuid($uuid);

    assert($loaded !== null);
    assert($loaded->getServiceLocation() !== null);
    assert($loaded->getServiceLocation()->latitude === -20.3155);
    assert($loaded->getServiceLocation()->longitude === -40.3128);

    $repo->delete($uuid);
});

test('Save and Load: Execution with Check-in', function() use ($repo) {
    $uuid = 'test-exec-3';

    $serviceLocation = new GeoLocation(-20.3155, -40.3128);
    $checkInGeo = new GeoLocation(-20.3160, -40.3130);

    $execution = new Execution(
        $uuid,
        'order-3',
        789,
        ExecutionStatusEnum::CHECKED_IN,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        $serviceLocation,
        150,
        new \DateTimeImmutable('2026-02-10 08:55:00'),
        $checkInGeo
    );

    $repo->save($execution);
    $loaded = $repo->findByUuid($uuid);

    assert($loaded !== null);
    assert($loaded->getStatus() === ExecutionStatusEnum::CHECKED_IN);
    assert($loaded->getCheckInAt() !== null);
    assert($loaded->getCheckInGeo() !== null);
    assert($loaded->getCheckInGeo()->latitude === -20.3160);

    $repo->delete($uuid);
});

test('Save and Load: Execution with Evidence', function() use ($repo) {
    $uuid = 'test-exec-4';

    $evidence = new EvidenceCollection([
        Evidence::photo('https://example.com/photo1.jpg'),
        Evidence::video('https://example.com/video1.mp4'),
    ]);

    $execution = new Execution(
        $uuid,
        'order-4',
        101,
        ExecutionStatusEnum::CHECKED_OUT,
        null,
        null,
        150,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128),
        new \DateTimeImmutable('2026-02-10 12:30:00'),
        new GeoLocation(-20.3160, -40.3130),
        $evidence
    );

    $repo->save($execution);
    $loaded = $repo->findByUuid($uuid);

    assert($loaded !== null);
    assert($loaded->hasEvidence());
    assert($loaded->getEvidence()->count() === 2);
    assert($loaded->getEvidence()->hasPhotos());
    assert($loaded->getEvidence()->hasVideos());

    $repo->delete($uuid);
});

test('Save and Load: Execution with SLA Violations', function() use ($repo) {
    $uuid = 'test-exec-5';

    $violations = [
        SlaViolation::lateCheckIn(15),
        SlaViolation::outOfGeofence(200.5),
    ];

    $execution = new Execution(
        $uuid,
        'order-5',
        202,
        ExecutionStatusEnum::CHECKED_IN,
        null,
        null,
        150,
        new \DateTimeImmutable('2026-02-10 09:15:00'),
        new GeoLocation(-20.3155, -40.3128),
        null,
        null,
        null,
        $violations
    );

    $repo->save($execution);
    $loaded = $repo->findByUuid($uuid);

    assert($loaded !== null);
    assert($loaded->hasSlaViolations());
    assert(count($loaded->getSlaViolations()) === 2);
    assert($loaded->getSlaViolations()[0]->reason === SlaViolation::REASON_LATE_CHECKIN);
    assert($loaded->getSlaViolations()[1]->reason === SlaViolation::REASON_OUT_OF_GEOFENCE);

    $repo->delete($uuid);
});

// ========================================
// IDEMPOTENCE
// ========================================

test('Idempotence: Save twice does not corrupt data', function() use ($repo) {
    $uuid = 'test-exec-6';

    $execution = new Execution(
        $uuid,
        'order-6',
        303,
        ExecutionStatusEnum::CREATED
    );

    $repo->save($execution);
    $loaded1 = $repo->findByUuid($uuid);

    $repo->save($execution);
    $loaded2 = $repo->findByUuid($uuid);

    assert($loaded1->getExecutionUuid() === $loaded2->getExecutionUuid());
    assert($loaded1->getStatus() === $loaded2->getStatus());
    assert($loaded1->getProfessionalId() === $loaded2->getProfessionalId());

    $repo->delete($uuid);
});

test('Idempotence: Re-hydration preserves all Value Objects', function() use ($repo) {
    $uuid = 'test-exec-7';

    $serviceLocation = new GeoLocation(-20.3155, -40.3128);
    $checkInGeo = new GeoLocation(-20.3160, -40.3130);
    $evidence = new EvidenceCollection([Evidence::photo('url.jpg')]);
    $violations = [SlaViolation::lateCheckIn(10)];

    $execution = new Execution(
        $uuid,
        'order-7',
        404,
        ExecutionStatusEnum::VALIDATED,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        $serviceLocation,
        150,
        new \DateTimeImmutable('2026-02-10 09:05:00'),
        $checkInGeo,
        new \DateTimeImmutable('2026-02-10 12:00:00'),
        new GeoLocation(-20.3160, -40.3130),
        $evidence,
        $violations
    );

    $repo->save($execution);
    $loaded1 = $repo->findByUuid($uuid);

    $repo->save($loaded1);
    $loaded2 = $repo->findByUuid($uuid);

    assert($loaded2->getServiceLocation() !== null);
    assert($loaded2->getCheckInGeo() !== null);
    assert($loaded2->hasEvidence());
    assert($loaded2->hasSlaViolations());

    $repo->delete($uuid);
});

// ========================================
// QUERY METHODS
// ========================================

test('findByOrderUuid: Returns correct Execution', function() use ($repo) {
    $uuid = 'test-exec-8';
    $orderUuid = 'test-order-unique-8';

    $execution = new Execution($uuid, $orderUuid, 505, ExecutionStatusEnum::CREATED);
    $repo->save($execution);

    $loaded = $repo->findByOrderUuid($orderUuid);

    assert($loaded !== null);
    assert($loaded->getExecutionUuid() === $uuid);
    assert($loaded->getOrderUuid() === $orderUuid);

    $repo->delete($uuid);
});

test('exists: Returns true for existing Execution', function() use ($repo) {
    $uuid = 'test-exec-9';

    $execution = new Execution($uuid, 'order-9', 606, ExecutionStatusEnum::CREATED);
    $repo->save($execution);

    assert($repo->exists($uuid) === true);

    $repo->delete($uuid);
});

test('exists: Returns false for non-existing Execution', function() use ($repo) {
    assert($repo->exists('non-existing-uuid') === false);
});

// ========================================
// DELETE
// ========================================

test('delete: Removes Execution from database', function() use ($repo) {
    $uuid = 'test-exec-10';

    $execution = new Execution($uuid, 'order-10', 707, ExecutionStatusEnum::CREATED);
    $repo->save($execution);

    assert($repo->exists($uuid) === true);

    $repo->delete($uuid);

    assert($repo->exists($uuid) === false);
    assert($repo->findByUuid($uuid) === null);
});

// ========================================
// EDGE CASES
// ========================================

test('findByUuid: Returns null for non-existing UUID', function() use ($repo) {
    $loaded = $repo->findByUuid('non-existing-uuid-12345');
    assert($loaded === null);
});

test('findByOrderUuid: Returns null for non-existing Order', function() use ($repo) {
    $loaded = $repo->findByOrderUuid('non-existing-order-67890');
    assert($loaded === null);
});

// ========================================
// RESULTS
// ========================================

echo "\n=== RESULTS ===\n";
echo "✅ Passed: $testsPassed\n";
echo "❌ Failed: $testsFailed\n";
echo "📊 Total: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n🎉 ALL REPOSITORY TESTS PASSED!\n";
    exit(0);
} else {
    echo "\n💥 SOME TESTS FAILED!\n";
    exit(1);
}
