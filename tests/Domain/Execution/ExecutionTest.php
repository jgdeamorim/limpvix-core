<?php
declare(strict_types=1);

/**
 * ExecutionTest - Unit Tests for Execution Aggregate Root
 *
 * Tests the full state machine lifecycle:
 * CREATED -> CHECKED_IN -> IN_EXECUTION -> CHECKED_OUT -> CLOSED
 *
 * Also tests geofence validation, SLA violations, and feedback window.
 *
 * @package LimpVix\Tests\Domain\Execution
 * @since 1.0.0
 */

namespace LimpVix\Tests\Domain\Execution;

use PHPUnit\Framework\TestCase;
use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\Exceptions\InvalidExecutionTransitionException;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Execution\ValueObjects\Evidence;
use LimpVix\Domain\Execution\ValueObjects\TimeWindow;
use LimpVix\Domain\Execution\ValueObjects\SlaViolation;

class ExecutionTest extends TestCase
{
    private string $executionUuid;
    private string $orderUuid;
    private int $professionalId;
    private \DateTimeImmutable $scheduledStartTime;
    private GeoLocation $serviceLocation;

    protected function setUp(): void
    {
        $this->executionUuid = 'exec-uuid-001';
        $this->orderUuid = 'order-uuid-001';
        $this->professionalId = 42;
        $this->scheduledStartTime = new \DateTimeImmutable('+1 hour');
        $this->serviceLocation = new GeoLocation(-20.3155, -40.3128);
    }

    // ========================================
    // CREATION TESTS
    // ========================================

    /**
     * @test
     */
    public function test_create_execution(): void
    {
        $execution = Execution::create(
            $this->executionUuid,
            $this->orderUuid,
            $this->professionalId,
            $this->scheduledStartTime,
            $this->serviceLocation
        );

        $this->assertInstanceOf(Execution::class, $execution);
        $this->assertSame($this->executionUuid, $execution->getExecutionUuid());
        $this->assertSame($this->orderUuid, $execution->getOrderUuid());
        $this->assertSame($this->professionalId, $execution->getProfessionalId());
    }

    /**
     * @test
     */
    public function test_execution_initial_status(): void
    {
        $execution = Execution::create(
            $this->executionUuid,
            $this->orderUuid,
            $this->professionalId,
            $this->scheduledStartTime,
            $this->serviceLocation
        );

        $this->assertSame(ExecutionStatusEnum::CREATED, $execution->getStatus());
    }

    /**
     * @test
     */
    public function test_create_execution_with_expected_rooms_count(): void
    {
        $execution = Execution::create(
            $this->executionUuid,
            $this->orderUuid,
            $this->professionalId,
            $this->scheduledStartTime,
            $this->serviceLocation,
            5
        );

        $this->assertSame(5, $execution->getExpectedRoomsCount());
    }

    // ========================================
    // STATE MACHINE TESTS
    // ========================================

    /**
     * @test
     */
    public function test_check_in_with_coordinates(): void
    {
        $execution = $this->createExecution();

        // Check-in at the same location (within geofence)
        $checkInGeo = new GeoLocation(-20.3155, -40.3128);
        $execution->checkIn($checkInGeo);

        $this->assertSame(ExecutionStatusEnum::CHECKED_IN, $execution->getStatus());
        $this->assertNotNull($execution->getCheckInAt());
        $this->assertNotNull($execution->getCheckInGeo());
        $this->assertSame($checkInGeo, $execution->getCheckInGeo());
    }

    /**
     * @test
     */
    public function test_start_execution(): void
    {
        $execution = $this->createExecution();

        $checkInGeo = new GeoLocation(-20.3155, -40.3128);
        $execution->checkIn($checkInGeo);
        $execution->startExecution();

        $this->assertSame(ExecutionStatusEnum::IN_EXECUTION, $execution->getStatus());
    }

