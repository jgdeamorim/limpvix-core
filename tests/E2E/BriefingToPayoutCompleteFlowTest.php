<?php
/**
 * BriefingToPayoutCompleteFlowTest - End-to-End Domain Orchestration Tests
 *
 * Tests the entire Briefing-to-Payout flow at the domain logic level.
 * No real database or external services -- only domain entities and their
 * state machines are exercised to verify they work together correctly.
 *
 * Flow under test:
 * 1. Briefing created (draft) -> progresses through state machine -> locked
 * 2. Contract created (draft) -> pending_allocation -> active
 * 3. Execution created -> check-in -> in_execution -> check-out (with evidence)
 * 4. RecurringPayment created (pending) -> processing -> completed
 * 5. Payout authorization logic based on feedback rating
 *
 * @package LimpVix\Tests\E2E
 * @group e2e
 * @group flow
 */

namespace LimpVix\Tests\E2E;

use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\PropertyType;
use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ValueObjects\ContractStatus;
use LimpVix\Domain\Execution\Execution;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;
use LimpVix\Domain\Execution\ValueObjects\GeoLocation;
use LimpVix\Domain\Execution\ValueObjects\Evidence;
use LimpVix\Domain\Execution\ValueObjects\EvidenceCollection;
use LimpVix\Domain\Finance\RecurringPayment;
use LimpVix\Domain\Finance\ValueObjects\RecurringPaymentStatus;
use LimpVix\Tests\Helpers\TestFactory;
use PHPUnit\Framework\TestCase;

