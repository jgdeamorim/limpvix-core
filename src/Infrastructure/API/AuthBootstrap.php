<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\API;

use LimpVix\Infrastructure\Auth\JwtService;
use LimpVix\Infrastructure\Auth\JwtAuthMiddleware;
use LimpVix\Infrastructure\Auth\ApiKeyService;
use LimpVix\Infrastructure\Auth\ApiKeyAuthMiddleware;
use LimpVix\Infrastructure\Security\RateLimitService;
use LimpVix\Infrastructure\Security\CorsService;
use LimpVix\Infrastructure\Security\RateLimitMiddleware;

/**
 * Bootstrap para autenticação JWT, API Keys e segurança
 */
final class AuthBootstrap
{
    private static ?JwtService $jwtService = null;
    private static ?JwtAuthMiddleware $jwtMiddleware = null;
    private static ?AuthController $authController = null;
    private static ?ApiKeyService $apiKeyService = null;
    private static ?CustomerController $customerController = null;
    private static ?ApiKeyAuthMiddleware $apiKeyMiddleware = null;
    private static ?ApiKeyController $apiKeyController = null;
    private static ?RateLimitService $rateLimitService = null;
    private static ?RateLimitMiddleware $rateLimitMiddleware = null;
    private static ?CorsService $corsService = null;

    public static function init(): void
    {
        add_action('rest_api_init', [self::class, 'registerInContainer'], 0);
        add_action('rest_api_init', [self::class, 'registerRoutes']);
        add_action('rest_api_init', [self::class, 'registerRateLimiting'], 1);
        add_action('rest_api_init', [self::class, 'registerApiKeyAuth'], 2);
        add_action('rest_api_init', [self::class, 'registerCors'], 0);
    }

    /**
     * Register services in ServiceContainer
     *
     * MUDANÇA (SPRINT 8.5 - FASE 2): Usa ServiceContainer ao invés de apenas static properties
     * - Registra jwt_middleware no container
     * - Melhora testabilidade e reutilização
     *
     * @return void
     */
    public static function registerInContainer(): void
    {
        $container = \LimpVix\Core\ServiceContainer::getInstance();

        // Register JWT Service
        $container->factory('jwt_service', function() {
            return self::getJwtService();
        });

        // Register JWT Middleware
        $container->factory('jwt_middleware', function() use ($container) {
            return self::getJwtMiddleware();
        });

        // Register API Key Service
        $container->factory('api_key_service', function() {
            return self::getApiKeyService();
        });

        // BACKWARD COMPATIBILITY: Manter $GLOBALS temporariamente
        // TODO: Remover após refatorar todos os consumidores
        if (!isset($GLOBALS['limpvix_jwt_middleware'])) {
            $GLOBALS['limpvix_jwt_middleware'] = $container->get('jwt_middleware');
        }
    }

    public static function registerRoutes(): void
    {
        $authController = self::getAuthController();
        $authController->register();

        $apiKeyController = self::getApiKeyController();
        $apiKeyController->register();

        $customerController = self::getCustomerController();
        $customerController->register_routes();

        // Register OTP Controller (Sprint 9)
        if (class_exists('LimpVix\\Infrastructure\\API\\OtpController')) {
            $otpController = new OtpController();
            $otpController->register_routes();
        }
    }

    public static function registerRateLimiting(): void
    {
        $rateLimitMiddleware = self::getRateLimitMiddleware();
        
        // Apply rate limiting to all LimpVix API endpoints
        add_filter('rest_pre_dispatch', function($result, $server, $request) use ($rateLimitMiddleware) {
            // Only apply to LimpVix endpoints
            $route = $request->get_route();
            if (strpos($route, '/limpvix/v1/') === 0) {
                $rateCheck = $rateLimitMiddleware->checkRateLimit($request);
                
                if (is_wp_error($rateCheck)) {
                    return $rateCheck;
                }
            }
            
            return $result;
        }, 10, 3);
        
        // Add rate limit headers to all responses
        add_filter('rest_post_dispatch', function($response, $server, $request) use ($rateLimitMiddleware) {
            $route = $request->get_route();
            if ($response instanceof \WP_REST_Response && strpos($route, '/limpvix/v1/') === 0) {
                return $rateLimitMiddleware->addRateLimitHeaders($response, $request);
            }
            
            return $response;
        }, 10, 3);
    }

