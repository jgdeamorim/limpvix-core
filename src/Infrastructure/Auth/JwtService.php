<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Auth;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use DateTimeImmutable;
use Exception;

/**
 * JWT Service para autenticação token-based
 */
final class JwtService
{
    private const ACCESS_TOKEN_TTL = 3600; // 1 hora
    private const REFRESH_TOKEN_TTL = 2592000; // 30 dias
    private const ALGORITHM = 'HS256';

    private string $secretKey;
    private string $issuer;

    public function __construct()
    {
        $this->secretKey = defined('AUTH_KEY') ? AUTH_KEY : 'limpvix-default-secret';
        $this->issuer = get_site_url();
    }

    public function generateAccessToken(int $userId, array $additionalClaims = []): string
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->getTimestamp() + self::ACCESS_TOKEN_TTL;

        $payload = array_merge([
            'iss' => $this->issuer,
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt,
            'sub' => (string) $userId,
            'type' => 'access',
        ], $additionalClaims);

        return JWT::encode($payload, $this->secretKey, self::ALGORITHM);
    }

    public function generateRefreshToken(int $userId): string
    {
        $now = new DateTimeImmutable();
        $expiresAt = $now->getTimestamp() + self::REFRESH_TOKEN_TTL;

        $payload = [
            'iss' => $this->issuer,
            'iat' => $now->getTimestamp(),
            'exp' => $expiresAt,
            'sub' => (string) $userId,
            'type' => 'refresh',
        ];

        return JWT::encode($payload, $this->secretKey, self::ALGORITHM);
    }

    public function validateToken(string $token): array
    {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, self::ALGORITHM));
            $claims = (array) $decoded;

            if (!isset($claims['iss']) || $claims['iss'] !== $this->issuer) {
                throw new Exception('Invalid token issuer');
            }

            if (!isset($claims['sub'])) {
                throw new Exception('Token missing subject claim');
            }

            $userId = (int) $claims['sub'];
            $user = get_user_by('ID', $userId);
            if (!$user) {
                throw new Exception('User not found');
            }

            return ['userId' => $userId, 'claims' => $claims];
        } catch (Exception $e) {
            throw new Exception('Invalid or expired token: ' . $e->getMessage());
        }
    }

    public function validateRefreshToken(string $refreshToken): int
    {
        $result = $this->validateToken($refreshToken);
        if (!isset($result['claims']['type']) || $result['claims']['type'] !== 'refresh') {
            throw new Exception('Invalid token type');
        }
        return $result['userId'];
    }

    public function extractTokenFromHeader(?string $authHeader): ?string
    {
        if (empty($authHeader)) {
            return null;
        }
        if (preg_match('/^Bearer\s+(.+)$/i', $authHeader, $matches)) {
            return $matches[1];
        }
        return null;
    }

    public function generateTokenPair(int $userId): array
    {
        return [
            'access_token' => $this->generateAccessToken($userId),
            'refresh_token' => $this->generateRefreshToken($userId),
            'expires_in' => self::ACCESS_TOKEN_TTL,
            'token_type' => 'Bearer',
        ];
    }
}
