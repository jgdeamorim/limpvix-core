<?php
/**
 * PackageTest - Testes Unitários para Package Value Object
 *
 * @package LimpVix\Tests\Domain\Briefing
 * @since 0.5.0
 */

namespace LimpVix\Tests\Domain\Briefing;

use PHPUnit\Framework\TestCase;
use LimpVix\Domain\Briefing\Package;
use LimpVix\Domain\Briefing\PackageType;

class PackageTest extends TestCase
{
    /**
     * @test
     */
    public function test_basic_package_factory_creates_correctly()
    {
        $package = Package::basic();

        $this->assertEquals('basic', $package->getType()->getValue());
        $this->assertEquals(0.0, $package->getPercentageIncrease());
        $this->assertEquals(1, $package->getMinProfessionals());
        $this->assertEquals(1, $package->getMaxProfessionals());
        $this->assertFalse($package->requiresMultipleProfessionals());
    }

    /**
     * @test
     */
    public function test_standard_package_factory_creates_correctly()
    {
        $package = Package::standard();

        $this->assertEquals('standard', $package->getType()->getValue());
        $this->assertEquals(0.15, $package->getPercentageIncrease());
        $this->assertEquals(1, $package->getMinProfessionals());
        $this->assertEquals(2, $package->getMaxProfessionals());
    }

    /**
     * @test
     */
    public function test_premium_package_factory_creates_correctly()
    {
        $package = Package::premium();

        $this->assertEquals('premium', $package->getType()->getValue());
        $this->assertEquals(0.30, $package->getPercentageIncrease());
        $this->assertEquals(2, $package->getMinProfessionals());
        $this->assertEquals(3, $package->getMaxProfessionals());
        $this->assertTrue($package->requiresMultipleProfessionals());
    }

    /**
     * @test
     */
    public function test_calculates_final_price_correctly()
    {
        $basePrice = 1000.0;

        $basic = Package::basic();
        $this->assertEquals(1000.0, $basic->calculateFinalPrice($basePrice));

        $standard = Package::standard();
        $this->assertEquals(1150.0, $standard->calculateFinalPrice($basePrice));

        $premium = Package::premium();
        $this->assertEquals(1300.0, $premium->calculateFinalPrice($basePrice));
    }

    /**
     * @test
     */
    public function test_determines_professionals_count_based_on_duration()
    {
        $package = Package::premium();

        // 3 horas (180 min) -> 1 profissional (dentro do max = 3)
        $this->assertEquals(1, $package->determineProfessionalsCount(180));

        // 6 horas (360 min) -> 2 profissionais (dentro do max = 3)
        $this->assertEquals(2, $package->determineProfessionalsCount(360));

        // 10 horas (600 min) -> 3 profissionais (capped at max)
        $this->assertEquals(3, $package->determineProfessionalsCount(600));
    }

    /**
     * @test
     */
    public function test_basic_package_caps_at_max_professionals()
    {
        $package = Package::basic();

        // Basic max = 1, mesmo com duração longa
        $this->assertEquals(1, $package->determineProfessionalsCount(600));
    }

    /**
     * @test
     */
    public function test_has_skill_checks_correctly()
    {
        $package = Package::premium();

        $this->assertTrue($package->hasSkill('limpeza_completa'));
        $this->assertTrue($package->hasSkill('organizacao'));
        $this->assertFalse($package->hasSkill('pos_obra'));
    }

    /**
     * @test
     */
    public function test_percentage_display_formats_correctly()
    {
        $basic = Package::basic();
        $this->assertEquals('0%', $basic->getPercentageDisplay());

        $standard = Package::standard();
        $this->assertEquals('+15%', $standard->getPercentageDisplay());

        $premium = Package::premium();
        $this->assertEquals('+30%', $premium->getPercentageDisplay());
    }

    /**
     * @test
     */
    public function test_to_array_serializes_correctly()
    {
        $package = Package::standard();
        $array = $package->toArray();

        $this->assertEquals('standard', $array['type']);
        $this->assertEquals(0.15, $array['percentage_increase']);
        $this->assertEquals(1, $array['min_professionals']);
        $this->assertEquals(2, $array['max_professionals']);
        $this->assertIsArray($array['required_skills']);
    }

    /**
     * @test
     */
    public function test_from_config_creates_package_correctly()
    {
        $config = [
            'type' => 'custom',
            'percentage_increase' => 0.20,
            'min_professionals' => 1,
            'max_professionals' => 4,
            'required_skills' => ['limpeza_basica', 'organizacao']
        ];

        $package = Package::fromConfig($config);

        $this->assertEquals('custom', $package->getType()->getValue());
        $this->assertEquals(0.20, $package->getPercentageIncrease());
        $this->assertEquals(1, $package->getMinProfessionals());
        $this->assertEquals(4, $package->getMaxProfessionals());
        $this->assertTrue($package->hasSkill('limpeza_basica'));
        $this->assertTrue($package->hasSkill('organizacao'));
    }

    /**
     * @test
     */
    public function test_equals_compares_packages_correctly()
    {
        $basic1 = Package::basic();
        $basic2 = Package::basic();
        $premium = Package::premium();

        $this->assertTrue($basic1->equals($basic2));
        $this->assertFalse($basic1->equals($premium));
    }

    /**
     * @test
     */
    public function test_get_multiplier_returns_correct_value()
    {
        $basic = Package::basic();
        $this->assertEquals(1.0, $basic->getMultiplier());

        $standard = Package::standard();
        $this->assertEquals(1.15, $standard->getMultiplier());

        $premium = Package::premium();
        $this->assertEquals(1.30, $premium->getMultiplier());
    }

    /**
     * @test
     */
    public function test_package_type_is_immutable()
    {
        $package = Package::standard();
        $type = $package->getType();

        // Não deve ser possível modificar o tipo
        $this->assertEquals('standard', $type->getValue());
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function test_invalid_percentage_throws_exception()
    {
        $config = [
            'type' => 'invalid',
            'percentage_increase' => -0.10, // Negativo inválido
            'min_professionals' => 1,
            'max_professionals' => 2,
            'required_skills' => []
        ];

        Package::fromConfig($config);
    }

    /**
     * @test
     * @expectedException \InvalidArgumentException
     */
    public function test_min_greater_than_max_professionals_throws_exception()
    {
        $config = [
            'type' => 'invalid',
            'percentage_increase' => 0.10,
            'min_professionals' => 3,
            'max_professionals' => 2, // Min > Max inválido
            'required_skills' => []
        ];

        Package::fromConfig($config);
    }
}
