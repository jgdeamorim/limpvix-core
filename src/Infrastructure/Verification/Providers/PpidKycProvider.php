<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Verification\Providers;

use LimpVix\Domain\Verification\Contracts\KycProviderInterface;
use LimpVix\Domain\Verification\ValueObjects\KycResult;
use LimpVix\Infrastructure\KYC\PPIDProvider;

/**
 * PpidKycProvider — Integração real com PPID (Prova de Posse de Identidade Digital)
 *
 * G-KYC-REAL: Delegates to PPIDProvider for real API calls.
 *
 * Flow:
 * 1. Login (JWT cached 23h)
 * 2. OCR document image → extract data
 * 3. Liveness detection on selfie
 * 4. Face match document vs selfie
 * 5. Map results to KycResult (never expose raw payload)
 *
 * Ativado automaticamente pelo VerificationProviderFactory quando as
 * credenciais estiverem configuradas (limpvix_ppid_email + limpvix_ppid_senha).
 *
 * @see VerificationProviderFactory::kycProvider()
 */
final class PpidKycProvider implements KycProviderInterface
{
    private string $apiKey;
    private string $endpoint;
    private ?PPIDProvider $ppidClient = null;

    // Thresholds for approval
    private const LIVENESS_THRESHOLD = 0.70;
    private const FACEMATCH_THRESHOLD = 0.75;

    public function __construct()
    {
        $this->apiKey   = (string) get_option('limpvix_ppid_api_key', '');
        $this->endpoint = (string) get_option('limpvix_ppid_endpoint', 'https://api.ppid.com.br/v1');
    }

    public function verify(
        string $cpf,
        string $fullName,
        string $birthDate,
        string $documentUrl,
        string $selfieUrl,
    ): KycResult {
        if (!$this->isConnected()) {
            throw new \RuntimeException(
                'PpidKycProvider não configurado. ' .
                'Configure as credenciais PPID em Settings → Verificação → PPID.'
            );
        }

        try {
            $client = $this->getClient();

            // 1. Login (cached JWT)
            $client->login();

            // 2. Get images as base64
            $documentBase64 = $this->urlToBase64($documentUrl);
            $selfieBase64 = $this->urlToBase64($selfieUrl);

            if ($documentBase64 === null || $selfieBase64 === null) {
                return KycResult::rejected(0.0, $this->providerName());
            }

            // 3. OCR — extract document data
            $ocrResult = $client->ocr($documentBase64);
            $ocrSuccess = !empty($ocrResult['nome'] ?? $ocrResult['cpf'] ?? null);

            // 4. Liveness — verify selfie is real person
            $livenessResult = $client->liveness($selfieBase64);
            $livenessScore = (float) ($livenessResult['score'] ?? $livenessResult['confianca'] ?? 0);
            $livenessPass = $livenessScore >= self::LIVENESS_THRESHOLD;

            // 5. Face Match — compare document photo with selfie
            $faceMatchResult = $client->faceMatch($documentBase64, $selfieBase64);
            $faceMatchScore = (float) ($faceMatchResult['score'] ?? $faceMatchResult['similaridade'] ?? 0);
            $faceMatchPass = $faceMatchScore >= self::FACEMATCH_THRESHOLD;

            // 6. Calculate composite confidence
            $confidence = ($livenessScore * 0.4) + ($faceMatchScore * 0.4) + ($ocrSuccess ? 0.2 : 0.0);

            // 7. Determine result
            if ($livenessPass && $faceMatchPass && $ocrSuccess) {
                return KycResult::approved($confidence, $this->providerName());
            }

            // Fraud flag if liveness failed significantly
            $fraudFlag = $livenessScore < 0.3;

            return KycResult::rejected($confidence, $this->providerName(), $fraudFlag);

        } catch (\RuntimeException $e) {
            error_log(sprintf(
                '[PpidKycProvider] KYC verification failed for CPF %s: %s',
                substr($cpf, 0, 3) . '***',
                $e->getMessage()
            ));

            // On 401 (expired token), clear cache and retry once
            if (str_contains($e->getMessage(), 'expired') || str_contains($e->getMessage(), '401')) {
                delete_transient('limpvix_ppid_jwt_token');
            }

            throw $e;
        }
    }

    public function providerName(): string
    {
        return 'ppid';
    }

    public function isConnected(): bool
    {
        // PPID uses email+senha authentication (not apiKey)
        $email = (string) get_option('limpvix_ppid_email', '');
        $senha = (string) get_option('limpvix_ppid_senha', '');

        return (!empty($email) && !empty($senha)) || !empty($this->apiKey);
    }

    private function getClient(): PPIDProvider
    {
        if ($this->ppidClient === null) {
            $this->ppidClient = new PPIDProvider();
        }
        return $this->ppidClient;
    }

    /**
     * Download image URL and convert to base64
     */
    private function urlToBase64(string $url): ?string
    {
        // If already base64, return as-is
        if (str_starts_with($url, 'data:image/') || !str_contains($url, '://')) {
            return $url;
        }

        $response = wp_remote_get($url, ['timeout' => 30]);

        if (is_wp_error($response)) {
            error_log('[PpidKycProvider] Failed to download image: ' . $response->get_error_message());
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if (empty($body)) {
            return null;
        }

        return base64_encode($body);
    }
}
