<?php
/**
 * RecurringPaymentCronAdapterTest - Integration Tests for Cron Job
 *
 * @package LimpVix\Tests\Integration\Cron
 * @group integration
 * @group cron
 * @group recurring-payment
 */

namespace LimpVix\Tests\Integration\Cron;

use LimpVix\Infrastructure\Cron\RecurringPaymentCronAdapter;
use LimpVix\Application\UseCases\Finance\ChargeRecurringPayment;
use LimpVix\Application\UseCases\Finance\RetryFailedPayment;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Contract;
use LimpVix\Common\Result;
use PHPUnit\Framework\TestCase;

final class RecurringPaymentCronAdapterTest extends TestCase
{
    private RecurringPaymentCronAdapter $adapter;
    private $chargeUseCase;
    private $retryUseCase;
    private $contractRepository;

    protected function setUp(): void
    {
        $this->chargeUseCase = $this->createMock(ChargeRecurringPayment::class);
        $this->retryUseCase = $this->createMock(RetryFailedPayment::class);
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);

        $this->adapter = new RecurringPaymentCronAdapter(
            $this->chargeUseCase,
            $this->retryUseCase,
            $this->contractRepository
        );
    }

    /**
     * @test
     */
    public function it_executes_successfully_with_no_contracts(): void
    {
        $this->contractRepository
            ->method('findActiveContracts')
            
            ->willReturn([]);

        $stats = $this->adapter->execute();

        $this->assertEquals(0, $stats['contracts_found']);
        $this->assertEquals(0, $stats['charges_created']);
        $this->assertArrayHasKey('execution_time', $stats);
    }

    /**
     * @test
     */
    public function it_finds_and_charges_expiring_contracts(): void
    {
        $contract1 = $this->createMock(Contract::class);
        $contract1->method('isAutoRenew')->willReturn(true);
        $contract1->method('getEndDate')->willReturn(new \DateTimeImmutable('+2 days'));
        $contract1->method('getId')->willReturn($this->createMockContractId(123));

        $contract2 = $this->createMock(Contract::class);
        $contract2->method('isAutoRenew')->willReturn(true);
        $contract2->method('getEndDate')->willReturn(new \DateTimeImmutable('+1 day'));
        $contract2->method('getId')->willReturn($this->createMockContractId(456));

        $this->contractRepository
            ->method('findActiveContracts')
            ->willReturn([$contract1, $contract2]);

        $this->chargeUseCase
            ->expects($this->exactly(2))
            ->method('execute')
            ->willReturn(Result::ok(['payment_uuid' => 'test-uuid']));

        $stats = $this->adapter->execute();

        $this->assertEquals(2, $stats['contracts_found']);
        $this->assertEquals(2, $stats['charges_created']);
        $this->assertEquals(0, $stats['charges_failed']);
    }

    /**
     * @test
     */
    public function it_handles_charge_failures_gracefully(): void
    {
        $contract = $this->createMock(Contract::class);
        $contract->method('isAutoRenew')->willReturn(true);
        $contract->method('getEndDate')->willReturn(new \DateTimeImmutable('+1 day'));
        $contract->method('getId')->willReturn($this->createMockContractId(123));

        $this->contractRepository
            ->method('findActiveContracts')
            ->willReturn([$contract]);

        $this->chargeUseCase
            ->method('execute')
            ->willReturn(Result::fail('MercadoPago API error'));

        $stats = $this->adapter->execute();

        $this->assertEquals(1, $stats['contracts_found']);
        $this->assertEquals(0, $stats['charges_created']);
        $this->assertEquals(1, $stats['charges_failed']);
    }

    /**
     * @test
     */
    public function it_skips_contracts_without_auto_renew(): void
    {
        $contract1 = $this->createMock(Contract::class);
        $contract1->method('isAutoRenew')->willReturn(false);
        $contract1->method('getEndDate')->willReturn(new \DateTimeImmutable('+1 day'));

        $contract2 = $this->createMock(Contract::class);
        $contract2->method('isAutoRenew')->willReturn(true);
        $contract2->method('getEndDate')->willReturn(new \DateTimeImmutable('+1 day'));
        $contract2->method('getId')->willReturn($this->createMockContractId(456));

        $this->contractRepository
            ->method('findActiveContracts')
            ->willReturn([$contract1, $contract2]);

        $this->chargeUseCase
            ->expects($this->once())
            ->method('execute')
            ->willReturn(Result::ok(['payment_uuid' => 'test-uuid']));

        $stats = $this->adapter->execute();

        $this->assertEquals(1, $stats['contracts_found']);
        $this->assertEquals(1, $stats['charges_created']);
    }

    /**
     * @test
     */
    public function it_tracks_execution_time(): void
    {
        $this->contractRepository
            ->method('findActiveContracts')
            ->willReturn([]);

        $stats = $this->adapter->execute();

        $this->assertArrayHasKey('execution_time', $stats);
        $this->assertIsFloat($stats['execution_time']);
        $this->assertGreaterThanOrEqual(0, $stats['execution_time']);
    }

    private function createMockContractId($value)
    {
        $id = $this->createMock(\LimpVix\Domain\Contract\ContractId::class);
        $id->method('toInt')->willReturn($value);
        return $id;
    }
}
