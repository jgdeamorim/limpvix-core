<?php
/**
 * Test Execution Use Cases (Sprint 1 - Dia 6)
 *
 * OBJETIVO: Validar Use Cases de Execution
 * - PerformCheckIn
 * - PerformCheckOut
 * - ValidateExecution
 *
 * REQUISITO: ≥15 testes
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\Evidence;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Infrastructure\Persistence\WpExecutionRepository;
use LimpVix\Application\UseCases\Execution\PerformCheckIn;
use LimpVix\Application\UseCases\Execution\PerformCheckOut;
use LimpVix\Application\UseCases\Execution\ValidateExecution;

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

echo "=== EXECUTION USE CASES TESTS ===\n\n";

$repo = new WpExecutionRepository();

// ========================================
// PERFORMCHECKIN TESTS
// ========================================

test('PerformCheckIn: Execution not found → Result::fail', function() use ($repo) {
    $useCase = new PerformCheckIn($repo);

    $result = $useCase->execute(
        'non-existing-uuid',
        new GeoLocation(-20.3155, -40.3128),
        new \DateTimeImmutable()
    );

    assert($result->isFail());
    assert(str_contains($result->error(), 'not found'));
});

test('PerformCheckIn: Happy path (CREATED → CHECKED_IN)', function() use ($repo) {
    $uuid = 'test-checkin-1';

    // Criar Execution
    $execution = new Execution(
        $uuid,
        'order-checkin-1',
        123,
        ExecutionStatusEnum::CREATED,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128)
    );
    $repo->save($execution);

    // Executar Use Case
    $useCase = new PerformCheckIn($repo);
    $result = $useCase->execute(
        $uuid,
        new GeoLocation(-20.3156, -40.3129), // Dentro do raio
        new \DateTimeImmutable('2026-02-10 09:00:00')
    );

    assert($result->isOk());
    assert($result->value()['status'] === 'checked_in');
    // Note: SLA violations podem ocorrer se check-in não estiver perfeitamente sincronizado

    // Cleanup
    $repo->delete($uuid);
});

test('PerformCheckIn: Check-in with SLA violation (late)', function() use ($repo) {
    $uuid = 'test-checkin-sla';

    $execution = new Execution(
        $uuid,
        'order-checkin-sla',
        456,
        ExecutionStatusEnum::CREATED,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128)
    );
    $repo->save($execution);

    $useCase = new PerformCheckIn($repo);
    $result = $useCase->execute(
        $uuid,
        new GeoLocation(-20.3156, -40.3129),
        new \DateTimeImmutable('2026-02-10 10:30:00') // 1.5h atrasado (fora da janela ±1h)
    );

    assert($result->isOk());
    assert($result->value()['status'] === 'checked_in');
    assert($result->value()['has_sla_violations'] === true);
    assert(count($result->value()['sla_violations']) > 0);

    $repo->delete($uuid);
});

test('PerformCheckIn: Check-in out of geofence → SLA violation', function() use ($repo) {
    $uuid = 'test-checkin-geo';

    $execution = new Execution(
        $uuid,
        'order-checkin-geo',
        789,
        ExecutionStatusEnum::CREATED,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128) // Local esperado
    );
    $repo->save($execution);

    $useCase = new PerformCheckIn($repo);
    $result = $useCase->execute(
        $uuid,
        new GeoLocation(-20.3200, -40.3200), // Muito longe (>150m)
        new \DateTimeImmutable('2026-02-10 09:00:00')
    );

    assert($result->isOk());
    assert($result->value()['has_sla_violations'] === true);

    $repo->delete($uuid);
});

test('PerformCheckIn: Invalid transition (already checked-in) → Result::fail', function() use ($repo) {
    $uuid = 'test-checkin-invalid';

    $execution = new Execution(
        $uuid,
        'order-checkin-invalid',
        101,
        ExecutionStatusEnum::CHECKED_IN, // Já está checked-in
        null,
        null,
        150,
        new \DateTimeImmutable()
    );
    $repo->save($execution);

    $useCase = new PerformCheckIn($repo);
    $result = $useCase->execute(
        $uuid,
        new GeoLocation(-20.3155, -40.3128),
        new \DateTimeImmutable()
    );

    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot perform check-in'));

    $repo->delete($uuid);
});

test('PerformCheckIn: Persistence check (Execution updated in DB)', function() use ($repo) {
    $uuid = 'test-checkin-persist';

    $execution = new Execution(
        $uuid,
        'order-checkin-persist',
        202,
        ExecutionStatusEnum::CREATED
    );
    $repo->save($execution);

    $useCase = new PerformCheckIn($repo);
    $useCase->execute(
        $uuid,
        new GeoLocation(-20.3155, -40.3128),
        new \DateTimeImmutable()
    );

    // Recarregar do banco
    $loaded = $repo->findByUuid($uuid);
    assert($loaded->getStatus() === ExecutionStatusEnum::CHECKED_IN);
    assert($loaded->getCheckInAt() !== null);
    assert($loaded->getCheckInGeo() !== null);

    $repo->delete($uuid);
});

// ========================================
// PERFORMCHECKOUT TESTS
// ========================================

test('PerformCheckOut: Execution not found → Result::fail', function() use ($repo) {
    $useCase = new PerformCheckOut($repo);

    $result = $useCase->execute(
        'non-existing-uuid',
        new GeoLocation(-20.3155, -40.3128),
        new EvidenceCollection([Evidence::photo('test.jpg')])
    );

    assert($result->isFail());
    assert(str_contains($result->error(), 'not found'));
});

test('PerformCheckOut: Happy path (IN_EXECUTION → CHECKED_OUT)', function() use ($repo) {
    $uuid = 'test-checkout-1';

    // Criar Execution já em execução (IN_EXECUTION)
    $execution = new Execution(
        $uuid,
        'order-checkout-1',
        303,
        ExecutionStatusEnum::IN_EXECUTION,
        null,
        null,
        150,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128)
    );
    $repo->save($execution);

    // Executar Use Case
    $useCase = new PerformCheckOut($repo);
    $evidence = new EvidenceCollection([
        Evidence::photo('photo1.jpg'),
        Evidence::photo('photo2.jpg'),
    ]);

    $result = $useCase->execute(
        $uuid,
        new GeoLocation(-20.3156, -40.3129),
        $evidence
    );

    assert($result->isOk());
    assert($result->value()['status'] === 'checked_out');
    assert($result->value()['evidence_count'] === 2);
    assert($result->value()['has_photos'] === true);
    assert($result->value()['duration_minutes'] !== null);

    $repo->delete($uuid);
});

test('PerformCheckOut: Without check-in → Result::fail', function() use ($repo) {
    $uuid = 'test-checkout-noci';

    $execution = new Execution(
        $uuid,
        'order-checkout-noci',
        404,
        ExecutionStatusEnum::CREATED // Não fez check-in
    );
    $repo->save($execution);

    $useCase = new PerformCheckOut($repo);
    $result = $useCase->execute(
        $uuid,
        new GeoLocation(-20.3155, -40.3128),
        new EvidenceCollection([Evidence::photo('test.jpg')])
    );

    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot perform check-out'));

    $repo->delete($uuid);
});

test('PerformCheckOut: Persistence check (Evidence saved)', function() use ($repo) {
    $uuid = 'test-checkout-persist';

    $execution = new Execution(
        $uuid,
        'order-checkout-persist',
        505,
        ExecutionStatusEnum::IN_EXECUTION,
        null,
        null,
        150,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128)
    );
    $repo->save($execution);

    $useCase = new PerformCheckOut($repo);
    $evidence = new EvidenceCollection([Evidence::video('video.mp4')]);

    $useCase->execute($uuid, new GeoLocation(-20.3155, -40.3128), $evidence);

    // Recarregar do banco
    $loaded = $repo->findByUuid($uuid);
    assert($loaded->getStatus() === ExecutionStatusEnum::CHECKED_OUT);
    assert($loaded->hasEvidence());
    assert($loaded->getEvidence()->hasVideos());

    $repo->delete($uuid);
});

// ========================================
// VALIDATEEXECUTION TESTS
// ========================================

test('ValidateExecution: Execution not found → Result::fail', function() use ($repo) {
    $useCase = new ValidateExecution($repo);

    $result = $useCase->execute('non-existing-uuid');

    assert($result->isFail());
    assert(str_contains($result->error(), 'not found'));
});

test('ValidateExecution: Happy path (CHECKED_OUT → VALIDATED)', function() use ($repo) {
    $uuid = 'test-validate-1';

    // Criar Execution já checked-out com evidence
    $evidence = new EvidenceCollection([Evidence::photo('photo.jpg')]);
    $execution = new Execution(
        $uuid,
        'order-validate-1',
        606,
        ExecutionStatusEnum::CHECKED_OUT,
        null,
        null,
        150,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128),
        new \DateTimeImmutable('2026-02-10 12:00:00'),
        new GeoLocation(-20.3156, -40.3129),
        $evidence
    );
    $repo->save($execution);

    // Executar Use Case
    $useCase = new ValidateExecution($repo);
    $result = $useCase->execute($uuid);

    assert($result->isOk());
    assert($result->value()['status'] === 'validated');
    assert($result->value()['is_validated'] === true);
    assert($result->value()['has_evidence'] === true);
    assert($result->value()['evidence_count'] === 1);

    $repo->delete($uuid);
});

test('ValidateExecution: Without evidence → Result::fail', function() use ($repo) {
    $uuid = 'test-validate-noev';

    $execution = new Execution(
        $uuid,
        'order-validate-noev',
        707,
        ExecutionStatusEnum::CHECKED_OUT,
        null,
        null,
        150,
        new \DateTimeImmutable(),
        new GeoLocation(-20.3155, -40.3128),
        new \DateTimeImmutable(),
        new GeoLocation(-20.3155, -40.3128)
        // SEM evidence
    );
    $repo->save($execution);

    $useCase = new ValidateExecution($repo);
    $result = $useCase->execute($uuid);

    assert($result->isFail());
    assert(str_contains($result->error(), 'Cannot validate'));

    $repo->delete($uuid);
});

test('ValidateExecution: Wrong status (IN_EXECUTION) → Result::fail', function() use ($repo) {
    $uuid = 'test-validate-wrong';

    $execution = new Execution(
        $uuid,
        'order-validate-wrong',
        808,
        ExecutionStatusEnum::IN_EXECUTION // Não está CHECKED_OUT
    );
    $repo->save($execution);

    $useCase = new ValidateExecution($repo);
    $result = $useCase->execute($uuid);

    assert($result->isFail());

    $repo->delete($uuid);
});

test('ValidateExecution: Persistence check (Status VALIDATED)', function() use ($repo) {
    $uuid = 'test-validate-persist';

    $evidence = new EvidenceCollection([Evidence::photo('photo.jpg')]);
    $execution = new Execution(
        $uuid,
        'order-validate-persist',
        909,
        ExecutionStatusEnum::CHECKED_OUT,
        null,
        null,
        150,
        new \DateTimeImmutable(),
        new GeoLocation(-20.3155, -40.3128),
        new \DateTimeImmutable(),
        new GeoLocation(-20.3155, -40.3128),
        $evidence
    );
    $repo->save($execution);

    $useCase = new ValidateExecution($repo);
    $useCase->execute($uuid);

    // Recarregar do banco
    $loaded = $repo->findByUuid($uuid);
    assert($loaded->getStatus() === ExecutionStatusEnum::VALIDATED);
    assert($loaded->getStatus()->isValidated());

    $repo->delete($uuid);
});

// ========================================
// INTEGRATION TESTS (End-to-End)
// ========================================

test('INTEGRATION: Complete flow (CheckIn → CheckOut → Validate)', function() use ($repo) {
    $uuid = 'test-e2e-complete';

    // 1. Criar Execution
    $execution = Execution::create(
        $uuid,
        'order-e2e-complete',
        1001,
        new \DateTimeImmutable('2026-02-10 09:00:00'),
        new GeoLocation(-20.3155, -40.3128)
    );
    $repo->save($execution);

    // 2. Check-in
    $checkInUseCase = new PerformCheckIn($repo);
    $checkInResult = $checkInUseCase->execute(
        $uuid,
        new GeoLocation(-20.3156, -40.3129),
        new \DateTimeImmutable('2026-02-10 09:00:00')
    );
    assert($checkInResult->isOk());
    assert($checkInResult->value()['status'] === 'checked_in');

    // 3. Start execution (direct call)
    $loaded = $repo->findByUuid($uuid);
    $loaded->startExecution();
    $repo->save($loaded);

    // 4. Check-out
    $checkOutUseCase = new PerformCheckOut($repo);
    $evidence = new EvidenceCollection([
        Evidence::photo('before.jpg'),
        Evidence::photo('after.jpg'),
    ]);
    $checkOutResult = $checkOutUseCase->execute(
        $uuid,
        new GeoLocation(-20.3157, -40.3130),
        $evidence
    );
    assert($checkOutResult->isOk());
    assert($checkOutResult->value()['status'] === 'checked_out');
    assert($checkOutResult->value()['evidence_count'] === 2);

    // 5. Validate
    $validateUseCase = new ValidateExecution($repo);
    $validateResult = $validateUseCase->execute($uuid);
    assert($validateResult->isOk());
    assert($validateResult->value()['status'] === 'validated');
    assert($validateResult->value()['is_validated'] === true);

    $repo->delete($uuid);
});

// ========================================
// RESULTS
// ========================================

echo "\n=== RESULTS ===\n";
echo "✅ Passed: $testsPassed\n";
echo "❌ Failed: $testsFailed\n";
echo "📊 Total: " . ($testsPassed + $testsFailed) . "\n";

if ($testsFailed === 0) {
    echo "\n🎉 ALL USE CASE TESTS PASSED!\n";
    exit(0);
} else {
    echo "\n💥 SOME TESTS FAILED!\n";
    exit(1);
}
