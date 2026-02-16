<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Auth;

use WP_REST_Request;
use Exception;

/**
 * Middleware para autenticação JWT em endpoints REST
 *
 * Aceita tanto JWT tokens quanto WordPress session cookies
 */
final class JwtAuthMiddleware
{
    private JwtService $jwtService;

    public function __construct(JwtService $jwtService)
    {
        $this->jwtService = $jwtService;
    }

    /**
     * Permission callback que aceita JWT ou WordPress session
     *
     * @param WP_REST_Request $request
     * @return bool True se autenticado
     */
    public function isAuthenticated(WP_REST_Request $request): bool
    {
        // 1. Tentar autenticação JWT
        $userId = $this->authenticateViaJwt($request);
        if ($userId !== null) {
            $request->set_param('_user_id', $userId);
            $request->set_param('_auth_method', 'jwt');
            return true;
        }

        // 2. Fallback: WordPress session cookies
        if (is_user_logged_in()) {
            $userId = get_current_user_id();
            $request->set_param('_user_id', $userId);
            $request->set_param('_auth_method', 'session');
            return true;
        }

        return false;
    }

    /**
     * Permission callback que requer admin capability
     *
     * @param WP_REST_Request $request
     * @return bool True se usuário é admin
     */
    public function isAdmin(WP_REST_Request $request): bool
    {
        if (!$this->isAuthenticated($request)) {
            return false;
        }

        $userId = $request->get_param('_user_id');
        $user = get_user_by('ID', $userId);

        return $user && user_can($user, 'manage_options');
    }

    /**
     * Permission callback com capability customizada
     *
     * @param string $capability WordPress capability necessária
     * @return callable
     */
    public function hasCapability(string $capability): callable
    {
        return function (WP_REST_Request $request) use ($capability): bool {
            if (!$this->isAuthenticated($request)) {
                return false;
            }

            $userId = $request->get_param('_user_id');
            $user = get_user_by('ID', $userId);

            return $user && user_can($user, $capability);
        };
    }

    /**
     * Tenta autenticar via JWT token
     *
     * @param WP_REST_Request $request
     * @return int|null User ID se autenticado, null se não
     */
    public function authenticateViaJwt(WP_REST_Request $request): ?int
    {
        $authHeader = $request->get_header('authorization');
        $token = $this->jwtService->extractTokenFromHeader($authHeader);

        if (!$token) {
            return null;
        }

        try {
            $result = $this->jwtService->validateToken($token);
            return $result['userId'];
        } catch (Exception $e) {
            return null;
        }
    }

    /**
     * Obtém user ID do request (funciona com JWT ou session)
     *
     * @param WP_REST_Request $request
     * @return int|null
     */
    public function getUserId(WP_REST_Request $request): ?int
    {
        // Se já autenticado, usar _user_id setado no request
        if ($request->has_param('_user_id')) {
            return $request->get_param('_user_id');
        }

        // Tentar autenticar
        if ($this->isAuthenticated($request)) {
            return $request->get_param('_user_id');
        }

        return null;
    }
}
