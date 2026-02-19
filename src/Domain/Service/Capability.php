<?php
/**
 * Capability - Value Object
 *
 * RESPONSABILIDADE:
 * - Representar uma competencia tecnica real
 * - Imutavel, identidade por slug
 * - Origem: tabela wp_limpvix_capabilities (SSOT)
 *
 * PRINCIPIOS:
 * - Value Object (imutavel)
 * - Identidade por slug (unique key no DB)
 * - Sem logica de negocio — apenas dados
 *
 * @package LimpVix\Domain\Service
 * @since 0.5.0
 */

namespace LimpVix\Domain\Service;

defined('ABSPATH') || exit;

class Capability
{
    /**
     * @var int Database ID
     */
    private $id;

    /**
     * @var string Unique slug (ex: cleaning_basic, window_cleaning)
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
     * Construtor
     *
     * @param int $id
     * @param string $slug
     * @param string $displayName
     * @param string|null $description
     */
    public function __construct(
        int $id,
        string $slug,
        string $displayName,
        ?string $description = null
    ) {
        if (empty($slug)) {
            throw new \InvalidArgumentException("Capability slug nao pode ser vazio");
        }

        $this->id = $id;
        $this->slug = $slug;
        $this->displayName = $displayName;
        $this->description = $description;
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
            slug: $row['slug'],
            displayName: $row['display_name'],
            description: $row['description'] ?? null
        );
    }

    // ==================== GETTERS ====================

    public function getId(): int
    {
        return $this->id;
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

    // ==================== COMPARISON ====================

    /**
     * Comparar com outra Capability
     *
     * @param Capability $other
     * @return bool
     */
    public function equals(Capability $other): bool
    {
        return $this->slug === $other->slug;
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'display_name' => $this->displayName,
            'description' => $this->description,
        ];
    }

    /**
     * To string
     *
     * @return string
     */
    public function __toString(): string
    {
        return $this->slug;
    }
}
