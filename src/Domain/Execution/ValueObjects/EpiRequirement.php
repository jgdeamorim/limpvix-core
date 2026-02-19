<?php
declare(strict_types=1);

/**
 * EpiRequirement - Value Object
 *
 * Represents a single EPI (Equipamento de Proteção Individual) requirement
 * linked to service types that require it.
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 * @since P1.5
 */

namespace LimpVix\Domain\Execution\ValueObjects;

defined('ABSPATH') || exit;

final class EpiRequirement
{
    private string $slug;
    private string $name;
    private string $description;
    private array $requiredForTypes;
    private bool $isMandatory;

    public function __construct(
        string $slug,
        string $name,
        string $description,
        array $requiredForTypes,
        bool $isMandatory = true
    ) {
        $this->slug = $slug;
        $this->name = $name;
        $this->description = $description;
        $this->requiredForTypes = $requiredForTypes;
        $this->isMandatory = $isMandatory;
    }

    public static function fromArray(array $data): self
    {
        $requiredForTypes = $data['required_for_types'] ?? [];
        if (is_string($requiredForTypes)) {
            $requiredForTypes = json_decode($requiredForTypes, true) ?: [];
        }

        return new self(
            $data['slug'] ?? '',
            $data['name'] ?? '',
            $data['description'] ?? '',
            $requiredForTypes,
            (bool) ($data['is_mandatory'] ?? true)
        );
    }

    public function isRequiredForServiceType(string $serviceType): bool
    {
        if (empty($this->requiredForTypes)) {
            return $this->isMandatory; // Empty = required for ALL types if mandatory
        }
        return in_array($serviceType, $this->requiredForTypes, true);
    }

    public function getSlug(): string { return $this->slug; }
    public function getName(): string { return $this->name; }
    public function getDescription(): string { return $this->description; }
    public function getRequiredForTypes(): array { return $this->requiredForTypes; }
    public function isMandatory(): bool { return $this->isMandatory; }

    public function toArray(): array
    {
        return [
            'slug' => $this->slug,
            'name' => $this->name,
            'description' => $this->description,
            'required_for_types' => $this->requiredForTypes,
            'is_mandatory' => $this->isMandatory,
        ];
    }
}
