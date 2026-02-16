<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: ServiceComplexity
 *
 * Representa complexidade de um serviço e seus impactos.
 * Calculada a partir de: pacotes adicionais (teto, esquadrias, pós-obra)
 *
 * IMUTÁVEL.
 */
final class ServiceComplexity
{
    private const LEVEL_BASIC = 'basic';
    private const LEVEL_INTERMEDIATE = 'intermediate';
    private const LEVEL_ADVANCED = 'advanced';
    private const LEVEL_EXPERT = 'expert';

    private string $level;
    private array $requiredSkills;
    private float $timeMultiplier; // Multiplicador de tempo (1.0 = normal, 1.4 = +40%)
    private bool $requiresMultipleProfessionals;

    private function __construct(
        string $level,
        array $requiredSkills,
        float $timeMultiplier,
        bool $requiresMultipleProfessionals
    ) {
        $this->validateLevel($level);

        if ($timeMultiplier < 1.0) {
            throw new \InvalidArgumentException('Time multiplier cannot be less than 1.0');
        }

        $this->level = $level;
        $this->requiredSkills = $requiredSkills;
        $this->timeMultiplier = $timeMultiplier;
        $this->requiresMultipleProfessionals = $requiresMultipleProfessionals;
    }

    /**
     * Factory: Complexidade básica (limpeza padrão)
     */
    public static function basic(): self
    {
        return new self(
            self::LEVEL_BASIC,
            [ProfessionalSkills::SKILL_BASIC_CLEANING],
            1.0,
            false
        );
    }

    /**
     * Factory: Complexidade intermediária (com esquadrias OU teto)
     */
    public static function intermediate(array $requiredSkills = []): self
    {
        $skills = array_merge(
            [ProfessionalSkills::SKILL_BASIC_CLEANING],
            $requiredSkills
        );

        return new self(
            self::LEVEL_INTERMEDIATE,
            array_unique($skills),
            1.3, // +30% tempo
            false
        );
    }

    /**
     * Factory: Complexidade avançada (esquadrias + teto OU área >150m²)
     */
    public static function advanced(array $requiredSkills = []): self
    {
        $skills = array_merge(
            [ProfessionalSkills::SKILL_BASIC_CLEANING],
            $requiredSkills
        );

        return new self(
            self::LEVEL_ADVANCED,
            array_unique($skills),
            1.5, // +50% tempo
            false
        );
    }

    /**
     * Factory: Complexidade expert (pós-obra)
     * Sempre requer múltiplos profissionais
     */
    public static function expert(): self
    {
        return new self(
            self::LEVEL_EXPERT,
            [
                ProfessionalSkills::SKILL_BASIC_CLEANING,
                ProfessionalSkills::SKILL_POST_CONSTRUCTION,
            ],
            2.0, // Dobra o tempo
            true // Sempre múltiplos profissionais
        );
    }

    /**
     * Factory: A partir de pacotes adicionais do briefing
     *
     * @param bool $hasCeiling Tem limpeza de teto
     * @param bool $hasWindows Tem esquadrias/vidros
     * @param bool $isPostConstruction É pós-obra
     */
    public static function fromPackages(
        bool $hasCeiling,
        bool $hasWindows,
        bool $isPostConstruction
    ): self {
        // Pós-obra sempre é Expert
        if ($isPostConstruction) {
            return self::expert();
        }

        $requiredSkills = [];

        if ($hasCeiling) {
            $requiredSkills[] = ProfessionalSkills::SKILL_CEILING;
        }

        if ($hasWindows) {
            $requiredSkills[] = ProfessionalSkills::SKILL_WINDOWS;
        }

        // Determinar nível
        $skillCount = count($requiredSkills);

        if ($skillCount === 0) {
            return self::basic();
        }

        if ($skillCount === 1) {
            return self::intermediate($requiredSkills);
        }

        return self::advanced($requiredSkills);
    }

    /**
     * Factory: A partir de array (hidratação)
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['level'] ?? self::LEVEL_BASIC,
            $data['required_skills'] ?? [],
            (float) ($data['time_multiplier'] ?? 1.0),
            (bool) ($data['requires_multiple_professionals'] ?? false)
        );
    }

    /**
     * Aplica multiplicador ao tempo estimado
     */
    public function applyToEstimatedTime(int $baseMinutes): int
    {
        return (int) round($baseMinutes * $this->timeMultiplier);
    }

    /**
     * Verifica se profissional tem skills necessárias
     */
    public function isCompatibleWith(ProfessionalSkills $skills): bool
    {
        return $skills->hasAll($this->requiredSkills);
    }

    public function getLevel(): string
    {
        return $this->level;
    }

    public function getRequiredSkills(): array
    {
        return $this->requiredSkills;
    }

    public function getTimeMultiplier(): float
    {
        return $this->timeMultiplier;
    }

    public function requiresMultipleProfessionals(): bool
    {
        return $this->requiresMultipleProfessionals;
    }

    public function isBasic(): bool
    {
        return $this->level === self::LEVEL_BASIC;
    }

    public function isExpert(): bool
    {
        return $this->level === self::LEVEL_EXPERT;
    }

    public function toArray(): array
    {
        return [
            'level' => $this->level,
            'required_skills' => $this->requiredSkills,
            'time_multiplier' => $this->timeMultiplier,
            'requires_multiple_professionals' => $this->requiresMultipleProfessionals,
        ];
    }

    private function validateLevel(string $level): void
    {
        $validLevels = [
            self::LEVEL_BASIC,
            self::LEVEL_INTERMEDIATE,
            self::LEVEL_ADVANCED,
            self::LEVEL_EXPERT,
        ];

        if (!in_array($level, $validLevels, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid complexity level: %s', $level)
            );
        }
    }
}
