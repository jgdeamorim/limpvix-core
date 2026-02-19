<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Adapters\Scheduling;

use LimpVix\Domain\Scheduling\ValueObjects\GeoCoordinates;

/**
 * Adapter: GeolocationAdapter
 *
 * Serviços de geolocalização:
 * - Geocoding (endereço → coordenadas) via BrasilAPI + mapa local fallback
 * - Cálculo de distância (já no GeoCoordinates via Haversine)
 *
 * ESTRATÉGIA (cascade):
 * 1. BrasilAPI CEP v2 (GET brasilapi.com.br/api/cep/v2/{cep}) — retorna lat/lng reais
 * 2. Cache WordPress transient (24h) — evita chamadas repetidas
 * 3. Mapa local CEP_MAP (Grande Vitória/ES) — fallback offline
 * 4. Coordenadas padrão (Centro de Vitória) — último recurso
 *
 * @since 0.12.0 BrasilAPI integration (Sprint 4 S4.3)
 */
final class GeolocationAdapter
{
    // Coordenadas padrão: Centro de Vitória/ES
    private const DEFAULT_LATITUDE = -20.3155;
    private const DEFAULT_LONGITUDE = -40.3128;

    private const BRASILAPI_CEP_URL = 'https://brasilapi.com.br/api/cep/v2/';
    private const BRASILAPI_TIMEOUT = 5;
    private const CACHE_TTL_SECONDS = 86400; // 24h

    /**
     * Mapeamento de CEP prefix → coordenadas para Grande Vitória/ES
     * Fonte: Correios faixas de CEP + coordenadas centrais dos bairros
     *
     * Formato: 'CEP_PREFIX' => [latitude, longitude, 'nome_região']
     */
    private const CEP_MAP = [
        // Vitória (290xx)
        '29000' => [-20.3155, -40.3128, 'Centro - Vitória'],
        '29010' => [-20.3195, -40.3375, 'Praia do Canto'],
        '29015' => [-20.3088, -40.3209, 'Santa Lúcia'],
        '29020' => [-20.3044, -40.2880, 'Jardim da Penha'],
        '29025' => [-20.2978, -40.2914, 'Mata da Praia'],
        '29030' => [-20.2885, -40.3025, 'Goiabeiras'],
        '29040' => [-20.3230, -40.3504, 'Enseada do Suá'],
        '29042' => [-20.3280, -40.3610, 'Bento Ferreira'],
        '29045' => [-20.3350, -40.3720, 'Jucutuquara'],
        '29050' => [-20.2750, -40.3100, 'Jardim Camburi'],
        '29055' => [-20.2650, -40.2830, 'Carapina/Camburi Norte'],
        '29060' => [-20.3400, -40.3500, 'Maruípe'],
        '29070' => [-20.3100, -40.3600, 'Santo Antônio'],
        '29075' => [-20.3500, -40.3400, 'São Pedro'],

        // Vila Velha (291xx - 292xx)
        '29100' => [-20.3297, -40.2923, 'Centro - Vila Velha'],
        '29101' => [-20.3450, -40.2950, 'Praia da Costa'],
        '29102' => [-20.3550, -40.2850, 'Itapuã'],
        '29103' => [-20.3400, -40.3100, 'Coqueiral de Itaparica'],
        '29104' => [-20.3650, -40.2750, 'Itaparica'],
        '29105' => [-20.3250, -40.2850, 'Glória'],
        '29106' => [-20.3500, -40.3200, 'Aribiri'],
        '29107' => [-20.3600, -40.3300, 'Paul'],
        '29108' => [-20.3350, -40.3000, 'Cristóvão Colombo'],
        '29120' => [-20.3700, -40.3100, 'Ibes'],
        '29122' => [-20.3800, -40.3200, 'Riviera da Barra'],
        '29125' => [-20.3900, -40.3300, 'Santa Inês'],

        // Cariacica (291xx)
        '29140' => [-20.2634, -40.4167, 'Centro - Cariacica'],
        '29142' => [-20.2750, -40.4050, 'Campo Grande'],
        '29145' => [-20.2900, -40.4200, 'Itacibá'],
        '29146' => [-20.3000, -40.4300, 'Nova Rosa da Penha'],
        '29147' => [-20.2500, -40.4000, 'Jardim América'],
        '29148' => [-20.2600, -40.3900, 'Porto de Santana'],

        // Serra (291xx)
        '29160' => [-20.1286, -40.3075, 'Centro - Serra'],
        '29161' => [-20.1400, -40.2900, 'Parque Residencial Laranjeiras'],
        '29162' => [-20.1200, -40.3200, 'Carapina'],
        '29163' => [-20.1550, -40.2800, 'Serra Dourada'],
        '29164' => [-20.1700, -40.2700, 'Barcelona'],
        '29165' => [-20.1100, -40.3400, 'CIVIT'],
        '29166' => [-20.2000, -40.2600, 'Manguinhos'],
        '29167' => [-20.1850, -40.2750, 'Colina de Laranjeiras'],
        '29168' => [-20.2100, -40.2500, 'Nova Almeida'],

        // Guarapari (295xx)
        '29500' => [-20.6743, -40.4997, 'Centro - Guarapari'],

        // Fundão (296xx)
        '29680' => [-19.9329, -40.4055, 'Centro - Fundão'],

        // Viana (291xx)
        '29130' => [-20.3903, -40.4935, 'Centro - Viana'],
    ];

