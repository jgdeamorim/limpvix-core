<?php
/**
 * FirebasePushProvider - Push Notifications via Firebase Cloud Messaging (FCM)
 *
 * Envia push notifications para dispositivos móveis via FCM HTTP v1 API.
 *
 * CONFIGURAÇÃO (wp_options):
 * - limpvix_fcm_server_key → FCM Server Key (legacy, mas funcional)
 * - limpvix_firebase_project_id → Firebase Project ID (já configurado)
 *
 * DEVICE TOKENS:
 * - Armazenados em user_meta: limpvix_fcm_device_tokens (JSON array)
 * - Registrados via REST endpoint POST /limpvix/v1/push/register
 *
 * FLUXO:
 * 1. Frontend registra device token via API
 * 2. Backend envia push via FCM quando evento ocorre
 * 3. Device tokens inválidos são removidos automaticamente
 *
 * @package LimpVix\Infrastructure\Communication\Providers
 * @since 0.12.0
 */

namespace LimpVix\Infrastructure\Communication\Providers;

defined('ABSPATH') || exit;

final class FirebasePushProvider
{
    private const FCM_SEND_URL = 'https://fcm.googleapis.com/fcm/send';
    private const TIMEOUT_SECONDS = 15;

    private string $serverKey;
    private string $projectId;
    private bool $enabled;

    public function __construct()
    {
        $this->serverKey = (string) get_option('limpvix_fcm_server_key', '');
        $this->projectId = (string) get_option('limpvix_firebase_project_id', '');
        $this->enabled = !empty($this->serverKey) && !empty($this->projectId);
    }

    /**
     * Check if FCM is configured
     */
    public function isConfigured(): bool
    {
        return $this->enabled;
    }

