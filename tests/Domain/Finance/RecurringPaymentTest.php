<?php
/**
 * RecurringPaymentTest - Unit Tests for RecurringPayment Aggregate
 *
 * Tests factory creation, state machine transitions, retry logic,
 * domain events, getters, and static helper methods.
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
    // ==================== Helpers ====================

    /**
     * Create a default recurring payment in pending status.
     */
    private function createPayment(
        int $contractId = 123,
        int $billingCycle = 1,
        float $amount = 150.00,
        ?string $dueDate = null
    ): RecurringPayment {
        return RecurringPayment::create(
            $contractId,
            $billingCycle,
            $amount,
            new \DateTimeImmutable($dueDate ?? '2026-03-15')
        );
    }

    /**
     * Create a payment that is already in processing state.
     */
    private function createProcessingPayment(): RecurringPayment
    {
        $payment = $this->createPayment();
        $payment->markAsProcessing('mp_123456');
        return $payment;
    }

    /**
     * Create a payment that is already in failed state.
     */
    private function createFailedPayment(): RecurringPayment
    {
        $payment = $this->createProcessingPayment();
        $payment->markAsFailed('Insufficient funds');
        return $payment;
    }

    // ==================== Factory: create() ====================

    /**
     * @test
     */
    public function test_create_recurring_payment_returns_pending_status(): void
    {
        $payment = $this->createPayment();

        $this->assertTrue($payment->getStatus()->isPending());
    }

    /**
     * @test
     */
    public function test_create_with_valid_data(): void
    {
        $contractId = 456;
        $billingCycle = 3;
        $amount = 275.50;
        $dueDate = new \DateTimeImmutable('2026-04-20');

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
        $this->assertNotNull($payment->getCreatedAt());
        $this->assertNotNull($payment->getUpdatedAt());
        $this->assertNull($payment->getExecutionScheduledDate());
        $this->assertNull($payment->getPixQrCode());
        $this->assertNull($payment->getPixQrImage());
        $this->assertEquals('efipay', $payment->getPaymentProvider());
    }

    /**
     * @test
     */
    public function test_create_with_execution_scheduled_date(): void
    {
        $scheduledDate = new \DateTimeImmutable('2026-04-01');
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable('2026-03-15'),
            $scheduledDate
        );

        $this->assertEquals($scheduledDate, $payment->getExecutionScheduledDate());
    }

    /**
     * @test
     */
    public function test_create_with_custom_payment_provider(): void
    {
        $payment = RecurringPayment::create(
            123,
            1,
            150.00,
            new \DateTimeImmutable('2026-03-15'),
            null,
            'mercadopago'
        );

        $this->assertEquals('mercadopago', $payment->getPaymentProvider());
    }

    /**
     * @test
     */
    public function test_create_with_invalid_amount_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        RecurringPayment::create(
            123,
            1,
            -50.00,
            new \DateTimeImmutable()
        );
    }

    /**
     * @test
     */
    public function test_create_with_zero_amount_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Amount must be positive');

        RecurringPayment::create(
            123,
            1,
            0.00,
            new \DateTimeImmutable()
        );
    }

    /**
     * @test
     */
    public function test_create_with_invalid_billing_cycle_throws(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Billing cycle number must be >= 1');

        RecurringPayment::create(
            123,
            0,
            150.00,
            new \DateTimeImmutable()
        );
    }

    // ==================== Transition: markAsProcessing ====================

    /**
     * @test
     */
    public function test_mark_as_processing_transitions_status(): void
    {
        $payment = $this->createPayment();

        $gatewayId = 'mp_123456789';
        $payment->markAsProcessing($gatewayId);

        $this->assertTrue($payment->getStatus()->isProcessing());
        $this->assertEquals($gatewayId, $payment->getGatewayTransactionId());
    }

    /**
     * @test
     */
    public function test_mark_as_processing_from_completed_throws(): void
    {
        $payment = $this->createProcessingPayment();
        $payment->markAsCompleted(new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transition from completed to processing');

        $payment->markAsProcessing('mp_new');
    }

    // ==================== Transition: markAsCompleted ====================

    /**
     * @test
     */
    public function test_mark_as_completed_transitions_status(): void
    {
        $payment = $this->createProcessingPayment();

        $paidAt = new \DateTimeImmutable('2026-03-15 10:30:00');
        $payment->markAsCompleted($paidAt);

        $this->assertTrue($payment->getStatus()->isCompleted());
    }

    /**
     * @test
     */
    public function test_mark_as_completed_records_paid_at(): void
    {
        $payment = $this->createProcessingPayment();

        $paidAt = new \DateTimeImmutable('2026-03-15 10:30:00');
        $payment->markAsCompleted($paidAt);

        $this->assertEquals($paidAt, $payment->getPaidAt());
        $this->assertNull($payment->getFailureReason());
    }

    /**
     * @test
     */
    public function test_mark_as_completed_clears_previous_failure_reason(): void
    {
        $payment = $this->createFailedPayment();

        // Retry: move back to processing, then complete
        $payment->markAsProcessing('mp_retry');
        $payment->markAsCompleted(new \DateTimeImmutable());

        $this->assertNull($payment->getFailureReason());
    }

    /**
     * @test
     */
    public function test_mark_as_completed_records_domain_event(): void
    {
        $payment = $this->createProcessingPayment();
        $payment->markAsCompleted(new \DateTimeImmutable());

        $events = $payment->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(RecurringPaymentCompleted::class, $events[0]);
    }

    /**
     * @test
     */
    public function test_mark_as_completed_from_pending_throws(): void
    {
        $payment = $this->createPayment();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transition from pending to completed');

        $payment->markAsCompleted(new \DateTimeImmutable());
    }

    // ==================== Transition: markAsFailed ====================

    /**
     * @test
     */
    public function test_mark_as_failed_records_reason(): void
    {
        $payment = $this->createProcessingPayment();

        $reason = 'Card declined';
        $payment->markAsFailed($reason);

        $this->assertTrue($payment->getStatus()->isFailed());
        $this->assertEquals($reason, $payment->getFailureReason());
        $this->assertNull($payment->getPaidAt());
    }

    /**
     * @test
     */
    public function test_mark_as_failed_records_domain_event(): void
    {
        $payment = $this->createProcessingPayment();
        $payment->markAsFailed('Insufficient funds');

        $events = $payment->releaseEvents();
        $this->assertCount(1, $events);
        $this->assertInstanceOf(RecurringPaymentFailed::class, $events[0]);
    }

    // ==================== Retry Logic ====================

    /**
     * @test
     */
    public function test_increment_attempt_increases_count(): void
    {
        $payment = $this->createPayment();

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
    public function test_increment_attempt_beyond_max_throws(): void
    {
        $payment = $this->createPayment();

        $payment->incrementAttempt();
        $payment->incrementAttempt();
        $payment->incrementAttempt();

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('max attempts (3) reached');

        $payment->incrementAttempt();
    }

    /**
     * @test
     */
    public function test_can_retry_returns_true_when_under_max(): void
    {
        $payment = $this->createFailedPayment();

        // Failed with 0 attempts
        $this->assertTrue($payment->canRetry());

        $payment->incrementAttempt();
        $this->assertTrue($payment->canRetry());

        $payment->incrementAttempt();
        $this->assertTrue($payment->canRetry());
    }

    /**
     * @test
     */
    public function test_can_retry_returns_false_at_max_attempts(): void
    {
        $payment = $this->createFailedPayment();

        $payment->incrementAttempt();
        $payment->incrementAttempt();
        $payment->incrementAttempt();

        // At max (3 attempts), cannot retry
        $this->assertFalse($payment->canRetry());
    }

    /**
     * @test
     */
    public function test_can_retry_returns_false_for_pending(): void
    {
        $payment = $this->createPayment();

        // Pending is not failed, so canRetry = false
        $this->assertFalse($payment->canRetry());
    }

    /**
     * @test
     */
    public function test_can_retry_returns_false_for_completed(): void
    {
        $payment = $this->createProcessingPayment();
        $payment->markAsCompleted(new \DateTimeImmutable());

        $this->assertFalse($payment->canRetry());
    }

    // ==================== Transition: cancel ====================

    /**
     * @test
     */
    public function test_cancel_payment_from_pending(): void
    {
        $payment = $this->createPayment();

        $reason = 'Contract cancelled by client';
        $payment->cancel($reason);

        $this->assertTrue($payment->getStatus()->isCancelled());
        $this->assertEquals($reason, $payment->getFailureReason());
    }

    /**
     * @test
     */
    public function test_cancel_payment_from_failed(): void
    {
        $payment = $this->createFailedPayment();

        $payment->cancel('Giving up after failure');

        $this->assertTrue($payment->getStatus()->isCancelled());
    }

    /**
     * @test
     */
    public function test_cancel_completed_payment_throws(): void
    {
        $payment = $this->createProcessingPayment();
        $payment->markAsCompleted(new \DateTimeImmutable());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Cannot transition from completed to cancelled');

        $payment->cancel('Should not work');
    }

    // ==================== Getters ====================

    /**
     * @test
     */
    public function test_get_payment_uuid_is_not_empty(): void
    {
        $payment = $this->createPayment();

        $this->assertNotEmpty($payment->getPaymentUuid());
        $this->assertIsString($payment->getPaymentUuid());
    }

    /**
     * @test
     */
    public function test_get_payment_uuid_is_unique(): void
    {
        $payment1 = $this->createPayment();
        $payment2 = $this->createPayment();

        $this->assertNotEquals(
            $payment1->getPaymentUuid(),
            $payment2->getPaymentUuid()
        );
    }

    /**
     * @test
     */
    public function test_get_contract_id_returns_correct_value(): void
    {
        $payment = $this->createPayment(contractId: 999);

        $this->assertEquals(999, $payment->getContractId());
    }

    /**
     * @test
     */
    public function test_get_billing_cycle_number_returns_correct_value(): void
    {
        $payment = $this->createPayment(billingCycle: 7);

        $this->assertEquals(7, $payment->getBillingCycleNumber());
    }

    // ==================== calculateExecutionValue ====================

    /**
     * @test
     */
    public function test_calculate_execution_value_weekly(): void
    {
        $monthlyValue = 600.00;

        $result = RecurringPayment::calculateExecutionValue($monthlyValue, 'weekly');

        // 600 / 4.33 = 138.57
        $this->assertEquals(138.57, $result);
    }

    /**
     * @test
     */
    public function test_calculate_execution_value_biweekly(): void
    {
        $monthlyValue = 600.00;

        $result = RecurringPayment::calculateExecutionValue($monthlyValue, 'biweekly');

        // 600 / 2.16 = 277.78
        $this->assertEquals(277.78, $result);
    }

    /**
     * @test
     */
    public function test_calculate_execution_value_monthly(): void
    {
        $monthlyValue = 600.00;

        $result = RecurringPayment::calculateExecutionValue($monthlyValue, 'monthly');

        $this->assertEquals(600.00, $result);
    }

    /**
     * @test
     */
    public function test_calculate_execution_value_unknown_type_defaults_to_monthly(): void
    {
        $monthlyValue = 450.00;

        $result = RecurringPayment::calculateExecutionValue($monthlyValue, 'avulso');

        // Default branch uses round($monthlyValue, 2)
        $this->assertEquals(450.00, $result);
    }

    // ==================== PIX QR Code ====================

    /**
     * @test
     */
    public function test_set_pix_qr_code(): void
    {
        $payment = $this->createPayment();

        $qrCode = '00020126580014br.gov.bcb.pix0136test-key';
        $payment->setPixQrCode($qrCode);

        $this->assertEquals($qrCode, $payment->getPixQrCode());
    }

    /**
     * @test
     */
    public function test_set_pix_qr_image(): void
    {
        $payment = $this->createPayment();

        $qrImage = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUg==';
        $payment->setPixQrImage($qrImage);

        $this->assertEquals($qrImage, $payment->getPixQrImage());
    }

    // ==================== Persistence ====================

    /**
     * @test
     */
    public function test_from_persistence_restores_all_fields(): void
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
    public function test_from_persistence_with_pix_fields(): void
    {
        $payment = RecurringPayment::fromPersistence(
            id: 50,
            paymentUuid: 'uuid-pix-001',
            contractId: 200,
            billingCycleNumber: 1,
            amount: 300.00,
            status: 'pending',
            dueDate: '2026-04-01',
            gatewayTransactionId: null,
            attemptCount: 0,
            paidAt: null,
            failureReason: null,
            createdAt: '2026-03-20 08:00:00',
            updatedAt: '2026-03-20 08:00:00',
            executionScheduledDate: '2026-04-01',
            pixQrCode: 'pix-qr-code-string',
            pixQrImage: 'pix-qr-image-base64',
            paymentProvider: 'efipay'
        );

        $this->assertEquals('pix-qr-code-string', $payment->getPixQrCode());
        $this->assertEquals('pix-qr-image-base64', $payment->getPixQrImage());
        $this->assertEquals('efipay', $payment->getPaymentProvider());
        $this->assertNotNull($payment->getExecutionScheduledDate());
    }

    // ==================== Domain Events ====================

    /**
     * @test
     */
    public function test_release_events_clears_events(): void
    {
        $payment = $this->createProcessingPayment();
        $payment->markAsCompleted(new \DateTimeImmutable());

        $events = $payment->releaseEvents();
        $this->assertCount(1, $events);

        $eventsAgain = $payment->releaseEvents();
        $this->assertEmpty($eventsAgain);
    }

    // ==================== Full Lifecycle ====================

    /**
     * @test
     */
    public function test_full_retry_lifecycle(): void
    {
        $payment = $this->createPayment();

        // Attempt 1: fail
        $payment->markAsProcessing('mp_attempt_1');
        $payment->markAsFailed('Network error');
        $payment->incrementAttempt();
        $this->assertTrue($payment->canRetry());

        // Attempt 2: fail
        $payment->markAsProcessing('mp_attempt_2');
        $payment->markAsFailed('Timeout');
        $payment->incrementAttempt();
        $this->assertTrue($payment->canRetry());

        // Attempt 3: succeed
        $payment->markAsProcessing('mp_attempt_3');
        $payment->incrementAttempt();
        $payment->markAsCompleted(new \DateTimeImmutable());

        $this->assertTrue($payment->getStatus()->isCompleted());
        $this->assertEquals(3, $payment->getAttemptCount());
        $this->assertNull($payment->getFailureReason());
        $this->assertNotNull($payment->getPaidAt());
    }
}
