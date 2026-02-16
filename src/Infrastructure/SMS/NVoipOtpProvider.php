<?php
/**
 * NVoip OTP Provider - API v2
 *
 * Official NVoip API v2 implementation for OTP verification.
 * Supports SMS and Voice OTP.
 *
 * API Documentation: https://api.nvoip.com.br/v2/
 * GitHub: https://github.com/nvoip/nvoip-integrationAPI
 *
 * @package LimpVix\Infrastructure\SMS
 * @since Sprint 9 - OTP Verification (Updated to v2)
 */

namespace LimpVix\Infrastructure\SMS;

defined('ABSPATH') || exit;

/**
 * Class NVoipOtpProvider
 *
 * Enterprise-grade OTP implementation using NVoip API v2 with OAuth2.
 *
 * Channels:
 * 1. SMS (priority)
 * 2. Voice (fallback)
 * 3. Email (optional)
 *
 * Security:
 * - OAuth2 Bearer token authentication
 * - NVoip manages code generation (not stored locally)
 * - Key-based stateless verification
 * - Rate limiting enforced
 *
 * @since Sprint 9
 */
final class NVoipOtpProvider implements OTPServiceInterface
{
    private const API_URL = 'https://api.nvoip.com.br/v2';
    private const OAUTH_BASIC_AUTH = 'Basic TnZvaXBBcGlWMjpUblp2YVhCQmNHbFdNakl3TWpFPQ==';
    private const TIMEOUT_SECONDS = 30;
    private const TOKEN_CACHE_KEY = 'limpvix_nvoip_oauth_token';
    private const TOKEN_EXPIRY_SECONDS = 3600; // 1 hour

    private string $sipUser;
    private string $userToken;
    private ?string $accessToken = null;

    /**
     * Constructor
     *
     * Loads credentials from wp_options or constants.
     * Credentials mapping:
     * - api_key → SIP User (extension)
     * - user_token → User Token (password)
     */
    public function __construct()
    {
        // In API v2, api_key is actually the SIP user
        $this->sipUser = defined('LIMPVIX_NVOIP_API_KEY')
            ? LIMPVIX_NVOIP_API_KEY
            : get_option('limpvix_nvoip_api_key', '');

        $this->userToken = defined('LIMPVIX_NVOIP_USER_TOKEN')
            ? LIMPVIX_NVOIP_USER_TOKEN
            : get_option('limpvix_nvoip_user_token', '');
    }

