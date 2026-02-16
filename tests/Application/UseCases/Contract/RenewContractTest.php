<?php
/**
 * RenewContractTest - INTEGRATION TESTS for RenewContract
 *
 * NOTE: These tests are SIMPLIFIED to avoid complex Contract mocking.
 * They validate the Use Case business logic only.
 *
 * @package LimpVix\Tests\Application\UseCases\Contract
 * @group integration
 * @group contract
 * @group gap-6
 */

namespace LimpVix\Tests\Application\UseCases\Contract;

use LimpVix\Application\UseCases\Contract\RenewContract;
use LimpVix\Domain\Contract\ContractRepositoryInterface;
use LimpVix\Domain\Contract\Contract;
use LimpVix\Domain\Contract\ContractId;
use LimpVix\Domain\Contract\ValueObjects\ContractStatus;
use PHPUnit\Framework\TestCase;

final class RenewContractTest extends TestCase
{
    private RenewContract $useCase;
    private $contractRepository;

    protected function setUp(): void
    {
        $this->contractRepository = $this->createMock(ContractRepositoryInterface::class);
        $this->useCase = new RenewContract($this->contractRepository);
    }

    /**
     * @test
     */
    public function it_fails_when_contract_not_found(): void
    {
        $this->contractRepository
            ->method('findById')
            ->willReturn(null);

        $result = $this->useCase->execute(999);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('not found', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_contract_is_cancelled(): void
    {
        $contract = $this->createMockContract(ContractStatus::cancelled());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $result = $this->useCase->execute(123);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('cannot be renewed', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_monthly_value_is_zero(): void
    {
        $contract = $this->createMockContract(ContractStatus::active());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $changes = ['monthly_value' => 0];

        $result = $this->useCase->execute(123, $changes);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('must be greater than zero', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_contract_type_is_invalid(): void
    {
        $contract = $this->createMockContract(ContractStatus::active());

        $this->contractRepository
            ->method('findById')
            ->willReturn($contract);

        $changes = ['contract_type' => 'invalid'];

        $result = $this->useCase->execute(123, $changes);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Invalid contract type', $result->error());
    }

    /**
     * Helper: Create mock contract (simplified)
     */
    private function createMockContract(ContractStatus $status, string $contractNumber = 'CTR-2026-001'): Contract
    {
        $contract = $this->createMock(Contract::class);
        
        $contract->method('getId')->willReturn(ContractId::fromInt(123));
        $contract->method('getClientUserId')->willReturn(1);
        $contract->method('getContractNumber')->willReturn($contractNumber);
        $contract->method('getStatus')->willReturn($status);
        $contract->method('getContractType')->willReturn('weekly');
        $contract->method('getRecurrenceDay')->willReturn(1);
        $contract->method('getServiceCode')->willReturn('LIMPEZA_BASICA');
        $contract->method('getPropertyType')->willReturn('apartamento');
        $contract->method('getMonthlyValue')->willReturn(500.00);
        $contract->method('getAllocatedProfessionalId')->willReturn(null);

        return $contract;
    }

    protected function tearDown(): void
    {
        $this->contractRepository = null;
    }
}