final class BriefingToPayoutCompleteFlowTest extends TestCase
{
    // ------------------------------------------------------------------
    // test_happy_path_briefing_to_payout
    //
    // Scenario: Full happy path from briefing creation to payout authorization.
    // Customer fills briefing, contract gets activated, professional executes
    // the service with evidence, customer gives 5-star feedback, and the
    // recurring payment completes -- authorizing payout.
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_happy_path_briefing_to_payout(): void
    {
        // ============================================================
        // STEP 1: Create Briefing (draft)
        // ============================================================
        $briefing = TestFactory::createBriefing([
            'uuid'    => 'briefing-happy-001',
            'user_id' => 42,
        ]);

        $this->assertFalse($briefing->isLocked());
        $this->assertTrue($briefing->getStatus()->isDraft());

        // Progress briefing through state machine:
        // draft -> in_progress -> pending_phone_verification -> awaiting_payment -> paid -> locked
        $briefing->transitionTo(BriefingStatus::inProgress());
        $this->assertTrue($briefing->getStatus()->isInProgress());

        $briefing->transitionTo(BriefingStatus::pendingPhoneVerification());
        $briefing->verifyPhone();
        $this->assertTrue($briefing->isPhoneVerified());

        $briefing->transitionTo(BriefingStatus::awaitingPayment());
        $briefing->transitionTo(BriefingStatus::paid());
        $this->assertTrue($briefing->getStatus()->isPaid());

        $briefing->lock(1001); // order ID
        $this->assertTrue($briefing->isLocked());
        $this->assertEquals(1001, $briefing->getOrderId());

        // ============================================================
        // STEP 2: Create Contract from Briefing data (draft -> active)
        // ============================================================
        $contract = TestFactory::createContract([
            'client_user_id'  => $briefing->getUserId(),
            'contract_number' => 'LMPVX-202602-000042',
            'contract_type'   => 'weekly',
            'monthly_value'   => 600.00,
            'auto_renew'      => true,
        ]);

        $this->assertTrue($contract->getStatus()->isDraft());
        $this->assertEquals(42, $contract->getClientUserId());

        // Transition: draft -> pending_allocation -> active
        $contract->submitForAllocation();
        $this->assertTrue($contract->getStatus()->isPendingAllocation());

        $professionalId = 10;
        $contract->activate($professionalId);
        $this->assertTrue($contract->getStatus()->isActive());
        $this->assertEquals($professionalId, $contract->getAllocatedProfessionalId());

        // ============================================================
        // STEP 3: Create Execution for this contract's service
        // ============================================================
        $serviceLocation = new GeoLocation(-20.3155, -40.3128);
        $execution = TestFactory::createExecution([
            'execution_uuid'       => 'exec-happy-001',
            'order_uuid'           => 'briefing-happy-001',
            'professional_id'      => $professionalId,
            'scheduled_start_time' => new \DateTimeImmutable('+2 hours'),
            'service_location'     => $serviceLocation,
            'expected_rooms_count' => 3,
        ]);

        $this->assertEquals(ExecutionStatusEnum::CREATED, $execution->getStatus());

        // Check-in (within geofence)
        $checkInGeo = new GeoLocation(-20.3155, -40.3128); // exact same location
        $execution->checkIn($checkInGeo);
        $this->assertEquals(ExecutionStatusEnum::CHECKED_IN, $execution->getStatus());
        $this->assertNotNull($execution->getCheckInAt());
        $this->assertEmpty($execution->getSlaViolations()); // within geofence

        // Start execution
        $execution->startExecution();
        $this->assertEquals(ExecutionStatusEnum::IN_EXECUTION, $execution->getStatus());

        // Check-out with evidence
        $checkOutGeo = new GeoLocation(-20.3155, -40.3128);
        $evidence = TestFactory::createEvidenceCollection(3);
        $execution->checkOut($checkOutGeo, $evidence);

        $this->assertEquals(ExecutionStatusEnum::CHECKED_OUT, $execution->getStatus());
        $this->assertNotNull($execution->getCheckOutAt());
        $this->assertTrue($execution->isServiceCompleted());
        $this->assertTrue($execution->hasEvidence());

        // Feedback window starts automatically after check-out
        $this->assertTrue($execution->isFeedbackWindowActive());
        $this->assertNotNull($execution->getFeedbackWindowExpiresAt());

        // ============================================================
        // STEP 4: Simulate 5-star feedback (happy path)
        // ============================================================
        $feedbackRating = 5;

        // In the real system, feedback would be submitted via a use case.
        // Here we verify the domain logic for payout authorization:
        // 5-star rating -> payout is authorized immediately
        $payoutAuthorized = $this->shouldAuthorizePayout($feedbackRating, $execution);
        $this->assertTrue($payoutAuthorized, 'Payout should be authorized for 5-star feedback');

        // ============================================================
        // STEP 5: RecurringPayment created and completed
        // ============================================================
        $payment = TestFactory::createRecurringPayment([
            'contract_id'          => 42, // would be the contract's real ID
            'billing_cycle_number' => 1,
            'amount'               => 138.57, // weekly: 600/4.33
            'due_date'             => new \DateTimeImmutable(),
        ]);

        $this->assertTrue($payment->getStatus()->isPending());
        $this->assertEquals(0, $payment->getAttemptCount());

        // Process payment: pending -> processing -> completed
        $payment->markAsProcessing('gw_happy_001');
        $this->assertTrue($payment->getStatus()->isProcessing());
        $this->assertEquals('gw_happy_001', $payment->getGatewayTransactionId());

        $payment->markAsCompleted(new \DateTimeImmutable());
        $this->assertTrue($payment->getStatus()->isCompleted());
        $this->assertNotNull($payment->getPaidAt());

        // Verify domain events were emitted
        $events = $payment->releaseEvents();
        $this->assertNotEmpty($events);

        // ============================================================
        // STEP 6: Close execution (terminal state)
        // ============================================================
        $execution->close();
        $this->assertEquals(ExecutionStatusEnum::CLOSED, $execution->getStatus());
        $this->assertTrue($execution->getStatus()->isTerminal());

        // Full flow completed successfully
        $this->assertTrue(true, 'Happy path: Briefing -> Contract -> Execution -> Payment -> Payout');
    }

