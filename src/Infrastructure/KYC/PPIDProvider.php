<?php
namespace LimpVix\Infrastructure\KYC;

defined('ABSPATH') || exit;

/**
 * PPID Provider - Real API Client
 *
 * Cliente para integração com API PPID (https://api.ppid.com.br)
 * - Login JWT
 * - OCR de documentos
 * - Liveness detection
 * - Face match
 * - Classification
 */
final class PPIDProvider
{
    private const BASE_URL = 'https://api.ppid.com.br';
    private const TOKEN_CACHE_KEY = 'limpvix_ppid_jwt_token';
    private const TOKEN_CACHE_DURATION = 82800; // 23 horas

    private ?string $jwtToken = null;

    /**
     * Login e obter JWT token
     */
    public function login(): array
    {
        // Check cache first
        $cachedToken = get_transient(self::TOKEN_CACHE_KEY);
        if ($cachedToken) {
            $this->jwtToken = $cachedToken;
            
            return [
                'success' => true,
                'token' => $cachedToken,
                'saldo' => $this->getSaldo(),
                'cached' => true,
            ];
        }

        $email = get_option('limpvix_ppid_email');
        $senhaRaw = get_option('limpvix_ppid_senha');
        $senha = \LimpVix\Infrastructure\Security\TokenEncryption::decryptSafe((string) $senhaRaw);

        if (empty($email) || empty($senha)) {
            throw new \RuntimeException('PPID credentials not configured');
        }

        $response = wp_remote_post(self::BASE_URL . '/api/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'senha' => $senha]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            throw new \RuntimeException('PPID login failed: ' . $response->get_error_message());
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            throw new \RuntimeException(sprintf('PPID login error %d: %s', $statusCode, $body['message'] ?? 'Unknown error'));
        }

        $this->jwtToken = $body['token'];
        
        // Cache token
        set_transient(self::TOKEN_CACHE_KEY, $this->jwtToken, self::TOKEN_CACHE_DURATION);

        return [
            'success' => true,
            'token' => $this->jwtToken,
            'saldo' => $body['saldo'] ?? 0,
            'nome' => $body['nome'] ?? 'Unknown',
        ];
    }

    /**
     * OCR - Extract data from document
     */
    public function ocr(string $imagemBase64, ?string $mimeType = null): array
    {
        $this->ensureAuthenticated();

        $response = wp_remote_post(self::BASE_URL . '/api/ocr/consultar', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->jwtToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'imagem' => $imagemBase64,
                'tipo_documento' => 'rg',
            ]),
            'timeout' => 60,
        ]);

        return $this->handleResponse($response, 'OCR');
    }

    /**
     * Liveness - Verify if selfie is a real person
     */
    public function liveness(string $selfieBase64): array
    {
        $this->ensureAuthenticated();

        $response = wp_remote_post(self::BASE_URL . '/api/liveness/consultar', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->jwtToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['selfie' => $selfieBase64]),
            'timeout' => 60,
        ]);

        return $this->handleResponse($response, 'Liveness');
    }

    /**
     * Face Match - Compare document photo with selfie
     */
    public function faceMatch(string $documentoBase64, string $selfieBase64): array
    {
        $this->ensureAuthenticated();

        $response = wp_remote_post(self::BASE_URL . '/api/facematch/consultar', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->jwtToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode([
                'documento' => $documentoBase64,
                'selfie' => $selfieBase64,
            ]),
            'timeout' => 60,
        ]);

        return $this->handleResponse($response, 'Face Match');
    }

    /**
     * Classification - Classify document type
     */
    public function classification(string $imagemBase64): array
    {
        $this->ensureAuthenticated();

        $response = wp_remote_post(self::BASE_URL . '/api/classification/consultar', [
            'headers' => [
                'Authorization' => 'Bearer ' . $this->jwtToken,
                'Content-Type' => 'application/json',
            ],
            'body' => json_encode(['imagem' => $imagemBase64]),
            'timeout' => 60,
        ]);

        return $this->handleResponse($response, 'Classification');
    }

    /**
     * Test connection
     */
    public static function testConnection(string $email, string $senha): array
    {
        $response = wp_remote_post(self::BASE_URL . '/api/auth/login', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => json_encode(['email' => $email, 'senha' => $senha]),
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            return [
                'success' => false,
                'message' => 'Erro de conexão: ' . $response->get_error_message(),
            ];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            return [
                'success' => false,
                'message' => sprintf('Erro PPID %d: %s', $statusCode, $body['message'] ?? 'Erro desconhecido'),
            ];
        }

        set_transient('limpvix_ppid_saldo', $body['saldo'] ?? 0, 3600);
        set_transient('limpvix_ppid_last_check', time(), 3600);

        return [
            'success' => true,
            'message' => 'Conexão estabelecida com sucesso!',
            'saldo' => $body['saldo'] ?? 0,
            'nome' => $body['nome'] ?? 'Unknown',
        ];
    }

    private function ensureAuthenticated(): void
    {
        if (!$this->jwtToken) {
            $this->login();
        }
    }

    private function getSaldo(): float
    {
        return (float) get_transient('limpvix_ppid_saldo') ?: 0;
    }

    private function handleResponse($response, string $operation): array
    {
        if (is_wp_error($response)) {
            throw new \RuntimeException(sprintf('%s failed: %s', $operation, $response->get_error_message()));
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode === 401) {
            delete_transient(self::TOKEN_CACHE_KEY);
            throw new \RuntimeException('JWT token expired - please retry');
        }

        if ($statusCode === 402) {
            throw new \RuntimeException('Insufficient PPID credits');
        }

        if ($statusCode >= 400) {
            throw new \RuntimeException(sprintf('PPID %s error %d: %s', $operation, $statusCode, $body['message'] ?? 'Unknown error'));
        }

        return $body;
    }
}
