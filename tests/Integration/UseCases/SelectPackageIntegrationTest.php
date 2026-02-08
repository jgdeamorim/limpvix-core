<?php
/**
 * SelectPackageIntegrationTest - Testes de Integração para SelectPackage Use Case
 *
 * Testa fluxo completo: Use Case → Repository → Database
 *
 * @package LimpVix\Tests\Integration\UseCases
 * @since 0.5.0
 */

namespace LimpVix\Tests\Integration\UseCases;

use PHPUnit\Framework\TestCase;
use LimpVix\Application\UseCases\Briefing\SelectPackage;
use LimpVix\Infrastructure\Persistence\WpBriefingRepository;
use LimpVix\Infrastructure\Persistence\WpBriefingLedgerRepository;
use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\PropertyType;

/**
 * @group integration
 * @group use-cases
 */
class SelectPackageIntegrationTest extends TestCase
{
    /**
     * @var SelectPackage
     */
    private $useCase;

    /**
     * @var WpBriefingRepository
     */
    private $repository;

    /**
     * @var WpBriefingLedgerRepository
     */
    private $ledgerRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new WpBriefingRepository();
        $this->ledgerRepository = new WpBriefingLedgerRepository();
        $this->useCase = new SelectPackage($this->repository, $this->ledgerRepository);

        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function test_execute_selects_basic_package_successfully()
    {
        // Arrange
        $briefing = $this->createAndSaveBriefing();

        // Act
        $result = $this->useCase->execute($briefing->getUuid(), 'basic');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('basic', $result['package']['type']);
        $this->assertEquals(0.0, $result['package']['percentage_increase']);

        // Verificar persistência
        $retrieved = $this->repository->findByUuid($briefing->getUuid());
        $this->assertNotNull($retrieved->getPackage());
        $this->assertEquals('basic', $retrieved->getPackage()->getType()->getValue());
    }

    /**
     * @test
     */
    public function test_execute_selects_standard_package_successfully()
    {
        // Arrange
        $briefing = $this->createAndSaveBriefing();

        // Act
        $result = $this->useCase->execute($briefing->getUuid(), 'standard');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('standard', $result['package']['type']);
        $this->assertEquals(0.15, $result['package']['percentage_increase']);
    }

    /**
     * @test
     */
    public function test_execute_selects_premium_package_successfully()
    {
        // Arrange
        $briefing = $this->createAndSaveBriefing();

        // Act
        $result = $this->useCase->execute($briefing->getUuid(), 'premium');

        // Assert
        $this->assertTrue($result['success']);
        $this->assertEquals('premium', $result['package']['type']);
        $this->assertEquals(0.30, $result['package']['percentage_increase']);
        $this->assertGreaterThanOrEqual(2, $result['package']['min_professionals']);
    }

    /**
     * @test
     */
    public function test_execute_fails_for_nonexistent_briefing()
    {
        // Act
        $result = $this->useCase->execute('nonexistent-uuid', 'basic');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('briefing_not_found', $result['error']);
    }

    /**
     * @test
     */
    public function test_execute_fails_for_invalid_package_type()
    {
        // Arrange
        $briefing = $this->createAndSaveBriefing();

        // Act
        $result = $this->useCase->execute($briefing->getUuid(), 'invalid-package');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('invalid_package_type', $result['error']);
    }

    /**
     * @test
     */
    public function test_execute_fails_for_locked_briefing()
    {
        // Arrange
        $briefing = $this->createAndSaveBriefing();
        $briefing->lock(123); // Simular lock
        $this->repository->save($briefing);

        // Act
        $result = $this->useCase->execute($briefing->getUuid(), 'premium');

        // Assert
        $this->assertFalse($result['success']);
        $this->assertEquals('briefing_locked', $result['error']);
    }

    /**
     * @test
     */
    public function test_execute_registers_event_in_ledger()
    {
        // Arrange
        $briefing = $this->createAndSaveBriefing();

        // Act
        $this->useCase->execute($briefing->getUuid(), 'premium');

        // Assert - Verificar que evento foi registrado no ledger
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_briefing_ledger';

        $event = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE briefing_uuid = %s AND event_type = 'package_selected' ORDER BY occurred_at DESC LIMIT 1",
            $briefing->getUuid()
        ), ARRAY_A);

        $this->assertNotNull($event);
        $this->assertEquals('package_selected', $event['event_type']);

        $eventData = json_decode($event['event_data'], true);
        $this->assertEquals('premium', $eventData['package_type']);
    }

    /**
     * @test
     */
    public function test_execute_allows_changing_package()
    {
        // Arrange
        $briefing = $this->createAndSaveBriefing();
        $this->useCase->execute($briefing->getUuid(), 'basic');

        // Act - Mudar para premium
        $result = $this->useCase->execute($briefing->getUuid(), 'premium');

        // Assert
        $this->assertTrue($result['success']);

        $retrieved = $this->repository->findByUuid($briefing->getUuid());
        $this->assertEquals('premium', $retrieved->getPackage()->getType()->getValue());

        // Verificar que há 2 eventos no ledger
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_briefing_ledger';

        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table} WHERE briefing_uuid = %s AND event_type = 'package_selected'",
            $briefing->getUuid()
        ));

        $this->assertEquals(2, $count, 'Should have 2 package_selected events');
    }

    // ==================== HELPER METHODS ====================

    private function createAndSaveBriefing(): Briefing
    {
        $briefing = new Briefing(
            uuid: 'test-select-package-' . uniqid(),
            userId: 1,
            propertyType: PropertyType::apartment(),
            status: BriefingStatus::draft()
        );

        $this->repository->save($briefing);
        return $briefing;
    }

    private function cleanupTestData(): void
    {
        global $wpdb;

        $tableBriefings = $wpdb->prefix . 'limpvix_briefings';
        $tableBriefingData = $wpdb->prefix . 'limpvix_briefing_data';
        $tableLedger = $wpdb->prefix . 'limpvix_briefing_ledger';

        $wpdb->query("DELETE FROM {$tableBriefings} WHERE uuid LIKE 'test-select-package-%'");
        $wpdb->query("DELETE FROM {$tableBriefingData} WHERE briefing_uuid LIKE 'test-select-package-%'");
        $wpdb->query("DELETE FROM {$tableLedger} WHERE briefing_uuid LIKE 'test-select-package-%'");
    }
}