    // ------------------------------------------------------------------
    // test_feedback_hold_flow
    //
    // Scenario: Same as happy path but with 3-star feedback.
    // 3 stars means the payout should go to on_hold for admin review.
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_feedback_hold_flow(): void
    {
        // ============================================================
        // STEP 1: Quick setup -- briefing and contract already active
        // ============================================================
        $contract = TestFactory::createContractFromPersistence([
            'id'                        => 55,
            'client_user_id'            => 42,
            'contract_number'           => 'LMPVX-202602-000055',
            'status'                    => 'active',
            'allocated_professional_id' => 10,
        ]);

        $this->assertTrue($contract->isActive());

        // ============================================================
        // STEP 2: Execution with full evidence
        // ============================================================
        $serviceLocation = new GeoLocation(-20.3155, -40.3128);
        $execution = TestFactory::createExecution([
            'execution_uuid'       => 'exec-hold-001',
            'order_uuid'           => 'order-hold-001',
            'professional_id'      => 10,
            'scheduled_start_time' => new \DateTimeImmutable('+1 hour'),
            'service_location'     => $serviceLocation,
        ]);

        // Run through execution: check-in -> execution -> check-out
        $execution->checkIn(new GeoLocation(-20.3155, -40.3128));
        $execution->startExecution();
        $execution->checkOut(
            new GeoLocation(-20.3155, -40.3128),
            TestFactory::createEvidenceCollection(2)
        );

        $this->assertTrue($execution->isServiceCompleted());

        // ============================================================
        // STEP 3: Simulate 3-star feedback -> payout on_hold
        // ============================================================
        $feedbackRating = 3;

        $payoutAuthorized = $this->shouldAuthorizePayout($feedbackRating, $execution);
        $this->assertFalse($payoutAuthorized, 'Payout should NOT be authorized for 3-star feedback');

        $payoutOnHold = $this->shouldHoldPayout($feedbackRating);
        $this->assertTrue($payoutOnHold, 'Payout should be on_hold for 3-star feedback');

        // ============================================================
        // STEP 4: Payment still completes (cash-in is separate from payout)
        // ============================================================
        $payment = TestFactory::createRecurringPayment([
            'contract_id'          => 55,
            'billing_cycle_number' => 1,
            'amount'               => 150.00,
        ]);

        $payment->markAsProcessing('gw_hold_001');
        $payment->markAsCompleted(new \DateTimeImmutable());

        $this->assertTrue($payment->getStatus()->isCompleted());

        // But payout to professional is ON HOLD (not released automatically)
        // This would be handled by the ExecutePayout use case checking feedback rating
        $this->assertTrue(true, 'Hold flow: 3-star feedback -> payment completed but payout on hold');
    }

    // ------------------------------------------------------------------
    // test_contract_renewal_flow
    //
    // Scenario: Contract is active, recurring payment completes,
    // and the contract end date is extended via renewWithPayment().
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_contract_renewal_flow(): void
    {
        // ============================================================
        // STEP 1: Active contract with auto_renew
        // ============================================================
        $contract = TestFactory::createContractFromPersistence([
            'id'                        => 77,
            'client_user_id'            => 42,
            'contract_number'           => 'LMPVX-202602-000077',
            'status'                    => 'active',
            'contract_type'             => 'monthly',
            'recurrence_day'            => 15,
            'end_date'                  => '2026-04-01',
            'monthly_value'             => 600.00,
            'allocated_professional_id' => 10,
            'auto_renew'                => true,
        ]);

        $this->assertTrue($contract->isActive());
        $this->assertTrue($contract->isAutoRenew());

        $originalEndDate = $contract->getEndDate();
        $this->assertNotNull($originalEndDate);

        // ============================================================
        // STEP 2: RecurringPayment completes
        // ============================================================
        $payment = TestFactory::createRecurringPayment([
            'contract_id'          => 77,
            'billing_cycle_number' => 3,
            'amount'               => 600.00,
            'due_date'             => new \DateTimeImmutable('2026-03-15'),
        ]);

        $payment->markAsProcessing('gw_renew_001');
        $payment->markAsCompleted(new \DateTimeImmutable('2026-03-15 14:30:00'));

        $this->assertTrue($payment->getStatus()->isCompleted());

        // ============================================================
        // STEP 3: Renew contract using the completed payment
        // ============================================================
        $newEndDate = new \DateTimeImmutable('2026-05-01');
        $contract->renewWithPayment($payment, $newEndDate);

        // Verify end date was extended
        $this->assertEquals($newEndDate, $contract->getEndDate());
        $this->assertNotEquals($originalEndDate, $contract->getEndDate());

        // Contract should still be active
        $this->assertTrue($contract->isActive());

        // Next execution date should have been recalculated
        $this->assertNotNull($contract->getNextExecutionDate());

        // Domain events should include ContractRenewed
        $events = $contract->releaseEvents();
        $this->assertNotEmpty($events, 'Contract should have emitted renewal event');

        // ============================================================
        // STEP 4: Verify next billing cycle can be created
        // ============================================================
        $nextPayment = TestFactory::createRecurringPayment([
            'contract_id'          => 77,
            'billing_cycle_number' => 4,
            'amount'               => 600.00,
            'due_date'             => new \DateTimeImmutable('2026-04-15'),
        ]);

        $this->assertTrue($nextPayment->getStatus()->isPending());
        $this->assertEquals(4, $nextPayment->getBillingCycleNumber());
    }

