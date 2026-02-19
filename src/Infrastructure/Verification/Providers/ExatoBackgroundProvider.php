<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Verification\Providers;

use LimpVix\Domain\Verification\Contracts\BackgroundProviderInterface;
use LimpVix\Domain\Verification\ValueObjects\BackgroundResult;
use LimpVix\Domain\Verification\Enums\BackgroundStatus;
use LimpVix\Domain\Verification\Enums\RiskLevel;

/**
 * ExatoBackgroundProvider — Integração real com Exato Digital (G-BACKGROUND-CHECK-REAL)
 *
 * REGRAS DE ENGENHARIA (imutáveis):
 * - Nunca expor retorno bruto da Exato ao domínio
 * - Mapear para BackgroundResult com enums internos fixos
 * - Dados sensíveis NUNCA persistidos (apenas classificação final)
 * - Circuit breaker: se Exato indisponível, retornar PENDING e agendar retry
 *
 * Flow:
 * 1. Verificar consentimento LGPD
 * 2. POST /consultas/background — enviar consulta
 * 3. Poll GET /consultas/{id}/resultado — aguardar resultado
 * 4. Mapear resposta para enums internos
 * 5. Persistir APENAS classificação final
 *
 * @see VerificationProviderFactory::backgroundProvider()
 */
final class ExatoBackgroundProvider implements BackgroundProviderInterface
{
    private string $apiKey;
    private string $token;
    private string $endpoint;

    private const MAX_POLL_ATTEMPTS = 5;
    private const POLL_INTERVAL_SECONDS = 3;
    private const CB_TRANSIENT_KEY = 'limpvix_exato_circuit_breaker';
    private const CB_FAILURE_THRESHOLD = 3;
    private const CB_COOLDOWN_SECONDS = 1800; // 30 minutes

    // Risk category mapping from Exato response codes to internal enums
    private const CATEGORY_MAP = [
        'crime_violento'   => BackgroundResult::CATEGORY_VIOLENT_CRIME,
        'violencia'        => BackgroundResult::CATEGORY_VIOLENT_CRIME,
        'crime_sexual'     => BackgroundResult::CATEGORY_SEXUAL_CRIME,
        'sexual'           => BackgroundResult::CATEGORY_SEXUAL_CRIME,
        'fraude'           => BackgroundResult::CATEGORY_FRAUD,
        'estelionato'      => BackgroundResult::CATEGORY_FRAUD,
        'drogas'           => BackgroundResult::CATEGORY_DRUGS,
        'trafico'          => BackgroundResult::CATEGORY_DRUGS,
        'furto'            => BackgroundResult::CATEGORY_THEFT,
        'roubo'            => BackgroundResult::CATEGORY_THEFT,
    ];

    // Categories that make professional NOT eligible
    private const BLOCKING_CATEGORIES = [
        BackgroundResult::CATEGORY_VIOLENT_CRIME,
        BackgroundResult::CATEGORY_SEXUAL_CRIME,
    ];

    public function __construct()
    {
        $this->apiKey   = (string) get_option('limpvix_exato_api_key', '');
        $this->token    = (string) get_option('limpvix_exato_token', '');
        $this->endpoint = (string) get_option('limpvix_exato_endpoint', 'https://api.exatodigital.com.br/v1');
    }

    public function check(
        string $cpf,
        string $fullName,
        string $birthDate,
    ): BackgroundResult {
        if (!$this->isConnected()) {
            throw new \RuntimeException(
                'ExatoBackgroundProvider não configurado. ' .
                'Configure as credenciais Exato em Settings → Verificação → Exato Digital.'
            );
        }

        // Circuit breaker check
        if ($this->isCircuitOpen()) {
            error_log('[ExatoBackgroundProvider] Circuit breaker OPEN — returning PENDING');
            return new BackgroundResult(
                status: BackgroundStatus::PENDING,
                riskLevel: RiskLevel::LOW,
                riskCategories: [],
                provider: $this->providerName(),
                checkedAt: new \DateTimeImmutable(),
                expiresAt: (new \DateTimeImmutable())->modify('+1 day'),
            );
        }

        try {
            // 1. Submit background check request
            $consultaId = $this->submitConsulta($cpf, $fullName, $birthDate);

            if ($consultaId === null) {
                $this->recordFailure();
                return $this->pendingResult();
            }

            // 2. Poll for result
            $resultado = $this->pollResultado($consultaId);

            if ($resultado === null) {
                $this->recordFailure();
                return $this->pendingResult();
            }

            // 3. Map response to internal categories (NEVER expose raw data)
            $riskCategories = $this->mapCategories($resultado);
            $riskLevel = $this->determineRiskLevel($riskCategories);
            $status = $this->determineStatus($riskCategories);

            // Reset circuit breaker on success
            $this->resetFailures();

            $validityDays = (int) get_option('limpvix_prof_background_check_validity_days', 365);

            $now = new \DateTimeImmutable();
            return new BackgroundResult(
                status: $status,
                riskLevel: $riskLevel,
                riskCategories: $riskCategories,
                provider: $this->providerName(),
                checkedAt: $now,
                expiresAt: $now->modify("+{$validityDays} days"),
            );

        } catch (\Throwable $e) {
            $this->recordFailure();
            error_log(sprintf(
                '[ExatoBackgroundProvider] Error for CPF %s: %s',
                substr($cpf, 0, 3) . '***',
                $e->getMessage()
            ));
            return $this->pendingResult();
        }
    }

    public function providerName(): string
    {
        return 'exato_digital';
    }

    public function isConnected(): bool
    {
        return !empty($this->apiKey) && !empty($this->token);
    }

