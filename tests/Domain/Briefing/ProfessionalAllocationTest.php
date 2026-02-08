<?php
/**
 * ProfessionalAllocationTest - Testes Unitários para ProfessionalAllocation Value Object
 *
 * @package LimpVix\Tests\Domain\Briefing
 * @since 0.5.0
 */

namespace LimpVix\Tests\Domain\Briefing;

use PHPUnit\Framework\TestCase;
use LimpVix\Domain\Briefing\ProfessionalAllocation;

class ProfessionalAllocationTest extends TestCase
{
    /**
     * @test
     */
    public function test_single_factory_creates_correctly()
    {
        $allocation = ProfessionalAllocation::single();

        $this->assertEquals(1, $allocation->getRequiredCount());
        $this->assertFalse($allocation->requiresMultiple());
        $this->assertEquals(5, $allocation->getMaxAllowed());
        $this->assertCount(1, $allocation->getReasoning());
    }

    /**
     * @test
     */
    public function test_multiple_factory_creates_correctly()
    {
        $reasoning = ['Serviço complexo', 'Duração longa'];
        $allocation = ProfessionalAllocation::multiple(3, $reasoning);

        $this->assertEquals(3, $allocation->getRequiredCount());
        $this->assertTrue($allocation->requiresMultiple());
        $this->assertCount(2, $allocation->getReasoning());
        $this->assertContains('Serviço complexo', $allocation->getReasoning());
    }

    /**
     * @test
     */
    public function test_from_config_creates_correctly()
    {
        $config = [
            'required_count' => 2,
            'reasoning' => ['Razão 1', 'Razão 2'],
            'max_allowed' => 4
        ];

        $allocation = ProfessionalAllocation::fromConfig($config);

        $this->assertEquals(2, $allocation->getRequiredCount());
        $this->assertEquals(4, $allocation->getMaxAllowed());
        $this->assertCount(2, $allocation->getReasoning());
    }

    /**
     * @test
     */
    public function test_requires_multiple_checks_correctly()
    {
        $single = ProfessionalAllocation::single();
        $this->assertFalse($single->requiresMultiple());

        $multiple = ProfessionalAllocation::multiple(2);
        $this->assertTrue($multiple->requiresMultiple());
    }

    /**
     * @test
     */
    public function test_is_at_max_capacity_checks_correctly()
    {
        $allocation = new ProfessionalAllocation(5, [], 5);
        $this->assertTrue($allocation->isAtMaxCapacity());

        $notAtMax = new ProfessionalAllocation(3, [], 5);
        $this->assertFalse($notAtMax->isAtMaxCapacity());
    }

    /**
     * @test
     */
    public function test_can_add_more_checks_correctly()
    {
        $allocation = new ProfessionalAllocation(3, [], 5);

        $this->assertTrue($allocation->canAddMore(1)); // 3 + 1 = 4 <= 5
        $this->assertTrue($allocation->canAddMore(2)); // 3 + 2 = 5 <= 5
        $this->assertFalse($allocation->canAddMore(3)); // 3 + 3 = 6 > 5
    }

    /**
     * @test
     */
    public function test_with_reason_creates_new_instance()
    {
        $allocation = ProfessionalAllocation::single();
        $original = $allocation->getReasoning();

        $withReason = $allocation->withReason('Nova razão');

        // Original não muda (imutabilidade)
        $this->assertCount(1, $original);

        // Nova instância tem reasoning adicional
        $newReasoning = $withReason->getReasoning();
        $this->assertCount(2, $newReasoning);
        $this->assertContains('Nova razão', $newReasoning);
    }

    /**
     * @test
     */
    public function test_with_count_creates_new_instance()
    {
        $allocation = new ProfessionalAllocation(2, [], 5);

        $withNewCount = $allocation->withCount(4);

        // Original não muda
        $this->assertEquals(2, $allocation->getRequiredCount());

        // Nova instância tem novo count
        $this->assertEquals(4, $withNewCount->getRequiredCount());
    }

