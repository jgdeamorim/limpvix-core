<?php
/**
 * FirebasePhoneVerificationAdapter
 *
 * Valida Firebase ID Token (SMS OTP) via Google Identity Toolkit REST API.
 * Substitui o mock que aceitava qualquer token.
 *
 * FLUXO:
 * 1. Frontend envia SMS OTP via Firebase SDK (client-side)
 * 2. Firebase retorna idToken ao frontend
 * 3. Frontend envia idToken ao backend via VerifyBriefingPhone
 * 4. Backend valida idToken via Google API (este adapter)
 * 5. Se válido → extrai phoneNumber e verifica match
 *
 * ENDPOINTS GOOGLE:
 * - Verify token: POST https://identitytoolkit.googleapis.com/v1/accounts:lookup?key={API_KEY}
 *
 * CONFIGURAÇÃO (wp_options):
 * - limpvix_firebase_api_key    → Web API Key
 * - limpvix_firebase_project_id → Project ID
 *
 * @package LimpVix\Infrastructure\Auth
 * @since 0.11.0
 */

namespace LimpVix\Infrastructure\Auth;

defined('ABSPATH') || exit;

final class FirebasePhoneVerificationAdapter
{
    private const VERIFY_URL = 'https://identitytoolkit.googleapis.com/v1/accounts:lookup';

    private string $apiKey;
    private string $projectId;
    private bool $enabled;

    public function __construct()
    {
        $this->apiKey = (string) get_option('limpvix_firebase_api_key', '');
        $this->projectId = (string) get_option('limpvix_firebase_project_id', '');
        $this->enabled = !empty($this->apiKey) && !empty($this->projectId);
    }

    /**
     * Verificar se Firebase está configurado
     */
    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    /**
     * Validar Firebase ID Token e extrair dados do usuário
     *
     * @param string $idToken Firebase ID Token recebido do frontend
     * @return array{valid: bool, phone_number: ?string, uid: ?string, error: ?string}
     */
    public function verifyIdToken(string $idToken): array
    {
        if (!$this->enabled) {
            error_log('[LimpVix][Firebase] Not configured — falling back to permissive mode');
            return [
                'valid' => true,
                'phone_number' => null,
                'uid' => null,
                'error' => null,
                'fallback' => true,
            ];
        }

        if (empty($idToken)) {
            return [
                'valid' => false,
                'phone_number' => null,
                'uid' => null,
                'error' => 'Empty ID token',
            ];
        }

        $url = self::VERIFY_URL . '?key=' . urlencode($this->apiKey);

        $response = wp_remote_post($url, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['idToken' => $idToken]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            error_log('[LimpVix][Firebase] Verify error: ' . $response->get_error_message());
            return [
                'valid' => false,
                'phone_number' => null,
                'uid' => null,
                'error' => 'Firebase connection error: ' . $response->get_error_message(),
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            $errorMessage = $body['error']['message'] ?? "HTTP {$statusCode}";
            error_log("[LimpVix][Firebase] Verify failed ({$statusCode}): {$errorMessage}");
            return [
                'valid' => false,
                'phone_number' => null,
                'uid' => null,
                'error' => "Firebase error: {$errorMessage}",
            ];
        }

        // Extrair dados do primeiro usuário retornado
        $users = $body['users'] ?? [];

        if (empty($users)) {
            return [
                'valid' => false,
                'phone_number' => null,
                'uid' => null,
                'error' => 'No user found for this token',
            ];
        }

        $user = $users[0];
        $phoneNumber = $user['phoneNumber'] ?? null;
        $uid = $user['localId'] ?? null;

        if (empty($phoneNumber)) {
            return [
                'valid' => false,
                'phone_number' => null,
                'uid' => $uid,
                'error' => 'Token valid but no phone number associated',
            ];
        }

        error_log(sprintf(
            '[LimpVix][Firebase] Phone verified: %s (uid: %s)',
            $this->maskPhone($phoneNumber),
            $uid
        ));

        return [
            'valid' => true,
            'phone_number' => $phoneNumber,
            'uid' => $uid,
            'error' => null,
        ];
    }

    /**
     * Verificar se o telefone do token match com o esperado
     *
     * @param string $idToken Firebase ID Token
     * @param string $expectedPhone Telefone esperado (formato: +55DDDNUMERO)
     * @return array{valid: bool, match: bool, error: ?string}
     */
    public function verifyPhoneMatch(string $idToken, string $expectedPhone): array
    {
        $result = $this->verifyIdToken($idToken);

        if (!$result['valid']) {
            return [
                'valid' => false,
                'match' => false,
                'error' => $result['error'],
            ];
        }

        // Se fallback (não configurado), aceitar
        if (!empty($result['fallback'])) {
            return [
                'valid' => true,
                'match' => true,
                'error' => null,
            ];
        }

        // Normalizar e comparar telefones
        $normalizedExpected = $this->normalizePhone($expectedPhone);
        $normalizedActual = $this->normalizePhone($result['phone_number'] ?? '');

        $match = $normalizedExpected === $normalizedActual;

        if (!$match) {
            error_log(sprintf(
                '[LimpVix][Firebase] Phone mismatch: expected=%s, got=%s',
                $this->maskPhone($normalizedExpected),
                $this->maskPhone($normalizedActual)
            ));
        }

        return [
            'valid' => true,
            'match' => $match,
            'error' => $match ? null : 'Phone number does not match',
        ];
    }

    /**
     * Normalizar telefone para formato +55XXXXXXXXXXX
     */
    private function normalizePhone(string $phone): string
    {
        $digits = preg_replace('/\D/', '', $phone);

        // Se começa com 55 e tem 12-13 dígitos, é BR completo
        if (str_starts_with($digits, '55') && strlen($digits) >= 12) {
            return '+' . $digits;
        }

        // Se tem 10-11 dígitos, adicionar +55
        if (strlen($digits) >= 10 && strlen($digits) <= 11) {
            return '+55' . $digits;
        }

        return '+' . $digits;
    }

    /**
     * Mascarar telefone para log (segurança)
     */
    private function maskPhone(string $phone): string
    {
        if (strlen($phone) < 6) {
            return '***';
        }
        return substr($phone, 0, 4) . '****' . substr($phone, -2);
    }
}
