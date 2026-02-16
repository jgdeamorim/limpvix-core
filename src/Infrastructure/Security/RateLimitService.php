<?php
namespace LimpVix\Infrastructure\Security;

defined('ABSPATH') || exit;

/**
 * Rate Limit Service
 *
 * Implements rate limiting using WordPress transients for storage.
 * Supports both IP-based and user-based rate limiting.
 *
 * Algorithm: Token Bucket with sliding window
 * - Each client gets a bucket of tokens
 * - Each request consumes 1 token
 * - Tokens refill at a constant rate
 * - When bucket is empty, requests are rejected
 */
final class RateLimitService
{
    private const TRANSIENT_PREFIX = 'limpvix_rate_limit_';
    
    // Default limits (requests per window)
    private const DEFAULT_LIMITS = [
        'public'        => ['requests' => 60,  'window' => 60],   // 60 req/min for public endpoints
        'authenticated' => ['requests' => 300, 'window' => 60],   // 300 req/min for authenticated
        'admin'         => ['requests' => 1000, 'window' => 60],  // 1000 req/min for admin
    ];

    private array $limits;

    public function __construct(?array $customLimits = null)
    {
        $this->limits = $customLimits ?? $this->loadLimitsFromOptions();
    }

    /**
     * Check if request is allowed under rate limit
     *
     * @param string $identifier Client identifier (IP or user ID)
     * @param string $tier Rate limit tier (public, authenticated, admin)
     * @return array [allowed => bool, limit => int, remaining => int, reset => int]
     */
    public function checkLimit(string $identifier, string $tier = 'public'): array
    {
        $limit = $this->limits[$tier] ?? self::DEFAULT_LIMITS['public'];
        $key = $this->getTransientKey($identifier, $tier);
        
        // Get current bucket state
        $bucket = get_transient($key);
        
        if ($bucket === false) {
            // First request - create new bucket
            $bucket = [
                'tokens' => $limit['requests'] - 1,
                'last_refill' => time(),
                'window_start' => time(),
            ];
            
            set_transient($key, $bucket, $limit['window']);
            
            return [
                'allowed' => true,
                'limit' => $limit['requests'],
                'remaining' => $bucket['tokens'],
                'reset' => time() + $limit['window'],
            ];
        }
        
        // Refill tokens based on time elapsed
        $now = time();
        $elapsed = $now - $bucket['last_refill'];
        $refillRate = $limit['requests'] / $limit['window']; // tokens per second
        $tokensToAdd = floor($elapsed * $refillRate);
        
        if ($tokensToAdd > 0) {
            $bucket['tokens'] = min(
                $limit['requests'],
                $bucket['tokens'] + $tokensToAdd
            );
            $bucket['last_refill'] = $now;
        }
        
        // Check if request can be processed
        if ($bucket['tokens'] > 0) {
            $bucket['tokens']--;
            set_transient($key, $bucket, $limit['window']);
            
            return [
                'allowed' => true,
                'limit' => $limit['requests'],
                'remaining' => $bucket['tokens'],
                'reset' => $bucket['window_start'] + $limit['window'],
            ];
        }
        
        // Rate limit exceeded
        return [
            'allowed' => false,
            'limit' => $limit['requests'],
            'remaining' => 0,
            'reset' => $bucket['window_start'] + $limit['window'],
        ];
    }

    /**
     * Get client identifier from request
     *
     * Priority: User ID > IP Address
     */
    public function getClientIdentifier(\WP_REST_Request $request): string
    {
        // Authenticated user
        $userId = $request->get_param('_user_id');
        if ($userId) {
            return 'user_' . $userId;
        }
        
        // IP address
        return 'ip_' . $this->getClientIp($request);
    }

    /**
     * Get rate limit tier for current request
     */
    public function getTier(\WP_REST_Request $request): string
    {
        $userId = $request->get_param('_user_id');
        
        if (!$userId) {
            return 'public';
        }
        
        // Check if user is admin
        $user = get_user_by('ID', $userId);
        if ($user && user_can($user, 'manage_options')) {
            return 'admin';
        }
        
        return 'authenticated';
    }

    /**
     * Reset rate limit for a client
     */
    public function resetLimit(string $identifier, string $tier = 'public'): void
    {
        $key = $this->getTransientKey($identifier, $tier);
        delete_transient($key);
    }

    /**
     * Get client IP address
     */
    private function getClientIp(\WP_REST_Request $request): string
    {
        // Check for proxy headers
        $headers = [
            'HTTP_CF_CONNECTING_IP',      // Cloudflare
            'HTTP_X_FORWARDED_FOR',       // Standard proxy
            'HTTP_X_REAL_IP',             // Nginx proxy
            'REMOTE_ADDR',                // Direct connection
        ];
        
        foreach ($headers as $header) {
            if (!empty($_SERVER[$header])) {
                $ip = $_SERVER[$header];
                
                // Handle multiple IPs (X-Forwarded-For can contain chain)
                if (strpos($ip, ',') !== false) {
                    $ips = explode(',', $ip);
                    $ip = trim($ips[0]);
                }
                
                // Validate IP
                if (filter_var($ip, FILTER_VALIDATE_IP)) {
                    return $ip;
                }
            }
        }
        
        return 'unknown';
    }

    /**
     * Generate transient key
     */
    private function getTransientKey(string $identifier, string $tier): string
    {
        return self::TRANSIENT_PREFIX . md5($identifier . '_' . $tier);
    }

    /**
     * Load limits from WordPress options
     */
    private function loadLimitsFromOptions(): array
    {
        $saved = get_option('limpvix_rate_limits', []);
        
        return [
            'public' => [
                'requests' => $saved['public_requests'] ?? 60,
                'window' => $saved['public_window'] ?? 60,
            ],
            'authenticated' => [
                'requests' => $saved['auth_requests'] ?? 300,
                'window' => $saved['auth_window'] ?? 60,
            ],
            'admin' => [
                'requests' => $saved['admin_requests'] ?? 1000,
                'window' => $saved['admin_window'] ?? 60,
            ],
        ];
    }
}
