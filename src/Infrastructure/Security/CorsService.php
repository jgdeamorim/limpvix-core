<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Security;

defined('ABSPATH') || exit;

/**
 * CORS (Cross-Origin Resource Sharing) Service
 *
 * Manages CORS headers for REST API to allow cross-origin requests
 * from mobile apps and web applications
 */
final class CorsService
{
    private const DEFAULT_ALLOWED_ORIGINS = ['*'];
    private const DEFAULT_ALLOWED_METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
    private const DEFAULT_ALLOWED_HEADERS = [
        'Authorization',
        'Content-Type',
        'X-API-Key',
        'X-Requested-With',
    ];
    private const DEFAULT_MAX_AGE = 86400; // 24 hours

    /**
     * Add CORS headers to REST API responses
     */
    public function addCorsHeaders(\WP_REST_Response $response, $server, \WP_REST_Request $request): \WP_REST_Response
    {
        $origin = $this->getRequestOrigin($request);
        $allowedOrigins = $this->getAllowedOrigins();
        
        // Check if origin is allowed
        if ($this->isOriginAllowed($origin, $allowedOrigins)) {
            $response->header('Access-Control-Allow-Origin', $origin);
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
        
        return $response;
    }

    /**
     * Handle preflight OPTIONS requests
     */
    public function handlePreflightRequest($response, $server, \WP_REST_Request $request)
    {
        if ($request->get_method() !== 'OPTIONS') {
            return $response;
        }

        $origin = $this->getRequestOrigin($request);
        $allowedOrigins = $this->getAllowedOrigins();
        
        if (!$this->isOriginAllowed($origin, $allowedOrigins)) {
            return $response;
        }

        // Create preflight response
        $preflightResponse = new \WP_REST_Response(null, 200);
        
        $preflightResponse->header('Access-Control-Allow-Origin', $origin);
        $preflightResponse->header('Access-Control-Allow-Methods', implode(', ', self::DEFAULT_ALLOWED_METHODS));
        $preflightResponse->header('Access-Control-Allow-Headers', implode(', ', self::DEFAULT_ALLOWED_HEADERS));
        $preflightResponse->header('Access-Control-Allow-Credentials', 'true');
        $preflightResponse->header('Access-Control-Max-Age', (string) self::DEFAULT_MAX_AGE);
        
        return $preflightResponse;
    }

    /**
     * Get request origin from headers
     */
    private function getRequestOrigin(\WP_REST_Request $request): string
    {
        $origin = $request->get_header('origin');
        
        if (!$origin) {
            $origin = $request->get_header('referer');
            
            if ($origin) {
                $parsed = parse_url($origin);
                $origin = ($parsed['scheme'] ?? 'https') . '://'. ($parsed['host'] ?? 'localhost');
            }
        }
        
        return $origin ?? '';
    }

    /**
     * Get allowed origins from WordPress options
     */
    private function getAllowedOrigins(): array
    {
        $saved = get_option('limpvix_cors_allowed_origins', []);
        
        if (empty($saved)) {
            return self::DEFAULT_ALLOWED_ORIGINS;
        }
        
        return is_array($saved) ? $saved : explode(',', $saved);
    }

    /**
     * Check if origin is allowed
     */
    private function isOriginAllowed(string $origin, array $allowedOrigins): bool
    {
        if (empty($origin)) {
            return false;
        }

        // Check for wildcard
        if (in_array('*', $allowedOrigins, true)) {
            return true;
        }

        // Check exact match
        if (in_array($origin, $allowedOrigins, true)) {
            return true;
        }

        // Check pattern match (e.g., *.example.com)
        foreach ($allowedOrigins as $allowed) {
            if ($this->matchesPattern($origin, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if origin matches pattern
     */
    private function matchesPattern(string $origin, string $pattern): bool
    {
        // Convert wildcard pattern to regex
        $regex = str_replace(
            ['.', '*'],
            ['\.', '.*'],
            $pattern
        );
        
        return (bool) preg_match('#^' . $regex . '$#', $origin);
    }

    /**
     * Get CORS configuration info
     */
    public function getConfiguration(): array
    {
        return [
            'allowed_origins' => $this->getAllowedOrigins(),
            'allowed_methods' => self::DEFAULT_ALLOWED_METHODS,
            'allowed_headers' => self::DEFAULT_ALLOWED_HEADERS,
            'max_age' => self::DEFAULT_MAX_AGE,
        ];
    }

    /**
     * Update allowed origins
     */
    public function updateAllowedOrigins(array $origins): void
    {
        update_option('limpvix_cors_allowed_origins', $origins);
    }
}