    /**
     * Send push notification to a user
     *
     * @param int $userId WordPress user ID
     * @param string $title Notification title
     * @param string $body Notification body
     * @param array $data Extra data payload (key-value pairs, all strings)
     * @return array{success: bool, sent: int, failed: int, error: ?string}
     */
    public function sendToUser(int $userId, string $title, string $body, array $data = []): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'error' => 'FCM not configured'];
        }

        $tokens = $this->getDeviceTokens($userId);

        if (empty($tokens)) {
            return ['success' => false, 'sent' => 0, 'failed' => 0, 'error' => 'No device tokens for user'];
        }

        $sent = 0;
        $failed = 0;
        $invalidTokens = [];

        foreach ($tokens as $token) {
            $result = $this->sendToDevice($token, $title, $body, $data);

            if ($result['success']) {
                $sent++;
            } else {
                $failed++;
                // Mark invalid tokens for removal
                if ($result['invalid_token'] ?? false) {
                    $invalidTokens[] = $token;
                }
            }
        }

        // Remove invalid tokens
        if (!empty($invalidTokens)) {
            $this->removeDeviceTokens($userId, $invalidTokens);
        }

        return [
            'success' => $sent > 0,
            'sent' => $sent,
            'failed' => $failed,
            'error' => $sent === 0 ? 'All push attempts failed' : null,
        ];
    }

    /**
     * Send push to a specific device token
     *
     * @param string $deviceToken FCM device token
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array{success: bool, invalid_token: bool}
     */
    public function sendToDevice(string $deviceToken, string $title, string $body, array $data = []): array
    {
        $payload = [
            'to' => $deviceToken,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
                'badge' => 1,
            ],
            'data' => array_map('strval', $data),
            'priority' => 'high',
        ];

        $response = wp_remote_post(self::FCM_SEND_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'key=' . $this->serverKey,
            ],
            'body' => json_encode($payload),
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (is_wp_error($response)) {
            error_log('[LimpVix][FCM] Send error: ' . $response->get_error_message());
            return ['success' => false, 'invalid_token' => false];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        $responseBody = json_decode(wp_remote_retrieve_body($response), true);

        if ($statusCode !== 200) {
            error_log(sprintf('[LimpVix][FCM] HTTP %d: %s', $statusCode, wp_remote_retrieve_body($response)));
            return ['success' => false, 'invalid_token' => false];
        }

        // Check FCM response
        $success = ($responseBody['success'] ?? 0) > 0;
        $invalidToken = false;

        // Check for invalid registration token errors
        if (!empty($responseBody['results'])) {
            foreach ($responseBody['results'] as $result) {
                $error = $result['error'] ?? '';
                if (in_array($error, ['NotRegistered', 'InvalidRegistration'], true)) {
                    $invalidToken = true;
                }
            }
        }

        return ['success' => $success, 'invalid_token' => $invalidToken];
    }

    /**
     * Send push to a topic (broadcast)
     *
     * @param string $topic Topic name (e.g. 'all_customers', 'all_professionals')
     * @param string $title
     * @param string $body
     * @param array $data
     * @return array{success: bool, error: ?string}
     */
    public function sendToTopic(string $topic, string $title, string $body, array $data = []): array
    {
        if (!$this->enabled) {
            return ['success' => false, 'error' => 'FCM not configured'];
        }

        $payload = [
            'to' => '/topics/' . $topic,
            'notification' => [
                'title' => $title,
                'body' => $body,
                'sound' => 'default',
            ],
            'data' => array_map('strval', $data),
            'priority' => 'high',
        ];

        $response = wp_remote_post(self::FCM_SEND_URL, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Authorization' => 'key=' . $this->serverKey,
            ],
            'body' => json_encode($payload),
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        if (is_wp_error($response)) {
            return ['success' => false, 'error' => $response->get_error_message()];
        }

        $statusCode = wp_remote_retrieve_response_code($response);
        return [
            'success' => $statusCode === 200,
            'error' => $statusCode !== 200 ? "HTTP {$statusCode}" : null,
        ];
    }

    /**
     * Register a device token for a user
     *
     * @param int $userId
     * @param string $deviceToken
     * @param string $platform 'android' or 'ios'
     * @return bool
     */
    public function registerDeviceToken(int $userId, string $deviceToken, string $platform = 'android'): bool
    {
        $tokens = $this->getDeviceTokensRaw($userId);

        // Check if token already registered
        foreach ($tokens as $entry) {
            if ($entry['token'] === $deviceToken) {
                return true; // Already registered
            }
        }

        $tokens[] = [
            'token' => $deviceToken,
            'platform' => $platform,
            'registered_at' => current_time('mysql'),
        ];

        return update_user_meta($userId, 'limpvix_fcm_device_tokens', json_encode($tokens));
    }

    /**
     * Unregister a device token
     *
     * @param int $userId
     * @param string $deviceToken
     * @return bool
     */
    public function unregisterDeviceToken(int $userId, string $deviceToken): bool
    {
        $tokens = $this->getDeviceTokensRaw($userId);

        $tokens = array_filter($tokens, fn($entry) => $entry['token'] !== $deviceToken);

        return update_user_meta($userId, 'limpvix_fcm_device_tokens', json_encode(array_values($tokens)));
    }

    /**
     * Get device tokens for a user (token strings only)
     *
     * @param int $userId
     * @return string[]
     */
    private function getDeviceTokens(int $userId): array
    {
        $raw = $this->getDeviceTokensRaw($userId);
        return array_map(fn($entry) => $entry['token'], $raw);
    }

    /**
     * Get raw device token entries
     *
     * @param int $userId
     * @return array
     */
    private function getDeviceTokensRaw(int $userId): array
    {
        $stored = get_user_meta($userId, 'limpvix_fcm_device_tokens', true);

        if (empty($stored)) {
            return [];
        }

        $decoded = json_decode($stored, true);
        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Remove invalid device tokens
     *
     * @param int $userId
     * @param string[] $tokensToRemove
     */
    private function removeDeviceTokens(int $userId, array $tokensToRemove): void
    {
        $tokens = $this->getDeviceTokensRaw($userId);
        $tokens = array_filter($tokens, fn($entry) => !in_array($entry['token'], $tokensToRemove, true));

        update_user_meta($userId, 'limpvix_fcm_device_tokens', json_encode(array_values($tokens)));

        error_log(sprintf(
            '[LimpVix][FCM] Removed %d invalid token(s) for user %d',
            count($tokensToRemove),
            $userId
        ));
    }
}
