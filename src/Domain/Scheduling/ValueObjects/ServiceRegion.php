<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: ServiceRegion
 *
 * Representa a região de atuação de um profissional.
 * Consiste em um ponto central (coordenadas) + raio em km.
 *
 * Ex: Centro = (-20.315, -40.298), Raio = 15km
 *
 * IMUTÁVEL.
 */
final class ServiceRegion
{
    private GeoCoordinates $center;
    private int $radiusKm;

    private function __construct(GeoCoordinates $center, int $radiusKm)
    {
        if ($radiusKm <= 0) {
            throw new \InvalidArgumentException('Radius must be positive');
        }

        if ($radiusKm > 100) {
            throw new \InvalidArgumentException('Radius cannot exceed 100km');
        }

        $this->center = $center;
        $this->radiusKm = $radiusKm;
    }

    public static function create(GeoCoordinates $center, int $radiusKm): self
    {
        return new self($center, $radiusKm);
    }

    /**
     * Factory: Criar a partir de array
     */
    public static function fromArray(array $data): self
    {
        return new self(
            GeoCoordinates::fromArray($data['center'] ?? []),
            (int) ($data['radius_km'] ?? 0)
        );
    }

    /**
     * Verifica se uma localização está dentro desta região
     */
    public function covers(ServiceLocation $location): bool
    {
        $radiusMeters = $this->radiusKm * 1000;
        return $location->isWithinRadiusOf($this->center, $radiusMeters);
    }

    /**
     * Verifica se coordenadas estão dentro desta região
     */
    public function containsCoordinates(GeoCoordinates $coordinates): bool
    {
        $radiusMeters = $this->radiusKm * 1000;
        return $coordinates->isWithinRadius($this->center, $radiusMeters);
    }

    /**
     * Calcula distância do centro até uma localização em km
     */
    public function distanceFromCenterInKm(ServiceLocation $location): float
    {
        return $this->center->distanceToInKm($location->getCoordinates());
    }

    /**
     * Verifica se esta região sobrepõe outra
     */
    public function overlaps(ServiceRegion $other): bool
    {
        $distanceBetweenCenters = $this->center->distanceToInKm($other->center);
        $sumOfRadii = $this->radiusKm + $other->radiusKm;

        return $distanceBetweenCenters <= $sumOfRadii;
    }

    public function getCenter(): GeoCoordinates
    {
        return $this->center;
    }

    public function getRadiusKm(): int
    {
        return $this->radiusKm;
    }

    public function toArray(): array
    {
        return [
            'center' => $this->center->toArray(),
            'radius_km' => $this->radiusKm,
        ];
    }

    public function equals(ServiceRegion $other): bool
    {
        return $this->center->equals($other->center) &&
               $this->radiusKm === $other->radiusKm;
    }
}
