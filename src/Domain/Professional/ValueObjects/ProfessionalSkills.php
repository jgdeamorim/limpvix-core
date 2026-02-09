<?php
/**
 * ProfessionalSkills Value Object
 *
 * Skills, certificações e limitações físicas do profissional
 * Imutável, com validação de skills conhecidas
 *
 * @package LimpVix\Domain\Professional\ValueObjects
 */

namespace LimpVix\Domain\Professional\ValueObjects;

defined('ABSPATH') || exit;

final class ProfessionalSkills
{
    private array $skills; // ['limpeza_basica', 'pos_obra', ...]
    private array $certifications; // ['nr35_altura', 'eletrica_basica', ...]
    private array $physicalLimitations; // ['no_heights', 'no_heavy_lifting', ...]

    // Skills conhecidas do sistema
    private const KNOWN_SKILLS = [
        'limpeza_basica',
        'limpeza_profunda',
        'pos_obra',
        'teto',
        'esquadrias',
        'vidros',
        'carpete',
        'estofados',
        'cozinha_industrial',
        'banheiros',
        'areas_externas',
        'piscinas',
    ];

    // Certificações conhecidas
    private const KNOWN_CERTIFICATIONS = [
        'nr35_altura',
        'nr10_eletrica',
        'manipulacao_quimicos',
        'primeiros_socorros',
    ];

    // Limitações conhecidas
    private const KNOWN_LIMITATIONS = [
        'no_heights',
        'no_heavy_lifting',
        'no_chemicals',
        'no_confined_spaces',
    ];

    /**
     * @param array $skills Lista de skills
     * @param array $certifications Lista de certificações (opcional)
     * @param array $physicalLimitations Lista de limitações físicas (opcional)
     * @throws \InvalidArgumentException
     */
    public function __construct(
        array $skills,
        array $certifications = [],
        array $physicalLimitations = []
    ) {
        $this->validateSkills($skills);
        $this->skills = array_unique($skills);
        $this->certifications = array_unique($certifications);
        $this->physicalLimitations = array_unique($physicalLimitations);
    }

    /**
     * Cria a partir de JSON
     *
     * @param string $skillsJson JSON das skills
     * @param string|null $certificationsJson JSON das certificações
     * @param string|null $limitationsJson JSON das limitações
     * @return self
     */
    public static function fromJson(
        string $skillsJson,
        ?string $certificationsJson = null,
        ?string $limitationsJson = null
    ): self {
        $skills = json_decode($skillsJson, true);
        $certifications = $certificationsJson ? json_decode($certificationsJson, true) : [];
        $limitations = $limitationsJson ? json_decode($limitationsJson, true) : [];

        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new \InvalidArgumentException('JSON inválido para ProfessionalSkills');
        }

