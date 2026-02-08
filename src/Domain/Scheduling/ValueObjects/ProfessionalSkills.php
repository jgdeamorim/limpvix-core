<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: ProfessionalSkills
 *
 * Representa skills/habilidades de um profissional.
 * Ex: limpeza_basica, pos_obra, teto, esquadrias, vidros
 *
 * IMUTÁVEL.
 */
final class ProfessionalSkills
{
    public const SKILL_BASIC_CLEANING = 'limpeza_basica';
    public const SKILL_POST_CONSTRUCTION = 'pos_obra';
    public const SKILL_CEILING = 'teto';
    public const SKILL_WINDOWS = 'esquadrias';
    public const SKILL_GLASS = 'vidros';
    public const SKILL_CARPET = 'tapetes';
    public const SKILL_FURNITURE = 'moveis';
    public const SKILL_GARDENING = 'jardinagem';

    private const VALID_SKILLS = [
        self::SKILL_BASIC_CLEANING,
        self::SKILL_POST_CONSTRUCTION,
        self::SKILL_CEILING,
        self::SKILL_WINDOWS,
        self::SKILL_GLASS,
        self::SKILL_CARPET,
        self::SKILL_FURNITURE,
        self::SKILL_GARDENING,
    ];

    /** @var string[] */
    private array $skills;

    /**
     * @param string[] $skills
     */
    private function __construct(array $skills)
    {
        if (empty($skills)) {
            throw new \InvalidArgumentException('Professional must have at least one skill');
        }

        foreach ($skills as $skill) {
            $this->validateSkill($skill);
        }

        // Remove duplicatas e reindexar
        $this->skills = array_values(array_unique($skills));
    }

    /**
     * @param string[] $skills
     */
    public static function create(array $skills): self
    {
        return new self($skills);
    }

    /**
     * Factory: Skills básicas (limpeza básica apenas)
     */
    public static function basicOnly(): self
    {
        return new self([self::SKILL_BASIC_CLEANING]);
    }

    /**
     * Factory: Profissional completo (todas skills)
     */
    public static function allSkills(): self
    {
        return new self(self::VALID_SKILLS);
    }

    /**
     * Factory: A partir de array (hidratação)
     */
    public static function fromArray(array $data): self
    {
        return new self($data['skills'] ?? []);
    }

    /**
     * Verifica se possui uma skill específica
     */
    public function has(string $skill): bool
    {
        return in_array($skill, $this->skills, true);
    }

    /**
     * Verifica se possui TODAS as skills de uma lista
     *
     * @param string[] $requiredSkills
     */
    public function hasAll(array $requiredSkills): bool
    {
        foreach ($requiredSkills as $skill) {
            if (!$this->has($skill)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Verifica se possui PELO MENOS UMA das skills de uma lista
     *
     * @param string[] $requiredSkills
     */
    public function hasAny(array $requiredSkills): bool
    {
        foreach ($requiredSkills as $skill) {
            if ($this->has($skill)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Adiciona nova skill (retorna nova instância - imutabilidade)
     */
    public function withSkill(string $skill): self
    {
        if ($this->has($skill)) {
            return $this; // Já tem, retorna mesma instância
        }

        $newSkills = $this->skills;
        $newSkills[] = $skill;

        return new self($newSkills);
    }

    /**
     * Remove skill (retorna nova instância)
     */
    public function withoutSkill(string $skill): self
    {
        $newSkills = array_filter(
            $this->skills,
            fn($s) => $s !== $skill
        );

        if (empty($newSkills)) {
            throw new \InvalidArgumentException('Cannot remove last skill');
        }

        return new self($newSkills);
    }

    /**
     * Conta total de skills
     */
    public function count(): int
    {
        return count($this->skills);
    }

    /**
     * Verifica se é profissional básico (apenas limpeza básica)
     */
    public function isBasicOnly(): bool
    {
        return $this->count() === 1 && $this->has(self::SKILL_BASIC_CLEANING);
    }

    /**
     * Verifica se é profissional avançado (pós-obra, teto, etc)
     */
    public function isAdvanced(): bool
    {
        return $this->has(self::SKILL_POST_CONSTRUCTION) ||
               $this->has(self::SKILL_CEILING) ||
               $this->has(self::SKILL_WINDOWS);
    }

    /**
     * @return string[]
     */
    public function getSkills(): array
    {
        return $this->skills;
    }

    public function toArray(): array
    {
        return [
            'skills' => $this->skills,
            'count' => $this->count(),
            'is_basic_only' => $this->isBasicOnly(),
            'is_advanced' => $this->isAdvanced(),
        ];
    }

    private function validateSkill(string $skill): void
    {
        if (!in_array($skill, self::VALID_SKILLS, true)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid skill: %s', $skill)
            );
        }
    }
}
