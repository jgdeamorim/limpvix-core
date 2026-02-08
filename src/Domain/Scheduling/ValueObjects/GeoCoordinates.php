<?php

declare(strict_types=1);

namespace LimpVix\Domain\Scheduling\ValueObjects;

/**
 * Value Object: GeoCoordinates
 *
 * Representa coordenadas geográficas (latitude/longitude) com cálculo de distância.
 * Usa fórmula de Haversine para calcular distância entre dois pontos.
 *
 * IMUTÁVEL.
 */
final class GeoCoordinates
{
    private const EARTH_RADIUS_KM = 6371;
    private const EARTH_RADIUS_METERS = 6371000;

    private float $latitude;
    private float $longitude;

    private function __construct(float $latitude, float $longitude)
    {
        $this->validateLatitude($latitude);
        $this->validateLongitude($longitude);

        $this->latitude = $latitude;
        $this->longitude = $longitude;
    }

    /**
     * Factory: Criar coordenadas a partir de lat/long
     */
    public static function fromLatLong(float $latitude, float $longitude): self
    {
        return new self($latitude, $longitude);
    }

    /**
     * Factory: Criar coordenadas a partir de array
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['latitude']) || !isset($data['longitude'])) {
            throw new \InvalidArgumentException('Array must contain latitude and longitude keys');
        }

        return new self((float) $data['latitude'], (float) $data['longitude']);
    }

    /**
     * Calcula distância em metros até outra coordenada usando fórmula de Haversine
     *
     * @return float Distância em metros
     */
    public function distanceToInMeters(GeoCoordinates $other): float
    {
        $lat1 = deg2rad($this->latitude);
        $lon1 = deg2rad($this->longitude);
        $lat2 = deg2rad($other->latitude);
        $lon2 = deg2rad($other->longitude);

        $deltaLat = $lat2 - $lat1;
        $deltaLon = $lon2 - $lon1;

        $a = sin($deltaLat / 2) * sin($deltaLat / 2) +
             cos($lat1) * cos($lat2) *
             sin($deltaLon / 2) * sin($deltaLon / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return self::EARTH_RADIUS_METERS * $c;
    }

    /**
     * Calcula distância em quilômetros até outra coordenada
     */
    public function distanceToInKm(GeoCoordinates $other): float
    {
        return $this->distanceToInMeters($other) / 1000;
    }

    /**
     * Verifica se está dentro de um raio de X metros de outra coordenada
     */
    public function isWithinRadius(GeoCoordinates $center, int $radiusMeters): bool
    {
        return $this->distanceToInMeters($center) <= $radiusMeters;
    }

    public function getLatitude(): float
    {
        return $this->latitude;
    }

    public function getLongitude(): float
    {
        return $this->longitude;
    }

    public function toArray(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    public function equals(GeoCoordinates $other): bool
    {
        return abs($this->latitude - $other->latitude) < 0.000001 &&
               abs($this->longitude - $other->longitude) < 0.000001;
    }

    private function validateLatitude(float $latitude): void
    {
        if ($latitude < -90 || $latitude > 90) {
            throw new \InvalidArgumentException(
                sprintf('Latitude must be between -90 and 90, got: %f', $latitude)
            );
        }
    }

    private function validateLongitude(float $longitude): void
    {
        if ($longitude < -180 || $longitude > 180) {
            throw new \InvalidArgumentException(
                sprintf('Longitude must be between -180 and 180, got: %f', $longitude)
            );
        }
    }
}
