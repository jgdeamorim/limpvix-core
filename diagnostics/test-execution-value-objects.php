<?php
/**
 * Test Execution Value Objects (Sprint 1 - Dia 1)
 *
 * OBJETIVO: Validar Enums e Value Objects do Execution domain
 * - ExecutionStatusEnum (estados + helpers)
 * - GeoLocation (coordenadas + Haversine)
 * - Evidence (foto/vídeo + validações)
 * - EvidenceCollection (coleção + validações)
 *
 * REQUISITO: ≥15 testes
 */

// Carrega WordPress
define('WP_USE_THEMES', false);
require_once dirname(dirname(dirname(dirname(__DIR__)))) . '/wp-load.php';

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

echo "=== EXECUTION VALUE OBJECTS TESTS ===\n\n";

// ========================================
// EXECUTION STATUS ENUM
// ========================================

test('ExecutionStatusEnum: has 6 states', function() {
    $cases = ExecutionStatusEnum::cases();
    assert(count($cases) === 6);
});

test('ExecutionStatusEnum: isIn() works correctly', function() {
    $status = ExecutionStatusEnum::CHECKED_IN;
    assert($status->isIn([ExecutionStatusEnum::CHECKED_IN, ExecutionStatusEnum::VALIDATED]));
    assert(!$status->isIn([ExecutionStatusEnum::CREATED]));
});

test('ExecutionStatusEnum: isTerminal() only for CLOSED', function() {
    assert(!ExecutionStatusEnum::CREATED->isTerminal());
    assert(!ExecutionStatusEnum::VALIDATED->isTerminal());
    assert(ExecutionStatusEnum::CLOSED->isTerminal());
});

test('ExecutionStatusEnum: isCheckedIn() works correctly', function() {
    assert(!ExecutionStatusEnum::CREATED->isCheckedIn());
    assert(ExecutionStatusEnum::CHECKED_IN->isCheckedIn());
    assert(ExecutionStatusEnum::IN_EXECUTION->isCheckedIn());
    assert(ExecutionStatusEnum::VALIDATED->isCheckedIn());
});

test('ExecutionStatusEnum: isCheckedOut() works correctly', function() {
    assert(!ExecutionStatusEnum::CREATED->isCheckedOut());
    assert(!ExecutionStatusEnum::CHECKED_IN->isCheckedOut());
    assert(ExecutionStatusEnum::CHECKED_OUT->isCheckedOut());
    assert(ExecutionStatusEnum::VALIDATED->isCheckedOut());
});

// ========================================
// GEO LOCATION
// ========================================

test('GeoLocation: creates with valid coordinates', function() {
    $geo = new GeoLocation(-23.5505, -46.6333); // São Paulo
    assert($geo->latitude === -23.5505);
    assert($geo->longitude === -46.6333);
});

test('GeoLocation: rejects invalid latitude', function() {
    $exceptionThrown = false;
    try {
        new GeoLocation(100.0, -46.6333); // > 90
    } catch (\InvalidArgumentException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Invalid latitude'));
    }
    assert($exceptionThrown);
});

test('GeoLocation: rejects invalid longitude', function() {
    $exceptionThrown = false;
    try {
        new GeoLocation(-23.5505, 200.0); // > 180
    } catch (\InvalidArgumentException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Invalid longitude'));
    }
    assert($exceptionThrown);
});

test('GeoLocation: calculates distance (Haversine)', function() {
    // São Paulo → Rio de Janeiro ~ 357 km
    $sp = new GeoLocation(-23.5505, -46.6333);
    $rj = new GeoLocation(-22.9068, -43.1729);
    
    $distance = $sp->distanceTo($rj);
    
    // Tolerância de 5 km (fórmula Haversine é aproximação)
    assert($distance > 350000 && $distance < 365000);
});

