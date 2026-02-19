<?php
/**
 * RetryFailedPaymentTest - Unit Tests for RetryFailedPayment Use Case
 *
 * Tests the retry logic for failed recurring payments including:
 * - Attempt count incrementing
 * - Status transitions on success/failure
 * - Max retry limit enforcement
 * - Provider interaction
 * - Edge cases (not found, already completed, provider errors)
 *
 * @package LimpVix\Tests\Application\UseCases\Finance
 * @group unit
 * @group finance
 * @group recurring-payment
 */

namespace LimpVix\Tests\Application\UseCases\Finance;

use LimpVix\Application\UseCases\Finance\RetryFailedPayment;
use LimpVix\Domain\Finance\RecurringPayment;
use LimpVix\Domain\Finance\RecurringPaymentRepositoryInterface;
use LimpVix\Domain\Finance\PaymentProviderInterface;
use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\ValueObjects\ContractStatus;
use LimpVix\Tests\Helpers\TestFactory;
use PHPUnit\Framework\TestCase;

final class RetryFailedPaymentTest extends TestCase
{
    private RetryFailedPayment $useCase;
    private RecurringPaymentRepositoryInterface $paymentRepository;
    private ContractRepositoryInterface $contractRepository;
    private PaymentProviderInterface $paymentProvider;

    protected function setUp(): void
    {
        $this->paymentRepository = $this->createMock(RecurringPaymentRepositoryInterface::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->paymentProvider = $this->createMock(PaymentProviderInterface::class);

        $this->useCase = new RetryFailedPayment(
            $this->paymentRepository,
            $this->contractRepository,
            $this->paymentProvider
        );
    }

    // ------------------------------------------------------------------
    // test_retry_increments_attempt_count
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_increments_attempt_count(): void
    {
        // Arrange: a failed payment with 0 attempts
        $payment = TestFactory::createFailedPayment(['contract_id' => 100], 'Card declined', 0);
        $initialAttempts = $payment->getAttemptCount();

        $contract = $this->createMockContract(100);

        $this->paymentRepository
            ->method('findByUuid')
            ->willReturn($payment);

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        // Provider returns a successful response
        $this->paymentProvider
            ->method('createPaymentCharge')
            ->willReturn(['id' => 'gw_retry_1', 'status' => 'pending']);

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert: attempt count was incremented by 1
        $this->assertTrue($result->isOk());
        $this->assertEquals($initialAttempts + 1, $payment->getAttemptCount());
    }

    // ------------------------------------------------------------------
    // test_retry_success_marks_as_processing
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_success_marks_as_processing(): void
    {
        // Arrange
        $payment = TestFactory::createFailedPayment(['contract_id' => 100]);
        $contract = $this->createMockContract(100);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);
        $this->contractRepository->method('findById')->willReturn($contract);

        $this->paymentProvider
            ->method('createPaymentCharge')
            ->willReturn(['id' => 'gw_success_1', 'status' => 'approved']);

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert
        $this->assertTrue($result->isOk());
        $this->assertTrue($payment->getStatus()->isProcessing());
        $this->assertEquals('gw_success_1', $payment->getGatewayTransactionId());

        $data = $result->value();
        $this->assertEquals('processing', $data['status']);
    }

    // ------------------------------------------------------------------
    // test_retry_exceeds_max_attempts_fails
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_exceeds_max_attempts_fails(): void
    {
        // Arrange: a failed payment that already has 3 attempts (max)
        $payment = TestFactory::createFailedPayment(['contract_id' => 100], 'Card declined', 3);

        // canRetry() should be false since attempt_count == 3
        $this->assertFalse($payment->canRetry());

        $this->paymentRepository->method('findByUuid')->willReturn($payment);

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert
        $this->assertTrue($result->isFail());
        $this->assertStringContainsString('max retry attempts', $result->error());
    }

    // ------------------------------------------------------------------
    // test_retry_nonexistent_payment_fails
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_nonexistent_payment_fails(): void
    {
        $this->paymentRepository
            ->expects($this->once())
            ->method('findByUuid')
            ->with('nonexistent-uuid')
            ->willReturn(null);

        $result = $this->useCase->execute('nonexistent-uuid');

        $this->assertTrue($result->isFail());
        $this->assertStringContainsString('not found', $result->error());
    }

    // ------------------------------------------------------------------
    // test_retry_already_completed_payment_fails
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_already_completed_payment_fails(): void
    {
        // Arrange: a completed payment (not in failed status)
        $payment = TestFactory::createCompletedPayment(['contract_id' => 100]);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert: should fail because payment is not in 'failed' status
        $this->assertTrue($result->isFail());
        $this->assertStringContainsString('not in failed status', $result->error());
    }

