<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Auth;

defined('ABSPATH') || exit;

/**
 * API Key Authentication Middleware
 *
 * Authenticates requests using X-API-Key header
 */
final class ApiKeyAuthMiddleware
{
    private ApiKeyService $apiKeyService;

    public function __construct(ApiKeyService $apiKeyService)
    {
        $this->apiKeyService = $apiKeyService;
    }

    /**
     * Authenticate via API key
     *
     * @param \WP_REST_Request $request
     * @return int|null User ID if authenticated, null otherwise
     */
    public function authenticateViaApiKey(\WP_REST_Request $request): ?int
    {
        $apiKey = $this->extractApiKey($request);
        
        if (!$apiKey) {
            return null;
        }

        $validatedKey = $this->apiKeyService->validateApiKey($apiKey);
        
        if (!$validatedKey) {
            return null;
        }

        // Store API key info in request for logging/auditing
        $request->set_param('_api_key_name', $validatedKey->getName());
        $request->set_param('_api_key_scopes', $validatedKey->getScopes());
        
        return $validatedKey->getUserId();
    }

    /**
     * Check if request has required scope
     */
    public function hasScope(\WP_REST_Request $request, string $requiredScope): bool
    {
        $scopes = $request->get_param('_api_key_scopes');
        
        if (!$scopes) {
            return false;
        }

        return in_array($requiredScope, $scopes, true) || in_array('*', $scopes, true);
    }

    /**
     * Permission callback for endpoints requiring specific scope
     */
    public function requireScope(string $scope): callable
    {
        return function(\WP_REST_Request $request) use ($scope): bool {
            $userId = $this->authenticateViaApiKey($request);
            
            if (!$userId) {
                return false;
            }

            $request->set_param('_user_id', $userId);
            $request->set_param('_auth_method', 'api_key');
            
            return $this->hasScope($request, $scope);
        };
    }

    /**
     * Extract API key from request headers
     */
    private function extractApiKey(\WP_REST_Request $request): ?string
    {
        // Try X-API-Key header first
        $apiKey = $request->get_header('X-API-Key');
        
        if ($apiKey) {
            return $apiKey;
        }

        // Fallback to Authorization: Bearer token
        $authHeader = $request->get_header('Authorization');
        
        if ($authHeader && str_starts_with($authHeader, 'Bearer ')) {
            return substr($authHeader, 7);
        }

        // Fallback to query parameter (less secure, but useful for testing)
        return $request->get_param('api_key');
    }
}
