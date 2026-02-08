<?php
/**
 * BriefingCompleteFlowTest - Teste End-to-End do Fluxo Completo de Briefing
 *
 * Testa o fluxo completo:
 * 1. Criar Briefing
 * 2. Adicionar Structure + Frequency
 * 3. Calcular Métricas
 * 4. Selecionar Package
 * 5. Avaliar Complexity
 * 6. Calcular Profissionais Necessários
 * 7. Lock (simular pagamento)
 * 8. Criar Snapshot
 *
 * @package LimpVix\Tests\E2E
 * @since 0.5.0
 */

namespace LimpVix\Tests\E2E;

use PHPUnit\Framework\TestCase;
use LimpVix\Infrastructure\Persistence\WpBriefingRepository;
use LimpVix\Infrastructure\Persistence\WpBriefingLedgerRepository;
use LimpVix\Application\UseCases\Briefing\SelectPackage;
use LimpVix\Application\UseCases\Briefing\AssessComplexity;
use LimpVix\Application\UseCases\Briefing\CalculateProfessionalsRequired;
use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\PropertyType;
use LimpVix\Domain\Briefing\PropertyStructure;
use LimpVix\Domain\Briefing\Frequency;
use LimpVix\Domain\Briefing\EstimatedMetrics;
use LimpVix\Domain\Briefing\BriefingSnapshot;
use LimpVix\Domain\Briefing\BriefingComplexityPolicy;

/**
 * @group e2e
 * @group complete-flow
 */
class BriefingCompleteFlowTest extends TestCase
{
    private $repository;
    private $ledgerRepository;
    private $briefingUuid;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new WpBriefingRepository();
        $this->ledgerRepository = new WpBriefingLedgerRepository();

