<?php
/**
 * PropertyStructure - Value Object
 *
 * Representa a estrutura física do imóvel.
 * Responsável pelo cálculo estimado de m².
 *
 * Baseado na tabela paramétrica:
 * - Quarto: 12m²
 * - Banheiro: 4m²
 * - Sala: 20m²
 * - Cozinha: 10m²
 * - Escritório: 10m²
 * - Área externa: 25m²
 *
 * @package LimpVix\Domain\Briefing
 * @since 0.2.0
 */

namespace LimpVix\Domain\Briefing;

defined('ABSPATH') || exit;

class PropertyStructure
{
    /**
     * Tabela de m² por cômodo (parametrizável)
     */
    private const M2_TABLE = [
        'bedroom' => 12,
        'bathroom' => 4,
        'living_room' => 20,
        'kitchen' => 10,
        'office' => 10,
        'external_area' => 25,
    ];

    /**
     * Extended m² table (v1.2 additions)
     */
    private const M2_EXTENDED = [
        'balcony' => 8,
        'garage' => 15,
    ];

    /**
     * @var int Número de quartos
     */
    private int $bedrooms;

    /**
     * @var int Número de banheiros
     */
    private int $bathrooms;

    /**
     * @var int Número de salas (G-PROPERTY-STRUCTURE-COUNTS: bool→int)
     */
    private int $livingRoomCount;

    /**
     * @var int Número de cozinhas (G-PROPERTY-STRUCTURE-COUNTS: bool→int)
     */
    private int $kitchenCount;

    /**
     * @var int Número de escritórios (G-PROPERTY-STRUCTURE-COUNTS: bool→int)
     */
    private int $officeCount;

    /**
     * @var int Número de áreas externas (G-PROPERTY-STRUCTURE-COUNTS: bool→int)
     */
    private int $externalAreaCount;

    /**
     * @var int Número de varandas (adicionado v1.2)
     */
    private int $balconyCount;

    /**
     * @var int Número de garagens (adicionado v1.2)
     */
    private int $garageCount;

    /**
     * Construtor
     *
     * @param int $bedrooms Número de quartos (>= 0)
     * @param int $bathrooms Número de banheiros (>= 0)
     * @param int|bool $livingRoomCount Número de salas (accepts bool for backward compat)
     * @param int|bool $kitchenCount Número de cozinhas (accepts bool for backward compat)
     * @param int|bool $officeCount Número de escritórios (accepts bool for backward compat)
     * @param int|bool $externalAreaCount Número de áreas externas (accepts bool for backward compat)
     * @param int $balconyCount Número de varandas
     * @param int $garageCount Número de garagens
     * @throws \InvalidArgumentException
     */
    public function __construct(
        int $bedrooms,
        int $bathrooms,
        int|bool $livingRoomCount = 0,
        int|bool $kitchenCount = 0,
        int|bool $officeCount = 0,
        int|bool $externalAreaCount = 0,
        int $balconyCount = 0,
        int $garageCount = 0
    ) {
        if ($bedrooms < 0) {
            throw new \InvalidArgumentException("Número de quartos não pode ser negativo");
        }

        if ($bathrooms < 0) {
            throw new \InvalidArgumentException("Número de banheiros não pode ser negativo");
        }

        $this->bedrooms = $bedrooms;
        $this->bathrooms = $bathrooms;
        // Backward compat: convert bool to int (true=1, false=0)
        $this->livingRoomCount = is_bool($livingRoomCount) ? (int) $livingRoomCount : max(0, $livingRoomCount);
        $this->kitchenCount = is_bool($kitchenCount) ? (int) $kitchenCount : max(0, $kitchenCount);
        $this->officeCount = is_bool($officeCount) ? (int) $officeCount : max(0, $officeCount);
        $this->externalAreaCount = is_bool($externalAreaCount) ? (int) $externalAreaCount : max(0, $externalAreaCount);
        $this->balconyCount = max(0, $balconyCount);
        $this->garageCount = max(0, $garageCount);
    }

