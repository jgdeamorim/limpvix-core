<?php
/**
 * ProfessionalAllocation - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar alocação de profissional(is) para um Briefing
 * - Encapsular quantidade necessária + reasoning
 * - Validar limites (min 1, max configurável)
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Self-validating
 * - Business rules encapsulation
 *
 * COMPOSIÇÃO:
 * - requiredCount: int (quantidade de profissionais necessários)
 * - reasoning: string[] (motivos da alocação)
 * - maxAllowed: int (limite máximo configurável)
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class ProfessionalAllocation
{
    private const MIN_PROFESSIONALS = 1;
    private const DEFAULT_MAX_PROFESSIONALS = 5;

    /**
     * @var int Quantidade de profissionais necessários
     */
    private $requiredCount;

    /**
     * @var string[] Reasoning (justificativas)
     */
    private $reasoning;

    /**
     * @var int Máximo permitido
     */
    private $maxAllowed;

    /**
     * Construtor
     *
     * @param int $requiredCount
     * @param string[] $reasoning
     * @param int $maxAllowed
     * @throws \InvalidArgumentException
     */
    public function __construct(
        int $requiredCount,
        array $reasoning = [],
        int $maxAllowed = self::DEFAULT_MAX_PROFESSIONALS
    ) {
        // Validações
        if ($requiredCount < self::MIN_PROFESSIONALS) {
            throw new \InvalidArgumentException(
                "Required count deve ser no mínimo " . self::MIN_PROFESSIONALS
            );
        }

        if ($requiredCount > $maxAllowed) {
            throw new \InvalidArgumentException(
                "Required count ({$requiredCount}) excede o máximo permitido ({$maxAllowed})"
            );
        }

        if ($maxAllowed < self::MIN_PROFESSIONALS) {
            throw new \InvalidArgumentException("Max allowed deve ser no mínimo 1");
        }

        $this->requiredCount = $requiredCount;
        $this->reasoning = $reasoning;
        $this->maxAllowed = $maxAllowed;
    }

    /**
     * Factory: Single professional (default)
     *
     * @return self
     */
    public static function single(): self
    {
        return new self(1, ['Serviço padrão - 1 profissional']);
    }

    /**
     * Factory: Multiple professionals
     *
     * @param int $count
     * @param string[] $reasoning
     * @return self
     */
    public static function multiple(int $count, array $reasoning = []): self
    {
        return new self($count, $reasoning);
    }

    /**
     * Factory: From configuration
     *
     * @param array $config
     * @return self
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            (int) $config['required_count'],
            $config['reasoning'] ?? [],
            $config['max_allowed'] ?? self::DEFAULT_MAX_PROFESSIONALS
        );
    }

    // ==================== GETTERS ====================

    public function getRequiredCount(): int
    {
        return $this->requiredCount;
    }

    public function getReasoning(): array
    {
        return $this->reasoning;
    }

    public function getMaxAllowed(): int
    {
        return $this->maxAllowed;
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Verificar se requer múltiplos profissionais
     *
     * @return bool
     */
    public function requiresMultiple(): bool
    {
        return $this->requiredCount > 1;
    }

    /**
     * Verificar se está no limite máximo
     *
     * @return bool
     */
    public function isAtMaxCapacity(): bool
    {
        return $this->requiredCount >= $this->maxAllowed;
    }

    /**
     * Verificar se pode adicionar mais profissionais
     *
     * @param int $additional
     * @return bool
     */
    public function canAddMore(int $additional = 1): bool
    {
        return ($this->requiredCount + $additional) <= $this->maxAllowed;
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

        return new self($this->requiredCount, $newReasoning, $this->maxAllowed);
    }

    /**
     * Criar nova instância com count ajustado (capped at max)
     *
     * @param int $newCount
     * @return self
     */
    public function withCount(int $newCount): self
    {
        $cappedCount = min($newCount, $this->maxAllowed);
        $cappedCount = max($cappedCount, self::MIN_PROFESSIONALS);

        return new self($cappedCount, $this->reasoning, $this->maxAllowed);
    }

    /**
     * Get display string
     *
     * @return string
     */
    public function getDisplayString(): string
    {
        if ($this->requiredCount === 1) {
            return "1 profissional";
        }

        return "{$this->requiredCount} profissionais";
    }

    /**
     * Comparar com outra alocação
     *
     * @param ProfessionalAllocation $other
     * @return bool
     */
    public function equals(ProfessionalAllocation $other): bool
    {
        return $this->requiredCount === $other->requiredCount;
    }

    /**
     * Verificar se requer mais profissionais que outro
     *
     * @param ProfessionalAllocation $other
     * @return bool
     */
    public function requiresMoreThan(ProfessionalAllocation $other): bool
    {
        return $this->requiredCount > $other->requiredCount;
    }

    /**
     * Converter para array (para persistência)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'required_count' => $this->requiredCount,
            'reasoning' => $this->reasoning,
            'max_allowed' => $this->maxAllowed
        ];
    }

    /**
     * To string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->getDisplayString();
    }
}