    /**
     * Converte endereço em coordenadas geográficas
     *
     * Extrai CEP do endereço e faz lookup no mapa de bairros.
     * Fallback: coordenadas padrão de Vitória/ES.
     *
     * @param string $address Endereço completo
     * @return GeoCoordinates
     */
    public function geocodeAddress(string $address): GeoCoordinates
    {
        // Try to extract CEP from address
        if (preg_match('/(\d{5})-?(\d{3})/', $address, $matches)) {
            $cep = $matches[1] . $matches[2];
            try {
                return $this->geocodeZipCode($cep);
            } catch (\InvalidArgumentException $e) {
                // Fall through to default
            }
        }

        // Try to match neighborhood name from address
        $addressLower = mb_strtolower($address);
        foreach (self::CEP_MAP as $prefix => [$lat, $lng, $name]) {
            $nameLower = mb_strtolower($name);
            // Extract just the neighborhood part (after " - ")
            $parts = explode(' - ', $nameLower);
            $neighborhood = $parts[0];
            if (str_contains($addressLower, $neighborhood)) {
                return GeoCoordinates::fromLatLong($lat, $lng);
            }
        }

        return GeoCoordinates::fromLatLong(
            self::DEFAULT_LATITUDE,
            self::DEFAULT_LONGITUDE
        );
    }

    /**
     * Converte CEP em coordenadas geográficas
     *
     * Cascade:
     * 1. Cache WordPress transient (24h)
     * 2. BrasilAPI CEP v2 (lat/lng reais)
     * 3. Mapa local CEP_MAP (prefixo 5→4→3 dígitos)
     * 4. Coordenadas padrão (Vitória/ES)
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

        // 1. Check transient cache
        $cacheKey = 'limpvix_geo_cep_' . $cleanZip;
        $cached = get_transient($cacheKey);
        if ($cached !== false && is_array($cached)) {
            return GeoCoordinates::fromLatLong($cached[0], $cached[1]);
        }

        // 2. Try BrasilAPI (real geocoding)
        $apiResult = $this->fetchFromBrasilApi($cleanZip);
        if ($apiResult !== null) {
            set_transient($cacheKey, [$apiResult[0], $apiResult[1]], self::CACHE_TTL_SECONDS);
            return GeoCoordinates::fromLatLong($apiResult[0], $apiResult[1]);
        }

        // 3. Fallback: local CEP_MAP (prefix matching)
        $localResult = $this->lookupLocalMap($cleanZip);
        if ($localResult !== null) {
            return $localResult;
        }

        // 4. Last resort: Vitória center
        return GeoCoordinates::fromLatLong(
            self::DEFAULT_LATITUDE,
            self::DEFAULT_LONGITUDE
        );
    }

    /**
     * Fetch coordinates from BrasilAPI CEP v2
     *
     * @param string $cleanZip 8-digit CEP
     * @return array{0: float, 1: float}|null [latitude, longitude] or null on failure
     */
    private function fetchFromBrasilApi(string $cleanZip): ?array
    {
        $url = self::BRASILAPI_CEP_URL . $cleanZip;

        $response = wp_remote_get($url, [
            'timeout' => self::BRASILAPI_TIMEOUT,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            error_log('[LimpVix][Geo] BrasilAPI error: ' . $response->get_error_message());
            return null;
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        if ($statusCode !== 200) {
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($body)) {
            return null;
        }

        // BrasilAPI v2 returns: location.coordinates.latitude / longitude
        $lat = $body['location']['coordinates']['latitude'] ?? null;
        $lng = $body['location']['coordinates']['longitude'] ?? null;

        if ($lat === null || $lng === null) {
            return null;
        }

        $lat = (float) $lat;
        $lng = (float) $lng;

        // Sanity check — coordinates must be valid
        if (!$this->validateCoordinates($lat, $lng)) {
            return null;
        }

        return [$lat, $lng];
    }

    /**
     * Lookup coordinates from local CEP prefix map (offline fallback)
     *
     * @param string $cleanZip 8-digit CEP
     * @return GeoCoordinates|null
     */
    private function lookupLocalMap(string $cleanZip): ?GeoCoordinates
    {
        // Try exact 5-digit prefix match
        $prefix5 = substr($cleanZip, 0, 5);
        if (isset(self::CEP_MAP[$prefix5])) {
            [$lat, $lng] = self::CEP_MAP[$prefix5];
            return GeoCoordinates::fromLatLong($lat, $lng);
        }

        // Try 4-digit prefix match (broader area)
        $prefix4 = substr($cleanZip, 0, 4);
        foreach (self::CEP_MAP as $prefix => [$lat, $lng, $name]) {
            if (str_starts_with($prefix, $prefix4)) {
                return GeoCoordinates::fromLatLong($lat, $lng);
            }
        }

        // Try 3-digit prefix match (city-level)
        $prefix3 = substr($cleanZip, 0, 3);
        foreach (self::CEP_MAP as $prefix => [$lat, $lng, $name]) {
            if (str_starts_with($prefix, $prefix3)) {
                return GeoCoordinates::fromLatLong($lat, $lng);
            }
        }

        return null;
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