    /**
     * Factory: A partir de array
     *
     * @param array $data Dados da estrutura
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int) ($data['bedrooms'] ?? 0),
            (int) ($data['bathrooms'] ?? 0),
            // Support both count (int) and boolean (backward compat)
            $data['living_room_count'] ?? $data['has_living_room'] ?? 0,
            $data['kitchen_count'] ?? $data['has_kitchen'] ?? 0,
            $data['office_count'] ?? $data['has_office'] ?? 0,
            $data['external_area_count'] ?? $data['has_external_area'] ?? 0,
            (int) ($data['balcony_count'] ?? 0),
            (int) ($data['garage_count'] ?? 0)
        );
    }

    /**
     * Calcular m² estimado
     *
     * Fórmula: (quartos × 12) + (banheiros × 4) + (sala ? 20 : 0) + ...
     *
     * @return float
     */
    public function calculateEstimatedM2(): float
    {
        $m2 = 0;

        $m2 += $this->bedrooms * self::M2_TABLE['bedroom'];
        $m2 += $this->bathrooms * self::M2_TABLE['bathroom'];
        $m2 += $this->livingRoomCount * self::M2_TABLE['living_room'];
        $m2 += $this->kitchenCount * self::M2_TABLE['kitchen'];
        $m2 += $this->officeCount * self::M2_TABLE['office'];
        $m2 += $this->externalAreaCount * self::M2_TABLE['external_area'];
        $m2 += $this->balconyCount * self::M2_EXTENDED['balcony'];
        $m2 += $this->garageCount * self::M2_EXTENDED['garage'];

        return (float) $m2;
    }

    /**
     * Obter número de quartos
     *
     * @return int
     */
    public function getBedrooms(): int
    {
        return $this->bedrooms;
    }

    /**
     * Obter número de banheiros
     *
     * @return int
     */
    public function getBathrooms(): int
    {
        return $this->bathrooms;
    }

    public function getLivingRoomCount(): int { return $this->livingRoomCount; }
    public function getKitchenCount(): int { return $this->kitchenCount; }
    public function getOfficeCount(): int { return $this->officeCount; }
    public function getExternalAreaCount(): int { return $this->externalAreaCount; }
    public function getBalconyCount(): int { return $this->balconyCount; }
    public function getGarageCount(): int { return $this->garageCount; }

    // Backward-compatible boolean accessors
    public function hasLivingRoom(): bool { return $this->livingRoomCount > 0; }
    public function hasKitchen(): bool { return $this->kitchenCount > 0; }
    public function hasOffice(): bool { return $this->officeCount > 0; }
    public function hasExternalArea(): bool { return $this->externalAreaCount > 0; }

    /**
     * Total number of rooms (for evidence validation)
     */
    public function getTotalRoomCount(): int
    {
        return $this->bedrooms
            + $this->bathrooms
            + $this->livingRoomCount
            + $this->kitchenCount
            + $this->officeCount
            + $this->externalAreaCount
            + $this->balconyCount
            + $this->garageCount;
    }

    /**
     * Converter para array
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'bedrooms' => $this->bedrooms,
            'bathrooms' => $this->bathrooms,
            'living_room_count' => $this->livingRoomCount,
            'kitchen_count' => $this->kitchenCount,
            'office_count' => $this->officeCount,
            'external_area_count' => $this->externalAreaCount,
            'balcony_count' => $this->balconyCount,
            'garage_count' => $this->garageCount,
            // Backward compat
            'has_living_room' => $this->hasLivingRoom(),
            'has_kitchen' => $this->hasKitchen(),
            'has_office' => $this->hasOffice(),
            'has_external_area' => $this->hasExternalArea(),
            'total_rooms' => $this->getTotalRoomCount(),
            'estimated_m2' => $this->calculateEstimatedM2()
        ];
    }

    public function equals(PropertyStructure $other): bool
    {
        return $this->bedrooms === $other->bedrooms
            && $this->bathrooms === $other->bathrooms
            && $this->livingRoomCount === $other->livingRoomCount
            && $this->kitchenCount === $other->kitchenCount
            && $this->officeCount === $other->officeCount
            && $this->externalAreaCount === $other->externalAreaCount
            && $this->balconyCount === $other->balconyCount
            && $this->garageCount === $other->garageCount;
    }
}
