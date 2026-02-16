<?php
/**
 * OTP Controller
 *
 * REST API endpoints for phone verification via OTP.
 *
 * Endpoints:
 * - POST /auth/otp/send
 * - POST /auth/otp/verify
 *
 * @package LimpVix\Infrastructure\API
 * @since Sprint 9 - OTP Verification
 */

namespace LimpVix\Infrastructure\API;

use WP_REST_Controller;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use LimpVix\Application\UseCase\Auth\SendOtp;
use LimpVix\Application\UseCase\Auth\VerifyOtp;
use LimpVix\Infrastructure\SMS\NVoipOtpProvider;
use LimpVix\Infrastructure\SMS\TwilioOtpProvider;
use LimpVix\Admin\Settings\TwilioSettings;

defined('ABSPATH') || exit;

/**
 * Class OtpController
 *
 * Handles OTP send and verification requests.
 *
 * @since Sprint 9
 */
final class OtpController extends WP_REST_Controller
{
    private SendOtp $sendOtpUseCase;
    private VerifyOtp $verifyOtpUseCase;

    public function __construct()
    {
        $this->namespace = 'limpvix/v1';
        $this->rest_base = 'auth/otp';

        // Escolher provider baseado nas configurações
        // Prioridade: Twilio (se configurado) > NVoip (fallback)
        if (TwilioSettings::isConnected()) {
            $otpProvider = new TwilioOtpProvider();
        } else {
            // Fallback para NVoip
            $otpProvider = new NVoipOtpProvider();
        }

        $this->sendOtpUseCase = new SendOtp($otpProvider);
        $this->verifyOtpUseCase = new VerifyOtp($otpProvider);
    }

    /**
     * Register routes
     *
     * @return void
     */
    public function register_routes(): void
    {
        // POST /auth/otp/send
        register_rest_route($this->namespace, '/' . $this->rest_base . '/send', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'sendOtp'],
                'permission_callback' => [$this, 'canSendOtp'],
                'args' => [
                    'phone_number' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => 'Phone number in E.164 format',
                        'validate_callback' => [$this, 'validatePhoneNumber'],
                    ],
                    'channel' => [
                        'required' => false,
                        'type' => 'string',
                        'default' => 'auto',
                        'enum' => ['auto', 'whatsapp', 'sms'],
                        'description' => 'Preferred channel (auto = WhatsApp with SMS fallback)',
                    ],
                ],
            ],
        ]);

        // POST /auth/otp/verify
        register_rest_route($this->namespace, '/' . $this->rest_base . '/verify', [
            [
                'methods' => 'POST',
                'callback' => [$this, 'verifyOtp'],
                'permission_callback' => [$this, 'canVerifyOtp'],
                'args' => [
                    'code' => [
                        'required' => true,
                        'type' => 'string',
                        'description' => '6-digit verification code',
                        'validate_callback' => function($param) {
                            return preg_match('/^\d{6}$/', $param);
                        },
                    ],
                ],
            ],
        ]);
    }

    /**
     * Send OTP handler
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function sendOtp(WP_REST_Request $request)
    {
        $userId = get_current_user_id();
        $phoneNumber = $request['phone_number'];
        $channel = $request['channel'] ?? 'auto';

        try {
            $result = $this->sendOtpUseCase->execute($userId, $phoneNumber, $channel);

            return new WP_REST_Response([
                'success' => true,
                'message' => 'OTP sent successfully',
                'data' => [
                    'request_id' => $result['request_id'],
                    'channel' => $result['channel'],
                    'rate_limit' => $result['rate_limit'],
                ],
            ], 200);

        } catch (\InvalidArgumentException $e) {
            return new WP_Error(
                'invalid_phone_number',
                $e->getMessage(),
                ['status' => 400]
            );
        } catch (\RuntimeException $e) {
            return new WP_Error(
                'otp_send_failed',
                $e->getMessage(),
                ['status' => 429] // Too Many Requests if rate limited
            );
        } catch (\Exception $e) {
            error_log('[LimpVix][OtpController] Send error: ' . $e->getMessage());

            return new WP_Error(
                'otp_send_error',
                'Failed to send OTP. Please try again later',
                ['status' => 500]
            );
        }
    }

    /**
     * Verify OTP handler
     *
     * @param WP_REST_Request $request
     * @return WP_REST_Response|WP_Error
     */
    public function verifyOtp(WP_REST_Request $request)
    {
        $userId = get_current_user_id();
        $code = $request['code'];

        try {
            $result = $this->verifyOtpUseCase->execute($userId, $code);

            if ($result['verified']) {
                return new WP_REST_Response([
                    'success' => true,
                    'message' => 'Phone verified successfully',
                    'data' => [
                        'phone_verified' => true,
                    ],
                ], 200);
            }

            return new WP_Error(
                'invalid_code',
                $result['error'],
                [
                    'status' => 400,
                    'remaining_attempts' => $result['remaining_attempts'],
                ]
            );

        } catch (\Exception $e) {
            error_log('[LimpVix][OtpController] Verify error: ' . $e->getMessage());

            return new WP_Error(
                'otp_verify_error',
                'Failed to verify OTP. Please try again later',
                ['status' => 500]
            );
        }
    }

    /**
     * Permission callback for send OTP
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function canSendOtp(WP_REST_Request $request): bool
    {
        // User must be logged in
        return is_user_logged_in();
    }

    /**
     * Permission callback for verify OTP
     *
     * @param WP_REST_Request $request
     * @return bool
     */
    public function canVerifyOtp(WP_REST_Request $request): bool
    {
        // User must be logged in
        return is_user_logged_in();
    }

    /**
     * Validate phone number format
     *
     * @param string $param
     * @return bool
     */
    public function validatePhoneNumber(string $param): bool
    {
        $cleaned = preg_replace('/[^0-9]/', '', $param);
        $digitCount = strlen($cleaned);

        return $digitCount >= 10 && $digitCount <= 15;
    }
}
