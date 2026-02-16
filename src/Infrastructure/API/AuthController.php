<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\API;

use LimpVix\Infrastructure\Auth\JwtService;
use LimpVix\Infrastructure\API\ApiResponse;
use WP_REST_Request;
use WP_REST_Response;
use WP_Error;
use Exception;

/**
 * Controller REST para autenticação JWT
 */
final class AuthController
{
    private JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    public function register(): void
    {
        register_rest_route('limpvix/v1', '/auth/login', [
            'methods' => 'POST',
            'callback' => [$this, 'login'],
            'permission_callback' => '__return_true',
            'args' => [
                'username' => ['required' => true, 'type' => 'string'],
                'password' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('limpvix/v1', '/auth/refresh', [
            'methods' => 'POST',
            'callback' => [$this, 'refresh'],
            'permission_callback' => '__return_true',
            'args' => [
                'refresh_token' => ['required' => true, 'type' => 'string'],
            ],
        ]);

        register_rest_route('limpvix/v1', '/auth/me', [
            'methods' => 'GET',
            'callback' => [$this, 'me'],
            'permission_callback' => [$this, 'isAuthenticated'],
        ]);
    }

    /**
     * POST /limpvix/v1/auth/login
     */
    public function login(WP_REST_Request $request): WP_REST_Response
    {
        $username = $request->get_param('username');
        $password = $request->get_param('password');

        $user = wp_authenticate($username, $password);

        if (is_wp_error($user)) {
            return ApiResponse::error(
                'Invalid username or password',
                'authentication_failed',
                401
            );
        }

        try {
            $tokens = $this->jwtService->generateTokenPair($user->ID);

            return ApiResponse::success([
                'user' => [
                    'id' => $user->ID,
                    'username' => $user->user_login,
                    'email' => $user->user_email,
                    'display_name' => $user->display_name,
                    'roles' => $user->roles,
                ],
                'tokens' => $tokens,
            ], 'Login successful');

        } catch (Exception $e) {
            return ApiResponse::error(
                'Failed to generate tokens',
                'token_generation_failed',
                500
            );
        }
    }

    /**
     * POST /limpvix/v1/auth/refresh
     */
    public function refresh(WP_REST_Request $request): WP_REST_Response
    {
        $refreshToken = $request->get_param('refresh_token');

        try {
            $userId = $this->jwtService->validateRefreshToken($refreshToken);
            $tokens = $this->jwtService->generateTokenPair($userId);

            return ApiResponse::success([
                'tokens' => $tokens,
            ], 'Token refreshed successfully');

        } catch (Exception $e) {
            return ApiResponse::error(
                $e->getMessage(),
                'invalid_refresh_token',
                401
            );
        }
    }

    /**
     * GET /limpvix/v1/auth/me
     */
    public function me(WP_REST_Request $request): WP_REST_Response
    {
        $userId = $request->get_param('_user_id'); // Set by middleware

        $user = get_user_by('ID', $userId);
        if (!$user) {
            return ApiResponse::error('User not found', 'user_not_found', 404);
        }

        return ApiResponse::success([
            'id' => $user->ID,
            'username' => $user->user_login,
            'email' => $user->user_email,
            'display_name' => $user->display_name,
            'roles' => $user->roles,
        ]);
    }

    /**
     * Permission callback para endpoints autenticados
     */
    public function isAuthenticated(WP_REST_Request $request): bool
    {
        // Tentar autenticação JWT
        $authHeader = $request->get_header('authorization');
        $token = $this->jwtService->extractTokenFromHeader($authHeader);

        if ($token) {
            try {
                $result = $this->jwtService->validateToken($token);
                $request->set_param('_user_id', $result['userId']);
                return true;
            } catch (Exception $e) {
                return false;
            }
        }

        // Fallback: WordPress session cookies
        return is_user_logged_in();
    }
}