test('GeoLocation: isWithinRadius() works correctly', function() {
    $center = new GeoLocation(-23.5505, -46.6333);
    $nearby = new GeoLocation(-23.5510, -46.6340); // ~100m
    $far = new GeoLocation(-22.9068, -43.1729); // ~357km
    
    assert($nearby->isWithinRadius($center, 200));
    assert(!$far->isWithinRadius($center, 200));
});

test('GeoLocation: equals() works correctly', function() {
    $geo1 = new GeoLocation(-23.5505, -46.6333);
    $geo2 = new GeoLocation(-23.5505, -46.6333);
    $geo3 = new GeoLocation(-23.5510, -46.6340);
    
    assert($geo1->equals($geo2));
    assert(!$geo1->equals($geo3));
});

// ========================================
// EVIDENCE
// ========================================

test('Evidence: creates photo with valid data', function() {
    $evidence = Evidence::photo('https://example.com/photo.jpg');
    assert($evidence->isPhoto());
    assert(!$evidence->isVideo());
    assert($evidence->url === 'https://example.com/photo.jpg');
});

test('Evidence: creates video with valid data', function() {
    $evidence = Evidence::video('https://example.com/video.mp4');
    assert($evidence->isVideo());
    assert(!$evidence->isPhoto());
});

test('Evidence: rejects invalid type', function() {
    $exceptionThrown = false;
    try {
        new Evidence('invalid', 'url', new \DateTimeImmutable());
    } catch (\InvalidArgumentException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'Invalid evidence type'));
    }
    assert($exceptionThrown);
});

test('Evidence: rejects empty URL', function() {
    $exceptionThrown = false;
    try {
        Evidence::photo('');
    } catch (\InvalidArgumentException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'URL cannot be empty'));
    }
    assert($exceptionThrown);
});

// ========================================
// EVIDENCE COLLECTION
// ========================================

test('EvidenceCollection: creates with single evidence', function() {
    $evidence = Evidence::photo('url');
    $collection = EvidenceCollection::single($evidence);
    assert($collection->count() === 1);
});

test('EvidenceCollection: rejects empty collection', function() {
    $exceptionThrown = false;
    try {
        new EvidenceCollection([]);
    } catch (\InvalidArgumentException $e) {
        $exceptionThrown = true;
        assert(str_contains($e->getMessage(), 'cannot be empty'));
    }
    assert($exceptionThrown);
});

test('EvidenceCollection: hasPhotos() works correctly', function() {
    $photo = Evidence::photo('photo.jpg');
    $video = Evidence::video('video.mp4');
    
    $collection1 = new EvidenceCollection([$photo]);
    $collection2 = new EvidenceCollection([$video]);
    
    assert($collection1->hasPhotos());
    assert(!$collection2->hasPhotos());
});

test('EvidenceCollection: hasVideos() works correctly', function() {
    $photo = Evidence::photo('photo.jpg');
    $video = Evidence::video('video.mp4');
    
    $collection1 = new EvidenceCollection([$photo]);
    $collection2 = new EvidenceCollection([$video]);
    
    assert(!$collection1->hasVideos());
    assert($collection2->hasVideos());
});

test('EvidenceCollection: filters photos and videos', function() {
    $photo1 = Evidence::photo('photo1.jpg');
    $photo2 = Evidence::photo('photo2.jpg');
    $video = Evidence::video('video.mp4');
    
    $collection = new EvidenceCollection([$photo1, $video, $photo2]);
    
    assert(count($collection->photos()) === 2);
    assert(count($collection->videos()) === 1);
});

test('EvidenceCollection: toUrls() works correctly', function() {
    $photo = Evidence::photo('photo.jpg');
    $video = Evidence::video('video.mp4');
    
    $collection = new EvidenceCollection([$photo, $video]);
    $urls = $collection->toUrls();
    
    assert(count($urls) === 2);
    assert(in_array('photo.jpg', $urls));
    assert(in_array('video.mp4', $urls));
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
