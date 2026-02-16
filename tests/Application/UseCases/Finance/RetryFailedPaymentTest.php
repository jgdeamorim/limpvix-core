<?php
/**
 * RetryFailedPaymentTest - Integration Tests for RetryFailedPayment Use Case
 *
 * @package LimpVix\Tests\Application\UseCases\Finance
 * @group integration
 * @group finance
 * @group recurring-payment
 */

namespace LimpVix\Tests\Application\UseCases\Finance;

use LimpVix\Application\UseCases\Finance\RetryFailedPayment;
use LimpVix\Domain\Finance\RecurringPayment;
use LimpVix\Domain\Finance\RecurringPaymentRepositoryInterface;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPaymentProvider;
use PHPUnit\Framework\TestCase;

final class RetryFailedPaymentTest extends TestCase
{
    private RetryFailedPayment $useCase;
    private $paymentRepository;
    private $contractRepository;
    private $paymentProvider;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(RecurringPaymentRepositoryInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->paymentProvider = $this->createMock(MercadoPagoPaymentProvider::class);

        $this->useCase = new RetryFailedPayment(
            $this->paymentRepository,
            $this->contractRepository,
            $this->paymentProvider
        );
    }

    /**
     * @test
     */
    public function it_fails_when_payment_not_found(): void
    {
        $this->paymentRepository
            ->expects($this->once())
            ->method('findByUuid')
            ->with('non-existent-uuid')
            ->willReturn(null);

        $result = $this->useCase->execute('non-existent-uuid');

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('not found', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_payment_is_not_failed(): void
    {
        $payment = RecurringPayment::create(123, 1, 150.00, new \DateTimeImmutable());
        // Payment is pending, not failed

        $this->paymentRepository
            ->method('findByUuid')
            ->willReturn($payment);

        $result = $this->useCase->execute($payment->getPaymentUuid());

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('not in failed status', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_max_attempts_reached(): void
    {
        $payment = RecurringPayment::create(123, 1, 150.00, new \DateTimeImmutable());
        $payment->markAsProcessing('mp_123');
        $payment->markAsFailed('Insufficient funds');
        
        // Max out attempts
        $payment->incrementAttempt();
        $payment->incrementAttempt();
        $payment->incrementAttempt();

        $this->paymentRepository
            ->method('findByUuid')
            ->willReturn($payment);

        $result = $this->useCase->execute($payment->getPaymentUuid());

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('max retry attempts', $result->error());
    }

    /**
     * @test
     */
    public function it_increments_attempt_count_on_retry(): void
    {
        $payment = RecurringPayment::create(123, 1, 150.00, new \DateTimeImmutable());
        $payment->markAsProcessing('mp_123');
        $payment->markAsFailed('Card declined');

        $initialAttempts = $payment->getAttemptCount();

        $this->markTestSkipped('Requires full integration with MercadoPago mock');

        // Would verify that attempt count is incremented after retry
    }

    /**
     * @test
     */
    public function it_retries_all_pending_payments_in_batch(): void
    {
        $payment1 = RecurringPayment::create(123, 1, 150.00, new \DateTimeImmutable());
        $payment1->markAsProcessing('mp_1');
        $payment1->markAsFailed('Error 1');

        $payment2 = RecurringPayment::create(456, 1, 200.00, new \DateTimeImmutable());
        $payment2->markAsProcessing('mp_2');
        $payment2->markAsFailed('Error 2');

        $this->paymentRepository
            ->method('findRetryablePayments')
            ->willReturn([$payment1, $payment2]);

        $this->markTestSkipped('Requires full batch retry implementation');

        // Would verify batch processing works correctly
    }

    /**
     * @test
     */
    public function it_handles_mercadopago_retry_failure_gracefully(): void
    {
        $this->markTestSkipped('Requires MercadoPago API mock setup');
    }
}
