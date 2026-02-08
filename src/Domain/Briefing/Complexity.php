<?php
/**
 * Complexity - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar complexidade completa de um serviço
 * - Encapsular level + multiplier + reasoning
 * - Fornecer métodos de cálculo e validação
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Self-contained (todas regras dentro do objeto)
 * - Business logic encapsulation
 *
 * COMPOSIÇÃO:
 * - level: ComplexityLevel (simple, medium, complex)
 * - multiplier: float (1.0, 1.3, 1.5 ou custom)
 * - reasoning: string[] (motivos da complexidade)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class Complexity
{
    /**
     * @var ComplexityLevel
     */
    private $level;

    /**
     * @var float Time multiplier (1.0 = base, 1.3 = +30%, 1.5 = +50%)
     */
    private $multiplier;

    /**
     * @var string[] Reasoning for this complexity level
     */
    private $reasoning;

    /**
     * Construtor
     *
     * @param ComplexityLevel $level
     * @param float $multiplier
     * @param string[] $reasoning
     * @throws \InvalidArgumentException
     */
    public function __construct(
        ComplexityLevel $level,
        float $multiplier,
        array $reasoning = []
    ) {
        // Validações
        if ($multiplier < 1.0 || $multiplier > 3.0) {
            throw new \InvalidArgumentException("Multiplier deve estar entre 1.0 e 3.0");
        }

        $this->level = $level;
        $this->multiplier = $multiplier;
        $this->reasoning = $reasoning;
    }

    /**
     * Factory: Simple complexity (default)
     *
     * @return self
     */
    public static function simple(): self
    {
        return new self(
            level: ComplexityLevel::simple(),
            multiplier: 1.0,
            reasoning: ['Limpeza básica padrão']
        );
    }

    /**
     * Factory: Medium complexity
     *
     * @return self
     */
    public static function medium(): self
    {
        return new self(
            level: ComplexityLevel::medium(),
            multiplier: 1.3,
            reasoning: ['Limpeza completa com detalhes']
        );
    }

    /**
     * Factory: Complex
     *
     * @return self
     */
    public static function complex(): self
    {
        return new self(
            level: ComplexityLevel::complex(),
            multiplier: 1.5,
            reasoning: ['Limpeza pesada ou pós-obra']
        );
    }

    /**
     * Factory: From configuration
     *
     * @param array $config
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            level: ComplexityLevel::fromString($config['level']),
            multiplier: (float) $config['multiplier'],
            reasoning: $config['reasoning'] ?? []
        );
    }

    // ==================== GETTERS ====================

    public function getLevel(): ComplexityLevel
    {
        return $this->level;
    }

    public function getMultiplier(): float
    {
        return $this->multiplier;
    }

    public function getReasoning(): array
    {
        return $this->reasoning;
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Aplicar multiplier a uma duração
     *
     * @param int $baseDurationMinutes
     * @return int Duration with multiplier applied
     */
    public function applyToDuration(int $baseDurationMinutes): int
    {
        return (int) ceil($baseDurationMinutes * $this->multiplier);
    }

    /**
     * Aplicar multiplier a um preço
     *
     * @param float $basePrice
     * @return float Price with multiplier applied
     */
    public function applyToPrice(float $basePrice): float
    {
        return $basePrice * $this->multiplier;
    }

    /**
     * Get multiplier como percentual legível
     *
     * @return string Ex: "+30%"
     */
    public function getMultiplierDisplay(): string
    {
        $percentage = ($this->multiplier - 1.0) * 100;
        return $percentage > 0 ? "+" . number_format($percentage, 0) . "%" : "0%";
    }

    /**
     * Verificar se requer atenção especial
     *
     * @return bool
     */
    public function requiresSpecialAttention(): bool
    {
        return $this->level->isComplex() || $this->multiplier >= 1.5;
    }

    /**
     * Adicionar reasoning
     *
     * @param string $reason
     * @return self Nova instância com reasoning atualizado
     */
    public function withReason(string $reason): self
    {
        $newReasoning = $this->reasoning;
        $newReasoning[] = $reason;

        return new self($this->level, $this->multiplier, $newReasoning);
    }

    /**
     * Comparar com outra Complexity
     *
     * @param Complexity $other
     * @return bool
     */
    public function equals(Complexity $other): bool
    {
        return $this->level->equals($other->level)
            && abs($this->multiplier - $other->multiplier) < 0.01;
    }

    /**
     * Verificar se é mais complexo que outro
     *
     * @param Complexity $other
     * @return bool
     */
    public function isMoreComplexThan(Complexity $other): bool
    {
        return $this->multiplier > $other->multiplier;
    }

    /**
     * Converter para array (para persistência)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'level' => $this->level->getValue(),
            'multiplier' => $this->multiplier,
            'reasoning' => $this->reasoning
        ];
    }

    /**
     * To string
     *
     * @return string
     */
    public function __toString(): string
    {
        return "{$this->level->getDisplayName()} ({$this->getMultiplierDisplay()})";
    }
}
