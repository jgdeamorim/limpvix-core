<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Services;

use LimpVix\Domain\Pricing\GeoIndex;

defined('ABSPATH') || exit;

/**
 * IBGEAreaIndexService - Consulta IBGE para índice socioeconômico por CEP (P0.4)
 *
 * Baseado na sugestão do usuário (IBGE_Area_Index class).
 *
 * FLUXO:
 * 1. CEP → BrasilAPI → município
 * 2. Município → IBGE código
 * 3. Código IBGE → SIDRA (PIB per capita, população, densidade)
 * 4. Normalização → índice 0-1
 * 5. Classificação → multiplicador de preço + fee
 *
 * CACHE:
 * - Transient por CEP: 30 dias
 * - Transient lista municípios: 7 dias
 *
 * FALLBACK:
 * - Se qualquer API falhar: retorna null (pricing usa multiplicador 1.0)
 *
 * @package LimpVix\Infrastructure\Services
 * @since P0.4
 */
final class IBGEAreaIndexService
{
    private const BRASIL_API_CEP = 'https://brasilapi.com.br/api/cep/v2/';
    private const IBGE_MUNICIPIOS = 'https://servicodados.ibge.gov.br/api/v1/localidades/municipios';
    private const SIDRA_BASE = 'https://api.sidra.ibge.gov.br/values/t/';

    private const CACHE_CEP_EXPIRY = 30 * DAY_IN_SECONDS;
    private const CACHE_MUNICIPIOS_EXPIRY = 7 * DAY_IN_SECONDS;

    // SIDRA table/variable pairs
    private const SIDRA_PIB_TABLE = 5938;
    private const SIDRA_PIB_VARIABLE = 47001;
    private const SIDRA_POP_TABLE = 6579;
    private const SIDRA_POP_VARIABLE = 9324;
    private const SIDRA_DENS_TABLE = 6579;
    private const SIDRA_DENS_VARIABLE = 9330;

    // National reference values for normalization
    private const REF_PIB_MAX = 200000.0;
    private const REF_POP_MAX = 12000000.0;
    private const REF_DENS_MAX = 14000.0;

    /**
     * Calculate geographic index for a given CEP
     *
     * @param string $cep CEP (with or without dash)
     * @return array|null {municipio, indice, classificacao, multiplicador, fee_percentage, pib_per_capita, populacao, densidade} or null on failure
     */
    public function calculate(string $cep): ?array
    {
        $cep = preg_replace('/\D/', '', $cep);

        if (strlen($cep) !== 8) {
            return null;
        }

        // Check cache first
        $cacheKey = 'limpvix_geo_index_' . $cep;
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        try {
            // 1. CEP → Municipality name
            $municipio = $this->getMunicipioFromCep($cep);
            if ($municipio === null) {
                return null;
            }

            // 2. Municipality name → IBGE code
            $codigo = $this->getMunicipioCodigo($municipio);
            if ($codigo === null) {
                return null;
            }

            // 3. IBGE code → indicators
            $pib = $this->getIndicador($codigo, self::SIDRA_PIB_TABLE, self::SIDRA_PIB_VARIABLE);
            $populacao = $this->getIndicador($codigo, self::SIDRA_POP_TABLE, self::SIDRA_POP_VARIABLE);
            $densidade = $this->getIndicador($codigo, self::SIDRA_DENS_TABLE, self::SIDRA_DENS_VARIABLE);

            // 4. Calculate normalized index
            $indice = $this->calculateIndex($pib, $populacao, $densidade);

            // 5. Build result
            $geoIndex = GeoIndex::fromIBGEResult([
                'municipio' => $municipio,
                'indice' => $indice,
                'pib_per_capita' => $pib,
                'populacao' => $populacao,
                'densidade' => $densidade,
            ]);

            $result = $geoIndex->toArray();

            // Cache for 30 days
            set_transient($cacheKey, $result, self::CACHE_CEP_EXPIRY);

            return $result;

        } catch (\Throwable $e) {
            error_log(sprintf(
                '[IBGEAreaIndexService] Error calculating index for CEP %s: %s',
                $cep,
                $e->getMessage()
            ));
            return null;
        }
    }