    /**
     * @test
     */
    public function test_check_out(): void
    {
        $execution = $this->createCheckedInAndStartedExecution();

        $checkOutGeo = new GeoLocation(-20.3155, -40.3128);
        $evidence = new EvidenceCollection([
            Evidence::photo('https://example.com/photo1.jpg'),
        ]);

        $execution->checkOut($checkOutGeo, $evidence);

        $this->assertSame(ExecutionStatusEnum::CHECKED_OUT, $execution->getStatus());
        $this->assertNotNull($execution->getCheckOutAt());
        $this->assertNotNull($execution->getCheckOutGeo());
        $this->assertNotNull($execution->getEvidence());
        $this->assertTrue($execution->hasEvidence());
    }

    /**
     * @test
     */
    public function test_complete_execution_via_close(): void
    {
        $execution = $this->createCheckedOutExecution();

        $execution->close();

        $this->assertSame(ExecutionStatusEnum::CLOSED, $execution->getStatus());
    }

    /**
     * @test
     */
    public function test_full_lifecycle(): void
    {
        $execution = $this->createExecution();
        $geo = new GeoLocation(-20.3155, -40.3128);

        // Step 1: Check-in
        $execution->checkIn($geo);
        $this->assertSame(ExecutionStatusEnum::CHECKED_IN, $execution->getStatus());

        // Step 2: Start execution
        $execution->startExecution();
        $this->assertSame(ExecutionStatusEnum::IN_EXECUTION, $execution->getStatus());

        // Step 3: Check-out with evidence
        $evidence = new EvidenceCollection([
            Evidence::photo('https://example.com/photo.jpg'),
        ]);
        $execution->checkOut($geo, $evidence);
        $this->assertSame(ExecutionStatusEnum::CHECKED_OUT, $execution->getStatus());

        // Step 4: Close
        $execution->close();
        $this->assertSame(ExecutionStatusEnum::CLOSED, $execution->getStatus());
    }

    // ========================================
    // INVALID TRANSITION TESTS
    // ========================================

    /**
     * @test
     */
    public function test_invalid_transition_throws(): void
    {
        $execution = $this->createExecution();

        // Cannot start execution without checking in first
        $this->expectException(InvalidExecutionTransitionException::class);
        $execution->startExecution();
    }

    /**
     * @test
     */
    public function test_cannot_check_out_without_check_in(): void
    {
        $execution = $this->createExecution();

        $geo = new GeoLocation(-20.3155, -40.3128);
        $evidence = new EvidenceCollection([
            Evidence::photo('https://example.com/photo.jpg'),
        ]);

        $this->expectException(InvalidExecutionTransitionException::class);
        $execution->checkOut($geo, $evidence);
    }

    /**
     * @test
     */
    public function test_cannot_check_out_with_empty_evidence(): void
    {
        // EvidenceCollection constructor throws if empty
        $this->expectException(\InvalidArgumentException::class);
        new EvidenceCollection([]);
    }

    /**
     * @test
     */
    public function test_cannot_transition_from_terminal_state(): void
    {
        $execution = $this->createCheckedOutExecution();
        $execution->close();

        // CLOSED is terminal - cannot check in again
        $this->expectException(InvalidExecutionTransitionException::class);
        $execution->checkIn(new GeoLocation(-20.3155, -40.3128));
    }

    /**
     * @test
     */
    public function test_cannot_skip_in_execution_to_checked_out(): void
    {
        $execution = $this->createExecution();

        $geo = new GeoLocation(-20.3155, -40.3128);
        $execution->checkIn($geo);

        // CHECKED_IN -> CHECKED_OUT is not allowed (must go through IN_EXECUTION)
        $evidence = new EvidenceCollection([
            Evidence::photo('https://example.com/photo.jpg'),
        ]);

        $this->expectException(InvalidExecutionTransitionException::class);
        $execution->checkOut($geo, $evidence);
    }

    // ========================================
    // GEOFENCE / SLA TESTS
    // ========================================

