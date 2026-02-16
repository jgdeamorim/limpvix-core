<?php
/**
 * ComplexityTest - Testes Unitários para Complexity Value Object
 *
 * @package LimpVix\Tests\Domain\Briefing
 * @since 0.5.0
 */

namespace LimpVix\Tests\Domain\Briefing;

use PHPUnit\Framework\TestCase;
use LimpVix\Domain\Briefing\Complexity;
use LimpVix\Domain\Briefing\ComplexityLevel;

class ComplexityTest extends TestCase
{
    /**
     * @test
     */
    public function test_simple_complexity_factory_creates_correctly()
    {
        $complexity = Complexity::simple();

        $this->assertEquals('simple', $complexity->getLevel()->getValue());
        $this->assertEquals(1.0, $complexity->getMultiplier());
        $this->assertFalse($complexity->isComplex());
        $this->assertTrue($complexity->isSimple());
    }

    /**
     * @test
     */
    public function test_medium_complexity_factory_creates_correctly()
    {
        $complexity = Complexity::medium();

        $this->assertEquals('medium', $complexity->getLevel()->getValue());
        $this->assertEquals(1.3, $complexity->getMultiplier());
        $this->assertFalse($complexity->isSimple());
        $this->assertFalse($complexity->isComplex());
    }

    /**
     * @test
     */
    public function test_complex_complexity_factory_creates_correctly()
    {
        $complexity = Complexity::complex();

        $this->assertEquals('complex', $complexity->getLevel()->getValue());
        $this->assertEquals(1.5, $complexity->getMultiplier());
        $this->assertTrue($complexity->isComplex());
        $this->assertFalse($complexity->isSimple());
    }

    /**
     * @test
     */
    public function test_applies_multiplier_to_duration_correctly()
    {
        $baseDuration = 120; // 2 horas

        $simple = Complexity::simple();
        $this->assertEquals(120, $simple->applyToDuration($baseDuration));

        $medium = Complexity::medium();
        $this->assertEquals(156, $medium->applyToDuration($baseDuration)); // 120 * 1.3 = 156

        $complex = Complexity::complex();
        $this->assertEquals(180, $complex->applyToDuration($baseDuration)); // 120 * 1.5 = 180
    }

    /**
     * @test
     */
    public function test_applies_multiplier_to_price_correctly()
    {
        $basePrice = 1000.0;

        $simple = Complexity::simple();
        $this->assertEquals(1000.0, $simple->applyToPrice($basePrice));

        $medium = Complexity::medium();
        $this->assertEquals(1300.0, $medium->applyToPrice($basePrice));

        $complex = Complexity::complex();
        $this->assertEquals(1500.0, $complex->applyToPrice($basePrice));
    }

    /**
     * @test
     */
    public function test_with_reasoning_creates_new_instance()
    {
        $complexity = Complexity::simple();
        $original = $complexity->getReasoning();

        $withReason = $complexity->withReasoning(['Área pequena', 'Limpeza rápida']);

        // Original não muda (imutabilidade)
        $this->assertEmpty($original);

        // Nova instância tem reasoning
        $newReasoning = $withReason->getReasoning();
        $this->assertCount(2, $newReasoning);
        $this->assertContains('Área pequena', $newReasoning);
    }

    /**
     * @test
     */
    public function test_custom_complexity_with_reasoning()
    {
        $level = ComplexityLevel::complex();
        $multiplier = 1.8;
        $reasoning = [
            'Área muito grande (250m²)',
            'Limpeza pós-obra',
            'Requer múltiplos profissionais'
        ];

        $complexity = new Complexity($level, $multiplier, $reasoning);

        $this->assertEquals(1.8, $complexity->getMultiplier());
        $this->assertCount(3, $complexity->getReasoning());
        $this->assertTrue($complexity->isComplex());
    }

    /**
     * @test
     */
    public function test_equals_compares_complexity_correctly()
    {
        $simple1 = Complexity::simple();
        $simple2 = Complexity::simple();
        $medium = Complexity::medium();

        $this->assertTrue($simple1->equals($simple2));
        $this->assertFalse($simple1->equals($medium));
    }

    /**
     * @test
     */
    public function test_is_more_complex_than_compares_correctly()
    {
        $simple = Complexity::simple();
        $medium = Complexity::medium();
        $complex = Complexity::complex();

        $this->assertTrue($medium->isMoreComplexThan($simple));
        $this->assertTrue($complex->isMoreComplexThan($medium));
        $this->assertFalse($simple->isMoreComplexThan($complex));
    }

    /**
     * @test
     */
    public function test_to_array_serializes_correctly()
    {
        $reasoning = ['Razão 1', 'Razão 2'];
        $complexity = Complexity::medium()->withReasoning($reasoning);

        $array = $complexity->toArray();

        $this->assertEquals('medium', $array['level']);
        $this->assertEquals(1.3, $array['multiplier']);
        $this->assertEquals($reasoning, $array['reasoning']);
    }

    /**
     * @test
     */
    public function test_display_string_formats_correctly()
    {
        $simple = Complexity::simple();
        $this->assertStringContainsString('Simple', $simple->getDisplayString());
        $this->assertStringContainsString('1.0x', $simple->getDisplayString());

        $medium = Complexity::medium();
        $this->assertStringContainsString('Medium', $medium->getDisplayString());
        $this->assertStringContainsString('1.3x', $medium->getDisplayString());

        $complex = Complexity::complex();
        $this->assertStringContainsString('Complex', $complex->getDisplayString());
        $this->assertStringContainsString('1.5x', $complex->getDisplayString());
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function test_invalid_multiplier_throws_exception()
    {
        $level = ComplexityLevel::simple();
        new Complexity($level, 0.5, []); // Multiplier < 1.0 inválido
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function test_multiplier_too_high_throws_exception()
    {
        $level = ComplexityLevel::complex();
        new Complexity($level, 3.0, []); // Multiplier > 2.5 inválido
    }

    /**
     * @test
     */
    public function test_complexity_level_has_correct_default_multipliers()
    {
        $simple = ComplexityLevel::simple();
        $this->assertEquals(1.0, $simple->getDefaultMultiplier());

        $medium = ComplexityLevel::medium();
        $this->assertEquals(1.3, $medium->getDefaultMultiplier());

        $complex = ComplexityLevel::complex();
        $this->assertEquals(1.5, $complex->getDefaultMultiplier());
    }

    /**
     * @test
     */
    public function test_reasoning_is_immutable()
    {
        $complexity = Complexity::simple();
        $withReason = $complexity->withReasoning(['Reason 1']);

        // Original não muda
        $this->assertEmpty($complexity->getReasoning());

        // Nova instância tem reasoning
        $this->assertCount(1, $withReason->getReasoning());
    }
}
