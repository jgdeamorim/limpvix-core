<?php

declare(strict_types=1);

namespace LimpVix\Tests\Integration\Security;

use LimpVix\Infrastructure\Security\RateLimitService;
use LimpVix\Infrastructure\Security\RateLimitMiddleware;
use WP_REST_Request;
use PHPUnit\Framework\TestCase;

/**
 * @group integration
 * @group security
 */
final class RateLimitTest extends TestCase
{
    private RateLimitService $rateLimitService;
    private RateLimitMiddleware $rateLimitMiddleware;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Custom limits for testing (lower values)
        $testLimits = [
            'public' => ['requests' => 5, 'window' => 10],
            'authenticated' => ['requests' => 10, 'window' => 10],
            'admin' => ['requests' => 50, 'window' => 10],
        ];
        
        $this->rateLimitService = new RateLimitService($testLimits);
        $this->rateLimitMiddleware = new RateLimitMiddleware($this->rateLimitService);
    }

    protected function tearDown(): void
    {
        // Clean up transients
        global $wpdb;
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_limpvix_rate_limit_%'");
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_timeout_limpvix_rate_limit_%'");
        
        parent::tearDown();
    }

    public function testPublicRateLimitAllowsRequestsUnderLimit(): void
    {
        $identifier = 'ip_127.0.0.1';
        
        // First 5 requests should be allowed
        for ($i = 0; $i < 5; $i++) {
            $result = $this->rateLimitService->checkLimit($identifier, 'public');
            
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
            $this->assertEquals(5, $result['limit']);
            $this->assertEquals(4 - $i, $result['remaining']);
        }
    }

    public function testPublicRateLimitBlocksRequestsOverLimit(): void
    {
        $identifier = 'ip_127.0.0.2';
        
        // Consume all 5 allowed requests
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimitService->checkLimit($identifier, 'public');
        }
        
        // 6th request should be blocked
        $result = $this->rateLimitService->checkLimit($identifier, 'public');
        
        $this->assertFalse($result['allowed']);
        $this->assertEquals(0, $result['remaining']);
        $this->assertGreaterThan(time(), $result['reset']);
    }

    public function testAuthenticatedTierHasHigherLimit(): void
    {
        $identifier = 'user_123';
        
        // Authenticated tier allows 10 requests
        for ($i = 0; $i < 10; $i++) {
            $result = $this->rateLimitService->checkLimit($identifier, 'authenticated');
            
            $this->assertTrue($result['allowed'], "Request {$i} should be allowed");
            $this->assertEquals(10, $result['limit']);
        }
        
        // 11th request should be blocked
        $result = $this->rateLimitService->checkLimit($identifier, 'authenticated');
        $this->assertFalse($result['allowed']);
    }

    public function testAdminTierHasHighestLimit(): void
    {
        $identifier = 'user_1';
        
        // Admin tier allows 50 requests
        for ($i = 0; $i < 50; $i++) {
            $result = $this->rateLimitService->checkLimit($identifier, 'admin');
            
            if (!$result['allowed']) {
                $this->fail("Request {$i} should be allowed but was blocked");
            }
        }
        
        $this->assertTrue(true); // All 50 requests passed
    }

    public function testTokenRefillOverTime(): void
    {
        $identifier = 'ip_127.0.0.3';
        
        // Consume 3 requests
        for ($i = 0; $i < 3; $i++) {
            $this->rateLimitService->checkLimit($identifier, 'public');
        }
        
        // Check remaining tokens
        $result = $this->rateLimitService->checkLimit($identifier, 'public');
        $this->assertEquals(1, $result['remaining']);
        
        // Wait 2 seconds (should refill ~1 token at 0.5 tokens/sec rate)
        sleep(2);
        
        // Should have more tokens now
        $result = $this->rateLimitService->checkLimit($identifier, 'public');
        $this->assertGreaterThanOrEqual(1, $result['remaining']);
    }

    public function testResetLimitClearsTokens(): void
    {
        $identifier = 'ip_127.0.0.4';
        
        // Consume all requests
        for ($i = 0; $i < 5; $i++) {
            $this->rateLimitService->checkLimit($identifier, 'public');
        }
        
        // Verify blocked
        $result = $this->rateLimitService->checkLimit($identifier, 'public');
        $this->assertFalse($result['allowed']);
        
        // Reset limit
        $this->rateLimitService->resetLimit($identifier, 'public');
        
        // Should be allowed again
        $result = $this->rateLimitService->checkLimit($identifier, 'public');
        $this->assertTrue($result['allowed']);
        $this->assertEquals(4, $result['remaining']);
    }

    public function testMiddlewareBlocksExcessiveRequests(): void
    {
        $request = new WP_REST_Request('GET', '/limpvix/v1/test');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.5';
        
        // First 5 requests should pass
        for ($i = 0; $i < 5; $i++) {
            $result = $this->rateLimitMiddleware->checkRateLimit($request);
            $this->assertTrue($result, "Request {$i} should pass middleware");
        }
        
        // 6th request should be blocked
        $result = $this->rateLimitMiddleware->checkRateLimit($request);
        
        $this->assertInstanceOf(\WP_Error::class, $result);
        $this->assertEquals('rate_limit_exceeded', $result->get_error_code());
        $this->assertEquals(429, $result->get_error_data()['status']);
    }

    public function testGetClientIdentifierPrefersUserId(): void
    {
        $request = new WP_REST_Request('GET', '/limpvix/v1/test');
        $request->set_param('_user_id', 123);
        $_SERVER['REMOTE_ADDR'] = '127.0.0.6';
        
        $identifier = $this->rateLimitService->getClientIdentifier($request);
        
        $this->assertEquals('user_123', $identifier);
    }

    public function testGetClientIdentifierFallsBackToIp(): void
    {
        $request = new WP_REST_Request('GET', '/limpvix/v1/test');
        $_SERVER['REMOTE_ADDR'] = '192.168.1.1';
        
        $identifier = $this->rateLimitService->getClientIdentifier($request);
        
        $this->assertEquals('ip_192.168.1.1', $identifier);
    }

    public function testGetTierReturnsPublicForUnauthenticated(): void
    {
        $request = new WP_REST_Request('GET', '/limpvix/v1/test');
        
        $tier = $this->rateLimitService->getTier($request);
        
        $this->assertEquals('public', $tier);
    }

    public function testGetTierReturnsAuthenticatedForRegularUser(): void
    {
        // Create test user
        $userId = \wp_create_user('testuser', 'password', 'test@example.com');
        
        $request = new WP_REST_Request('GET', '/limpvix/v1/test');
        $request->set_param('_user_id', $userId);
        
        $tier = $this->rateLimitService->getTier($request);
        
        $this->assertEquals('authenticated', $tier);
    }

    public function testRateLimitHeadersAreAdded(): void
    {
        $request = new WP_REST_Request('GET', '/limpvix/v1/test');
        $_SERVER['REMOTE_ADDR'] = '127.0.0.7';
        
        // Check rate limit first
        $this->rateLimitMiddleware->checkRateLimit($request);
        
        // Create response and add headers
        $response = new \WP_REST_Response(['test' => true]);
        $response = $this->rateLimitMiddleware->addRateLimitHeaders($response, $request);
        
        $headers = $response->get_headers();
        
        $this->assertArrayHasKey('X-RateLimit-Limit', $headers);
        $this->assertArrayHasKey('X-RateLimit-Remaining', $headers);
        $this->assertArrayHasKey('X-RateLimit-Reset', $headers);
        
        $this->assertEquals('5', $headers['X-RateLimit-Limit']);
        $this->assertGreaterThanOrEqual(0, (int) $headers['X-RateLimit-Remaining']);
    }
}
