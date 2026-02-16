<?php
/**
 * Phone Verification Middleware
 *
 * Enforces phone verification requirement for critical actions.
 *
 * SECURITY POLICY (Sprint 9):
 * - Professionals CANNOT accept offers without phone_verified = true
 * - Customers CANNOT create contracts/briefings without phone_verified = true
 * - Admin CANNOT send offers without phone_verified = true
 *
 * BLOCKED ACTIONS:
 * - Accept Offer (Professional)
 * - Send Offers (Customer/Admin)
 * - Create Contract (Customer)
 * - Create Briefing (Customer)
 * - Payment actions (if applicable)
 *
 * @package LimpVix\Infrastructure\Middleware
 * @since Sprint 9 - OTP Verification
 */

namespace LimpVix\Infrastructure\Middleware;

use WP_Error;

defined('ABSPATH') || exit;

/**
 * Class PhoneVerificationMiddleware
 *
 * Validates that user has verified phone number before allowing critical actions.
 *
 * @since Sprint 9
 */
final class PhoneVerificationMiddleware
{
    /**
     * Check if user has verified phone
     *
     * @param int $userId WordPress user ID
     * @return bool|WP_Error True if verified, WP_Error if not
     */
    public function requirePhoneVerified(int $userId): bool|WP_Error
    {
        global $wpdb;

        $table = $wpdb->prefix . 'limpvix_user_verifications';

        // Get verification record
        $verification = $wpdb->get_row($wpdb->prepare(
            "SELECT phone_verified, phone_verified_at FROM {$table} WHERE user_id = %d",
            $userId
        ));

        // If no record exists, user never started verification
        if (!$verification) {
            return new WP_Error(
                'phone_verification_required',
                'Phone verification required. Please verify your phone number to perform this action.',
                [
                    'status' => 403,
                    'data' => [
                        'verification_status' => 'not_started',
                        'action_required' => 'verify_phone',
                        'endpoint' => '/limpvix/v1/auth/otp/send',
                    ],
                ]
            );
        }

        // If phone not verified
        if (!$verification->phone_verified) {
            return new WP_Error(
                'phone_verification_required',
                'Phone verification pending. Please verify your phone number to perform this action.',
                [
                    'status' => 403,
                    'data' => [
                        'verification_status' => 'pending',
                        'action_required' => 'verify_phone',
                        'endpoint' => '/limpvix/v1/auth/otp/verify',
                    ],
                ]
            );
        }

        // Phone verified - allow action
        return true;
    }

    /**
     * Wrapper for REST API permission callbacks
     *
     * Usage in controller:
     * 'permission_callback' => function() {
     *     $middleware = new PhoneVerificationMiddleware();
     *     $check = $middleware->checkPhoneVerified(get_current_user_id());
     *     if (is_wp_error($check)) {
     *         return $check;
     *     }
     *     // Continue with other permission checks...
     *     return current_user_can('...');
     * }
     *
     * @param int|null $userId User ID (defaults to current user)
     * @return bool|WP_Error
     */
    public function checkPhoneVerified(?int $userId = null): bool|WP_Error
    {
        $userId = $userId ?? get_current_user_id();

        if (!$userId || $userId === 0) {
            return new WP_Error(
                'authentication_required',
                'You must be logged in to perform this action.',
                ['status' => 401]
            );
        }

        return $this->requirePhoneVerified($userId);
    }

    /**
     * Check if user is exempt from phone verification
     *
     * Admins MAY be exempt in some contexts (configurable).
     * By default, everyone including admins must verify.
     *
     * @param int $userId
     * @return bool
     */
    private function isExempt(int $userId): bool
    {
        // Get exemption policy from settings
        $adminExempt = get_option('limpvix_phone_verification_exempt_admin', false);

        if ($adminExempt && user_can($userId, 'manage_options')) {
            return true;
        }

        return false;
    }
}
