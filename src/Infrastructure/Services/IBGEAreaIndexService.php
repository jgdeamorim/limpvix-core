<?php
declare(strict_types=1);

namespace LimpVix\Infrastructure\Services;

use LimpVix\Domain\Pricing\GeoIndex;

defined('ABSPATH') || exit;

/**
 * IBGEAreaIndexService - Consulta IBGE para índice socioeconômico por CEP (P0.4)
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
 * - Transient de falha: 5 minutos (evita thrashing)
 *
 * RETRY + CIRCUIT BREAKER (G-IBGE-FALLBACK):
 * - 3 tentativas por chamada com backoff exponencial (1s, 2s, 4s)
 * - Circuit breaker: 5 falhas em 1h = pausa de 30min
 * - Notificação admin após 3 falhas consecutivas
 * - Fallback para último resultado válido em cache
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
    private const CACHE_FAILURE_EXPIRY = 300; // 5 minutes

    // Retry configuration
    private const MAX_RETRIES = 3;
    private const BASE_BACKOFF_SECONDS = 1;

    // Circuit breaker configuration
    private const CB_FAILURE_THRESHOLD = 5;
    private const CB_WINDOW_SECONDS = 3600;    // 1 hour
    private const CB_COOLDOWN_SECONDS = 1800;  // 30 minutes
    private const CB_TRANSIENT_KEY = 'limpvix_ibge_circuit_breaker';

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

        // Check circuit breaker
        if ($this->isCircuitOpen()) {
            error_log('[IBGEAreaIndexService] Circuit breaker OPEN — skipping API calls for CEP ' . $cep);
            return $this->getLastKnownResult($cep);
        }

        try {
            // 1. CEP → Municipality name
            $municipio = $this->getMunicipioFromCep($cep);
            if ($municipio === null) {
                $this->recordFailure('brasilapi');
                return $this->getLastKnownResult($cep);
            }

            // 2. Municipality name → IBGE code
            $codigo = $this->getMunicipioCodigo($municipio);
            if ($codigo === null) {
                $this->recordFailure('ibge_municipios');
                return $this->getLastKnownResult($cep);
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

            // Also store as last-known-good for fallback
            set_transient('limpvix_geo_last_good_' . $cep, $result, 90 * DAY_IN_SECONDS);

            // Reset failure counter on success
            $this->resetFailures();

            return $result;

        } catch (\Throwable $e) {
            $this->recordFailure('general');
            error_log(sprintf(
                '[IBGEAreaIndexService] Error calculating index for CEP %s: %s',
                $cep,
                $e->getMessage()
            ));
            return $this->getLastKnownResult($cep);
        }
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Retry with exponential backoff
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Execute HTTP GET with retry and exponential backoff
     *
     * @param string $url URL to fetch
     * @param int $timeout Timeout in seconds
     * @param string $apiName Name for logging
     * @return array|null Response body as array, or null on failure
     */
    private function fetchWithRetry(string $url, int $timeout, string $apiName): ?array
    {
        $lastError = '';

        for ($attempt = 1; $attempt <= self::MAX_RETRIES; $attempt++) {
            $response = wp_remote_get($url, [
                'timeout' => $timeout,
                'headers' => ['Accept' => 'application/json'],
            ]);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                // Network error — retry
                if ($attempt < self::MAX_RETRIES) {
                    $backoff = self::BASE_BACKOFF_SECONDS * pow(2, $attempt - 1);
                    sleep($backoff);
                }
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);

            // Success
            if ($code === 200) {
                $data = json_decode(wp_remote_retrieve_body($response), true);
                return is_array($data) ? $data : null;
            }

            // 4xx — permanent error, don't retry
            if ($code >= 400 && $code < 500) {
                error_log(sprintf('[IBGEAreaIndexService] %s returned %d (permanent) — not retrying', $apiName, $code));
                return null;
            }

            // 5xx — server error, retry
            $lastError = "HTTP {$code}";
            if ($attempt < self::MAX_RETRIES) {
                $backoff = self::BASE_BACKOFF_SECONDS * pow(2, $attempt - 1);
                sleep($backoff);
            }
        }

        error_log(sprintf(
            '[IBGEAreaIndexService] %s failed after %d attempts: %s',
            $apiName,
            self::MAX_RETRIES,
            $lastError
        ));

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Circuit Breaker
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Check if circuit breaker is open (too many failures)
     */
    private function isCircuitOpen(): bool
    {
        $state = get_transient(self::CB_TRANSIENT_KEY);
        if ($state === false) {
            return false;
        }

        $state = json_decode($state, true);
        if (!is_array($state)) {
            return false;
        }

        // If in cooldown, check if cooldown expired
        if (($state['open_since'] ?? 0) > 0) {
            $elapsed = time() - $state['open_since'];
            if ($elapsed >= self::CB_COOLDOWN_SECONDS) {
                // Cooldown expired — allow half-open (single request)
                delete_transient(self::CB_TRANSIENT_KEY);
                return false;
            }
            return true; // Still in cooldown
        }

        return false;
    }

    /**
     * Record a failure for circuit breaker tracking
     */
    private function recordFailure(string $api): void
    {
        $state = get_transient(self::CB_TRANSIENT_KEY);
        $state = $state !== false ? json_decode($state, true) : [];

        if (!is_array($state)) {
            $state = [];
        }

        $now = time();
        $failures = $state['failures'] ?? [];

        // Remove failures outside the window
        $failures = array_filter($failures, fn(int $ts) => ($now - $ts) < self::CB_WINDOW_SECONDS);
        $failures[] = $now;

        $state['failures'] = array_values($failures);

        // Check if threshold exceeded
        if (count($failures) >= self::CB_FAILURE_THRESHOLD) {
            $state['open_since'] = $now;
            error_log(sprintf(
                '[IBGEAreaIndexService] CIRCUIT BREAKER OPENED — %d failures in %d seconds (api: %s). Cooldown: %d min.',
                count($failures),
                self::CB_WINDOW_SECONDS,
                $api,
                self::CB_COOLDOWN_SECONDS / 60
            ));

            // Notify admin
            $this->notifyAdminFailure($api, count($failures));
        }

        set_transient(self::CB_TRANSIENT_KEY, json_encode($state), self::CB_WINDOW_SECONDS);
    }

    /**
     * Reset failure counter after a successful request
     */
    private function resetFailures(): void
    {
        delete_transient(self::CB_TRANSIENT_KEY);
    }

    /**
     * Notify admin when IBGE APIs are consistently failing
     */
    private function notifyAdminFailure(string $api, int $failureCount): void
    {
        // Avoid spamming — only notify once per cooldown period
        $notifyKey = 'limpvix_ibge_admin_notified';
        if (get_transient($notifyKey) !== false) {
            return;
        }

        set_transient($notifyKey, '1', self::CB_COOLDOWN_SECONDS);

        do_action('limpvix_admin_notification', [
            'type' => 'warning',
            'title' => 'IBGE API Indisponível',
            'message' => sprintf(
                'O serviço IBGE (%s) falhou %d vezes consecutivas. O pricing usará valores neutros (multiplicador 1.0) até a API se restabelecer. Cooldown de %d minutos ativado.',
                $api,
                $failureCount,
                self::CB_COOLDOWN_SECONDS / 60
            ),
            'source' => 'IBGEAreaIndexService',
        ]);

        error_log(sprintf(
            '[IBGEAreaIndexService] Admin notification sent — %s failed %dx',
            $api,
            $failureCount
        ));
    }

    /**
     * Get last known good result for a CEP (fallback)
     */
    private function getLastKnownResult(string $cep): ?array
    {
        $lastGood = get_transient('limpvix_geo_last_good_' . $cep);
        if ($lastGood !== false) {
            error_log(sprintf('[IBGEAreaIndexService] Using last-known-good result for CEP %s', $cep));
            return $lastGood;
        }
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // API methods (with retry)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Get municipality name from CEP via BrasilAPI
     */
    private function getMunicipioFromCep(string $cep): ?string
    {
        $data = $this->fetchWithRetry(self::BRASIL_API_CEP . $cep, 10, 'BrasilAPI');
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
     * Get full IBGE municipalities list (cached, with retry)
     */
    private function getMunicipiosList(): ?array
    {
        $cacheKey = 'limpvix_ibge_municipios_list';
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            return $cached;
        }

        $data = $this->fetchWithRetry(self::IBGE_MUNICIPIOS, 30, 'IBGE Municipios');
        if ($data === null || !is_array($data)) {
            // Cache failure briefly to avoid hammering
            set_transient($cacheKey . '_fail', '1', self::CACHE_FAILURE_EXPIRY);
            return null;
        }

        set_transient($cacheKey, $data, self::CACHE_MUNICIPIOS_EXPIRY);
        return $data;
    }

    /**
     * Get indicator from IBGE SIDRA API (with retry)
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

        $data = $this->fetchWithRetry($url, 15, "SIDRA t{$tabela}");

        // SIDRA returns array, first element is header, second is data
        if (is_array($data) && count($data) > 1) {
            $value = $data[1]['V'] ?? null;
            if ($value !== null && is_numeric($value)) {
                return (float) $value;
            }
        }

        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Calculation & Utilities
    // ──────────────────────────────────────────────────────────────────────────

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
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_limpvix_geo_last_good_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_limpvix_geo_last_good_%'");
        delete_transient('limpvix_ibge_municipios_list');
        delete_transient(self::CB_TRANSIENT_KEY);
    }
}
