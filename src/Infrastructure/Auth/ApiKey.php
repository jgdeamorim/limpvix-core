<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Auth;

defined('ABSPATH') || exit;

/**
 * API Key Value Object
 *
 * Represents an API key for external application authentication
 */
final class ApiKey
{
    private const PREFIX = 'limpvix_';
    private const KEY_LENGTH = 32;

    private string $key;
    private string $name;
    private array $scopes;
    private int $userId;
    private \DateTimeImmutable $createdAt;
    private ?\DateTimeImmutable $lastUsedAt;
    private ?\DateTimeImmutable $expiresAt;
    private bool $isActive;

    private function __construct(
        string $key,
        string $name,
        array $scopes,
        int $userId,
        \DateTimeImmutable $createdAt,
        ?\DateTimeImmutable $lastUsedAt = null,
        ?\DateTimeImmutable $expiresAt = null,
        bool $isActive = true
    ) {
        $this->key = $key;
        $this->name = $name;
        $this->scopes = $scopes;
        $this->userId = $userId;
        $this->createdAt = $createdAt;
        $this->lastUsedAt = $lastUsedAt;
        $this->expiresAt = $expiresAt;
        $this->isActive = $isActive;
    }

    /**
     * Generate new API key
     */
    public static function generate(
        string $name,
        array $scopes,
        int $userId,
        ?\DateTimeImmutable $expiresAt = null
    ): self {
        $key = self::PREFIX . bin2hex(random_bytes(self::KEY_LENGTH));
        
        return new self(
            $key,
            $name,
            $scopes,
            $userId,
            new \DateTimeImmutable(),
            null,
            $expiresAt,
            true
        );
    }

    /**
     * Reconstitute from storage
     */
    public static function fromArray(array $data): self
    {
        return new self(
            $data['key'],
            $data['name'],
            $data['scopes'] ?? [],
            (int) $data['user_id'],
            new \DateTimeImmutable($data['created_at']),
            isset($data['last_used_at']) ? new \DateTimeImmutable($data['last_used_at']) : null,
            isset($data['expires_at']) ? new \DateTimeImmutable($data['expires_at']) : null,
            (bool) ($data['is_active'] ?? true)
        );
    }

    /**
     * Convert to array for storage
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'scopes' => $this->scopes,
            'user_id' => $this->userId,
            'created_at' => $this->createdAt->format('Y-m-d H:i:s'),
            'last_used_at' => $this->lastUsedAt?->format('Y-m-d H:i:s'),
            'expires_at' => $this->expiresAt?->format('Y-m-d H:i:s'),
            'is_active' => $this->isActive,
        ];
    }

    /**
     * Check if key is valid (active and not expired)
     */
    public function isValid(): bool
    {
        if (!$this->isActive) {
            return false;
        }

        if ($this->expiresAt && $this->expiresAt < new \DateTimeImmutable()) {
            return false;
        }

        return true;
    }

    /**
     * Check if key has a specific scope
     */
    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true) || in_array('*', $this->scopes, true);
    }

    /**
     * Update last used timestamp
     */
    public function markAsUsed(): void
    {
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    /**
     * Revoke the API key
     */
    public function revoke(): void
    {
        $this->isActive = false;
    }

    // Getters
    public function getKey(): string
    {
        return $this->key;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getScopes(): array
    {
        return $this->scopes;
    }

    public function getUserId(): int
    {
        return $this->userId;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    /**
     * Get masked key for display (show only first 12 chars)
     */
    public function getMaskedKey(): string
    {
        return substr($this->key, 0, 20) . '...';
    }
}
