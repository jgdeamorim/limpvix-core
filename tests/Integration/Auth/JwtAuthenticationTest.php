<?php

declare(strict_types=1);

namespace LimpVix\Tests\Integration\Auth;

use PHPUnit\Framework\TestCase;
use LimpVix\Infrastructure\Auth\JwtService;
use LimpVix\Infrastructure\Auth\JwtAuthMiddleware;

/**
 * Integration Tests para JWT Authentication
 * 
 * Testa todo o fluxo de autenticação JWT com banco de dados real
 */
class JwtAuthenticationTest extends TestCase
{
    private JwtService $jwtService;
    private JwtAuthMiddleware $jwtMiddleware;
    private int $testUserId;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Carregar WordPress
        require_once '/var/www/html/wp-load.php';
        
        // Inicializar serviços
        $this->jwtService = new JwtService();
        $this->jwtMiddleware = new JwtAuthMiddleware($this->jwtService);
        
        // Criar usuário de teste
        $this->testUserId = wp_create_user(
            'jwt_test_user_' . time(),
            'test_password_123',
            'jwttest@example.com'
        );
        
        $this->assertIsInt($this->testUserId);
        $this->assertGreaterThan(0, $this->testUserId);
    }

    protected function tearDown(): void
    {
        // Remover usuário de teste
        if ($this->testUserId > 0) {
            require_once ABSPATH . 'wp-admin/includes/user.php';
            wp_delete_user($this->testUserId);
        }
        
        parent::tearDown();
    }

    /**
     * Teste 1: Gerar access token para usuário válido
     */
    public function testGenerateAccessToken(): void
    {
        $token = $this->jwtService->generateAccessToken($this->testUserId);
        
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
        $this->assertStringContainsString('.', $token); // JWT tem pontos
        
        // JWT tem 3 partes: header.payload.signature
        $parts = explode('.', $token);
        $this->assertCount(3, $parts);
    }

    /**
     * Teste 2: Gerar refresh token para usuário válido
     */
    public function testGenerateRefreshToken(): void
    {
        $refreshToken = $this->jwtService->generateRefreshToken($this->testUserId);
        
        $this->assertIsString($refreshToken);
        $this->assertNotEmpty($refreshToken);
        
        $parts = explode('.', $refreshToken);
        $this->assertCount(3, $parts);
    }

    /**
     * Teste 3: Gerar par de tokens (access + refresh)
     */
    public function testGenerateTokenPair(): void
    {
        $tokens = $this->jwtService->generateTokenPair($this->testUserId);
        
        $this->assertIsArray($tokens);
        $this->assertArrayHasKey('access_token', $tokens);
        $this->assertArrayHasKey('refresh_token', $tokens);
        $this->assertArrayHasKey('expires_in', $tokens);
        $this->assertArrayHasKey('token_type', $tokens);
        
        $this->assertEquals('Bearer', $tokens['token_type']);
        $this->assertEquals(3600, $tokens['expires_in']); // 1 hora
    }

    /**
     * Teste 4: Validar access token válido
     */
    public function testValidateAccessToken(): void
    {
        $token = $this->jwtService->generateAccessToken($this->testUserId);
        $result = $this->jwtService->validateToken($token);
        
        $this->assertIsArray($result);
        $this->assertArrayHasKey('userId', $result);
        $this->assertArrayHasKey('claims', $result);
        
        $this->assertEquals($this->testUserId, $result['userId']);
        
        // Verificar claims
        $claims = $result['claims'];
        $this->assertIsArray($claims);
        $this->assertArrayHasKey('sub', $claims);
        $this->assertArrayHasKey('type', $claims);
        $this->assertEquals('access', $claims['type']);
    }

    /**
     * Teste 5: Validar refresh token válido
     */
    public function testValidateRefreshToken(): void
    {
        $refreshToken = $this->jwtService->generateRefreshToken($this->testUserId);
        $userId = $this->jwtService->validateRefreshToken($refreshToken);
        
        $this->assertEquals($this->testUserId, $userId);
    }

    /**
     * Teste 6: Rejeitar token inválido (string aleatória)
     */
    public function testRejectInvalidToken(): void
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Invalid or expired token/i');
        
        $this->jwtService->validateToken('invalid.token.here');
    }

    /**
     * Teste 7: Rejeitar access token quando esperado refresh token
     */
    public function testRejectAccessTokenAsRefreshToken(): void
    {
        $accessToken = $this->jwtService->generateAccessToken($this->testUserId);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Invalid token type/i');
        
        $this->jwtService->validateRefreshToken($accessToken);
    }

    /**
     * Teste 8: Extrair token do header Authorization
     */
    public function testExtractTokenFromHeader(): void
    {
        $token = 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.test';
        
        // Formato: Bearer <token>
        $authHeader = 'Bearer ' . $token;
        $extracted = $this->jwtService->extractTokenFromHeader($authHeader);
        
        $this->assertEquals($token, $extracted);
        
        // Case insensitive
        $authHeader2 = 'bearer ' . $token;
        $extracted2 = $this->jwtService->extractTokenFromHeader($authHeader2);
        
        $this->assertEquals($token, $extracted2);
    }

    /**
     * Teste 9: Retornar null para header vazio
     */
    public function testExtractTokenFromEmptyHeader(): void
    {
        $this->assertNull($this->jwtService->extractTokenFromHeader(null));
        $this->assertNull($this->jwtService->extractTokenFromHeader(''));
        $this->assertNull($this->jwtService->extractTokenFromHeader('InvalidFormat'));
    }

    /**
     * Teste 10: Middleware - Autenticar com JWT válido
     */
    public function testMiddlewareAuthenticateWithValidJwt(): void
    {
        $token = $this->jwtService->generateAccessToken($this->testUserId);
        
        // Criar mock de WP_REST_Request
        $request = new \WP_REST_Request('GET', '/test');
        $request->set_header('authorization', 'Bearer ' . $token);
        
        $isAuthenticated = $this->jwtMiddleware->isAuthenticated($request);
        
        $this->assertTrue($isAuthenticated);
        $this->assertEquals($this->testUserId, $request->get_param('_user_id'));
        $this->assertEquals('jwt', $request->get_param('_auth_method'));
    }

    /**
     * Teste 11: Middleware - Rejeitar JWT inválido
     */
    public function testMiddlewareRejectInvalidJwt(): void
    {
        $request = new \WP_REST_Request('GET', '/test');
        $request->set_header('authorization', 'Bearer invalid.token.here');
        
        $isAuthenticated = $this->jwtMiddleware->isAuthenticated($request);
        
        $this->assertFalse($isAuthenticated);
        $this->assertNull($request->get_param('_user_id'));
    }

    /**
     * Teste 12: Middleware - Autenticar com WordPress session (fallback)
     */
    public function testMiddlewareFallbackToWordPressSession(): void
    {
        // Simular usuário logado
        wp_set_current_user($this->testUserId);
        
        $request = new \WP_REST_Request('GET', '/test');
        // Sem header Authorization
        
        $isAuthenticated = $this->jwtMiddleware->isAuthenticated($request);
        
        $this->assertTrue($isAuthenticated);
        $this->assertEquals($this->testUserId, $request->get_param('_user_id'));
        $this->assertEquals('session', $request->get_param('_auth_method'));
        
        // Limpar
        wp_set_current_user(0);
    }

    /**
     * Teste 13: Middleware - isAdmin verifica capability
     */
    public function testMiddlewareIsAdminCheckCapability(): void
    {
        // Criar admin user
        $adminId = wp_create_user(
            'admin_test_' . time(),
            'admin_pass',
            'admin@test.com'
        );
        $user = get_user_by('ID', $adminId);
        $user->set_role('administrator');
        
        $token = $this->jwtService->generateAccessToken($adminId);
        
        $request = new \WP_REST_Request('GET', '/test');
        $request->set_header('authorization', 'Bearer ' . $token);
        
        $isAdmin = $this->jwtMiddleware->isAdmin($request);
        
        $this->assertTrue($isAdmin);
        
        // Cleanup
        require_once ABSPATH . 'wp-admin/includes/user.php';
        wp_delete_user($adminId);
    }

    /**
     * Teste 14: Token expira corretamente (simulação)
     */
    public function testTokenContainsExpirationClaim(): void
    {
        $token = $this->jwtService->generateAccessToken($this->testUserId);
        $result = $this->jwtService->validateToken($token);
        
        $claims = $result['claims'];
        
        $this->assertArrayHasKey('exp', $claims);
        $this->assertArrayHasKey('iat', $claims);
        
        $issuedAt = $claims['iat'];
        $expiresAt = $claims['exp'];
        
        // Diferença deve ser 1 hora (3600 segundos)
        $this->assertEquals(3600, $expiresAt - $issuedAt);
    }

    /**
     * Teste 15: Refresh token tem TTL maior que access token
     */
    public function testRefreshTokenHasLongerTTL(): void
    {
        $accessToken = $this->jwtService->generateAccessToken($this->testUserId);
        $refreshToken = $this->jwtService->generateRefreshToken($this->testUserId);
        
        $accessResult = $this->jwtService->validateToken($accessToken);
        $refreshResult = $this->jwtService->validateToken($refreshToken);
        
        $accessExpiry = $accessResult['claims']['exp'];
        $refreshExpiry = $refreshResult['claims']['exp'];
        
        // Refresh token deve expirar depois do access token
        $this->assertGreaterThan($accessExpiry, $refreshExpiry);
    }
}