        return new self($skills, $certifications, $limitations);
    }

    /**
     * Cria skills básicas (mínimo para operar)
     *
     * @return self
     */
    public static function basicSkills(): self
    {
        return new self(['limpeza_basica']);
    }

    /**
     * Verifica se possui uma skill específica
     *
     * @param string $skill
     * @return bool
     */
    public function hasSkill(string $skill): bool
    {
        return in_array($skill, $this->skills, true);
    }

    /**
     * Verifica se possui TODAS as skills necessárias
     *
     * @param array $requiredSkills
     * @return bool
     */
    public function hasAllSkills(array $requiredSkills): bool
    {
        foreach ($requiredSkills as $skill) {
            if (!$this->hasSkill($skill)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Verifica se possui QUALQUER uma das skills
     *
     * @param array $skills
     * @return bool
     */
    public function hasAnySkill(array $skills): bool
    {
        foreach ($skills as $skill) {
            if ($this->hasSkill($skill)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Verifica se possui certificação específica
     *
     * @param string $certification
     * @return bool
     */
    public function hasCertification(string $certification): bool
    {
        return in_array($certification, $this->certifications, true);
    }

    /**
     * Verifica se possui limitação física que impede um serviço
     *
     * @param string $limitation
     * @return bool
     */
    public function hasLimitation(string $limitation): bool
    {
        return in_array($limitation, $this->physicalLimitations, true);
    }

    /**
     * Verifica se pode fazer um serviço baseado em limitações
     * Ex: Se serviço requer trabalho em altura e profissional tem 'no_heights' = não pode
     *
     * @param array $serviceRequirements ['requires_heights', 'requires_heavy_lifting']
     * @return bool
     */
    public function canPerformService(array $serviceRequirements): bool
    {
        $limitationMap = [
            'requires_heights' => 'no_heights',
            'requires_heavy_lifting' => 'no_heavy_lifting',
            'requires_chemicals' => 'no_chemicals',
            'requires_confined_spaces' => 'no_confined_spaces',
        ];

        foreach ($serviceRequirements as $requirement) {
            $limitation = $limitationMap[$requirement] ?? null;
            if ($limitation && $this->hasLimitation($limitation)) {
                return false; // Profissional tem limitação para este requisito
            }
        }

        return true; // Sem conflitos
    }

    /**
     * Retorna skills faltantes para um conjunto de skills requeridas
     *
     * @param array $requiredSkills
     * @return array Skills que faltam
     */
    public function getMissingSkills(array $requiredSkills): array
    {
        return array_diff($requiredSkills, $this->skills);
    }

    /**
     * Retorna nível de match com skills requeridas (0-100)
     *
     * @param array $requiredSkills
     * @return float
     */
    public function getSkillMatchPercentage(array $requiredSkills): float
    {
        if (empty($requiredSkills)) {
            return 100.0;
        }

        $matchCount = 0;
        foreach ($requiredSkills as $skill) {
            if ($this->hasSkill($skill)) {
                $matchCount++;
            }
        }

        return round(($matchCount / count($requiredSkills)) * 100, 2);
    }

    // Getters

    public function getSkills(): array
    {
        return $this->skills;
    }

    public function getCertifications(): array
    {
        return $this->certifications;
    }

    public function getPhysicalLimitations(): array
    {
        return $this->physicalLimitations;
    }

    public function getSkillsCount(): int
    {
        return count($this->skills);
    }

    /**
     * Retorna array para serialização
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'skills' => $this->skills,
            'certifications' => $this->certifications,
            'physical_limitations' => $this->physicalLimitations,
        ];
    }

    public function getSkillsJson(): string
    {
        return json_encode($this->skills);
    }

    public function getCertificationsJson(): string
    {
        return json_encode($this->certifications);
    }

    public function getLimitationsJson(): string
    {
        return json_encode($this->physicalLimitations);
    }

    /**
     * Igualdade de Value Objects
     *
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->getSkillsJson() === $other->getSkillsJson()
            && $this->getCertificationsJson() === $other->getCertificationsJson()
            && $this->getLimitationsJson() === $other->getLimitationsJson();
    }

    // Validações privadas

    private function validateSkills(array $skills): void
    {
        if (empty($skills)) {
            throw new \InvalidArgumentException('Profissional deve ter pelo menos 1 skill');
        }

        foreach ($skills as $skill) {
            if (!is_string($skill)) {
                throw new \InvalidArgumentException('Skill deve ser string');
            }

            // Apenas warn se skill desconhecida (permite extensibilidade)
            if (!in_array($skill, self::KNOWN_SKILLS, true)) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log("[LimpVix] Warning: Skill desconhecida '$skill'. Considere adicionar em KNOWN_SKILLS.");
                }
            }
        }
    }

    public function __toString(): string
    {
        $skillCount = count($this->skills);
        $certCount = count($this->certifications);
        $limitCount = count($this->physicalLimitations);

        return sprintf(
            'ProfessionalSkills(skills: %d, certs: %d, limitations: %d)',
            $skillCount,
            $certCount,
            $limitCount
        );
    }

    /**
     * Retorna lista de todas as skills conhecidas do sistema
     *
     * @return array
     */
    public static function getKnownSkills(): array
    {
        return self::KNOWN_SKILLS;
    }

    /**
     * Retorna lista de todas as certificações conhecidas
     *
     * @return array
     */
    public static function getKnownCertifications(): array
    {
        return self::KNOWN_CERTIFICATIONS;
    }

    /**
     * Retorna lista de todas as limitações conhecidas
     *
     * @return array
     */
    public static function getKnownLimitations(): array
    {
        return self::KNOWN_LIMITATIONS;
    }
}
