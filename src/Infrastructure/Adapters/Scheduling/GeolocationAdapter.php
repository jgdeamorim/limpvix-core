<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Adapters\Scheduling;

use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;

/**
 * Adapter: GeolocationAdapter
 *
 * Serviços de geolocalização:
 * - Geocoding (endereço → coordenadas)
 * - Cálculo de distância (já no GeoCoordinates via Haversine)
 *
 * Implementação atual: Fallback com coordenadas padrão
 * TODO: Integrar Google Maps Geocoding API ou similar
 */
final class GeolocationAdapter
{
    // Coordenadas padrão: Centro de Vitória/ES
    private const DEFAULT_LATITUDE = -20.3155;
    private const DEFAULT_LONGITUDE = -40.3128;

    /**
     * Converte endereço em coordenadas geográficas
     *
     * @param string $address Endereço completo
     * @return GeoCoordinates
     */
    public function geocodeAddress(string $address): GeoCoordinates
    {
        // TODO: Implementar geocoding real via API
        // Por enquanto, retornar coordenadas padrão de Vitória/ES

        return GeoCoordinates::fromLatLong(
            self::DEFAULT_LATITUDE,
            self::DEFAULT_LONGITUDE
        );
    }

    /**
     * Converte CEP em coordenadas (via banco de CEPs local)
     *
     * @param string $zipCode CEP (8 dígitos)
     * @return GeoCoordinates
     */
    public function geocodeZipCode(string $zipCode): GeoCoordinates
    {
        // Limpar CEP
        $cleanZip = preg_replace('/[^0-9]/', '', $zipCode);

        if (strlen($cleanZip) !== 8) {
            throw new \InvalidArgumentException('Invalid zip code: ' . $zipCode);
        }

        // TODO: Buscar em banco de CEPs local
        // Por enquanto, retornar coordenadas padrão

        return GeoCoordinates::fromLatLong(
            self::DEFAULT_LATITUDE,
            self::DEFAULT_LONGITUDE
        );
    }

    /**
     * Calcula distância entre dois pontos
     * (wrapper para GeoCoordinates::distanceToInMeters)
     *
     * @param GeoCoordinates $from
     * @param GeoCoordinates $to
     * @return float Distância em metros
     */
    public function calculateDistance(GeoCoordinates $from, GeoCoordinates $to): float
    {
        return $from->distanceToInMeters($to);
    }

    /**
     * Calcula distância em km
     *
     * @param GeoCoordinates $from
     * @param GeoCoordinates $to
     * @return float Distância em km
     */
    public function calculateDistanceInKm(GeoCoordinates $from, GeoCoordinates $to): float
    {
        return $from->distanceToInKm($to);
    }

    /**
     * Verifica se coordenadas estão dentro de um raio
     *
     * @param GeoCoordinates $point
     * @param GeoCoordinates $center
     * @param int $radiusMeters
     * @return bool
     */
    public function isWithinRadius(
        GeoCoordinates $point,
        GeoCoordinates $center,
        int $radiusMeters
    ): bool {
        return $point->isWithinRadius($center, $radiusMeters);
    }

    /**
     * Valida se coordenadas são válidas
     *
     * @param float $latitude
     * @param float $longitude
     * @return bool
     */
    public function validateCoordinates(float $latitude, float $longitude): bool
    {
        return $latitude >= -90 && $latitude <= 90 &&
               $longitude >= -180 && $longitude <= 180 &&
               !($latitude === 0.0 && $longitude === 0.0); // 0,0 geralmente é erro de GPS
    }

    /**
     * Retorna coordenadas padrão (centro de Vitória/ES)
     *
     * @return GeoCoordinates
     */
    public function getDefaultCoordinates(): GeoCoordinates
    {
        return GeoCoordinates::fromLatLong(
            self::DEFAULT_LATITUDE,
            self::DEFAULT_LONGITUDE
        );
    }
}
