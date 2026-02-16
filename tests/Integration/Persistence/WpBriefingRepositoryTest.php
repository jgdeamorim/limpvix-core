<?php
/**
 * WpBriefingRepositoryTest - Testes de Integração para WpBriefingRepository
 *
 * Testa persistência real no banco de dados WordPress
 *
 * @package LimpVix\Tests\Integration\Persistence
 * @since 0.5.0
 */

namespace LimpVix\Tests\Integration\Persistence;

use PHPUnit\Framework\TestCase;
use LimpVix\Infrastructure\Persistence\WpBriefingRepository;
use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\PropertyType;
use LimpVix\Domain\Briefing\PropertyStructure;
use LimpVix\Domain\Briefing\Frequency;
use LimpVix\Domain\Briefing\EstimatedMetrics;
use LimpVix\Domain\Briefing\Package;
use LimpVix\Domain\Briefing\Complexity;

/**
 * @group integration
 * @group database
 */
class WpBriefingRepositoryTest extends TestCase
{
    /**
     * @var WpBriefingRepository
     */
    private $repository;

    /**
     * @var \wpdb
     */
    private $wpdb;

    /**
     * Setup antes de cada teste
     */
    protected function setUp(): void
    {
        parent::setUp();

        global $wpdb;
        $this->wpdb = $wpdb;
        $this->repository = new WpBriefingRepository();

        // Limpar dados de teste
        $this->cleanupTestData();
    }

    /**
     * Cleanup após cada teste
     */
    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @test
     */
    public function test_save_creates_new_briefing_in_database()
    {
        // Arrange
        $briefing = $this->createTestBriefing();

        // Act
        $result = $this->repository->save($briefing);

        // Assert
        $this->assertTrue($result, 'Save should return true');

        // Verificar que existe no banco
        $exists = $this->repository->exists($briefing->getUuid());
        $this->assertTrue($exists, 'Briefing should exist in database');
    }

    /**
     * @test
     */
    public function test_find_by_uuid_retrieves_correct_briefing()
    {
        // Arrange
        $briefing = $this->createTestBriefing();
        $this->repository->save($briefing);

        // Act
        $retrieved = $this->repository->findByUuid($briefing->getUuid());

        // Assert
        $this->assertNotNull($retrieved, 'Should retrieve briefing');
        $this->assertEquals($briefing->getUuid(), $retrieved->getUuid());
        $this->assertEquals($briefing->getUserId(), $retrieved->getUserId());
        $this->assertEquals($briefing->getPropertyType()->getValue(), $retrieved->getPropertyType()->getValue());
        $this->assertEquals($briefing->getStatus()->getValue(), $retrieved->getStatus()->getValue());
    }

    /**
     * @test
     */
    public function test_save_updates_existing_briefing()
    {
        // Arrange
        $briefing = $this->createTestBriefing();
        $this->repository->save($briefing);

        // Act - Modificar e salvar novamente
        $briefing->selectPackage(Package::premium());
        $result = $this->repository->save($briefing);

        // Assert
        $this->assertTrue($result);

        $retrieved = $this->repository->findByUuid($briefing->getUuid());
        $this->assertNotNull($retrieved->getPackage());
        $this->assertEquals('premium', $retrieved->getPackage()->getType()->getValue());
    }

    /**
     * @test
     */
    public function test_hydration_preserves_value_objects()
    {
        // Arrange
        $structure = PropertyStructure::create(
            bedrooms: 2,
            bathrooms: 1,
            hasLivingRoom: true,
            hasKitchen: true,
            hasOffice: false,
            hasExternalArea: false
        );

        $frequency = Frequency::weekly();
        $metrics = new EstimatedMetrics(80.0, 180, 30);
        $package = Package::standard();
        $complexity = Complexity::medium();

        $briefing = new Briefing(
            uuid: 'test-hydration-' . uniqid(),
            userId: 1,
            propertyType: PropertyType::apartment(),
            status: BriefingStatus::metricsCalculated(),
            structure: $structure,
            frequency: $frequency,
            metrics: $metrics,
            package: $package,
            complexity: $complexity
        );

        // Act
        $this->repository->save($briefing);
        $retrieved = $this->repository->findByUuid($briefing->getUuid());

        // Assert - Verificar que todos Value Objects foram reconstituídos
        $this->assertNotNull($retrieved->getStructure());
        $this->assertEquals(2, $retrieved->getStructure()->getBedrooms());
        $this->assertTrue($retrieved->getStructure()->hasLivingRoom());

        $this->assertNotNull($retrieved->getFrequency());
        $this->assertEquals('weekly', $retrieved->getFrequency()->getType());

        $this->assertNotNull($retrieved->getMetrics());
        $this->assertEquals(80.0, $retrieved->getMetrics()->getEstimatedM2());

        $this->assertNotNull($retrieved->getPackage());
        $this->assertEquals('standard', $retrieved->getPackage()->getType()->getValue());

        $this->assertNotNull($retrieved->getComplexity());
        $this->assertEquals('medium', $retrieved->getComplexity()->getLevel()->getValue());
    }

    /**
     * @test
     */
    public function test_find_by_user_id_returns_user_briefings()
    {
        // Arrange
        $userId = 123;
        $briefing1 = $this->createTestBriefing($userId);
        $briefing2 = $this->createTestBriefing($userId);
        $briefing3 = $this->createTestBriefing(456); // Outro usuário

        $this->repository->save($briefing1);
        $this->repository->save($briefing2);
        $this->repository->save($briefing3);

        // Act
        $briefings = $this->repository->findByUserId($userId, 10);

        // Assert
        $this->assertCount(2, $briefings);
        $this->assertEquals($userId, $briefings[0]->getUserId());
        $this->assertEquals($userId, $briefings[1]->getUserId());
    }

