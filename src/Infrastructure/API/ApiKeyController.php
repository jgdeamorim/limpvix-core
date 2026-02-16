<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\API;

use LimpVix\Infrastructure\Auth\ApiKeyService;
use LimpVix\Infrastructure\Auth\JwtAuthMiddleware;

defined('ABSPATH') || exit;

/**
 * API Key Management Controller
 */
final class ApiKeyController extends \WP_REST_Controller
{
    private ApiKeyService $apiKeyService;
    private ?JwtAuthMiddleware $jwtMiddleware;

    public function __construct(
        ApiKeyService $apiKeyService,
        ?JwtAuthMiddleware $jwtMiddleware = null
    ) {
        $this->namespace = 'limpvix/v1';
        $this->rest_base = 'api-keys';
        $this->apiKeyService = $apiKeyService;
        $this->jwtMiddleware = $jwtMiddleware;
    }

    public function register(): void
    {
        register_rest_route($this->namespace, '/'. $this->rest_base, [
            [
                'methods' => \WP_REST_Server::READABLE,
                'callback' => [$this, 'listKeys'],
                'permission_callback' => [$this, 'checkAuthenticated'],
            ],
            [
                'methods' => \WP_REST_Server::CREATABLE,
                'callback' => [$this, 'createKey'],
                'permission_callback' => [$this, 'checkAuthenticated'],
                'args' => [
                    'name' => [
                        'required' => true,
                        'type' => 'string',
                    ],
                    'scopes' => [
                        'required' => false,
                        'type' => 'array',
                        'default' => ['read'],
                    ],
                    'expires_in_days' => [
                        'required' => false,
                        'type' => 'integer',
                    ],
                ],
            ],
        ]);

        register_rest_route($this->namespace, '/'. $this->rest_base . '/(?P<key>[a-f0-9]+)',[
            [
                'methods' => \WP_REST_Server::DELETABLE,
                'callback' => [$this, 'revokeKey'],
                'permission_callback' => [$this, 'checkAuthenticated'],
            ],
        ]);
    }

    public function listKeys(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = $this->getCurrentUserId($request);
        
        $apiKeys = $this->apiKeyService->listApiKeys($userId);
        
        $response = array_map(function($apiKey) {
            return [
                'key' => $apiKey->getMaskedKey(),
                'name' => $apiKey->getName(),
                'scopes' => $apiKey->getScopes(),
                'created_at' => $apiKey->getCreatedAt()->format('Y-m-d H:i:s'),
                'last_used_at' => $apiKey->getLastUsedAt()?->format('Y-m-d H:i:s'),
                'expires_at' => $apiKey->getExpiresAt()?->format('Y-m-d H:i:s'),
                'is_active' => $apiKey->isActive(),
            ];
        }, $apiKeys);
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => $response,
        ], 200);
    }

    public function createKey(\WP_REST_Request $request): \WP_REST_Response
    {
        $userId = $this->getCurrentUserId($request);
        $name = $request->get_param('name');
        $scopes = $request->get_param('scopes');
        $expiresInDays = $request->get_param('expires_in_days');
        
        $expiresAt = null;
        if ($expiresInDays) {
            $expiresAt = (new \DateTimeImmutable())->modify("+{$expiresInDays} days");
        }
        
        $apiKey = $this->apiKeyService->createApiKey($name, $scopes, $userId, $expiresAt);
        
        $message = 'API key created. Store securely - this is the only time it will be shown.';
        
        return new \WP_REST_Response([
            'success' => true,
            'data' => [
                'key' => $apiKey->getKey(),
                'name' => $apiKey->getName(),
                'scopes' => $apiKey->getScopes(),
                'created_at' => $apiKey->getCreatedAt()->format('Y-m-d H:i:s'),
                'expires_at' => $apiKey->getExpiresAt()?->format('Y-m-d H:i:s'),
            ],
            'message' => $message,
        ], 201);
    }

    public function revokeKey(\WP_REST_Request $request): \WP_REST_Response
    {
        $keyHash = $request->get_param('key');
        
        $userId = $this->getCurrentUserId($request);
        $apiKeys = $this->apiKeyService->listApiKeys($userId);
        
        $found = false;
        foreach ($apiKeys as $apiKey) {
            if (str_contains($apiKey->getKey(), $keyHash)) {
                $this->apiKeyService->revokeApiKey($apiKey->getKey());
                $found = true;
                break;
            }
        }
        
        if (!$found) {
            return new \WP_REST_Response([
                'success' => false,
                'message' => 'API key not found or insufficient permissions',
            ], 404);
        }
        
        return new \WP_REST_Response([
            'success' => true,
            'message' => 'API key revoked successfully',
        ], 200);
    }

    public function checkAuthenticated(\WP_REST_Request $request): bool
    {
        if ($this->jwtMiddleware && $this->jwtMiddleware->isAuthenticated($request)) {
            return true;
        }
        
        return is_user_logged_in();
    }

    private function getCurrentUserId(\WP_REST_Request $request): int
    {
        $userId = $request->get_param('_user_id');
        
        if ($userId) {
            return (int) $userId;
        }
        
        return get_current_user_id();
    }
}
