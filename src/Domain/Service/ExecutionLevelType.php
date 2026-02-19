<?php
/**
 * ExecutionLevelType - Value Object (Enum)
 *
 * RESPONSABILIDADE:
 * - Representar tipos de niveis de execucao
 * - Garantir valores validos (basic_execution, standard_execution, premium_execution)
 * - PHP 7.4 compatible enum-like behavior
 *
 * PRINCIPIOS:
 * - Value Object (imutavel)
 * - Type-safe (validacao em construcao)
 *
 * NIVEIS:
 * - BASIC_EXECUTION: 1 profissional, checklist basico, sem garantia
 * - STANDARD_EXECUTION: 1-2 profissionais, checklist detalhado, garantia 12h
 * - PREMIUM_EXECUTION: 2-3 profissionais, checklist completo, garantia 24h
 *
 * @package LimpVix\Domain\Service
 * @since 0.5.0
 */

namespace LimpVix\Domain\Service;

defined('ABSPATH') || exit;

class ExecutionLevelType
{
    private const BASIC = 'basic_execution';
    private const STANDARD = 'standard_execution';
    private const PREMIUM = 'premium_execution';

    private const VALID_TYPES = [
        self::BASIC,
        self::STANDARD,
        self::PREMIUM,
    ];

    /**
     * @var string
     */
    private $value;

    /**
     * Construtor privado
     *
     * @param string $value
     * @throws \InvalidArgumentException
     */
    private function __construct(string $value)
    {
        if (!in_array($value, self::VALID_TYPES, true)) {
            throw new \InvalidArgumentException("ExecutionLevelType invalido: {$value}");
        }

        $this->value = $value;
    }

    // ==================== FACTORIES ====================

    public static function basicExecution(): self
    {
        return new self(self::BASIC);
    }

    public static function standardExecution(): self
    {
        return new self(self::STANDARD);
    }

    public static function premiumExecution(): self
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

    // ==================== GETTERS ====================

    public function getValue(): string
    {
        return $this->value;
    }

    public function isBasic(): bool
    {
        return $this->value === self::BASIC;
    }

    public function isStandard(): bool
    {
        return $this->value === self::STANDARD;
    }

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
            self::BASIC => 'Execucao Basica',
            self::STANDARD => 'Execucao Padrao',
            self::PREMIUM => 'Execucao Premium',
        ];

        return $names[$this->value];
    }

    /**
     * Map to legacy package type slug
     *
     * @return string basic|standard|premium
     */
    public function toLegacyPackageType(): string
    {
        $map = [
            self::BASIC => 'basic',
            self::STANDARD => 'standard',
            self::PREMIUM => 'premium',
        ];

        return $map[$this->value];
    }

    /**
     * Factory: From legacy package type
     *
     * @param string $legacyType basic|standard|premium
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromLegacyPackageType(string $legacyType): self
    {
        $map = [
            'basic' => self::BASIC,
            'standard' => self::STANDARD,
            'premium' => self::PREMIUM,
        ];

        if (!isset($map[$legacyType])) {
            throw new \InvalidArgumentException("Legacy package type invalido: {$legacyType}");
        }

        return new self($map[$legacyType]);
    }

    // ==================== COMPARISON ====================

    public function equals(ExecutionLevelType $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    /**
     * Listar todos os tipos validos
     *
     * @return string[]
     */
    public static function all(): array
    {
        return self::VALID_TYPES;
    }
}
