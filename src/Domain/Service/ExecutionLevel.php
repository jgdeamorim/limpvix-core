<?php
/**
 * ExecutionLevel - Entity
 *
 * RESPONSABILIDADE:
 * - Representar um nivel de execucao operacional
 * - Definir COMO o servico sera executado (nao O QUE)
 * - Encapsular regras de pricing (price_multiplier)
 * - Determinar tamanho de equipe
 * - Definir nivel de checklist e garantia
 *
 * NAO POSSUI capabilities — nao participa do match tecnico.
 *
 * SUBSTITUI: Package.php + PackageType.php (legado)
 *
 * PRINCIPIOS:
 * - Entity (identidade por slug)
 * - Dados vem do banco (wp_limpvix_execution_levels)
 * - Business logic encapsulation
 *
 * @package LimpVix\Domain\Service
 * @since 0.5.0
 */

namespace LimpVix\Domain\Service;

defined('ABSPATH') || exit;

class ExecutionLevel
{
    /**
     * @var int Database ID
     */
    private $id;

    /**
     * @var ExecutionLevelType
     */
    private $type;

    /**
     * @var string Display name for UI
     */
    private $displayName;

    /**
     * @var string|null Description
     */
    private $description;

    /**
     * @var float Price multiplier (1.00, 1.15, 1.30)
     */
    private $priceMultiplier;

    /**
     * @var int Minimum professionals
     */
    private $teamMin;

    /**
     * @var int Maximum professionals
     */
    private $teamMax;

    /**
     * @var string Checklist level (basic, detailed, complete)
     */
    private $checklistLevel;

    /**
     * @var int Warranty hours post-service
     */
    private $warrantyHours;

    /**
     * Construtor
     *
     * @param int $id
     * @param ExecutionLevelType $type
     * @param string $displayName
     * @param string|null $description
     * @param float $priceMultiplier
     * @param int $teamMin
     * @param int $teamMax
     * @param string $checklistLevel
     * @param int $warrantyHours
     */
    public function __construct(
        int $id,
        ExecutionLevelType $type,
        string $displayName,
        ?string $description,
        float $priceMultiplier,
        int $teamMin,
        int $teamMax,
        string $checklistLevel,
        int $warrantyHours
    ) {
        if ($priceMultiplier < 0.5 || $priceMultiplier > 5.0) {
            throw new \InvalidArgumentException("Price multiplier deve estar entre 0.5 e 5.0");
        }

        if ($teamMin < 1 || $teamMax < $teamMin) {
            throw new \InvalidArgumentException("Team min/max invalidos");
        }

        $this->id = $id;
        $this->type = $type;
        $this->displayName = $displayName;
        $this->description = $description;
        $this->priceMultiplier = $priceMultiplier;
        $this->teamMin = $teamMin;
        $this->teamMax = $teamMax;
        $this->checklistLevel = $checklistLevel;
        $this->warrantyHours = $warrantyHours;
    }

    /**
     * Factory: From database row
     *
     * @param array $row
     * @return self
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            type: ExecutionLevelType::fromString($row['slug']),
            displayName: $row['display_name'],
            description: $row['description'] ?? null,
            priceMultiplier: (float) $row['price_multiplier'],
            teamMin: (int) $row['team_min'],
            teamMax: (int) $row['team_max'],
            checklistLevel: $row['checklist_level'] ?? 'basic',
            warrantyHours: (int) ($row['warranty_hours'] ?? 0)
        );
    }

    // ==================== GETTERS ====================

    public function getId(): int
    {
        return $this->id;
    }

    public function getType(): ExecutionLevelType
    {
        return $this->type;
    }

    public function getSlug(): string
    {
        return $this->type->getValue();
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getPriceMultiplier(): float
    {
        return $this->priceMultiplier;
    }

    public function getTeamMin(): int
    {
        return $this->teamMin;
    }

    public function getTeamMax(): int
    {
        return $this->teamMax;
    }

    public function getChecklistLevel(): string
    {
        return $this->checklistLevel;
    }

    public function getWarrantyHours(): int
    {
        return $this->warrantyHours;
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Calcular preco final baseado em preco base
     *
     * @param float $basePrice
     * @return float
     */
    public function calculateFinalPrice(float $basePrice): float
    {
        return $basePrice * $this->priceMultiplier;
    }

    /**
     * Get percentage increase display
     *
     * @return string Ex: "+15%", "0%"
     */
    public function getPercentageDisplay(): string
    {
        $percentage = ($this->priceMultiplier - 1.0) * 100;
        return $percentage > 0 ? '+' . number_format($percentage, 0) . '%' : '0%';
    }

    /**
     * Determinar numero de profissionais baseado em duracao
     *
     * @param int $durationMinutes
     * @return int
     */
    public function determineTeamSize(int $durationMinutes): int
    {
        // Regra: > 5h (300min) = escalar equipe
        if ($durationMinutes > 300) {
            $calculated = (int) ceil($durationMinutes / 300);
            return min(max($calculated, $this->teamMin), $this->teamMax);
        }

        return $this->teamMin;
    }

    /**
     * Verificar se tem garantia
     *
     * @return bool
     */
    public function hasWarranty(): bool
    {
        return $this->warrantyHours > 0;
    }

    // ==================== SERIALIZATION ====================

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->getSlug(),
            'display_name' => $this->displayName,
            'description' => $this->description,
            'price_multiplier' => $this->priceMultiplier,
            'team_min' => $this->teamMin,
            'team_max' => $this->teamMax,
            'checklist_level' => $this->checklistLevel,
            'warranty_hours' => $this->warrantyHours,
        ];
    }

    public function __toString(): string
    {
        return $this->displayName;
    }
}