    // ------------------------------------------------------------------
    // test_retry_saves_updated_payment
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_saves_updated_payment(): void
    {
        // Arrange
        $payment = TestFactory::createFailedPayment(['contract_id' => 100]);
        $contract = $this->createMockContract(100);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);
        $this->contractRepository->method('findById')->willReturn($contract);

        $this->paymentProvider
            ->method('createPaymentCharge')
            ->willReturn(['id' => 'gw_save_1', 'status' => 'pending']);

        // Expect save to be called exactly once with the payment object
        $this->paymentRepository
            ->expects($this->once())
            ->method('save')
            ->with($this->identicalTo($payment));

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert
        $this->assertTrue($result->isOk());
    }

    // ------------------------------------------------------------------
    // test_retry_calls_payment_provider
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_calls_payment_provider(): void
    {
        // Arrange
        $payment = TestFactory::createFailedPayment(['contract_id' => 100]);
        $contract = $this->createMockContract(100);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Expect the payment provider to be called once with the payment
        $this->paymentProvider
            ->expects($this->once())
            ->method('createPaymentCharge')
            ->with(
                $this->identicalTo($payment),
                $this->isType('array')
            )
            ->willReturn(['id' => 'gw_provider_1', 'status' => 'in_process']);

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert
        $this->assertTrue($result->isOk());
    }

    // ------------------------------------------------------------------
    // test_retry_with_provider_error
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_with_provider_error(): void
    {
        // Arrange
        $payment = TestFactory::createFailedPayment(['contract_id' => 100]);
        $contract = $this->createMockContract(100);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Provider throws exception
        $this->paymentProvider
            ->method('createPaymentCharge')
            ->willThrowException(new \RuntimeException('Gateway timeout'));

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert: the use case catches exceptions and returns a fail result
        $this->assertTrue($result->isFail());
        $this->assertStringContainsString('Gateway timeout', $result->error());
    }

    // ------------------------------------------------------------------
    // test_retry_with_rejected_response_marks_as_failed
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_with_rejected_response_marks_as_failed(): void
    {
        // Arrange: failed payment with 0 attempts
        $payment = TestFactory::createFailedPayment(['contract_id' => 100], 'Initial failure', 0);
        $contract = $this->createMockContract(100);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);
        $this->contractRepository->method('findById')->willReturn($contract);

        // Provider returns a rejection
        $this->paymentProvider
            ->method('createPaymentCharge')
            ->willReturn(['id' => 'gw_rej_1', 'status' => 'rejected', 'status_detail' => 'cc_rejected_insufficient_amount']);

        $this->paymentProvider
            ->method('getFailureReason')
            ->willReturn('Saldo insuficiente');

        // save may or may not be called depending on how far the retry gets
        $this->paymentRepository
            ->method('save');

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert: retry failed -- either with rejection reason or state transition error
        $this->assertTrue($result->isFail());
        $this->assertTrue($payment->getStatus()->isFailed());
    }

    // ------------------------------------------------------------------
    // test_retry_pending_payment_is_rejected
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_pending_payment_is_rejected(): void
    {
        // Arrange: a pending payment (not failed)
        $payment = TestFactory::createRecurringPayment(['contract_id' => 100]);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert
        $this->assertTrue($result->isFail());
        $this->assertStringContainsString('not in failed status', $result->error());
    }

    // ------------------------------------------------------------------
    // test_retry_result_contains_expected_data_keys
    // ------------------------------------------------------------------

    /**
     * @test
     */
    public function test_retry_result_contains_expected_data_keys(): void
    {
        // Arrange
        $payment = TestFactory::createFailedPayment(['contract_id' => 100]);
        $contract = $this->createMockContract(100);

        $this->paymentRepository->method('findByUuid')->willReturn($payment);
        $this->contractRepository->method('findById')->willReturn($contract);

        $this->paymentProvider
            ->method('createPaymentCharge')
            ->willReturn(['id' => 'gw_data_1', 'status' => 'pending']);

        // Act
        $result = $this->useCase->execute($payment->getPaymentUuid());

        // Assert
        $this->assertTrue($result->isOk());
        $data = $result->value();
        $this->assertArrayHasKey('payment_uuid', $data);
        $this->assertArrayHasKey('gateway_transaction_id', $data);
        $this->assertArrayHasKey('status', $data);
        $this->assertArrayHasKey('attempt_count', $data);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Create a mock Contract with expected methods for the RetryFailedPayment use case.
     *
     * @param int $contractId
     * @return Contract
     */
    private function createMockContract(int $contractId): Contract
    {
        $contract = $this->createMock(Contract::class);
        $contract->method('getId')->willReturn(ContractId::fromInt($contractId));
        $contract->method('getClientUserId')->willReturn(1);

        return $contract;
    }

    protected function tearDown(): void
    {
        unset(
            $this->useCase,
            $this->paymentRepository,
            $this->contractRepository,
            $this->paymentProvider
        );
    }
}
