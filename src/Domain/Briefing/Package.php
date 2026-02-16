<?php
/**
 * Package - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar um pacote de serviço completo
 * - Encapsular regras de pricing (percentage increase)
 * - Determinar profissionais necessários
 * - Skills requeridas por pacote
 *
 * PRINCÍPIOS:
 * - Value Object (imutável)
 * - Self-contained (todas regras dentro do objeto)
 * - Business logic encapsulation
 *
 * REGRAS DE NEGÓCIO:
 * - Basic: +0%, 1 profissional, limpeza_basica
 * - Standard: +15%, 1-2 profissionais, limpeza_completa
 * - Premium: +30%, 2-3 profissionais, limpeza_pesada + skills especiais
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.4.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class Package
{
    /**
     * @var PackageType
     */
    private $type;

    /**
     * @var float Percentage increase (0.0 = +0%, 0.15 = +15%, 0.30 = +30%)
     */
    private $percentageIncrease;

    /**
     * @var int Minimum professionals required
     */
    private $minProfessionals;

    /**
     * @var int Maximum professionals allowed
     */
    private $maxProfessionals;

    /**
     * @var string[] Required skills
     */
    private $requiredSkills;

    /**
     * @var string|null Optional description
     */
    private $description;

    /**
     * Construtor
     *
     * @param PackageType $type
     * @param float $percentageIncrease
     * @param int $minProfessionals
     * @param int $maxProfessionals
     * @param string[] $requiredSkills
     * @param string|null $description
     * @throws \InvalidArgumentException
     */
    public function __construct(
        PackageType $type,
        float $percentageIncrease,
        int $minProfessionals,
        int $maxProfessionals,
        array $requiredSkills,
        ?string $description = null
    ) {
        // Validações
        if ($percentageIncrease < 0.0 || $percentageIncrease > 2.0) {
            throw new \InvalidArgumentException("Percentage increase deve estar entre 0.0 e 2.0");
        }

        if ($minProfessionals < 1 || $maxProfessionals < $minProfessionals) {
            throw new \InvalidArgumentException("Min/Max profissionais inválidos");
        }

        if (empty($requiredSkills)) {
            throw new \InvalidArgumentException("Required skills não pode ser vazio");
        }

        $this->type = $type;
        $this->percentageIncrease = $percentageIncrease;
        $this->minProfessionals = $minProfessionals;
        $this->maxProfessionals = $maxProfessionals;
        $this->requiredSkills = $requiredSkills;
        $this->description = $description;
    }

    /**
     * Factory: Basic package (default)
     *
     * @return self
     */
    public static function basic(): self
    {
        return new self(
            type: PackageType::basic(),
            percentageIncrease: 0.0,
            minProfessionals: 1,
            maxProfessionals: 1,
            requiredSkills: ['limpeza_basica'],
            description: 'Limpeza básica padrão. Ideal para manutenção regular.'
        );
    }

    /**
     * Factory: Standard package
     *
     * @return self
     */
    public static function standard(): self
    {
        return new self(
            type: PackageType::standard(),
            percentageIncrease: 0.15,
            minProfessionals: 1,
            maxProfessionals: 2,
            requiredSkills: ['limpeza_basica', 'limpeza_completa'],
            description: 'Limpeza completa com detalhes. +15% no preço.'
        );
    }

    /**
     * Factory: Premium package
     *
     * @return self
     */
    public static function premium(): self
    {
        return new self(
            type: PackageType::premium(),
            percentageIncrease: 0.30,
            minProfessionals: 2,
            maxProfessionals: 3,
            requiredSkills: ['limpeza_basica', 'limpeza_completa', 'limpeza_pesada', 'pos_obra'],
            description: 'Limpeza pesada/pós-obra. Múltiplos profissionais. +30% no preço.'
        );
    }

    /**
     * Factory: From configuration array
     *
     * @param array $config
     * @return self
     * @throws \InvalidArgumentException
     */
    public static function fromConfig(array $config): self
    {
        return new self(
            type: PackageType::fromString($config['type']),
            percentageIncrease: (float) $config['percentage_increase'],
            minProfessionals: (int) $config['min_professionals'],
            maxProfessionals: (int) $config['max_professionals'],
            requiredSkills: $config['required_skills'],
            description: $config['description'] ?? null
        );
    }

    // ==================== GETTERS ====================

    public function getType(): PackageType
    {
        return $this->type;
    }

    public function getPercentageIncrease(): float
    {
        return $this->percentageIncrease;
    }

    public function getMinProfessionals(): int
    {
        return $this->minProfessionals;
    }

    public function getMaxProfessionals(): int
    {
        return $this->maxProfessionals;
    }

    public function getRequiredSkills(): array
    {
        return $this->requiredSkills;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Calcular preço total baseado em preço base
     *
     * @param float $basePrice Preço base
     * @return float Preço final com percentage increase
     */
    public function calculateFinalPrice(float $basePrice): float
    {
        return $basePrice * (1.0 + $this->percentageIncrease);
    }

    /**
     * Get percentage increase como porcentagem legível
     *
     * @return string Ex: "+15%"
     */
    public function getPercentageDisplay(): string
    {
        $percentage = $this->percentageIncrease * 100;
        return $percentage > 0 ? "+{$percentage}%" : "0%";
    }

    /**
     * Get multiplier (1.0 + percentage_increase)
     *
     * @return float Ex: 1.15 para standard
     */
    public function getMultiplier(): float
    {
        return 1.0 + $this->percentageIncrease;
    }

    /**
     * Verificar se skill específica é requerida
     *
     * @param string $skill
     * @return bool
     */
    public function requiresSkill(string $skill): bool
    {
        return in_array($skill, $this->requiredSkills, true);
    }

    /**
     * Determinar número de profissionais necessários baseado em duração
     *
     * @param int $estimatedDurationMinutes Duração estimada
     * @return int Número de profissionais (dentro de min/max)
     */
    public function determineProfessionalsCount(int $estimatedDurationMinutes): int
    {
        // Regra: > 5h (300min) = 2+ profissionais
        if ($estimatedDurationMinutes > 300) {
            $calculated = (int) ceil($estimatedDurationMinutes / 300);
            return min($calculated, $this->maxProfessionals);
        }

        return $this->minProfessionals;
    }

    /**
     * Comparar com outro Package
     *
     * @param Package $other
     * @return bool
     */
    public function equals(Package $other): bool
    {
        return $this->type->equals($other->type);
    }

    /**
     * Converter para array (para persistência)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type->getValue(),
            'percentage_increase' => $this->percentageIncrease,
            'min_professionals' => $this->minProfessionals,
            'max_professionals' => $this->maxProfessionals,
            'required_skills' => $this->requiredSkills,
            'description' => $this->description
        ];
    }

    /**
     * To string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->type->getDisplayName();
    }
}
