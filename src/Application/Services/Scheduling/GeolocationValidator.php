<?php

declare(strict_types=1);

namespace LimpVix\Application\Services\Scheduling;

use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;
use LimpVix\Domain\Scheduling\ValueObjects\ServiceLocation;

/**
 * Application Service: GeolocationValidator
 *
 * Validação de geolocalização para check-in.
 * Verifica se coordenadas estão dentro do geofence permitido.
 */
final class GeolocationValidator
{
    private const DEFAULT_GEOFENCE_RADIUS_METERS = 150;

    /**
     * Verifica se coordenadas estão dentro do geofence
     *
     * @param GeoCoordinates $actualCoordinates Coordenadas do check-in
     * @param ServiceLocation $expectedLocation Localização esperada do serviço
     * @param int $radiusMeters Raio do geofence em metros (padrão: 150m)
     * @return bool
     */
    public function isWithinGeofence(
        GeoCoordinates $actualCoordinates,
        ServiceLocation $expectedLocation,
        int $radiusMeters = self::DEFAULT_GEOFENCE_RADIUS_METERS
    ): bool {
        return $actualCoordinates->isWithinRadius(
            $expectedLocation->getCoordinates(),
            $radiusMeters
        );
    }

    /**
     * Calcula distância real entre coordenadas e localização esperada
     *
     * @param GeoCoordinates $actualCoordinates
     * @param ServiceLocation $expectedLocation
     * @return float Distância em metros
     */
    public function calculateDistance(
        GeoCoordinates $actualCoordinates,
        ServiceLocation $expectedLocation
    ): float {
        return $actualCoordinates->distanceToInMeters(
            $expectedLocation->getCoordinates()
        );
    }

    /**
     * Valida coordenadas e retorna resultado detalhado
     *
     * @param GeoCoordinates $actualCoordinates
     * @param ServiceLocation $expectedLocation
     * @param int $radiusMeters
     * @return array{valid: bool, distance_meters: float, within_radius: bool, radius_meters: int}
     */
    public function validate(
        GeoCoordinates $actualCoordinates,
        ServiceLocation $expectedLocation,
        int $radiusMeters = self::DEFAULT_GEOFENCE_RADIUS_METERS
    ): array {
        $distanceMeters = $this->calculateDistance($actualCoordinates, $expectedLocation);
        $withinRadius = $distanceMeters <= $radiusMeters;

        return [
            'valid' => $withinRadius,
            'distance_meters' => $distanceMeters,
            'within_radius' => $withinRadius,
            'radius_meters' => $radiusMeters,
            'expected_location' => $expectedLocation->getShortAddress(),
            'actual_coordinates' => $actualCoordinates->toArray(),
        ];
    }

    /**
     * Verifica se coordenadas são válidas (não são 0,0 ou valores inválidos)
     *
     * @param GeoCoordinates $coordinates
     * @return bool
     */
    public function areCoordinatesValid(GeoCoordinates $coordinates): bool
    {
        // Coordenadas 0,0 geralmente indicam erro de GPS
        if ($coordinates->getLatitude() === 0.0 && $coordinates->getLongitude() === 0.0) {
            return false;
        }

        // Validar que estão dentro de ranges válidos
        $lat = $coordinates->getLatitude();
        $lon = $coordinates->getLongitude();

        return $lat >= -90 && $lat <= 90 && $lon >= -180 && $lon <= 180;
    }

    /**
     * Calcula precisão estimada do GPS baseado em contexto
     *
     * @param float $distanceMeters
     * @return string 'excellent'|'good'|'fair'|'poor'
     */
    public function estimateGpsPrecision(float $distanceMeters): string
    {
        if ($distanceMeters <= 10) {
            return 'excellent'; // Dentro de 10m
        }

        if ($distanceMeters <= 50) {
            return 'good'; // Dentro de 50m
        }

        if ($distanceMeters <= 150) {
            return 'fair'; // Dentro de 150m (limite do geofence)
        }

        return 'poor'; // Fora do geofence
    }
}
