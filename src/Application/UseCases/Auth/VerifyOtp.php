<?php
/**
 * Verify OTP Use Case
 *
 * Verifies OTP code via configured provider (Twilio or NVoip).
 * Implements brute force protection and account locking.
 *
 * @package LimpVix\Application\UseCases\Auth
 * @since Sprint 9 - OTP Verification
 */

namespace LimpVix\Application\UseCases\Auth;

use LimpVix\Infrastructure\SMS\OTPServiceInterface;

defined('ABSPATH') || exit;

/**
 * Class VerifyOtp
 *
 * Business logic for verifying OTP codes.
 *
 * Security:
 * - Max 5 verification attempts
 * - 15-minute block after exceeding
 * - Verification via provider (stateless)
 * - Marks user as verified in database
 *
 * @since Sprint 9
 */
final class VerifyOtp
{
    private const MAX_VERIFICATION_ATTEMPTS = 5;
    private const BLOCK_DURATION = 900; // 15 minutes in seconds

    public function __construct(
        private OTPServiceInterface $otpProvider
    ) {}

    /**
     * Verify OTP code
     *
     * @param int $userId WordPress user ID
     * @param string $code 6-digit code provided by user
     * @return array ['verified' => bool, 'remaining_attempts' => int, 'error' => ?string]
     */
    public function execute(int $userId, string $code): array
    {
        global $wpdb;

        // Validate code format (6 digits)
        if (!preg_match('/^\d{6}$/', $code)) {
            return [
                'verified' => false,
                'remaining_attempts' => 0,
                'error' => 'Invalid code format. Must be 6 digits',
            ];
        }

        $table = $wpdb->prefix . 'limpvix_user_verifications';

        // Get verification record
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table} WHERE user_id = %d",
            $userId
        ));

        if (!$verification) {
            return [
                'verified' => false,
                'remaining_attempts' => 0,
                'error' => 'No OTP request found. Please request a new code',
            ];
        }

        // Check if user is blocked
        if ($verification->blocked_until) {
            $blockedUntil = new \DateTime($verification->blocked_until);
            $now = new \DateTime();

            if ($blockedUntil > $now) {
                return [
                    'verified' => false,
                    'remaining_attempts' => 0,
                    'error' => sprintf(
                        'Too many failed attempts. Try again after %s',
                        $blockedUntil->format('H:i')
                    ),
                ];
            }

            // Block expired, clear it
            $wpdb->update(
                $table,
                ['blocked_until' => null],
                ['user_id' => $userId],
                ['%s'],
                ['%d']
            );
        }

        // Check if request_id exists
        if (empty($verification->last_request_id)) {
            return [
                'verified' => false,
                'remaining_attempts' => 0,
                'error' => 'No active OTP session. Please request a new code',
            ];
        }

        // Check attempts
        $remainingAttempts = self::MAX_VERIFICATION_ATTEMPTS - $verification->otp_attempts;

        if ($remainingAttempts <= 0) {
            // Block user
            $this->blockUser($userId);

            return [
                'verified' => false,
                'remaining_attempts' => 0,
                'error' => 'Maximum verification attempts exceeded. You are blocked for 15 minutes',
            ];
        }

        // Verify via provider API
        try {
            $verified = $this->otpProvider->verify($verification->last_request_id, $code);
        } catch (\Exception $e) {
            // API error - don't count as failed attempt
            return [
                'verified' => false,
                'remaining_attempts' => $remainingAttempts,
                'error' => 'Verification service temporarily unavailable. Try again',
            ];
        }

        if ($verified) {
            // Success! Mark user as verified
            $this->markPhoneVerified($userId);

            return [
                'verified' => true,
                'remaining_attempts' => $remainingAttempts - 1,
                'error' => null,
            ];
        }

        // Failed attempt
        $this->incrementAttempts($userId);

        $this->logVerificationFailure($userId, $remainingAttempts - 1);

        return [
            'verified' => false,
            'remaining_attempts' => $remainingAttempts - 1,
            'error' => sprintf(
                'Invalid code. %d attempt%s remaining',
                $remainingAttempts - 1,
                ($remainingAttempts - 1) === 1 ? '' : 's'
            ),
        ];
    }

    /**
     * Mark user's phone as verified
     *
     * @param int $userId
     * @return void
     */
    private function markPhoneVerified(int $userId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_user_verifications';

        $wpdb->update(
            $table,
            [
                'phone_verified' => true,
                'phone_verified_at' => current_time('mysql'),
                'otp_attempts' => 0,
                'last_request_id' => null, // Clear request ID (single use)
                'blocked_until' => null,
                'updated_at' => current_time('mysql'),
            ],
            ['user_id' => $userId],
            ['%d', '%s', '%d', '%s', '%s', '%s'],
            ['%d']
        );

        $this->logVerificationSuccess($userId);
    }

    /**
     * Increment verification attempts
     *
     * @param int $userId
     * @return void
     */
    private function incrementAttempts(int $userId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_user_verifications';

        $wpdb->query($wpdb->prepare(
            "UPDATE {$table} SET otp_attempts = otp_attempts + 1, updated_at = %s WHERE user_id = %d",
            current_time('mysql'),
            $userId
        ));
    }

    /**
     * Block user temporarily
     *
     * @param int $userId
     * @return void
     */
    private function blockUser(int $userId): void
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_user_verifications';

        $blockedUntil = (new \DateTime())->modify('+' . self::BLOCK_DURATION . ' seconds');

        $wpdb->update(
            $table,
            [
                'blocked_until' => $blockedUntil->format('Y-m-d H:i:s'),
                'updated_at' => current_time('mysql'),
            ],
            ['user_id' => $userId],
            ['%s', '%s'],
            ['%d']
        );

        $this->logBruteForceAttempt($userId);
    }

    /**
     * Log successful verification
     *
     * @param int $userId
     * @return void
     */
    private function logVerificationSuccess(int $userId): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf('[LimpVix][VerifyOtp] Phone verified for user %d', $userId));
        }
    }

    /**
     * Log failed verification
     *
     * @param int $userId
     * @param int $remainingAttempts
     * @return void
     */
    private function logVerificationFailure(int $userId, int $remainingAttempts): void
    {
        error_log(sprintf(
            '[LimpVix][VerifyOtp] Verification failed for user %d (%d attempts remaining)',
            $userId,
            $remainingAttempts
        ));
    }

    /**
     * Log potential brute force attempt
     *
     * @param int $userId
     * @return void
     */
    private function logBruteForceAttempt(int $userId): void
    {
        error_log(sprintf(
            '[LimpVix][VerifyOtp][SECURITY] User %d blocked for 15 minutes (brute force protection)',
            $userId
        ));
    }
}