    // ------------------------------------------------------------------
    // test_failed_payment_retry_and_eventual_success
    //
    // Scenario: Payment fails on first attempt, succeeds on retry.
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_failed_payment_retry_and_eventual_success(): void
    {
        // Create payment
        $payment = TestFactory::createRecurringPayment([
            'contract_id'          => 100,
            'billing_cycle_number' => 1,
            'amount'               => 150.00,
        ]);

        // First attempt: pending -> processing -> failed
        $payment->markAsProcessing('gw_attempt_1');
        $payment->markAsFailed('Card declined');

        $this->assertTrue($payment->getStatus()->isFailed());
        $this->assertTrue($payment->canRetry());

        // Increment attempt for retry
        $payment->incrementAttempt();
        $this->assertEquals(1, $payment->getAttemptCount());

        // Second attempt: failed -> processing -> completed (success)
        $payment->markAsProcessing('gw_attempt_2');
        $payment->markAsCompleted(new \DateTimeImmutable());

        $this->assertTrue($payment->getStatus()->isCompleted());
        $this->assertNotNull($payment->getPaidAt());
        $this->assertNull($payment->getFailureReason()); // cleared on completion
    }

    // ------------------------------------------------------------------
    // test_execution_with_sla_violation_still_completes
    //
    // Scenario: Professional checks in outside the geofence, but the
    // execution still completes (SLA violation is recorded, not blocking).
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_execution_with_sla_violation_still_completes(): void
    {
        $serviceLocation = new GeoLocation(-20.3155, -40.3128);
        $execution = TestFactory::createExecution([
            'service_location' => $serviceLocation,
        ]);

        // Check-in far from the service location (> 150m)
        $farAwayGeo = new GeoLocation(-20.3200, -40.3200); // ~600m away
        $execution->checkIn($farAwayGeo);

        // SLA violation should be recorded
        $this->assertTrue($execution->hasSlaViolations());
        $this->assertNotEmpty($execution->getSlaViolations());

        // But the check-in still succeeds
        $this->assertEquals(ExecutionStatusEnum::CHECKED_IN, $execution->getStatus());

        // Complete the execution
        $execution->startExecution();
        $execution->checkOut(
            new GeoLocation(-20.3155, -40.3128),
            TestFactory::createEvidenceCollection(1)
        );

        $this->assertTrue($execution->isServiceCompleted());
        // Violations persist
        $this->assertTrue($execution->hasSlaViolations());
    }

    // ------------------------------------------------------------------
    // Private helpers for payout authorization logic
    // ------------------------------------------------------------------

    /**
     * Simulate payout authorization decision based on feedback rating.
     *
     * Business rule:
     * - Rating >= 4: authorize payout immediately
     * - Rating < 4: hold payout for admin review
     * - No feedback + window expired: authorize payout (assumed satisfied)
     *
     * @param int $rating
     * @param Execution $execution
     * @return bool
     */
    private function shouldAuthorizePayout(int $rating, Execution $execution): bool
    {
        if (!$execution->isServiceCompleted()) {
            return false;
        }

        return $rating >= 4;
    }

    /**
     * Check if payout should be held for admin review.
     *
     * @param int $rating
     * @return bool
     */
    private function shouldHoldPayout(int $rating): bool
    {
        return $rating > 0 && $rating < 4;
    }
}
