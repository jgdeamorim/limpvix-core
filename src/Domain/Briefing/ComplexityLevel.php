<?php
/**
 * ComplexityLevel - Value Object (Enum)
 *
 * RESPONSABILIDADE:
 * - Representar níveis de complexidade de serviço
 * - Garantir valores válidos (simple, medium, complex)
 * - Enum-like behavior (PHP 7.4 compatible)
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Type-safe (validação em construção)
 * - Self-documenting
 *
 * NÍVEIS:
 * - SIMPLE: Limpeza básica, < 80m², < 3h, multiplier 1.0x
 * - MEDIUM: Limpeza completa, 80-150m², 3-5h, multiplier 1.3x
 * - COMPLEX: Limpeza pesada/pós-obra, > 150m², > 5h, multiplier 1.5x
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class ComplexityLevel
{
    private const SIMPLE = 'simple';
    private const MEDIUM = 'medium';
    private const COMPLEX = 'complex';

    private const VALID_LEVELS = [
        self::SIMPLE,
        self::MEDIUM,
        self::COMPLEX
    ];

    /**
     * @var string
     */
    private $value;

    /**
     * Construtor
     *
     * @param string $value
     * @throws \InvalidArgumentException
     */
    private function __construct(string $value)
    {
        if (!in_array($value, self::VALID_LEVELS, true)) {
            throw new \InvalidArgumentException("Complexity level inválido: {$value}");
        }

        $this->value = $value;
    }

    /**
     * Factory: Simple level
     *
     * @return self
     */
    public static function simple(): self
    {
        return new self(self::SIMPLE);
    }

    /**
     * Factory: Medium level
     *
     * @return self
     */
    public static function medium(): self
    {
        return new self(self::MEDIUM);
    }

    /**
     * Factory: Complex level
     *
     * @return self
     */
    public static function complex(): self
    {
        return new self(self::COMPLEX);
    }

    /**
     * Factory: From string
     *
     * @param string $value
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromString(string $value): self
    {
        return new self($value);
    }

    /**
     * Get value
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Verificar se é simple
     *
     * @return bool
     */
    public function isSimple(): bool
    {
        return $this->value === self::SIMPLE;
    }

    /**
     * Verificar se é medium
     *
     * @return bool
     */
    public function isMedium(): bool
    {
        return $this->value === self::MEDIUM;
    }

    /**
     * Verificar se é complex
     *
     * @return bool
     */
    public function isComplex(): bool
    {
        return $this->value === self::COMPLEX;
    }

    /**
     * Get display name
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $names = [
            self::SIMPLE => 'Simples',
            self::MEDIUM => 'Média',
            self::COMPLEX => 'Complexa'
        ];

        return $names[$this->value];
    }

    /**
     * Get default multiplier for this level
     *
     * @return float
     */
    public function getDefaultMultiplier(): float
    {
        $multipliers = [
            self::SIMPLE => 1.0,
            self::MEDIUM => 1.3,
            self::COMPLEX => 1.5
        ];

        return $multipliers[$this->value];
    }

    /**
     * Comparar com outro ComplexityLevel
     *
     * @param ComplexityLevel $other
     * @return bool
     */
    public function equals(ComplexityLevel $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Comparar se é mais complexo que outro
     *
     * @param ComplexityLevel $other
     * @return bool
     */
    public function isMoreComplexThan(ComplexityLevel $other): bool
    {
        $order = [
            self::SIMPLE => 1,
            self::MEDIUM => 2,
            self::COMPLEX => 3
        ];

        return $order[$this->value] > $order[$other->value];
    }

    /**
     * To string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Listar todos os níveis válidos
     *
     * @return string[]
     */
    public static function all(): array
    {
        return self::VALID_LEVELS;
    }
}
