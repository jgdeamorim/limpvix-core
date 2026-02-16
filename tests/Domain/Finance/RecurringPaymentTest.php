<?php
/**
 * RecurringPaymentTest - Unit Tests for RecurringPayment Aggregate
 *
 * @package LimpVix\Tests\Domain\Finance
 * @group unit
 * @group finance
 * @group recurring-payment
 */

namespace LimpVix\Tests\Domain\Finance;

use LimpVix\Domain\Finance\RecurringPayment;
use LimpVix\Domain\Finance\ValueObjects\RecurringPaymentStatus;
use LimpVix\Domain\Finance\Events\RecurringPaymentCompleted;
use LimpVix\Domain\Finance\Events\RecurringPaymentFailed;
use PHPUnit\Framework\TestCase;

final class RecurringPaymentTest extends TestCase
{
    /**
     * @test
     */
    public function it_creates_recurring_payment_with_pending_status(): void
    {
        $contractId = 123;
        $billingCycle = 1;
        $amount = 150.00;
        $dueDate = new \DateTimeImmutable('2026-03-15');

        $payment = RecurringPayment::create(
            $contractId,
            $billingCycle,
            $amount,
            $dueDate
        );

        $this->assertNull($payment->getId());
        $this->assertNotEmpty($payment->getPaymentUuid());
        $this->assertEquals($contractId, $payment->getContractId());
        $this->assertEquals($billingCycle, $payment->getBillingCycleNumber());
        $this->assertEquals($amount, $payment->getAmount());
        $this->assertTrue($payment->getStatus()->isPending());
        $this->assertEquals($dueDate, $payment->getDueDate());
        $this->assertNull($payment->getGatewayTransactionId());
        $this->assertEquals(0, $payment->getAttemptCount());
        $this->assertNull($payment->getPaidAt());
        $this->assertNull($payment->getFailureReason());
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        RecurringPayment::create(
            123,
            1,
            -50.00, // Invalid: negative amount
            new \DateTimeImmutable()
        );
    }

    /**
     * @test
     */
    public function it_throws_exception_for_zero_amount(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        RecurringPayment::create(
            123,
            1,
            0.00, // Invalid: zero amount
            new \DateTimeImmutable()
        );
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_billing_cycle(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Billing cycle number must be >= 1');

        RecurringPayment::create(
            123,
            0, // Invalid: billing cycle must be >= 1
            150.00,
            new \DateTimeImmutable()
        );
    }

    /**
     * @test
     */
    public function it_transitions_from_pending_to_processing(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        $gatewayId = 'mp_123456789';
        $payment->markAsProcessing($gatewayId);

        $this->assertTrue($payment->getStatus()->isProcessing());
        $this->assertEquals($gatewayId, $payment->getGatewayTransactionId());
    }

    /**
     * @test
     */
    public function it_transitions_from_processing_to_completed(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        $payment->markAsProcessing('mp_123');

        $paidAt = new \DateTimeImmutable('2026-03-15 10:30:00');
        $payment->markAsCompleted($paidAt);

        $this->assertTrue($payment->getStatus()->isCompleted());
        $this->assertEquals($paidAt, $payment->getPaidAt());
        $this->assertNull($payment->getFailureReason());

        // Check domain event was recorded
        $events = $payment->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(RecurringPaymentCompleted::class, $events[0]);
    }

    /**
     * @test
     */
    public function it_transitions_from_processing_to_failed(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        $payment->markAsProcessing('mp_123');

        $failureReason = 'Insufficient funds';
        $payment->markAsFailed($failureReason);

        $this->assertTrue($payment->getStatus()->isFailed());
        $this->assertEquals($failureReason, $payment->getFailureReason());
        $this->assertNull($payment->getPaidAt());

        // Check domain event was recorded
        $events = $payment->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(RecurringPaymentFailed::class, $events[0]);
    }

    /**
     * @test
     */
    public function it_throws_exception_for_invalid_status_transition(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        // Try to mark as completed without going through processing
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transition from pending to completed');

        $payment->markAsCompleted(new \DateTimeImmutable());
    }

    /**
     * @test
     */
    public function it_increments_attempt_count(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        $this->assertEquals(0, $payment->getAttemptCount());

        $payment->incrementAttempt();
        $this->assertEquals(1, $payment->getAttemptCount());

        $payment->incrementAttempt();
        $this->assertEquals(2, $payment->getAttemptCount());

        $payment->incrementAttempt();
        $this->assertEquals(3, $payment->getAttemptCount());
    }

    /**
     * @test
     */
    public function it_throws_exception_when_max_attempts_exceeded(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        // Increment 3 times (max allowed)
        $payment->incrementAttempt();
        $payment->incrementAttempt();
        $payment->incrementAttempt();

        // 4th attempt should throw
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('max attempts (3) reached');

        $payment->incrementAttempt();
    }

    /**
     * @test
     */
    public function it_checks_if_payment_can_retry(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        // Pending payment cannot retry (not failed yet)
        $this->assertFalse($payment->canRetry());

        // Mark as processing then failed
        $payment->markAsProcessing('mp_123');
        $payment->markAsFailed('Insufficient funds');

        // Failed payment with 0 attempts can retry
        $this->assertTrue($payment->canRetry());

        // Increment attempts
        $payment->incrementAttempt();
        $this->assertTrue($payment->canRetry());

        $payment->incrementAttempt();
        $this->assertTrue($payment->canRetry());

        $payment->incrementAttempt();
        // Max attempts reached, cannot retry
        $this->assertFalse($payment->canRetry());
    }

    /**
     * @test
     */
    public function it_cancels_payment(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        $reason = 'Contract cancelled by client';
        $payment->cancel($reason);

        $this->assertTrue($payment->getStatus()->isCancelled());
        $this->assertEquals($reason, $payment->getFailureReason());
    }

    /**
     * @test
     */
    public function it_reconstructs_from_persistence(): void
    {
        $payment = RecurringPayment::fromPersistence(
            id: 42,
            paymentUuid: 'uuid-123-456',
            contractId: 123,
            billingCycleNumber: 2,
            amount: 150.00,
            status: 'completed',
            dueDate: '2026-03-15',
            gatewayTransactionId: 'mp_987654321',
            attemptCount: 1,
            paidAt: '2026-03-15 10:30:00',
            failureReason: null,
            createdAt: '2026-03-01 00:00:00',
            updatedAt: '2026-03-15 10:30:00'
        );

        $this->assertEquals(42, $payment->getId());
        $this->assertEquals('uuid-123-456', $payment->getPaymentUuid());
        $this->assertEquals(123, $payment->getContractId());
        $this->assertEquals(2, $payment->getBillingCycleNumber());
        $this->assertEquals(150.00, $payment->getAmount());
        $this->assertTrue($payment->getStatus()->isCompleted());
        $this->assertEquals('mp_987654321', $payment->getGatewayTransactionId());
        $this->assertEquals(1, $payment->getAttemptCount());
        $this->assertNotNull($payment->getPaidAt());
    }

    /**
     * @test
     */
    public function it_releases_domain_events_only_once(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable()
        );

        $payment->markAsProcessing('mp_123');
        $payment->markAsCompleted(new \DateTimeImmutable());

        // First release
        $events = $payment->releaseEvents();
        $this->assertCount(1, $events);

        // Second release should return empty array
        $eventsAgain = $payment->releaseEvents();
        $this->assertEmpty($eventsAgain);
    }
}
