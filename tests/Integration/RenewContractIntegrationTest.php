<?php
/**
 * RenewContractIntegrationTest - REAL DATABASE INTEGRATION TESTS
 *
 * SEM MOCKS - Usa banco de dados real do WordPress
 * Cria contratos reais, testa renovação real, limpa dados após teste
 *
 * @package LimpVix\Tests\Integration
 * @group integration
 * @group contract
 * @group gap-6
 * @group real-database
 */

namespace LimpVix\Tests\Integration;

use LimpVix\Application\UseCases\Contract\RenewContract;
use LimpVix\Infrastructure\Persistence\Contract\WpContractRepository;
use LimpVix\Domain\Contract\ContractId;
use PHPUnit\Framework\TestCase;

final class RenewContractIntegrationTest extends TestCase
{
    private RenewContract $useCase;
    private WpContractRepository $contractRepository;
    private array $testContractIds = [];
    private \wpdb $wpdb;

    protected function setUp(): void
    {
        global $wpdb;
        $this->wpdb = $wpdb;

        if (!$this->wpdb) {
            $this->markTestSkipped('wpdb not available - run tests with bootstrap-integration.php');
        }

        $this->contractRepository = new WpContractRepository();
        $this->useCase = new RenewContract($this->contractRepository);
    }

    /**
     * @test
     */
    public function it_renews_contract_successfully_with_same_data(): void
    {
        // 1. Create REAL contract in database
        $originalContractId = $this->createRealContract([
            'client_user_id' => 1,
            'contract_number' => 'LMPVX-202602-000001',
            'status' => 'active',
            'contract_type' => 'weekly',
            'recurrence_day' => 1,
            'service_code' => 'LIMPEZA_BASICA',
            'property_type' => 'apartamento',
            'monthly_value' => 500.00,
        ]);

        $this->testContractIds[] = $originalContractId;

        // 2. Execute renewal WITHOUT changes
        $result = $this->useCase->execute($originalContractId);

        // 3. Assert success
        $this->assertTrue($result->isOk(), 'Renewal should succeed: ' . ($result->isOk() ? '' : $result->error()));

        $data = $result->value();

        $this->assertEquals($originalContractId, $data['original_contract_id']);
        $this->assertArrayHasKey('new_contract_id', $data);
        $this->assertArrayHasKey('new_contract_number', $data);

        // 4. Verify new contract exists in database
        $newContractId = $data['new_contract_id'];
        $this->testContractIds[] = $newContractId;

        $newContract = $this->contractRepository->findById(ContractId::fromInt($newContractId));
        $this->assertNotNull($newContract, 'New contract should exist in database');

        // 5. Verify contract number incremented
        $this->assertEquals('LMPVX-202602-000001-R1', $data['new_contract_number']);

        // 6. Verify original contract marked as completed
        $originalContract = $this->contractRepository->findById(ContractId::fromInt($originalContractId));
        $this->assertTrue($originalContract->getStatus()->isCompleted(), 'Original contract should be completed');
    }

    /**
     * @test
     */
    public function it_renews_contract_with_changed_monthly_value(): void
    {
        $originalContractId = $this->createRealContract([
            'client_user_id' => 1,
            'contract_number' => 'LMPVX-202602-000002',
            'status' => 'active',
            'contract_type' => 'monthly',
            'recurrence_day' => 1,
            'service_code' => 'LIMPEZA_BASICA',
            'property_type' => 'casa',
            'monthly_value' => 500.00,
        ]);

        $this->testContractIds[] = $originalContractId;

        // Renew with increased value
        $result = $this->useCase->execute($originalContractId, [
            'monthly_value' => 750.00,
        ]);

        $this->assertTrue($result->isOk());

        $newContractId = $result->value()['new_contract_id'];
        $this->testContractIds[] = $newContractId;

        // Verify new contract has new value
        $newContract = $this->contractRepository->findById(ContractId::fromInt($newContractId));
        $this->assertEquals(750.00, $newContract->getMonthlyValue());
    }

    /**
     * @test
     */
    public function it_renews_contract_with_changed_contract_type(): void
    {
        $originalContractId = $this->createRealContract([
            'client_user_id' => 1,
            'contract_number' => 'LMPVX-202602-000003',
            'status' => 'active',
            'contract_type' => 'weekly',
            'recurrence_day' => 1,
            'service_code' => 'LIMPEZA_BASICA',
            'property_type' => 'apartamento',
            'monthly_value' => 500.00,
        ]);

        $this->testContractIds[] = $originalContractId;

        // Change from weekly to monthly
        $result = $this->useCase->execute($originalContractId, [
            'contract_type' => 'monthly',
            'recurrence_day' => 15,
        ]);

        $this->assertTrue($result->isOk());

        $newContractId = $result->value()['new_contract_id'];
        $this->testContractIds[] = $newContractId;

        $newContract = $this->contractRepository->findById(ContractId::fromInt($newContractId));
        $this->assertEquals('monthly', $newContract->getContractType());
        $this->assertEquals(15, $newContract->getRecurrenceDay());
    }

