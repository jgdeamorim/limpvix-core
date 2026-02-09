<?php
/**
 * ServiceRegion Value Object
 *
 * Região de atuação do profissional (centro geográfico + raio)
 * Imutável, com cálculo de distância Haversine
 *
 * @package LimpVix\Domain\Professional\ValueObjects
 */

namespace LimpVix\Domain\Professional\ValueObjects;

defined('ABSPATH') || exit;

final class ServiceRegion
{
    private float $centerLatitude;
    private float $centerLongitude;
    private int $radiusKm;

    /**
     * @param float $centerLatitude Latitude do centro (-90 a 90)
     * @param float $centerLongitude Longitude do centro (-180 a 180)
     * @param int $radiusKm Raio de atuação em km (min 1, max 100)
     * @throws \InvalidArgumentException
     */
    public function __construct(float $centerLatitude, float $centerLongitude, int $radiusKm)
    {
        $this->validateLatitude($centerLatitude);
        $this->validateLongitude($centerLongitude);
        $this->validateRadius($radiusKm);

        $this->centerLatitude = $centerLatitude;
        $this->centerLongitude = $centerLongitude;
        $this->radiusKm = $radiusKm;
    }

    /**
     * Cria ServiceRegion a partir de array
     *
     * @param array $data ['center' => ['lat' => -20.123, 'lng' => -40.456], 'radius_km' => 10]
     * @return self
     */
    public static function fromArray(array $data): self
    {
        if (!isset($data['center']['lat'], $data['center']['lng'], $data['radius_km'])) {
            throw new \InvalidArgumentException('ServiceRegion array deve ter center.lat, center.lng e radius_km');
        }

        return new self(
            (float) $data['center']['lat'],
            (float) $data['center']['lng'],
            (int) $data['radius_km']
        );
    }

    /**
     * Verifica se uma localização está dentro do raio de atuação
     *
     * @param float $targetLat Latitude do destino
     * @param float $targetLng Longitude do destino
     * @return bool
     */
    public function coversLocation(float $targetLat, float $targetLng): bool
    {
        $distanceKm = $this->calculateDistanceKm($targetLat, $targetLng);
        return $distanceKm <= $this->radiusKm;
    }

    /**
     * Calcula distância em km até um ponto usando fórmula Haversine
     *
     * @param float $targetLat
     * @param float $targetLng
     * @return float Distância em km
     */
    public function calculateDistanceKm(float $targetLat, float $targetLng): float
    {
        $earthRadiusKm = 6371;

        $latFrom = deg2rad($this->centerLatitude);
        $lonFrom = deg2rad($this->centerLongitude);
        $latTo = deg2rad($targetLat);
        $lonTo = deg2rad($targetLng);

        $latDelta = $latTo - $latFrom;
        $lonDelta = $lonTo - $lonFrom;

        $a = sin($latDelta / 2) * sin($latDelta / 2) +
             cos($latFrom) * cos($latTo) *
             sin($lonDelta / 2) * sin($lonDelta / 2);

        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));

        return $earthRadiusKm * $c;
    }

    /**
     * Score de proximidade (0-100)
     * Quanto mais perto, maior o score
     *
     * @param float $targetLat
     * @param float $targetLng
     * @return float Score 0-100
     */
    public function proximityScore(float $targetLat, float $targetLng): float
    {
        $distanceKm = $this->calculateDistanceKm($targetLat, $targetLng);

        // Se fora do raio, score = 0
        if ($distanceKm > $this->radiusKm) {
            return 0.0;
        }

        // Score inversamente proporcional à distância
        // Distância 0 = 100, Distância = radiusKm = 0
        $score = 100 * (1 - ($distanceKm / $this->radiusKm));

        return round($score, 2);
    }

    // Getters

    public function getCenterLatitude(): float
    {
        return $this->centerLatitude;
    }

    public function getCenterLongitude(): float
    {
        return $this->centerLongitude;
    }

    public function getRadiusKm(): int
    {
        return $this->radiusKm;
    }

    /**
     * Converte para array (para JSON)
     *
     * @return array
     */
    public function toArray(): array
    {
        return [
            'center' => [
                'lat' => $this->centerLatitude,
                'lng' => $this->centerLongitude,
            ],
            'radius_km' => $this->radiusKm,
        ];
    }

    /**
     * Converte para JSON
     *
     * @return string
     */
    public function toJson(): string
    {
        return json_encode($this->toArray());
    }

    /**
     * Igualdade de Value Objects
     *
     * @param self $other
     * @return bool
     */
    public function equals(self $other): bool
    {
        return $this->centerLatitude === $other->centerLatitude
            && $this->centerLongitude === $other->centerLongitude
            && $this->radiusKm === $other->radiusKm;
    }

    // Validações privadas

    private function validateLatitude(float $lat): void
    {
        if ($lat < -90 || $lat > 90) {
            throw new \InvalidArgumentException("Latitude inválida: $lat. Deve estar entre -90 e 90.");
        }
    }

    private function validateLongitude(float $lng): void
    {
        if ($lng < -180 || $lng > 180) {
            throw new \InvalidArgumentException("Longitude inválida: $lng. Deve estar entre -180 e 180.");
        }
    }

    private function validateRadius(int $radiusKm): void
    {
        if ($radiusKm < 1 || $radiusKm > 100) {
            throw new \InvalidArgumentException("Raio inválido: $radiusKm km. Deve estar entre 1 e 100 km.");
        }
    }

    public function __toString(): string
    {
        return sprintf(
            'ServiceRegion(center: %.6f,%.6f, radius: %d km)',
            $this->centerLatitude,
            $this->centerLongitude,
            $this->radiusKm
        );
    }
}
