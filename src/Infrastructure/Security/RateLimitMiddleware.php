<?php
namespace LimpVix\Infrastructure\Security;

defined('ABSPATH') || exit;

/**
 * Rate Limit Middleware
 *
 * REST API middleware that enforces rate limits on incoming requests.
 * Adds X-RateLimit-* headers to all responses.
 */
final class RateLimitMiddleware
{
    private RateLimitService $rateLimitService;

    public function __construct(RateLimitService $rateLimitService)
    {
        $this->rateLimitService = $rateLimitService;
    }

    /**
     * Check rate limit for incoming request
     *
     * @param \WP_REST_Request $request
     * @return bool|\WP_Error True if allowed, WP_Error if rate limit exceeded
     */
    public function checkRateLimit(\WP_REST_Request $request)
    {
        // Skip rate limiting for specific endpoints if needed
        if ($this->shouldSkipRateLimit($request)) {
            return true;
        }

        $identifier = $this->rateLimitService->getClientIdentifier($request);
        $tier = $this->rateLimitService->getTier($request);
        
        $result = $this->rateLimitService->checkLimit($identifier, $tier);
        
        // Store rate limit info in request for response headers
        $request->set_param('_rate_limit_info', $result);
        
        if (!$result['allowed']) {
            $retryAfter = $result['reset'] - time();
            
            return new \WP_Error(
                'rate_limit_exceeded',
                sprintf(
                    'Rate limit exceeded. Try again in %d seconds.',
                    $retryAfter
                ),
                [
                    'status' => 429,
                    'retry_after' => $retryAfter,
                    'limit' => $result['limit'],
                    'reset' => $result['reset'],
                ]
            );
        }
        
        return true;
    }

    /**
     * Add rate limit headers to response
     *
     * @param \WP_REST_Response $response
     * @param \WP_REST_Request $request
     * @return \WP_REST_Response
     */
    public function addRateLimitHeaders(\WP_REST_Response $response, \WP_REST_Request $request): \WP_REST_Response
    {
        $rateLimitInfo = $request->get_param('_rate_limit_info');
        
        if (!$rateLimitInfo) {
            // If no rate limit info, calculate it now
            $identifier = $this->rateLimitService->getClientIdentifier($request);
            $tier = $this->rateLimitService->getTier($request);
            $rateLimitInfo = $this->rateLimitService->checkLimit($identifier, $tier);
        }
        
        $response->header('X-RateLimit-Limit', (string) $rateLimitInfo['limit']);
        $response->header('X-RateLimit-Remaining', (string) $rateLimitInfo['remaining']);
        $response->header('X-RateLimit-Reset', (string) $rateLimitInfo['reset']);
        
        return $response;
    }

    /**
     * Check if endpoint should skip rate limiting
     */
    private function shouldSkipRateLimit(\WP_REST_Request $request): bool
    {
        $route = $request->get_route();
        
        // Skip for health check endpoints
        $skipRoutes = [
            '/limpvix/v1/health',
        ];
        
        return in_array($route, $skipRoutes, true);
    }
}