    /**
     * @test
     */
    public function test_with_count_caps_at_max()
    {
        $allocation = new ProfessionalAllocation(2, [], 5);

        $withCapped = $allocation->withCount(10);

        // Deve ser cappado em max_allowed
        $this->assertEquals(5, $withCapped->getRequiredCount());
    }

    /**
     * @test
     */
    public function test_with_count_respects_minimum()
    {
        $allocation = new ProfessionalAllocation(2, [], 5);

        $withMin = $allocation->withCount(0);

        // Deve ser no mínimo 1
        $this->assertEquals(1, $withMin->getRequiredCount());
    }

    /**
     * @test
     */
    public function test_get_display_string_formats_correctly()
    {
        $single = ProfessionalAllocation::single();
        $this->assertEquals('1 profissional', $single->getDisplayString());

        $multiple = ProfessionalAllocation::multiple(3);
        $this->assertEquals('3 profissionais', $multiple->getDisplayString());
    }

    /**
     * @test
     */
    public function test_equals_compares_correctly()
    {
        $allocation1 = new ProfessionalAllocation(2, ['Razão']);
        $allocation2 = new ProfessionalAllocation(2, ['Outra razão']);
        $allocation3 = new ProfessionalAllocation(3, ['Razão']);

        // Iguais se mesmo count (reasoning não importa para equals)
        $this->assertTrue($allocation1->equals($allocation2));
        $this->assertFalse($allocation1->equals($allocation3));
    }

    /**
     * @test
     */
    public function test_requires_more_than_compares_correctly()
    {
        $two = new ProfessionalAllocation(2, []);
        $three = new ProfessionalAllocation(3, []);

        $this->assertTrue($three->requiresMoreThan($two));
        $this->assertFalse($two->requiresMoreThan($three));
    }

    /**
     * @test
     */
    public function test_to_array_serializes_correctly()
    {
        $reasoning = ['Razão 1', 'Razão 2'];
        $allocation = new ProfessionalAllocation(3, $reasoning, 5);

        $array = $allocation->toArray();

        $this->assertEquals(3, $array['required_count']);
        $this->assertEquals($reasoning, $array['reasoning']);
        $this->assertEquals(5, $array['max_allowed']);
    }

    /**
     * @test
     */
    public function test_to_string_returns_display_string()
    {
        $allocation = ProfessionalAllocation::multiple(2);

        $this->assertEquals('2 profissionais', (string) $allocation);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function test_required_count_below_minimum_throws_exception()
    {
        new ProfessionalAllocation(0, []); // Min = 1
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function test_required_count_exceeds_max_throws_exception()
    {
        new ProfessionalAllocation(10, [], 5); // 10 > max 5
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function test_max_allowed_below_minimum_throws_exception()
    {
        new ProfessionalAllocation(1, [], 0); // Max < 1 inválido
    }

    /**
     * @test
     */
    public function test_immutability_preserved()
    {
        $original = new ProfessionalAllocation(2, ['Original reason']);

        $modified1 = $original->withReason('New reason');
        $modified2 = $original->withCount(3);

        // Original permanece inalterado
        $this->assertEquals(2, $original->getRequiredCount());
        $this->assertCount(1, $original->getReasoning());

        // Novas instâncias têm mudanças
        $this->assertCount(2, $modified1->getReasoning());
        $this->assertEquals(3, $modified2->getRequiredCount());
    }

    /**
     * @test
     */
    public function test_custom_max_allowed_respected()
    {
        $allocation = new ProfessionalAllocation(3, [], 10);

        $this->assertEquals(10, $allocation->getMaxAllowed());
        $this->assertTrue($allocation->canAddMore(7)); // 3 + 7 = 10
        $this->assertFalse($allocation->canAddMore(8)); // 3 + 8 = 11 > 10
    }
}