    /**
     * @test
     */
    public function test_find_by_status_returns_correct_briefings()
    {
        // Arrange
        $briefing1 = $this->createTestBriefing();
        $briefing1->calculateMetrics(new EstimatedMetrics(80, 180, 30));

        $briefing2 = $this->createTestBriefing();
        // Manter em draft

        $this->repository->save($briefing1);
        $this->repository->save($briefing2);

        // Act
        $metricsCalculated = $this->repository->findByStatus(BriefingStatus::metricsCalculated(), 10);
        $drafts = $this->repository->findByStatus(BriefingStatus::draft(), 10);

        // Assert
        $this->assertGreaterThanOrEqual(1, count($metricsCalculated));
        $this->assertGreaterThanOrEqual(1, count($drafts));
    }

    /**
     * @test
     */
    public function test_count_returns_correct_total()
    {
        // Arrange
        $beforeCount = $this->repository->count();

        $briefing1 = $this->createTestBriefing();
        $briefing2 = $this->createTestBriefing();

        $this->repository->save($briefing1);
        $this->repository->save($briefing2);

        // Act
        $afterCount = $this->repository->count();

        // Assert
        $this->assertEquals($beforeCount + 2, $afterCount);
    }

    /**
     * @test
     */
    public function test_count_by_status_returns_correct_count()
    {
        // Arrange
        $briefing1 = $this->createTestBriefing();
        $briefing1->calculateMetrics(new EstimatedMetrics(80, 180, 30));

        $briefing2 = $this->createTestBriefing();
        $briefing2->calculateMetrics(new EstimatedMetrics(100, 240, 30));

        $this->repository->save($briefing1);
        $this->repository->save($briefing2);

        // Act
        $count = $this->repository->count(BriefingStatus::metricsCalculated());

        // Assert
        $this->assertGreaterThanOrEqual(2, $count);
    }

    /**
     * @test
     */
    public function test_delete_removes_briefing()
    {
        // Arrange
        $briefing = $this->createTestBriefing();
        $this->repository->save($briefing);
        $this->assertTrue($this->repository->exists($briefing->getUuid()));

        // Act
        $result = $this->repository->delete($briefing->getUuid());

        // Assert
        $this->assertTrue($result);
        $this->assertFalse($this->repository->exists($briefing->getUuid()));
    }

    /**
     * @test
     */
    public function test_cannot_delete_locked_briefing()
    {
        // Arrange
        $briefing = $this->createTestBriefing();
        $briefing->calculateMetrics(new EstimatedMetrics(80, 180, 30));
        $briefing->lock(1); // Simular pagamento confirmado
        $this->repository->save($briefing);

        // Act
        $result = $this->repository->delete($briefing->getUuid());

        // Assert
        $this->assertFalse($result, 'Should not delete locked briefing');
        $this->assertTrue($this->repository->exists($briefing->getUuid()));
    }

    /**
     * @test
     */
    public function test_find_by_order_id_retrieves_correct_briefing()
    {
        // Arrange
        $briefing = $this->createTestBriefing();
        $briefing->calculateMetrics(new EstimatedMetrics(80, 180, 30));
        $briefing->lock(999); // Order ID = 999
        $this->repository->save($briefing);

        // Act
        $retrieved = $this->repository->findByOrderId(999);

        // Assert
        $this->assertNotNull($retrieved);
        $this->assertEquals($briefing->getUuid(), $retrieved->getUuid());
        $this->assertEquals(999, $retrieved->getOrderId());
    }

    /**
     * @test
     */
    public function test_transactional_save_rollback_on_error()
    {
        // Este teste valida que se houver erro, nada é salvo
        // Arrange
        $briefing = $this->createTestBriefing();

        // Simular erro forçando UUID inválido (muito longo)
        $reflection = new \ReflectionClass($briefing);
        $uuidProperty = $reflection->getProperty('uuid');
        $uuidProperty->setAccessible(true);
        $uuidProperty->setValue($briefing, str_repeat('a', 500)); // UUID muito longo

        // Act
        $result = $this->repository->save($briefing);

        // Assert
        $this->assertFalse($result, 'Save should fail');
    }

    /**
     * @test
     */
    public function test_immutability_briefing_retrieved_is_new_instance()
    {
        // Arrange
        $briefing = $this->createTestBriefing();
        $this->repository->save($briefing);

        // Act
        $retrieved1 = $this->repository->findByUuid($briefing->getUuid());
        $retrieved2 = $this->repository->findByUuid($briefing->getUuid());

        // Assert
        $this->assertNotSame($retrieved1, $retrieved2, 'Each retrieval should create new instance');
        $this->assertEquals($retrieved1->getUuid(), $retrieved2->getUuid(), 'But content should be equal');
    }

    // ==================== HELPER METHODS ====================

    /**
     * Criar Briefing de teste
     */
    private function createTestBriefing(int $userId = 1): Briefing
    {
        return new Briefing(
            uuid: 'test-' . uniqid(),
            userId: $userId,
            propertyType: PropertyType::apartment(),
            status: BriefingStatus::draft()
        );
    }

    /**
     * Limpar dados de teste do banco
     */
    private function cleanupTestData(): void
    {
        global $wpdb;

        $tableBriefings = $wpdb->prefix . 'limpvix_briefings';
        $tableBriefingData = $wpdb->prefix . 'limpvix_briefing_data';

        // Deletar briefings de teste
        $wpdb->query("DELETE FROM {$tableBriefings} WHERE uuid LIKE 'test-%'");
        $wpdb->query("DELETE FROM {$tableBriefingData} WHERE briefing_uuid LIKE 'test-%'");
    }
}