    public static function registerApiKeyAuth(): void
    {
        $apiKeyMiddleware = self::getApiKeyMiddleware();
        $jwtMiddleware = self::getJwtMiddleware();
        
        // Add API key authentication filter
        add_filter('rest_pre_dispatch', function($result, $server, $request) use ($apiKeyMiddleware, $jwtMiddleware) {
            $route = $request->get_route();
            
            if (strpos($route, '/limpvix/v1/') === 0) {
                // Try API key authentication first
                $userId = $apiKeyMiddleware->authenticateViaApiKey($request);
                
                if ($userId) {
                    $request->set_param('_user_id', $userId);
                    $request->set_param('_auth_method', 'api_key');
                    return $result;
                }
                
                // Fallback to JWT
                $userId = $jwtMiddleware->authenticateViaJwt($request);
                
                if ($userId) {
                    $request->set_param('_user_id', $userId);
                    $request->set_param('_auth_method', 'jwt');
                }
            }
            
            return $result;
        }, 5, 3); // Priority 5 = before rate limiting
    }

    public static function registerCors(): void
    {
        $corsService = self::getCorsService();
        
        // Handle preflight OPTIONS requests
        add_filter('rest_pre_dispatch', [$corsService, 'handlePreflightRequest'], 1, 3);
        
        // Add CORS headers to all responses
        add_filter('rest_post_dispatch', [$corsService, 'addCorsHeaders'], 20, 3);
    }

    public static function getCorsService(): CorsService
    {
        if (self::$corsService === null) {
            self::$corsService = new CorsService();
        }
        return self::$corsService;
    }

    public static function getJwtService(): JwtService
    {
        if (self::$jwtService === null) {
            self::$jwtService = new JwtService();
        }
        return self::$jwtService;
    }

    public static function getJwtMiddleware(): JwtAuthMiddleware
    {
        if (self::$jwtMiddleware === null) {
            self::$jwtMiddleware = new JwtAuthMiddleware(self::getJwtService());
        }
        return self::$jwtMiddleware;
    }

    public static function getApiKeyService(): ApiKeyService
    {
        if (self::$apiKeyService === null) {
            self::$apiKeyService = new ApiKeyService();
        }
        return self::$apiKeyService;
    }

    public static function getApiKeyMiddleware(): ApiKeyAuthMiddleware
    {
        if (self::$apiKeyMiddleware === null) {
            self::$apiKeyMiddleware = new ApiKeyAuthMiddleware(self::getApiKeyService());
        }
        return self::$apiKeyMiddleware;
    }

    public static function getRateLimitService(): RateLimitService
    {
        if (self::$rateLimitService === null) {
            self::$rateLimitService = new RateLimitService();
        }
        return self::$rateLimitService;
    }

    public static function getRateLimitMiddleware(): RateLimitMiddleware
    {
        if (self::$rateLimitMiddleware === null) {
            self::$rateLimitMiddleware = new RateLimitMiddleware(self::getRateLimitService());
        }
        return self::$rateLimitMiddleware;
    }

    private static function getAuthController(): AuthController
    {
        if (self::$authController === null) {
            self::$authController = new AuthController(self::getJwtService());
        }
        return self::$authController;
    }

    private static function getCustomerController(): CustomerController
    {
        if (self::$customerController === null) {
            self::$customerController = new CustomerController();
        }
        return self::$customerController;
    }

    private static function getApiKeyController(): ApiKeyController
    {
        if (self::$apiKeyController === null) {
            self::$apiKeyController = new ApiKeyController(
                self::getApiKeyService(),
                self::getJwtMiddleware()
            );
        }
        return self::$apiKeyController;
    }
}