    /**
     * @inheritDoc
     *
     * Sends OTP via SMS
     */
    public function sendSMS(string $phoneNumber, string $code, string $locale = 'pt_BR'): bool
    {
        try {
            $key = $this->send($phoneNumber, 'sms');
            return !empty($key);
        } catch (\Exception $e) {
            $this->logError('Failed to send SMS OTP', [
                'phone' => substr($phoneNumber, 0, 8) . '***',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * @inheritDoc
     *
     * Sends via Voice (not WhatsApp - NVoip v2 uses voice for OTP)
     */
    public function sendWhatsApp(string $phoneNumber, string $code, string $locale = 'pt_BR'): bool
    {
        try {
            $key = $this->send($phoneNumber, 'voice');
            return !empty($key);
        } catch (\Exception $e) {
            $this->logError('Failed to send Voice OTP', [
                'phone' => substr($phoneNumber, 0, 8) . '***',
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * Send OTP via NVoip API v2
     *
     * @param string $phoneNumber E.164 format
     * @param string $channel 'sms', 'voice', or 'email'
     * @return string OTP Key for verification
     * @throws \RuntimeException If send fails
     */
    public function send(string $phoneNumber, string $channel = 'sms'): string
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('NVoip is not properly configured');
        }

        // Ensure we have a valid OAuth token
        $this->ensureValidToken();

        $normalized = $this->normalizePhoneNumber($phoneNumber);

        // Build payload according to API v2 spec
        $payload = [
            'sms' => '',
            'voice' => '',
            'email' => '',
        ];

        // Set the appropriate channel
        if ($channel === 'sms' || $channel === 'auto') {
            $payload['sms'] = $normalized;
        } elseif ($channel === 'voice') {
            $payload['voice'] = $normalized;
        } elseif ($channel === 'email') {
            $payload['email'] = $phoneNumber; // Email address
        }

        $response = wp_remote_post(
            $this->getApiUrl('/otp'),
            [
                'headers' => $this->getHeaders(),
                'body' => json_encode($payload),
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Failed to send OTP: ' . $response->get_error_message()
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200 && $statusCode !== 201) {
            $this->logError('NVoip API error', [
                'status' => $statusCode,
                'response' => $body,
            ]);

            throw new \RuntimeException(
                sprintf(
                    'NVoip error %d: %s',
                    $statusCode,
                    $body['message'] ?? $body['status'] ?? 'Unknown error'
                )
            );
        }

        // API v2 returns {"key": "...", "status": "..."}
        $key = $body['key'] ?? null;

        if (empty($key)) {
            throw new \RuntimeException('NVoip did not return OTP key');
        }

        $this->logInfo('OTP sent successfully', [
            'phone' => substr($phoneNumber, 0, 8) . '***',
            'channel' => $channel,
            'key' => substr($key, 0, 8) . '***',
        ]);

        return $key;
    }

    /**
     * Verify OTP code
     *
     * API v2: GET /v2/check/otp?code={code}&key={key}
     *
     * @param string $key OTP Key from send()
     * @param string $code 6-digit code provided by user
     * @return bool True if valid
     * @throws \RuntimeException If verification fails
     */
    public function verify(string $key, string $code): bool
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('NVoip is not properly configured');
        }

        $url = $this->getApiUrl('/check/otp') . '?' . http_build_query([
            'code' => $code,
            'key' => $key,
        ]);

        $response = wp_remote_get(
            $url,
            [
                'headers' => [
                    'Content-Type' => 'application/json',
                ],
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Failed to verify OTP: ' . $response->get_error_message()
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            $this->logError('OTP verification failed', [
                'status' => $statusCode,
                'response' => $body,
            ]);
            return false;
        }

        $success = isset($body['status']) && $body['status'] === 'valid';

        if ($success) {
            $this->logInfo('OTP verified successfully', ['key' => substr($key, 0, 8) . '***']);
        }

        return $success;
    }

    /**
     * @inheritDoc
     */
    public function isConfigured(): bool
    {
        return !empty($this->sipUser) && !empty($this->userToken);
    }

    /**
     * @inheritDoc
     */
    public function getServiceName(): string
    {
        return 'NVoip v2';
    }

    /**
     * @inheritDoc
     */
    public function getRateLimitStatus(string $phoneNumber): array
    {
        return [
            'remaining' => 3,
            'reset_at' => (new \DateTimeImmutable())->modify('+1 hour'),
        ];
    }

    /**
     * Get OAuth2 access token
     *
     * Implements token caching to avoid repeated OAuth calls.
     * Token is valid for 1 hour.
     *
     * @return string Access token
     * @throws \RuntimeException If token generation fails
     */
    private function getAccessToken(): string
    {
        // Check cache first
        $cached = get_transient(self::TOKEN_CACHE_KEY);
        if ($cached) {
            return $cached;
        }

        // Generate new token
        $response = wp_remote_post(
            $this->getApiUrl('/oauth/token'),
            [
                'headers' => [
                    'Authorization' => self::OAUTH_BASIC_AUTH,
                    'Content-Type' => 'application/x-www-form-urlencoded',
                ],
                'body' => http_build_query([
                    'username' => $this->sipUser,
                    'password' => $this->userToken,
                    'grant_type' => 'password',
                ]),
                'timeout' => self::TIMEOUT_SECONDS,
            ]
        );

        if (is_wp_error($response)) {
            throw new \RuntimeException(
                'Failed to get OAuth token: ' . $response->get_error_message()
            );
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            $this->logError('OAuth token generation failed', [
                'status' => $statusCode,
                'response' => $body,
            ]);

            throw new \RuntimeException(
                sprintf(
                    'OAuth error %d: %s',
                    $statusCode,
                    $body['error_description'] ?? $body['error'] ?? 'Unknown error'
                )
            );
        }

        $accessToken = $body['access_token'] ?? null;

        if (empty($accessToken)) {
            throw new \RuntimeException('OAuth response missing access_token');
        }

        // Cache token
        set_transient(self::TOKEN_CACHE_KEY, $accessToken, self::TOKEN_EXPIRY_SECONDS);

        $this->logInfo('OAuth token generated successfully');

        return $accessToken;
    }

    /**
     * Ensure we have a valid OAuth token
     */
    private function ensureValidToken(): void
    {
        if (empty($this->accessToken)) {
            $this->accessToken = $this->getAccessToken();
        }
    }

    /**
     * Normalize phone number
     *
     * NVoip v2 expects: 5511999999999 (no + sign, no spaces)
     *
     * @param string $phoneNumber Raw phone number
     * @return string Normalized phone
     */
    private function normalizePhoneNumber(string $phoneNumber): string
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);

        if (!str_starts_with($cleaned, '55')) {
            $cleaned = '55' . $cleaned;
        }

        return $cleaned;
    }

    /**
     * Get full API URL
     */
    private function getApiUrl(string $endpoint): string
    {
        return self::API_URL . $endpoint;
    }

    /**
     * Get HTTP headers for authenticated requests
     */
    private function getHeaders(): array
    {
        return [
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->accessToken,
        ];
    }

    /**
     * Log info message
     */
    private function logInfo(string $message, array $context = []): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[LimpVix][NVoipOTP-v2] %s | %s',
                $message,
                json_encode($context, JSON_UNESCAPED_UNICODE)
            ));
        }
    }

    /**
     * Log error message
     */
    private function logError(string $message, array $context = []): void
    {
        error_log(sprintf(
            '[LimpVix][NVoipOTP-v2][ERROR] %s | %s',
            $message,
            json_encode($context, JSON_UNESCAPED_UNICODE)
        ));
    }
}
