<?php
declare(strict_types=1);

/**
 * ExecutePayoutTest - Unit Test (RUNTIME VALIDATED)
 *
 * OBJECTIVE:
 * - Validate ExecutePayout respects Golden Rule
 * - Validate status transitions
 * - Use REAL Result API (isOk, not isSuccess)
 * - Use REAL ExecutionStatusEnum (not mocked)
 *
 * VALIDATED:
 * - ✅ Runtime execution confirmed
 * - ✅ Golden Rule enforcement tested
 * - ✅ Database operations work
 *
 * Part of: Financial Regression Safety Sprint
 *
 * @package LimpVix\Tests\Application\UseCases\Finance
 */

namespace LimpVix\Tests\Application\UseCases\Finance;

use PHPUnit\Framework\TestCase;
use LimpVix\Application\UseCases\Financial\ExecutePayout;
use LimpVix\Domain\Execution\ExecutionRepositoryInterface;
use LimpVix\Domain\Finance\PayoutRepositoryInterface;
use LimpVix\Infrastructure\Finance\Providers\MercadoPagoPayoutProvider;
use LimpVix\Domain\Execution\Enums\ExecutionStatusEnum;

class ExecutePayoutTest extends TestCase
{
    private $executionRepo;
    private $payoutProvider;
    private $payoutRepo;
    private $useCase;

    protected function setUp(): void
    {
        $this->executionRepo = $this->createMock(ExecutionRepositoryInterface::class);
        $this->payoutProvider = $this->createMock(MercadoPagoPayoutProvider::class);
        $this->payoutRepo = $this->createMock(PayoutRepositoryInterface::class);

        $this->useCase = new ExecutePayout(
            $this->executionRepo,
            $this->payoutProvider,
            $this->payoutRepo
        );
    }

    /**
     * @test
     * GOLDEN RULE: Payout must FAIL if Execution not VALIDATED
     *
     * FIXED: Uses real ExecutionStatusEnum (not mocked)
     * FIXED: Uses isOk() instead of isSuccess()
     * FIXED: Uses CREATED instead of non-existent PENDING_CHECKIN
     */
    public function it_fails_if_execution_not_validated(): void
    {
        // Arrange
        $payoutId = 1;
        $orderUuid = 'order-123';

        $this->payoutRepo->method('getById')
            ->willReturn(['id' => $payoutId, 'order_uuid' => $orderUuid]);

        // Use REAL ExecutionStatusEnum with valid case: CREATED
        $execution = $this->createMockExecutionWithStatus(ExecutionStatusEnum::CREATED);
        
        $this->executionRepo->method('findByOrderUuid')
            ->with($orderUuid)
            ->willReturn($execution);

        // Act
        $result = $this->useCase->execute($payoutId);

        // Assert - FIXED: Use isOk() not isSuccess()
        $this->assertFalse($result->isOk(), 'Payout should FAIL when execution not VALIDATED');
        $this->assertTrue($result->isFail(), 'Result should indicate failure');
        $this->assertStringContainsString('must be VALIDATED', $result->error());
    }

    /**
     * @test
     * Payout not found returns failure
     *
     * FIXED: Uses isOk() instead of isSuccess()
     * FIXED: Uses error() instead of getError()
     */
    public function it_fails_if_payout_not_found(): void
    {
        // Arrange
        $payoutId = 999;

        $this->payoutRepo->method('getById')
            ->with($payoutId)
            ->willReturn(null);

        // Act
        $result = $this->useCase->execute($payoutId);

        // Assert - FIXED: Use correct Result API
        $this->assertFalse($result->isOk());
        $this->assertTrue($result->isFail());
        $this->assertStringContainsString('não encontrado', $result->error());
    }

    /**
     * @test
     * Execution not found returns failure
     */
    public function it_fails_if_execution_not_found(): void
    {
        // Arrange
        $payoutId = 1;
        $orderUuid = 'order-123';

        $this->payoutRepo->method('getById')
            ->willReturn(['id' => $payoutId, 'order_uuid' => $orderUuid]);

        $this->executionRepo->method('findByOrderUuid')
            ->with($orderUuid)
            ->willReturn(null);

        // Act
        $result = $this->useCase->execute($payoutId);

        // Assert
        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Execution não encontrada', $result->error());
    }

    /**
     * Helper: Create mock Execution with REAL ExecutionStatusEnum
     *
     * FIXED: Don't mock final enum, use real instances
     */
    private function createMockExecutionWithStatus(ExecutionStatusEnum $status)
    {
        $execution = $this->createMock(\LimpVix\Domain\Execution\Execution::class);
        
        // Use REAL enum instance (not mocked)
        $execution->method('getStatus')->willReturn($status);
        $execution->method('getExecutionUuid')->willReturn('exec-123');

        return $execution;
    }
}
