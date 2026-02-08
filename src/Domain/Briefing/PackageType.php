<?php
/**
 * PackageType - Value Object (Enum)
 *
 * RESPONSABILIDADE:
 * - Representar tipos de pacotes de serviço
 * - Garantir valores válidos (basic, standard, premium)
 * - Enum-like behavior (PHP 7.4 compatible)
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Type-safe (validação em construção)
 * - Self-documenting
 *
 * PACOTES:
 * - BASIC: Limpeza básica, 1 profissional, preço base (100%)
 * - STANDARD: Limpeza completa, 1-2 profissionais, +15% no preço
 * - PREMIUM: Limpeza pesada/pós-obra, 2-3 profissionais, +30% no preço
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class PackageType
{
    private const BASIC = 'basic';
    private const STANDARD = 'standard';
    private const PREMIUM = 'premium';

    private const VALID_TYPES = [
        self::BASIC,
        self::STANDARD,
        self::PREMIUM
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
        if (!in_array($value, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("Package type inválido: {$value}");
        }

        $this->value = $value;
    }

    /**
     * Factory: Basic package
     *
     * @return self
     */
    public static function basic(): self
    {
        return new self(self::BASIC);
    }

    /**
     * Factory: Standard package
     *
     * @return self
     */
    public static function standard(): self
    {
        return new self(self::STANDARD);
    }

    /**
     * Factory: Premium package
     *
     * @return self
     */
    public static function premium(): self
    {
        return new self(self::PREMIUM);
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
     * Verificar se é basic
     *
     * @return bool
     */
    public function isBasic(): bool
    {
        return $this->value === self::BASIC;
    }

    /**
     * Verificar se é standard
     *
     * @return bool
     */
    public function isStandard(): bool
    {
        return $this->value === self::STANDARD;
    }

    /**
     * Verificar se é premium
     *
     * @return bool
     */
    public function isPremium(): bool
    {
        return $this->value === self::PREMIUM;
    }

    /**
     * Get display name
     *
     * @return string
     */
    public function getDisplayName(): string
    {
        $names = [
            self::BASIC => 'Básico',
            self::STANDARD => 'Padrão',
            self::PREMIUM => 'Premium'
        ];

        return $names[$this->value];
    }

    /**
     * Comparar com outro PackageType
     *
     * @param PackageType $other
     * @return bool
     */
    public function equals(PackageType $other): bool
    {
        return $this->value === $other->value;
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
     * Listar todos os tipos válidos
     *
     * @return string[]
     */
    public static function all(): array
    {
        return self::VALID_TYPES;
    }
}