    /**
     * Get municipality name from CEP via BrasilAPI
     */
    private function getMunicipioFromCep(string $cep): ?string
    {
        $response = wp_remote_get(self::BRASIL_API_CEP . $cep, [
            'timeout' => 10,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            error_log('[IBGEAreaIndexService] BrasilAPI error: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['city'] ?? null;
    }

    /**
     * Get IBGE municipality code from name
     */
    private function getMunicipioCodigo(string $municipio): ?string
    {
        $municipios = $this->getMunicipiosList();
        if ($municipios === null) {
            return null;
        }

        $normalizado = $this->normalizeString($municipio);

        foreach ($municipios as $m) {
            if ($this->normalizeString($m['nome'] ?? '') === $normalizado) {
                return (string) ($m['id'] ?? '');
            }
        }

        return null;
    }

    /**
     * Get full IBGE municipalities list (cached)
     */
    private function getMunicipiosList(): ?array
    {
        $cacheKey = 'limpvix_ibge_municipios_list';
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $response = wp_remote_get(self::IBGE_MUNICIPIOS, [
            'timeout' => 30,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }

        set_transient($cacheKey, $data, self::CACHE_MUNICIPIOS_EXPIRY);
        return $data;
    }

    /**
     * Get indicator from IBGE SIDRA API
     *
     * @param string $codigoMunicipio
     * @param int $tabela
     * @param int $variavel
     * @return float|null
     */
    private function getIndicador(string $codigoMunicipio, int $tabela, int $variavel): ?float
    {
        $url = sprintf(
            '%s%d/n6/%s/v/%d/p/last/d/2',
            self::SIDRA_BASE,
            $tabela,
            $codigoMunicipio,
            $variavel
        );

        $response = wp_remote_get($url, [
            'timeout' => 15,
            'headers' => ['Accept' => 'application/json'],
        ]);

        if (is_wp_error($response)) {
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);

        // SIDRA returns array, first element is header, second is data
        if (is_array($data) && count($data) > 1) {
            $value = $data[1]['V'] ?? null;
            if ($value !== null && is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    /**
     * Calculate normalized index from indicators
     *
     * Formula: (PIB_norm × 0.6) + (POP_norm × 0.2) + (DENS_norm × 0.2)
     * Result: 0.0 to 1.0
     */
    private function calculateIndex(?float $pib, ?float $populacao, ?float $densidade): float
    {
        $pibNorm = $pib !== null ? min(1.0, $pib / self::REF_PIB_MAX) : 0.5;
        $popNorm = $populacao !== null ? min(1.0, $populacao / self::REF_POP_MAX) : 0.5;
        $densNorm = $densidade !== null ? min(1.0, $densidade / self::REF_DENS_MAX) : 0.5;

        $indice = ($pibNorm * 0.6) + ($popNorm * 0.2) + ($densNorm * 0.2);

        return round(min(1.0, max(0.0, $indice)), 3);
    }

    /**
     * Normalize string for comparison (remove accents, lowercase)
     */
    private function normalizeString(string $str): string
    {
        $str = mb_strtolower($str, 'UTF-8');
        $str = preg_replace('/[áàãâä]/u', 'a', $str);
        $str = preg_replace('/[éèêë]/u', 'e', $str);
        $str = preg_replace('/[íìîï]/u', 'i', $str);
        $str = preg_replace('/[óòõôö]/u', 'o', $str);
        $str = preg_replace('/[úùûü]/u', 'u', $str);
        $str = preg_replace('/[ç]/u', 'c', $str);
        return trim($str);
    }

    /**
     * Clear all cached geo index data
     */
    public function clearCache(): void
    {
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_limpvix_geo_index_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_limpvix_geo_index_%'");
        delete_transient('limpvix_ibge_municipios_list');
    }
}
