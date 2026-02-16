<?php

declare(strict_types=1);

namespace LimpVix\Infrastructure\Auth;

defined('ABSPATH') || exit;

/**
 * API Key Service
 *
 * Manages API key lifecycle: creation, validation, revocation
 * Storage: WordPress options (simple key-value store)
 */
final class ApiKeyService
{
    private const OPTION_PREFIX = 'limpvix_api_keys';
    private const HASH_ALGO = 'sha256';

    /**
     * Create new API key
     */
    public function createApiKey(
        string $name,
        array $scopes,
        int $userId,
        ?\DateTimeImmutable $expiresAt = null
    ): ApiKey {
        $apiKey = ApiKey::generate($name, $scopes, $userId, $expiresAt);
        
        $this->saveApiKey($apiKey);
        
        return $apiKey;
    }

    /**
     * Validate API key
     *
     * @return ApiKey|null Returns ApiKey if valid, null otherwise
     */
    public function validateApiKey(string $key): ?ApiKey
    {
        $apiKey = $this->findApiKey($key);
        
        if (!$apiKey || !$apiKey->isValid()) {
            return null;
        }

        // Update last used timestamp
        $apiKey->markAsUsed();
        $this->saveApiKey($apiKey);
        
        return $apiKey;
    }

    /**
     * Find API key by key string
     */
    public function findApiKey(string $key): ?ApiKey
    {
        $hash = $this->hashKey($key);
        $data = get_option($this->getOptionName($hash), null);
        
        if (!$data) {
            return null;
        }

        return ApiKey::fromArray(json_decode($data, true));
    }

    /**
     * List all API keys for a user
     */
    public function listApiKeys(int $userId): array
    {
        global $wpdb;
        
        $pattern = $wpdb->esc_like(self::OPTION_PREFIX) . '_%';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_value FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                $pattern
            ),
            ARRAY_A
        );
        
        $apiKeys = [];
        foreach ($results as $row) {
            $data = json_decode($row['option_value'], true);
            
            if ($data && (int) $data['user_id'] === $userId) {
                $apiKeys[] = ApiKey::fromArray($data);
            }
        }
        
        return $apiKeys;
    }

    /**
     * Revoke API key
     */
    public function revokeApiKey(string $key): bool
    {
        $apiKey = $this->findApiKey($key);
        
        if (!$apiKey) {
            return false;
        }

        $apiKey->revoke();
        $this->saveApiKey($apiKey);
        
        return true;
    }

    /**
     * Delete API key permanently
     */
    public function deleteApiKey(string $key): bool
    {
        $hash = $this->hashKey($key);
        return delete_option($this->getOptionName($hash));
    }

    /**
     * Save API key to storage
     */
    private function saveApiKey(ApiKey $apiKey): void
    {
        $hash = $this->hashKey($apiKey->getKey());
        $data = json_encode($apiKey->toArray());
        
        update_option($this->getOptionName($hash), $data, false);
    }

    /**
     * Hash API key for storage (don't store raw keys)
     */
    private function hashKey(string $key): string
    {
        return hash(self::HASH_ALGO, $key);
    }

    /**
     * Get WordPress option name for hashed key
     */
    private function getOptionName(string $hash): string
    {
        return self::OPTION_PREFIX . '_' . $hash;
    }

    /**
     * Clean up expired API keys (run via cron)
     */
    public function cleanupExpiredKeys(): int
    {
        global $wpdb;
        
        $pattern = $wpdb->esc_like(self::OPTION_PREFIX) . '_%';
        
        $results = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT option_name, option_value FROM {$wpdb->options} 
                 WHERE option_name LIKE %s",
                $pattern
            ),
            ARRAY_A
        );
        
        $deleted = 0;
        foreach ($results as $row) {
            $data = json_decode($row['option_value'], true);
            
            if ($data) {
                $apiKey = ApiKey::fromArray($data);
                
                if (!$apiKey->isValid() && !$apiKey->isActive()) {
                    delete_option($row['option_name']);
                    $deleted++;
                }
            }
        }
        
        return $deleted;
    }
}
