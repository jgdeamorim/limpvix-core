<?php
/**
 * ProfessionalAllocationPolicyTest - Testes Unitários para ProfessionalAllocationPolicy
 *
 * @package LimpVix\Tests\Domain\Briefing
 * @since 0.5.0
 */

namespace LimpVix\Tests\Domain\Briefing;

use PHPUnit\Framework\TestCase;
use LimpVix\Domain\Briefing\ProfessionalAllocationPolicy;
use LimpVix\Domain\Briefing\Briefing;
use LimpVix\Domain\Briefing\BriefingStatus;
use LimpVix\Domain\Briefing\PropertyType;
use LimpVix\Domain\Briefing\EstimatedMetrics;
use LimpVix\Domain\Briefing\Complexity;
use LimpVix\Domain\Briefing\Package;
use LimpVix\Domain\Briefing\Frequency;

class ProfessionalAllocationPolicyTest extends TestCase
{
    /**
     * @test
     */
    public function test_single_professional_for_simple_service()
    {
        // Serviço simples: 80m², 3h (180min)
        $briefing = $this->createBriefing(
            m2: 80,
            durationMinutes: 180,
            bufferMinutes: 30
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        $this->assertEquals(1, $allocation->getRequiredCount());
        $this->assertFalse($allocation->requiresMultiple());
        $this->assertStringContainsString('Serviço padrão: 1 profissional', implode(' ', $allocation->getReasoning()));
    }

    /**
     * @test
     */
    public function test_multiple_professionals_for_long_duration()
    {
        // Serviço longo: 100m², 6h (360min)
        $briefing = $this->createBriefing(
            m2: 100,
            durationMinutes: 360,
            bufferMinutes: 30
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // 390min (360 + 30) / 300 = 1.3 → ceil = 2 profissionais
        $this->assertEquals(2, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());
        $this->assertStringContainsString('Duração total', implode(' ', $allocation->getReasoning()));
    }

    /**
     * @test
     */
    public function test_multiple_professionals_for_very_large_area()
    {
        // Área muito grande: 250m², 4h (240min)
        $briefing = $this->createBriefing(
            m2: 250,
            durationMinutes: 240,
            bufferMinutes: 30
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Área > 200m² → forçar múltiplos
        $this->assertGreaterThanOrEqual(2, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());
        $this->assertStringContainsString('Área muito grande', implode(' ', $allocation->getReasoning()));
    }

    /**
     * @test
     */
    public function test_complex_plus_large_area_requires_minimum_two()
    {
        // Complex + área grande: 160m², 4h
        $briefing = $this->createBriefing(
            m2: 160,
            durationMinutes: 240,
            bufferMinutes: 30,
            complexity: Complexity::complex()
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Complex + > 150m² → mínimo 2
        $this->assertGreaterThanOrEqual(2, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());
        $reasoning = implode(' ', $allocation->getReasoning());
        $this->assertStringContainsString('Complexidade: Complex', $reasoning);
    }

    /**
     * @test
     */
    public function test_premium_package_requires_minimum_two()
    {
        // Premium package: 100m², 3h
        $briefing = $this->createBriefing(
            m2: 100,
            durationMinutes: 180,
            bufferMinutes: 30,
            package: Package::premium()
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Premium → mínimo 2
        $this->assertGreaterThanOrEqual(2, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());
        $this->assertStringContainsString('Pacote Premium', implode(' ', $allocation->getReasoning()));
    }

    /**
     * @test
     */
    public function test_post_construction_always_requires_multiple()
    {
        // Pós-obra: 120m², 5h
        $briefing = $this->createBriefing(
            m2: 120,
            durationMinutes: 300,
            bufferMinutes: 30,
            cleaningType: 'pos_obra'
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Pós-obra → sempre múltiplos (mín 2)
        $this->assertGreaterThanOrEqual(2, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());
        $this->assertStringContainsString('pós-obra', implode(' ', $allocation->getReasoning()));
    }

    /**
     * @test
     */
    public function test_caps_at_maximum_allowed()
    {
        // Serviço muito longo: 400m², 20h (1200min)
        $briefing = $this->createBriefing(
            m2: 400,
            durationMinutes: 1200,
            bufferMinutes: 30
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Deve ser cappado em 5 (default max)
        $this->assertLessThanOrEqual(5, $allocation->getRequiredCount());
        $this->assertEquals(5, $allocation->getMaxAllowed());
    }

    /**
     * @test
     */
    public function test_simulate_calculates_correctly()
    {
        $result = ProfessionalAllocationPolicy::simulate(
            m2: 180,
            durationMinutes: 360,
            complexityLevel: 'complex',
            packageType: 'premium'
        );

        // 180m² + 6h + complex + premium → deve alocar múltiplos
        $this->assertGreaterThanOrEqual(2, $result['required_count']);
        $this->assertArrayHasKey('reasoning', $result);
        $this->assertArrayHasKey('display', $result);
    }

    /**
     * @test
     */
    public function test_basic_package_single_professional()
    {
        // Basic package: sempre 1 profissional (max = 1)
        $briefing = $this->createBriefing(
            m2: 100,
            durationMinutes: 180,
            bufferMinutes: 30,
            package: Package::basic()
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Basic não requer múltiplos
        $this->assertEquals(1, $allocation->getRequiredCount());
        $this->assertFalse($allocation->requiresMultiple());
    }

    /**
     * @test
     */
    public function test_standard_package_allows_up_to_two()
    {
        // Standard package + longa duração: pode até 2
        $briefing = $this->createBriefing(
            m2: 120,
            durationMinutes: 360,
            bufferMinutes: 30,
            package: Package::standard()
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Duração longa → 2 profissionais
        $this->assertEquals(2, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());
    }

    /**
     * @test
     */
    public function test_no_metrics_returns_single()
    {
        // Briefing sem métricas calculadas
        $briefing = new Briefing(
            uuid: 'test-uuid',
            userId: 1,
            propertyType: PropertyType::apartment(),
            status: BriefingStatus::draft()
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Sem métricas → single por default
        $this->assertEquals(1, $allocation->getRequiredCount());
        $this->assertFalse($allocation->requiresMultiple());
    }

    /**
     * @test
     */
    public function test_combined_rules_max_takes_precedence()
    {
        // Múltiplas regras aplicadas ao mesmo tempo
        $briefing = $this->createBriefing(
            m2: 250, // Área muito grande (2 profs)
            durationMinutes: 600, // 10h (2 profs)
            bufferMinutes: 30,
            complexity: Complexity::complex(), // Complex (2 profs)
            package: Package::premium() // Premium (2 profs)
        );

        $allocation = ProfessionalAllocationPolicy::calculate($briefing);

        // Múltiplas regras → max de todas
        $this->assertGreaterThanOrEqual(2, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());

        // Reasoning deve mencionar múltiplos fatores
        $reasoning = implode(' ', $allocation->getReasoning());
        $this->assertGreaterThan(2, $allocation->getRequiredCount());
    }

    /**
     * @test
     */
    public function test_update_config_changes_thresholds()
    {
        // Salvar config original
        $originalConfig = get_option('limpvix_professional_allocation_config', []);

        // Atualizar config
        $newConfig = [
            'base_duration_per_professional' => 240, // 4h em vez de 5h
            'large_area_threshold' => 120,
            'very_large_area_threshold' => 180,
            'complex_min_professionals' => 3,
            'premium_min_professionals' => 3,
            'max_professionals_allowed' => 6
        ];

        $updated = ProfessionalAllocationPolicy::updateConfig($newConfig);
        $this->assertTrue($updated);

        // Verificar que config foi salvo
        $savedConfig = get_option('limpvix_professional_allocation_config');
        $this->assertEquals(240, $savedConfig['base_duration_per_professional']);
        $this->assertEquals(6, $savedConfig['max_professionals_allowed']);

        // Restaurar config original
        if (empty($originalConfig)) {
            delete_option('limpvix_professional_allocation_config');
        } else {
            update_option('limpvix_professional_allocation_config', $originalConfig);
        }
    }

    /**
     * @test
     */
    public function test_reset_config_deletes_customizations()
    {
        // Criar config customizado
        $customConfig = [
            'base_duration_per_professional' => 360,
            'large_area_threshold' => 200,
            'very_large_area_threshold' => 250,
            'complex_min_professionals' => 3,
            'premium_min_professionals' => 3,
            'max_professionals_allowed' => 7
        ];
        update_option('limpvix_professional_allocation_config', $customConfig);

        // Reset
        $reset = ProfessionalAllocationPolicy::resetConfig();
        $this->assertTrue($reset);

        // Verificar que voltou ao default
        $config = get_option('limpvix_professional_allocation_config', false);
        $this->assertFalse($config);
    }

    // ==================== HELPER METHODS ====================

    /**
     * Criar Briefing de teste
     */
    private function createBriefing(
        float $m2,
        int $durationMinutes,
        int $bufferMinutes = 30,
        ?Complexity $complexity = null,
        ?Package $package = null,
        ?string $cleaningType = null
    ): Briefing {
        $metrics = new EstimatedMetrics($m2, $durationMinutes, $bufferMinutes);

        $frequency = null;
        if ($cleaningType !== null) {
            $frequency = Frequency::oneTime($cleaningType);
        }

        $briefing = new Briefing(
            uuid: 'test-uuid-' . uniqid(),
            userId: 1,
            propertyType: PropertyType::apartment(),
            status: BriefingStatus::metricsCalculated(),
            metrics: $metrics,
            frequency: $frequency
        );

        if ($complexity !== null) {
            $briefing->assessComplexity($complexity);
        }

        if ($package !== null) {
            $briefing->selectPackage($package);
        }

        return $briefing;
    }
}