    /**
     * @test
     */
    public function test_check_in_outside_geofence_records_sla_violation(): void
    {
        $execution = $this->createExecution();

        // Far away location (several kilometers)
        $farAwayGeo = new GeoLocation(-20.3500, -40.3500);
        $execution->checkIn($farAwayGeo);

        // Check-in still succeeds, but SLA violation is recorded
        $this->assertSame(ExecutionStatusEnum::CHECKED_IN, $execution->getStatus());
        $this->assertTrue($execution->hasSlaViolations());

        $violations = $execution->getSlaViolations();
        $this->assertNotEmpty($violations);
        $this->assertInstanceOf(SlaViolation::class, $violations[0]);
        $this->assertTrue($violations[0]->isOutOfGeofence());
    }

    /**
     * @test
     */
    public function test_check_in_within_geofence_no_sla_violation(): void
    {
        $execution = $this->createExecution();

        // Very close to service location (within 150m)
        $nearbyGeo = new GeoLocation(-20.3155, -40.3128);
        $execution->checkIn($nearbyGeo);

        $this->assertSame(ExecutionStatusEnum::CHECKED_IN, $execution->getStatus());
        $this->assertFalse($execution->hasSlaViolations());
    }

    // ========================================
    // GETTER TESTS
    // ========================================

    /**
     * @test
     */
    public function test_get_order_uuid(): void
    {
        $execution = $this->createExecution();
        $this->assertSame($this->orderUuid, $execution->getOrderUuid());
    }

    /**
     * @test
     */
    public function test_get_professional_id(): void
    {
        $execution = $this->createExecution();
        $this->assertSame($this->professionalId, $execution->getProfessionalId());
    }

    /**
     * @test
     */
    public function test_get_status(): void
    {
        $execution = $this->createExecution();
        $this->assertSame(ExecutionStatusEnum::CREATED, $execution->getStatus());
    }

    /**
     * @test
     */
    public function test_get_scheduled_start_time(): void
    {
        $execution = $this->createExecution();
        $this->assertSame($this->scheduledStartTime, $execution->getScheduledStartTime());
    }

    /**
     * @test
     */
    public function test_get_service_location(): void
    {
        $execution = $this->createExecution();
        $this->assertSame($this->serviceLocation, $execution->getServiceLocation());
    }

    /**
     * @test
     */
    public function test_get_geofence_radius_default(): void
    {
        $execution = $this->createExecution();
        $this->assertSame(150, $execution->getGeofenceRadiusMeters());
    }

    // ========================================
    // TIMESTAMP TESTS
    // ========================================

    /**
     * @test
     */
    public function test_execution_has_correct_timestamps(): void
    {
        $execution = $this->createExecution();

        // Before check-in, no timestamps
        $this->assertNull($execution->getCheckInAt());
        $this->assertNull($execution->getCheckOutAt());

        // After check-in
        $geo = new GeoLocation(-20.3155, -40.3128);
        $execution->checkIn($geo);

        $this->assertInstanceOf(\DateTimeImmutable::class, $execution->getCheckInAt());
        $this->assertNull($execution->getCheckOutAt());

        // After full flow
        $execution->startExecution();
        $evidence = new EvidenceCollection([
            Evidence::photo('https://example.com/photo.jpg'),
        ]);
        $execution->checkOut($geo, $evidence);

        $this->assertInstanceOf(\DateTimeImmutable::class, $execution->getCheckOutAt());
    }

    /**
     * @test
     */
    public function test_duration_minutes_null_when_incomplete(): void
    {
        $execution = $this->createExecution();
        $this->assertNull($execution->getDurationMinutes());
    }

    /**
     * @test
     */
    public function test_duration_minutes_calculated_after_checkout(): void
    {
        $execution = $this->createCheckedOutExecution();
        // Both check-in and check-out are set, so duration should be calculated
        $duration = $execution->getDurationMinutes();
        $this->assertIsInt($duration);
        $this->assertGreaterThanOrEqual(0, $duration);
    }

    // ========================================
    // FEEDBACK WINDOW TESTS
    // ========================================

    /**
     * @test
     */
    public function test_feedback_window_starts_after_checkout(): void
    {
        $execution = $this->createCheckedOutExecution();

        $this->assertNotNull($execution->getFeedbackWindowExpiresAt());
        $this->assertTrue($execution->isFeedbackWindowActive());
        $this->assertFalse($execution->isFeedbackWindowExpired());
    }

