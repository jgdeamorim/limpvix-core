<?php
/**
 * Send OTP Use Case
 *
 * Sends OTP code via configured provider (Twilio or NVoip) with automatic channel fallback.
 * Enforces rate limiting and security controls.
 *
 * @package LimpVix\Application\UseCase\Auth
 * @since Sprint 9 - OTP Verification
 */

namespace LimpVix\Application\UseCase\Auth;

use LimpVix\Infrastructure\SMS\OTPServiceInterface;

defined('ABSPATH') || exit;

/**
 * Class SendOtp
 *
 * Business logic for sending OTP codes.
 *
 * Security:
 * - Rate limiting: 3 sends per hour
 * - Temporary blocking after abuse
 * - Tracks in wp_limpvix_user_verifications table
 *
 * @since Sprint 9
 */
final class SendOtp
{
    private const MAX_SENDS_PER_HOUR = 3;
    private const RATE_LIMIT_WINDOW = 3600; // 1 hour
    private const BLOCK_DURATION = 900; // 15 minutes

    public function __construct(
        private OTPServiceInterface $otpProvider
    ) {}

    /**
     * Send OTP code to phone number
     *
     * @param int $userId WordPress user ID
     * @param string $phoneNumber E.164 format
     * @param string $channel 'auto', 'whatsapp', or 'sms'
     * @return array ['request_id' => string, 'channel' => string, 'rate_limit' => array]
     * @throws \RuntimeException If rate limit exceeded or send fails
     */
    public function execute(
        int $userId,
        string $phoneNumber,
        string $channel = 'auto'
    ): array {
        global $wpdb;

        // Validate phone number
        if (!$this->isValidPhoneNumber($phoneNumber)) {
            throw new \InvalidArgumentException('Invalid phone number format');
        }

        // Check if service is configured
        if (!$this->otpProvider->isConfigured()) {
            throw new \RuntimeException('OTP service is not properly configured');
        }

        // Get or create verification record
        $verification = $this->getOrCreateVerification($userId, $phoneNumber);

        // Check if user is blocked
        if ($verification->blocked_until && new \DateTime($verification->blocked_until) > new \DateTime()) {
            throw new \RuntimeException(
                sprintf(
                    'Too many attempts. Blocked until %s',
                    (new \DateTime($verification->blocked_until))->format('H:i')
                )
            );
        }

        // Check rate limit
        $this->checkRateLimit($verification);

        // Send OTP via configured provider (handles channel fallback)
        $requestId = $this->otpProvider->send($phoneNumber, $channel);

        // Update verification record
        $this->updateVerificationAfterSend($userId, $requestId, $channel);

        return [
            'request_id' => $requestId,
            'channel' => $channel === 'auto' ? 'whatsapp_or_sms' : $channel,
            'rate_limit' => [
                'remaining' => self::MAX_SENDS_PER_HOUR - ($verification->otp_send_count + 1),
                'reset_at' => $verification->otp_send_window_start 
                    ? (new \DateTime($verification->otp_send_window_start))->modify('+1 hour')
                    : (new \DateTime())->modify('+1 hour'),
            ],
        ];
    }

    /**
     * Get or create verification record
     *
     * @param int $userId
     * @param string $phoneNumber
     * @return object Verification record
     */
    private function getOrCreateVerification(int $userId, string $phoneNumber): object
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_user_verifications';

        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $userId
        ));

        if (!$verification) {
            $wpdb->insert(
                $table,
                [
                    'user_id' => $userId,
                    'phone_number' => $phoneNumber,
                    'phone_verified' => false,
                    'otp_send_count' => 0,
                    'created_at' => current_time('mysql'),
                ],
                ['%d', '%s', '%d', '%d', '%s']
            );

            return $this->getOrCreateVerification($userId, $phoneNumber);
        }

        return $verification;
    }

    /**
     * Check rate limit
     *
     * @param object $verification
     * @throws \RuntimeException If rate limit exceeded
     */
    private function checkRateLimit(object $verification): void
    {
        $now = new \DateTime();

        // If no window start, this is first send - OK
        if (!$verification->otp_send_window_start) {
            return;
        }

        $windowStart = new \DateTime($verification->otp_send_window_start);
        $windowAge = $now->getTimestamp() - $windowStart->getTimestamp();

        // Window expired, will be reset on update
        if ($windowAge >= self::RATE_LIMIT_WINDOW) {
            return;
        }

        // Check if limit exceeded
        if ($verification->otp_send_count >= self::MAX_SENDS_PER_HOUR) {
            $resetAt = $windowStart->modify('+1 hour');

            throw new \RuntimeException(
                sprintf(
                    'Rate limit exceeded. Try again after %s',
                    $resetAt->format('H:i')
                )
            );
        }
    }

    /**
     * Update verification record after send
     *
     * @param int $userId
     * @param string $requestId Provider verification key
     * @param string $channel Channel used
     * @return void
     */
    private function updateVerificationAfterSend(int $userId, string $requestId, string $channel): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_user_verifications';
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $userId
        ));

        $now = new \DateTime();
        $windowStart = $verification->otp_send_window_start 
            ? new \DateTime($verification->otp_send_window_start) 
            : null;

        // Reset window if expired
        if (!$windowStart || ($now->getTimestamp() - $windowStart->getTimestamp()) >= self::RATE_LIMIT_WINDOW) {
            $wpdb->update(
                $table,
                [
                    'last_request_id' => $requestId,
                    'last_channel' => $channel,
                    'otp_send_count' => 1,
                    'otp_send_window_start' => $now->format('Y-m-d H:i:s'),
                    'otp_attempts' => 0, // Reset verification attempts on new send
                    'updated_at' => current_time('mysql'),
                ],
                ['user_id' => $userId],
                ['%s', '%s', '%d', '%s', '%d', '%s'],
                ['%d']
            );
        } else {
            // Increment within window
            $wpdb->update(
                $table,
                [
                    'last_request_id' => $requestId,
                    'last_channel' => $channel,
                    'otp_send_count' => $verification->otp_send_count + 1,
                    'otp_attempts' => 0, // Reset verification attempts on new send
                    'updated_at' => current_time('mysql'),
                ],
                ['user_id' => $userId],
                ['%s', '%s', '%d', '%d', '%s'],
                ['%d']
            );
        }
    }

    /**
     * Validate phone number format
     *
     * @param string $phoneNumber
     * @return bool
     */
    private function isValidPhoneNumber(string $phoneNumber): bool
    {
        $cleaned = preg_replace('/[^0-9]/', '', $phoneNumber);
        $digitCount = strlen($cleaned);

        return $digitCount >= 10 && $digitCount <= 15;
    }
}
