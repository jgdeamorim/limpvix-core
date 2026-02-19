<?php
/**
 * Complexity - Entity (Service Domain)
 *
 * RESPONSABILIDADE:
 * - Representar a complexidade tecnica de um servico
 * - Definir O QUE sera feito (escopo tecnico)
 * - Encapsular time_multiplier + capabilities requeridas
 * - Participar do match tecnico (profissional deve ter as capabilities)
 *
 * DISTINCAO DO Briefing\Complexity:
 * - Briefing\Complexity: nivel generico (simple/medium/complex) — assessment
 * - Service\Complexity: escopo tecnico real (standard/detailed/post_construction) — com capabilities
 *
 * ORIGEM: tabela wp_limpvix_service_complexities + wp_limpvix_complexity_capabilities
 *
 * @package LimpVix\Domain\Service
 * @since 0.5.0
 */

namespace LimpVix\Domain\Service;

defined('ABSPATH') || exit;

class Complexity
{
    /**
     * @var int Database ID
     */
    private $id;

    /**
     * @var int Service ID (FK to service_catalog)
     */
    private $serviceId;

    /**
     * @var string Slug (standard, detailed, post_construction)
     */
    private $slug;

    /**
     * @var string Display name for UI
     */
    private $displayName;

    /**
     * @var string|null Description
     */
    private $description;

    /**
     * @var float Time multiplier (1.00, 1.30, 1.80)
     */
    private $timeMultiplier;

    /**
     * @var Capability[] Capabilities requeridas (do junction table)
     */
    private $capabilities;

    /**
     * Construtor
     *
     * @param int $id
     * @param int $serviceId
     * @param string $slug
     * @param string $displayName
     * @param string|null $description
     * @param float $timeMultiplier
     * @param Capability[] $capabilities
     */
    public function __construct(
        int $id,
        int $serviceId,
        string $slug,
        string $displayName,
        ?string $description,
        float $timeMultiplier,
        array $capabilities = []
    ) {
        if ($timeMultiplier < 0.5 || $timeMultiplier > 5.0) {
            throw new \InvalidArgumentException("Time multiplier deve estar entre 0.5 e 5.0");
        }

        $this->id = $id;
        $this->serviceId = $serviceId;
        $this->slug = $slug;
        $this->displayName = $displayName;
        $this->description = $description;
        $this->timeMultiplier = $timeMultiplier;
        $this->capabilities = $capabilities;
    }

    /**
     * Factory: From database row (sem capabilities — carregadas depois)
     *
     * @param array $row
     * @return self
     */
    public static function fromDatabase(array $row): self
    {
        return new self(
            id: (int) $row['id'],
            serviceId: (int) $row['service_id'],
            slug: $row['slug'],
            displayName: $row['display_name'],
            description: $row['description'] ?? null,
            timeMultiplier: (float) $row['time_multiplier'],
            capabilities: [] // carregadas via CapabilityRegistry::getForComplexity()
        );
    }

    /**
     * Criar nova instancia com capabilities carregadas
     *
     * @param Capability[] $capabilities
     * @return self
     */
    public function withCapabilities(array $capabilities): self
    {
        return new self(
            $this->id,
            $this->serviceId,
            $this->slug,
            $this->displayName,
            $this->description,
            $this->timeMultiplier,
            $capabilities
        );
    }

    // ==================== GETTERS ====================

    public function getId(): int
    {
        return $this->id;
    }

    public function getServiceId(): int
    {
        return $this->serviceId;
    }

    public function getSlug(): string
    {
        return $this->slug;
    }

    public function getDisplayName(): string
    {
        return $this->displayName;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function getTimeMultiplier(): float
    {
        return $this->timeMultiplier;
    }

    /**
     * @return Capability[]
     */
    public function getCapabilities(): array
    {
        return $this->capabilities;
    }

    /**
     * Get required capability slugs
     *
     * @return string[]
     */
    public function getRequiredCapabilitySlugs(): array
    {
        return array_map(
            fn(Capability $cap) => $cap->getSlug(),
            $this->capabilities
        );
    }

    // ==================== BUSINESS LOGIC ====================

    /**
     * Aplicar multiplier a uma duracao
     *
     * @param int $baseDurationMinutes
     * @return int
     */
    public function applyToDuration(int $baseDurationMinutes): int
    {
        return (int) ceil($baseDurationMinutes * $this->timeMultiplier);
    }

    /**
     * Get multiplier como percentual legivel
     *
     * @return string Ex: "+30%", "0%"
     */
    public function getMultiplierDisplay(): string
    {
        $percentage = ($this->timeMultiplier - 1.0) * 100;
        return $percentage > 0 ? '+' . number_format($percentage, 0) . '%' : '0%';
    }

    /**
     * Verificar se requer uma capability especifica
     *
     * @param string $capabilitySlug
     * @return bool
     */
    public function requiresCapability(string $capabilitySlug): bool
    {
        return in_array($capabilitySlug, $this->getRequiredCapabilitySlugs(), true);
    }

    /**
     * Verificar se e mais complexo que outro (por multiplier)
     *
     * @param Complexity $other
     * @return bool
     */
    public function isMoreComplexThan(Complexity $other): bool
    {
        return $this->timeMultiplier > $other->timeMultiplier;
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
            'service_id' => $this->serviceId,
            'slug' => $this->slug,
            'display_name' => $this->displayName,
            'description' => $this->description,
            'time_multiplier' => $this->timeMultiplier,
            'capabilities' => array_map(
                fn(Capability $cap) => $cap->toArray(),
                $this->capabilities
            ),
        ];
    }

    public function __toString(): string
    {
        return "{$this->displayName} ({$this->getMultiplierDisplay()})";
    }
}