        $this->cleanupTestData();
    }

    protected function tearDown(): void
    {
        $this->cleanupTestData();
        parent::tearDown();
    }

    /**
     * @test
     *
     * CENÁRIO: Cliente de apartamento pequeno, serviço simples
     *
     * EXPECTATIVA:
     * - Basic package (0% aumento)
     * - Complexity: Simple
     * - 1 profissional
     */
    public function test_complete_flow_simple_service()
    {
        // ==================== PASSO 1: CRIAR BRIEFING ====================
        $briefing = new Briefing(
            uuid: 'test-e2e-simple-' . uniqid(),
            userId: 100,
            propertyType: PropertyType::apartment(),
            status: BriefingStatus::draft()
        );
        $this->briefingUuid = $briefing->getUuid();

        $this->assertTrue($this->repository->save($briefing));
        $this->assertStatus(BriefingStatus::draft());

        // ==================== PASSO 2: ADICIONAR ESTRUTURA ====================
        $structure = PropertyStructure::create(
            bedrooms: 1,
            bathrooms: 1,
            hasLivingRoom: true,
            hasKitchen: true,
            hasOffice: false,
            hasExternalArea: false
        );

        $briefing->defineStructure($structure);
        $this->repository->save($briefing);

        // ==================== PASSO 3: ADICIONAR FREQUÊNCIA ====================
        $frequency = Frequency::oneTime('limpeza_basica');
        $briefing->defineFrequency($frequency);
        $this->repository->save($briefing);

        // ==================== PASSO 4: CALCULAR MÉTRICAS ====================
        $metrics = new EstimatedMetrics(
            m2: 50.0,
            durationMinutes: 120, // 2h
            bufferMinutes: 30
        );

        $briefing->calculateMetrics($metrics);
        $this->repository->save($briefing);
        $this->assertStatus(BriefingStatus::metricsCalculated());

        // ==================== PASSO 5: SELECIONAR PACKAGE ====================
        $selectPackageUseCase = new SelectPackage($this->repository, $this->ledgerRepository);
        $packageResult = $selectPackageUseCase->execute($this->briefingUuid, 'basic');

        $this->assertTrue($packageResult['success']);
        $this->assertEquals('basic', $packageResult['package']['type']);

        // ==================== PASSO 6: AVALIAR COMPLEXITY ====================
        $briefing = $this->repository->findByUuid($this->briefingUuid);
        $complexity = BriefingComplexityPolicy::determine($briefing);

        $briefing->assessComplexity($complexity);
        $this->repository->save($briefing);

        $this->assertEquals('simple', $complexity->getLevel()->getValue());
        $this->assertEquals(1.0, $complexity->getMultiplier());

        // ==================== PASSO 7: CALCULAR PROFISSIONAIS ====================
        $calculateProfessionalsUseCase = new CalculateProfessionalsRequired(
            $this->repository,
            $this->ledgerRepository
        );

        $allocationResult = $calculateProfessionalsUseCase->execute($this->briefingUuid);

        $this->assertTrue($allocationResult['success']);
        $this->assertEquals(1, $allocationResult['allocation']['required_count']);
        $this->assertFalse($allocationResult['allocation']['requires_multiple']);

        // ==================== PASSO 8: LOCK (SIMULAR PAGAMENTO) ====================
        $briefing = $this->repository->findByUuid($this->briefingUuid);
        $briefing->lock(9999); // Order ID simulado
        $this->repository->save($briefing);

        $this->assertTrue($briefing->isLocked());
        $this->assertEquals(9999, $briefing->getOrderId());

        // ==================== PASSO 9: CRIAR SNAPSHOT ====================
        $snapshot = BriefingSnapshot::createFromBriefing($briefing);

        $this->assertTrue($snapshot->verifyIntegrity());
        $this->assertEquals($this->briefingUuid, $snapshot->getBriefingUuid());
        $this->assertEquals('v1', $snapshot->getVersion());

        // Verificar dados normalizados
        $this->assertEquals('apartment', $snapshot->getData('property_type'));
        $this->assertEquals(50.0, $snapshot->getMetric('estimated_m2'));
        $this->assertEquals(1, $snapshot->getMetric('required_professionals_count'));

        // ==================== VALIDAÇÕES FINAIS ====================
        $this->assertLedgerHasEvents([
            'package_selected',
            'complexity_assessed',
            'professionals_calculated',
            'briefing_locked'
        ]);
    }

    /**
     * @test
     *
     * CENÁRIO: Casa grande, serviço complexo com pacote premium
     *
     * EXPECTATIVA:
     * - Premium package (+30% aumento)
     * - Complexity: Complex
     * - Múltiplos profissionais (≥ 2)
     */
    public function test_complete_flow_complex_premium_service()
    {
        // ==================== PASSO 1: CRIAR BRIEFING ====================
        $briefing = new Briefing(
            uuid: 'test-e2e-complex-' . uniqid(),
            userId: 200,
            propertyType: PropertyType::house(),
            status: BriefingStatus::draft()
        );
        $this->briefingUuid = $briefing->getUuid();
        $this->repository->save($briefing);

        // ==================== PASSO 2: ESTRUTURA GRANDE ====================
        $structure = PropertyStructure::create(
            bedrooms: 4,
            bathrooms: 3,
            hasLivingRoom: true,
            hasKitchen: true,
            hasOffice: true,
            hasExternalArea: true
        );

        $briefing->defineStructure($structure);
        $this->repository->save($briefing);

        // ==================== PASSO 3: FREQUÊNCIA (LIMPEZA PESADA) ====================
        $frequency = Frequency::oneTime('limpeza_pesada');
        $briefing->defineFrequency($frequency);
        $this->repository->save($briefing);

        // ==================== PASSO 4: MÉTRICAS (ÁREA GRANDE + LONGA DURAÇÃO) ====================
        $metrics = new EstimatedMetrics(
            m2: 200.0, // Área grande
            durationMinutes: 360, // 6h
            bufferMinutes: 60
        );

        $briefing->calculateMetrics($metrics);
        $this->repository->save($briefing);

        // ==================== PASSO 5: PACKAGE PREMIUM ====================
        $selectPackageUseCase = new SelectPackage($this->repository, $this->ledgerRepository);
        $packageResult = $selectPackageUseCase->execute($this->briefingUuid, 'premium');

        $this->assertTrue($packageResult['success']);
        $this->assertEquals('premium', $packageResult['package']['type']);
        $this->assertEquals(0.30, $packageResult['package']['percentage_increase']);

        // ==================== PASSO 6: COMPLEXITY COMPLEX ====================
        $briefing = $this->repository->findByUuid($this->briefingUuid);
        $complexity = BriefingComplexityPolicy::determine($briefing);

        $briefing->assessComplexity($complexity);
        $this->repository->save($briefing);

        $this->assertEquals('complex', $complexity->getLevel()->getValue());
        $this->assertGreaterThanOrEqual(1.3, $complexity->getMultiplier());

        // ==================== PASSO 7: MÚLTIPLOS PROFISSIONAIS ====================
        $calculateProfessionalsUseCase = new CalculateProfessionalsRequired(
            $this->repository,
            $this->ledgerRepository
        );

        $allocationResult = $calculateProfessionalsUseCase->execute($this->briefingUuid);

        $this->assertTrue($allocationResult['success']);
        $this->assertGreaterThanOrEqual(2, $allocationResult['allocation']['required_count']);
        $this->assertTrue($allocationResult['allocation']['requires_multiple']);

        // Verificar reasoning
        $reasoning = implode(' ', $allocationResult['allocation']['reasoning']);
        $this->assertStringContainsStringIgnoringCase('área', $reasoning);

        // ==================== PASSO 8: LOCK ====================
        $briefing = $this->repository->findByUuid($this->briefingUuid);
        $briefing->lock(8888);
        $this->repository->save($briefing);

        // ==================== PASSO 9: SNAPSHOT ====================
        $snapshot = BriefingSnapshot::createFromBriefing($briefing);

        $this->assertTrue($snapshot->verifyIntegrity());
        $this->assertEquals(200.0, $snapshot->getMetric('estimated_m2'));
        $this->assertGreaterThanOrEqual(2, $snapshot->getMetric('required_professionals_count'));
        $this->assertTrue($snapshot->getMetric('requires_multiple_professionals'));

        // Verificar pricing breakdown
        $pricing = $snapshot->getMetric('pricing_breakdown');
        $this->assertArrayHasKey('package_increase', $pricing);
        $this->assertGreaterThan(0, $pricing['package_increase']);

        // ==================== VALIDAÇÕES FINAIS ====================
        $this->assertLedgerHasEvents([
            'package_selected',
            'complexity_assessed',
            'professionals_calculated',
            'briefing_locked'
        ]);
    }

    /**
     * @test
     *
     * CENÁRIO: Tentar modificar briefing locked (deve falhar)
     */
    public function test_locked_briefing_cannot_be_modified()
    {
        // Arrange - Criar e lock briefing
        $briefing = new Briefing(
            uuid: 'test-e2e-locked-' . uniqid(),
            userId: 300,
            propertyType: PropertyType::apartment(),
            status: BriefingStatus::draft()
        );
        $this->briefingUuid = $briefing->getUuid();

        $metrics = new EstimatedMetrics(80, 180, 30);
        $briefing->calculateMetrics($metrics);
        $briefing->lock(7777);
        $this->repository->save($briefing);

        // Act & Assert - Tentar selecionar package (deve falhar)
        $selectPackageUseCase = new SelectPackage($this->repository, $this->ledgerRepository);
        $result = $selectPackageUseCase->execute($this->briefingUuid, 'premium');

        $this->assertFalse($result['success']);
        $this->assertEquals('briefing_locked', $result['error']);
    }

    // ==================== HELPER METHODS ====================

    private function assertStatus(BriefingStatus $expectedStatus): void
    {
        $briefing = $this->repository->findByUuid($this->briefingUuid);
        $this->assertEquals(
            $expectedStatus->getValue(),
            $briefing->getStatus()->getValue(),
            "Status should be {$expectedStatus->getValue()}"
        );
    }

    private function assertLedgerHasEvents(array $expectedEventTypes): void
    {
        global $wpdb;
        $table = $wpdb->prefix . 'limpvix_briefing_ledger';

        foreach ($expectedEventTypes as $eventType) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE briefing_uuid = %s AND event_type = %s",
                $this->briefingUuid,
                $eventType
            ));

            $this->assertGreaterThan(0, $exists, "Ledger should have event: {$eventType}");
        }
    }

    private function cleanupTestData(): void
    {
        global $wpdb;

        $tableBriefings = $wpdb->prefix . 'limpvix_briefings';
        $tableBriefingData = $wpdb->prefix . 'limpvix_briefing_data';
        $tableLedger = $wpdb->prefix . 'limpvix_briefing_ledger';

        $wpdb->query("DELETE FROM {$tableBriefings} WHERE uuid LIKE 'test-e2e-%'");
        $wpdb->query("DELETE FROM {$tableBriefingData} WHERE briefing_uuid LIKE 'test-e2e-%'");
        $wpdb->query("DELETE FROM {$tableLedger} WHERE briefing_uuid LIKE 'test-e2e-%'");
    }
}
