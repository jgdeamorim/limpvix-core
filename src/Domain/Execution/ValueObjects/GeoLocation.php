<?php
declare(strict_types=1);

namespace LimpVix\Domain\Execution\ValueObjects;

/**
 * GeoLocation - Coordenadas Geográficas (Sprint 1 - Dia 1)
 *
 * Value Object imutável representando localização geográfica.
 * Inclui cálculo de distância via fórmula Haversine.
 *
 * PRINCÍPIOS:
 * - Imutável (todas propriedades readonly)
 * - Auto-validação (coordenadas válidas)
 * - Sem side effects
 *
 * @package LimpVix\Domain\Execution\ValueObjects
 */
final readonly class GeoLocation
{
    private const EARTH_RADIUS_METERS = 6371000; // Raio da Terra em metros

    /**
     * Construtor
     *
     * @param float $latitude Latitude (-90 a 90)
     * @param float $longitude Longitude (-180 a 180)
     * @throws \InvalidArgumentException Se coordenadas inválidas
     */
    public function __construct(
        public float $latitude,
        public float $longitude
    ) {
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException(
                sprintf('Invalid latitude: %f (must be -90 to 90)', $latitude)
            );
        }

        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException(
                sprintf('Invalid longitude: %f (must be -180 to 180)', $longitude)
            );
        }
    }

    /**
     * Calcula distância para outra localização (Haversine)
     *
     * @param GeoLocation $other Outra localização
     * @return float Distância em metros
     */
    public function distanceTo(GeoLocation $other): float
    {
        $lat1 = deg2rad($this->latitude);
        $lon1 = deg2rad($this->longitude);
        $lat2 = deg2rad($other->latitude);
        $lon2 = deg2rad($other->longitude);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) ** 2 +
             cos($lat1) * cos($lat2) *
             sin($deltaLon / 2) ** 2;

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Verifica se está dentro do raio de outra localização
     *
     * @param GeoLocation $center Centro do raio
     * @param float $radiusMeters Raio em metros
     * @return bool
     */
    public function isWithinRadius(GeoLocation $center, float $radiusMeters): bool
    {
        return $this->distanceTo($center) <= $radiusMeters;
    }

    /**
     * Igualdade baseada em coordenadas
     *
     * @param GeoLocation $other
     * @return bool
     */
    public function equals(GeoLocation $other): bool
    {
        return abs($this->latitude - $other->latitude) < 0.0001 &&
               abs($this->longitude - $other->longitude) < 0.0001;
    }

    /**
     * Representação string
     *
     * @return string
     */
    public function toString(): string
    {
        return sprintf('%.6f,%.6f', $this->latitude, $this->longitude);
    }
}
