<?php
/**
 * PropertyType - Value Object
 *
 * Representa o tipo de propriedade do Briefing.
 *
 * Tipos:
 * - residential: Residencial (casa, apartamento)
 * - commercial: Comercial (escritório, loja)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class PropertyType
{
    /**
     * Tipos possíveis
     */
    private const RESIDENTIAL = 'residential';
    private const COMMERCIAL = 'commercial';

    /**
     * Todos os tipos válidos
     */
    private const VALID_TYPES = [
        self::RESIDENTIAL,
        self::COMMERCIAL
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
            throw new \InvalidArgumentException("Tipo de propriedade inválido: {$value}");
        }

        $this->value = $value;
    }

    /**
     * Factory: Residencial
     *
     * @return self
     */
    public static function residential(): self
    {
        return new self(self::RESIDENTIAL);
    }

    /**
     * Factory: Comercial
     *
     * @return self
     */
    public static function commercial(): self
    {
        return new self(self::COMMERCIAL);
    }

    /**
     * Factory: A partir de string
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
     * Obter valor
     *
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }

    /**
     * Verificar se é residencial
     *
     * @return bool
     */
    public function isResidential(): bool
    {
        return $this->value === self::RESIDENTIAL;
    }

    /**
     * Verificar se é comercial
     *
     * @return bool
     */
    public function isCommercial(): bool
    {
        return $this->value === self::COMMERCIAL;
    }

    /**
     * Comparar com outro tipo
     *
     * @param PropertyType $other
     * @return bool
     */
    public function equals(PropertyType $other): bool
    {
        return $this->value === $other->value;
    }

    /**
     * Representação em string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->value;
    }
}
