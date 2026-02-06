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
     * @var int Número de quartos
     */
    private $bedrooms;

    /**
     * @var int Número de banheiros
     */
    private $bathrooms;

    /**
     * @var bool Possui sala?
     */
    private $hasLivingRoom;

    /**
     * @var bool Possui cozinha?
     */
    private $hasKitchen;

    /**
     * @var bool Possui escritório?
     */
    private $hasOffice;

    /**
     * @var bool Possui área externa?
     */
    private $hasExternalArea;

    /**
     * Construtor
     *
     * @param int $bedrooms Número de quartos (>= 0)
     * @param int $bathrooms Número de banheiros (>= 0)
     * @param bool $hasLivingRoom Possui sala?
     * @param bool $hasKitchen Possui cozinha?
     * @param bool $hasOffice Possui escritório?
     * @param bool $hasExternalArea Possui área externa?
     * @throws \InvalidArgumentException
     */
    public function __construct(
        int $bedrooms,
        int $bathrooms,
        bool $hasLivingRoom = false,
        bool $hasKitchen = false,
        bool $hasOffice = false,
        bool $hasExternalArea = false
    ) {
        if ($bedrooms < 0) {
            throw new \InvalidArgumentException("Número de quartos não pode ser negativo");
        }

        if ($bathrooms < 0) {
            throw new \InvalidArgumentException("Número de banheiros não pode ser negativo");
        }

        $this->bedrooms = $bedrooms;
        $this->bathrooms = $bathrooms;
        $this->hasLivingRoom = $hasLivingRoom;
        $this->hasKitchen = $hasKitchen;
        $this->hasOffice = $hasOffice;
        $this->hasExternalArea = $hasExternalArea;
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
            $data['bedrooms'] ?? 0,
            $data['bathrooms'] ?? 0,
            $data['has_living_room'] ?? false,
            $data['has_kitchen'] ?? false,
            $data['has_office'] ?? false,
            $data['has_external_area'] ?? false
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
        $m2 += $this->hasLivingRoom ? self::M2_TABLE['living_room'] : 0;
        $m2 += $this->hasKitchen ? self::M2_TABLE['kitchen'] : 0;
        $m2 += $this->hasOffice ? self::M2_TABLE['office'] : 0;
        $m2 += $this->hasExternalArea ? self::M2_TABLE['external_area'] : 0;

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

    /**
     * Verificar se possui sala
     *
     * @return bool
     */
    public function hasLivingRoom(): bool
    {
        return $this->hasLivingRoom;
    }

    /**
     * Verificar se possui cozinha
     *
     * @return bool
     */
    public function hasKitchen(): bool
    {
        return $this->hasKitchen;
    }

    /**
     * Verificar se possui escritório
     *
     * @return bool
     */
    public function hasOffice(): bool
    {
        return $this->hasOffice;
    }

    /**
     * Verificar se possui área externa
     *
     * @return bool
     */
    public function hasExternalArea(): bool
    {
        return $this->hasExternalArea;
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
            'has_living_room' => $this->hasLivingRoom,
            'has_kitchen' => $this->hasKitchen,
            'has_office' => $this->hasOffice,
            'has_external_area' => $this->hasExternalArea,
            'estimated_m2' => $this->calculateEstimatedM2()
        ];
    }

    /**
     * Comparar com outra estrutura
     *
     * @param PropertyStructure $other
     * @return bool
     */
    public function equals(PropertyStructure $other): bool
    {
        return $this->bedrooms === $other->bedrooms
            && $this->bathrooms === $other->bathrooms
            && $this->hasLivingRoom === $other->hasLivingRoom
            && $this->hasKitchen === $other->hasKitchen
            && $this->hasOffice === $other->hasOffice
            && $this->hasExternalArea === $other->hasExternalArea;
    }
}