    /**
     * @test
     */
    public function test_feedback_window_not_started_before_checkout(): void
    {
        $execution = $this->createExecution();

        $this->assertNull($execution->getFeedbackWindowExpiresAt());
        $this->assertFalse($execution->isFeedbackWindowActive());
        $this->assertFalse($execution->isFeedbackWindowExpired());
    }

    // ========================================
    // SERVICE COMPLETED TESTS
    // ========================================

    /**
     * @test
     */
    public function test_is_service_completed_after_checkout(): void
    {
        $execution = $this->createCheckedOutExecution();
        $this->assertTrue($execution->isServiceCompleted());
    }

    /**
     * @test
     */
    public function test_is_not_service_completed_before_checkout(): void
    {
        $execution = $this->createExecution();
        $this->assertFalse($execution->isServiceCompleted());
    }

    // ========================================
    // ISSUE REPORTING TESTS
    // ========================================

    /**
     * @test
     */
    public function test_report_issue_on_execution(): void
    {
        $execution = $this->createExecution();

        $execution->reportIssue(
            'quality',
            'Service quality issue',
            'customer',
            100
        );

        $this->assertTrue($execution->hasOpenIssues());
        $issues = $execution->getIssues();
        $this->assertSame(1, $issues->count());
    }

    /**
     * @test
     */
    public function test_no_open_issues_initially(): void
    {
        $execution = $this->createExecution();

        $this->assertFalse($execution->hasOpenIssues());
        $this->assertSame(0, $execution->getIssues()->count());
    }

    // ========================================
    // EQUALITY TESTS
    // ========================================

    /**
     * @test
     */
    public function test_equality_by_uuid(): void
    {
        $execution1 = $this->createExecution();
        $execution2 = Execution::create(
            $this->executionUuid,
            'different-order',
            99,
            $this->scheduledStartTime,
            $this->serviceLocation
        );

        $this->assertTrue($execution1->equals($execution2));
    }

    /**
     * @test
     */
    public function test_inequality_by_uuid(): void
    {
        $execution1 = $this->createExecution();
        $execution2 = Execution::create(
            'different-uuid',
            $this->orderUuid,
            $this->professionalId,
            $this->scheduledStartTime,
            $this->serviceLocation
        );

        $this->assertFalse($execution1->equals($execution2));
    }

    // ========================================
    // RECONSTITUTE TESTS (via constructor)
    // ========================================

    /**
     * @test
     */
    public function test_reconstitute_execution_with_checked_in_status(): void
    {
        $checkInAt = new \DateTimeImmutable('-30 minutes');
        $checkInGeo = new GeoLocation(-20.3155, -40.3128);

        $execution = new Execution(
            'exec-123',
            'order-456',
            42,
            ExecutionStatusEnum::CHECKED_IN,
            $this->scheduledStartTime,
            $this->serviceLocation,
            150,
            $checkInAt,
            $checkInGeo
        );

        $this->assertSame(ExecutionStatusEnum::CHECKED_IN, $execution->getStatus());
        $this->assertSame($checkInAt, $execution->getCheckInAt());
        $this->assertSame($checkInGeo, $execution->getCheckInGeo());
    }

    // ========================================
    // HELPER METHODS
    // ========================================

    private function createExecution(): Execution
    {
        return Execution::create(
            $this->executionUuid,
            $this->orderUuid,
            $this->professionalId,
            $this->scheduledStartTime,
            $this->serviceLocation
        );
    }

    private function createCheckedInAndStartedExecution(): Execution
    {
        $execution = $this->createExecution();
        $geo = new GeoLocation(-20.3155, -40.3128);
        $execution->checkIn($geo);
        $execution->startExecution();
        return $execution;
    }

    private function createCheckedOutExecution(): Execution
    {
        $execution = $this->createCheckedInAndStartedExecution();
        $geo = new GeoLocation(-20.3155, -40.3128);
        $evidence = new EvidenceCollection([
            Evidence::photo('https://example.com/photo.jpg'),
        ]);
        $execution->checkOut($geo, $evidence);
        return $execution;
    }
}