    // ──────────────────────────────────────────────────────────────────────────
    // API Methods
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Submit background check request to Exato API
     */
    private function submitConsulta(string $cpf, string $fullName, string $birthDate): ?string
    {
        $response = wp_remote_post($this->endpoint . '/consultas/background', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->token,
                'X-Api-Key' => $this->apiKey,
                'Content-Type' => 'application/json',
            ],
            'body' => wp_json_encode([
                'cpf' => preg_replace('/\D/', '', $cpf),
                'nome_completo' => $fullName,
                'data_nascimento' => $birthDate,
                'consentimento_lgpd' => true,
                'tipo_consulta' => 'criminal_completa',
            ]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            error_log('[ExatoBackgroundProvider] Submit failed: ' . $response->get_error_message());
            return null;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log(sprintf('[ExatoBackgroundProvider] Submit returned HTTP %d', $code));
            return null;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        return $body['consulta_id'] ?? $body['id'] ?? null;
    }

    /**
     * Poll for background check result
     */
    private function pollResultado(string $consultaId): ?array
    {
        for ($attempt = 1; $attempt <= self::MAX_POLL_ATTEMPTS; $attempt++) {
            if ($attempt > 1) {
                sleep(self::POLL_INTERVAL_SECONDS);
            }

            $response = wp_remote_get($this->endpoint . '/consultas/' . $consultaId . '/resultado', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $this->token,
                    'X-Api-Key' => $this->apiKey,
                ],
                'timeout' => 15,
            ]);

            if (is_wp_error($response)) {
                continue;
            }

            $code = wp_remote_retrieve_response_code($response);

            // 202 = still processing
            if ($code === 202) {
                continue;
            }

            // 200 = result ready
            if ($code === 200) {
                return json_decode(wp_remote_retrieve_body($response), true);
            }

            // Other codes = error
            error_log(sprintf('[ExatoBackgroundProvider] Poll returned HTTP %d', $code));
            return null;
        }

        error_log(sprintf('[ExatoBackgroundProvider] Polling timed out after %d attempts for consulta %s', self::MAX_POLL_ATTEMPTS, $consultaId));
        return null;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Result Mapping (NEVER expose raw Exato data)
    // ──────────────────────────────────────────────────────────────────────────

    /**
     * Map Exato response categories to internal enums
     */
    private function mapCategories(array $resultado): array
    {
        $mapped = [];

        $rawCategories = $resultado['categorias'] ?? $resultado['ocorrencias'] ?? [];

        if (!is_array($rawCategories)) {
            return [];
        }

        foreach ($rawCategories as $raw) {
            $rawType = strtolower($raw['tipo'] ?? $raw['categoria'] ?? '');

            foreach (self::CATEGORY_MAP as $pattern => $internalCategory) {
                if (str_contains($rawType, $pattern)) {
                    $mapped[] = $internalCategory;
                    break;
                }
            }
        }

        // If categories found but none mapped, classify as OTHER
        if (!empty($rawCategories) && empty($mapped)) {
            $mapped[] = BackgroundResult::CATEGORY_OTHER;
        }

        return array_unique($mapped);
    }

    /**
     * Determine risk level from mapped categories
     */
    private function determineRiskLevel(array $categories): RiskLevel
    {
        if (empty($categories)) {
            return RiskLevel::LOW;
        }

        foreach (self::BLOCKING_CATEGORIES as $blocking) {
            if (in_array($blocking, $categories, true)) {
                return RiskLevel::HIGH;
            }
        }

        return RiskLevel::MEDIUM;
    }

    /**
     * Determine background status from categories
     */
    private function determineStatus(array $categories): BackgroundStatus
    {
        if (empty($categories)) {
            return BackgroundStatus::APPROVED;
        }

        // Blocking categories = NOT_ELIGIBLE
        foreach (self::BLOCKING_CATEGORIES as $blocking) {
            if (in_array($blocking, $categories, true)) {
                return BackgroundStatus::NOT_ELIGIBLE;
            }
        }

        // Other categories = RESTRICTED (needs admin review)
        return BackgroundStatus::RESTRICTED;
    }

    // ──────────────────────────────────────────────────────────────────────────
    // Circuit Breaker
    // ──────────────────────────────────────────────────────────────────────────

    private function isCircuitOpen(): bool
    {
        $state = get_transient(self::CB_TRANSIENT_KEY);
        if ($state === false) {
            return false;
        }

        $state = json_decode($state, true);
        if (!is_array($state) || empty($state['open_since'])) {
            return false;
        }

        return (time() - $state['open_since']) < self::CB_COOLDOWN_SECONDS;
    }

    private function recordFailure(): void
    {
        $state = get_transient(self::CB_TRANSIENT_KEY);
        $state = $state !== false ? json_decode($state, true) : [];
        if (!is_array($state)) {
            $state = [];
        }

        $count = ($state['failure_count'] ?? 0) + 1;
        $state['failure_count'] = $count;

        if ($count >= self::CB_FAILURE_THRESHOLD) {
            $state['open_since'] = time();
            error_log(sprintf(
                '[ExatoBackgroundProvider] Circuit breaker OPENED after %d failures. Cooldown: %d min.',
                $count,
                self::CB_COOLDOWN_SECONDS / 60
            ));
        }

        set_transient(self::CB_TRANSIENT_KEY, wp_json_encode($state), 3600);
    }

    private function resetFailures(): void
    {
        delete_transient(self::CB_TRANSIENT_KEY);
    }

    private function pendingResult(): BackgroundResult
    {
        return new BackgroundResult(
            status: BackgroundStatus::PENDING,
            riskLevel: RiskLevel::LOW,
            riskCategories: [],
            provider: $this->providerName(),
            checkedAt: new \DateTimeImmutable(),
            expiresAt: (new \DateTimeImmutable())->modify('+1 day'),
        );
    }
}