    /**
     * @test
     */
    public function it_generates_correct_renewal_numbers(): void
    {
        $originalContractId = $this->createRealContract([
            'client_user_id' => 1,
            'contract_number' => 'LMPVX-202602-000004',
            'status' => 'active',
            'contract_type' => 'weekly',
            'recurrence_day' => 1,
            'service_code' => 'LIMPEZA_BASICA',
            'property_type' => 'apartamento',
            'monthly_value' => 500.00,
        ]);

        $this->testContractIds[] = $originalContractId;

        // First renewal: CTR-2026-TEST-004-R1
        $result1 = $this->useCase->execute($originalContractId);
        $this->assertTrue($result1->isOk());
        $this->assertEquals('LMPVX-202602-000004-R1', $result1->value()['new_contract_number']);

        $firstRenewalId = $result1->value()['new_contract_id'];
        $this->testContractIds[] = $firstRenewalId;

    }

    /**
     * @test
     */
    public function it_fails_when_contract_not_found(): void
    {
        $result = $this->useCase->execute(999999);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('not found', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_contract_is_cancelled(): void
    {
        $contractId = $this->createRealContract([
            'client_user_id' => 1,
            'contract_number' => 'LMPVX-202602-000005',
            'status' => 'cancelled',
            'contract_type' => 'weekly',
            'recurrence_day' => 1,
            'service_code' => 'LIMPEZA_BASICA',
            'property_type' => 'apartamento',
            'monthly_value' => 500.00,
        ]);

        $this->testContractIds[] = $contractId;

        $result = $this->useCase->execute($contractId);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('cannot be renewed', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_monthly_value_is_zero(): void
    {
        $contractId = $this->createRealContract([
            'client_user_id' => 1,
            'contract_number' => 'LMPVX-202602-000006',
            'status' => 'active',
            'contract_type' => 'weekly',
            'recurrence_day' => 1,
            'service_code' => 'LIMPEZA_BASICA',
            'property_type' => 'apartamento',
            'monthly_value' => 500.00,
        ]);

        $this->testContractIds[] = $contractId;

        $result = $this->useCase->execute($contractId, ['monthly_value' => 0]);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('must be greater than zero', $result->error());
    }

    /**
     * @test
     */
    public function it_fails_when_contract_type_is_invalid(): void
    {
        $contractId = $this->createRealContract([
            'client_user_id' => 1,
            'contract_number' => 'LMPVX-202602-000007',
            'status' => 'active',
            'contract_type' => 'weekly',
            'recurrence_day' => 1,
            'service_code' => 'LIMPEZA_BASICA',
            'property_type' => 'apartamento',
            'monthly_value' => 500.00,
        ]);

        $this->testContractIds[] = $contractId;

        $result = $this->useCase->execute($contractId, ['contract_type' => 'invalid']);

        $this->assertFalse($result->isOk());
        $this->assertStringContainsString('Invalid contract type', $result->error());
    }

    /**
     * Helper: Create REAL contract in database
     */
    private function createRealContract(array $data): int
    {
        $table = $this->wpdb->prefix . 'limpvix_contracts';

        $inserted = $this->wpdb->insert(
            $table,
            [
                'client_user_id' => $data['client_user_id'],
                'contract_number' => $data['contract_number'],
                'status' => $data['status'],
                'contract_type' => $data['contract_type'],
                'recurrence_day' => $data['recurrence_day'],
                'start_date' => date('Y-m-d'),
                'end_date' => null,
                'service_code' => $data['service_code'],
                'property_type' => $data['property_type'],
                'monthly_value' => $data['monthly_value'],
                'auto_renew' => 1,
                'allocated_professional_id' => null,
                'created_at' => current_time('mysql'),
                'updated_at' => current_time('mysql'),
            ],
            [
                '%d', // client_user_id
                '%s', // contract_number
                '%s', // status
                '%s', // contract_type
                '%d', // recurrence_day
                '%s', // start_date
                '%s', // end_date
                '%s', // service_code
                '%s', // property_type
                '%f', // monthly_value
                '%d', // auto_renew
                '%d', // allocated_professional_id
                '%s', // created_at
                '%s', // updated_at
            ]
        );

        if ($inserted === false) {
            throw new \RuntimeException('Failed to create test contract: ' . $this->wpdb->last_error);
        }

        return (int) $this->wpdb->insert_id;
    }

    /**
     * Cleanup: Delete test contracts after each test
     */
    protected function tearDown(): void
    {
        if (!empty($this->testContractIds) && $this->wpdb) {
            $table = $this->wpdb->prefix . 'limpvix_contracts';

            foreach ($this->testContractIds as $contractId) {
                $this->wpdb->delete($table, ['id' => $contractId], ['%d']);
            }
        }

        $this->testContractIds = [];
    }
}
