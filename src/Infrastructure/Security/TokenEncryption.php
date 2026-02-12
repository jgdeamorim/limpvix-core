<?php
/**
 * Token Encryption Service
 *
 * Encrypts/Decrypts sensitive tokens (MercadoPago OAuth, API keys, etc.)
 * using OpenSSL AES-256-CBC encryption.
 *
 * CRITICAL SECURITY:
 * - Tokens NEVER stored in plaintext
 * - Uses strong AES-256-CBC encryption
 * - Unique IV (Initialization Vector) per encryption
 * - HMAC verification to detect tampering
 *
 * @package LimpVix\Infrastructure\Security
 * @since 1.2.0
 */

namespace LimpVix\Infrastructure\Security;

defined('ABSPATH') || exit;

final class TokenEncryption
{
    private const CIPHER_METHOD = 'AES-256-CBC';
    private const HMAC_ALGO = 'sha256';

    private string $encryptionKey;

    /**
     * @throws \RuntimeException if encryption key not configured
     */
    public function __construct()
    {
        // Get encryption key from wp-config.php
        if (!defined('LIMPVIX_ENCRYPTION_KEY')) {
            throw new \RuntimeException(
                'LIMPVIX_ENCRYPTION_KEY not defined in wp-config.php. ' .
                'Add: define(\'LIMPVIX_ENCRYPTION_KEY\', \'your-64-char-hex-key\');'
            );
        }

        $this->encryptionKey = LIMPVIX_ENCRYPTION_KEY;

        // Validate key length (must be 32 bytes = 64 hex chars for AES-256)
        if (strlen($this->encryptionKey) !== 64) {
            throw new \RuntimeException(
                'LIMPVIX_ENCRYPTION_KEY must be exactly 64 hexadecimal characters (32 bytes). ' .
                'Generate with: bin2hex(random_bytes(32))'
            );
        }

        // Validate key is valid hex
        if (!ctype_xdigit($this->encryptionKey)) {
            throw new \RuntimeException('LIMPVIX_ENCRYPTION_KEY must contain only hexadecimal characters (0-9, a-f)');
        }
    }

    /**
     * Encrypt a token
     *
     * Format: base64(iv:hmac:ciphertext)
     *
     * @param string|null $plaintext Token to encrypt (null returns null)
     * @return string|null Encrypted token or null
     * @throws \RuntimeException on encryption failure
     */
    public function encrypt(?string $plaintext): ?string
    {
        // Handle null/empty
        if ($plaintext === null || $plaintext === '') {
            return null;
        }

        try {
            // Generate random IV (Initialization Vector)
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $iv = openssl_random_pseudo_bytes($ivLength);

            // Convert hex key to binary
            $key = hex2bin($this->encryptionKey);

            // Encrypt
            $ciphertext = openssl_encrypt(
                $plaintext,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($ciphertext === false) {
                throw new \RuntimeException('Encryption failed: ' . openssl_error_string());
            }

            // Generate HMAC for integrity verification
            $hmac = hash_hmac(self::HMAC_ALGO, $iv . $ciphertext, $key, true);

            // Combine: iv:hmac:ciphertext
            $encrypted = $iv . $hmac . $ciphertext;

            // Base64 encode for safe DB storage
            return base64_encode($encrypted);

        } catch (\Exception $e) {
            error_log('[LimpVix] Token encryption failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to encrypt token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Decrypt a token
     *
     * @param string|null $encrypted Encrypted token (null returns null)
     * @return string|null Decrypted token or null
     * @throws \RuntimeException on decryption failure or tampered data
     */
    public function decrypt(?string $encrypted): ?string
    {
        // Handle null/empty
        if ($encrypted === null || $encrypted === '') {
            return null;
        }

        try {
            // Base64 decode
            $data = base64_decode($encrypted, true);

            if ($data === false) {
                throw new \RuntimeException('Invalid base64 encoding');
            }

            // Extract components
            $ivLength = openssl_cipher_iv_length(self::CIPHER_METHOD);
            $hmacLength = 32; // SHA-256 produces 32 bytes

            if (strlen($data) < $ivLength + $hmacLength) {
                throw new \RuntimeException('Encrypted data too short - possibly corrupted');
            }

            $iv = substr($data, 0, $ivLength);
            $hmac = substr($data, $ivLength, $hmacLength);
            $ciphertext = substr($data, $ivLength + $hmacLength);

            // Convert hex key to binary
            $key = hex2bin($this->encryptionKey);

            // Verify HMAC (detect tampering)
            $expectedHmac = hash_hmac(self::HMAC_ALGO, $iv . $ciphertext, $key, true);

            if (!hash_equals($expectedHmac, $hmac)) {
                throw new \RuntimeException('HMAC verification failed - data has been tampered with');
            }

            // Decrypt
            $plaintext = openssl_decrypt(
                $ciphertext,
                self::CIPHER_METHOD,
                $key,
                OPENSSL_RAW_DATA,
                $iv
            );

            if ($plaintext === false) {
                throw new \RuntimeException('Decryption failed: ' . openssl_error_string());
            }

            return $plaintext;

        } catch (\Exception $e) {
            error_log('[LimpVix] Token decryption failed: ' . $e->getMessage());
            throw new \RuntimeException('Failed to decrypt token: ' . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Generate a secure encryption key
     *
     * Helper method to generate a new encryption key.
     * Should be run once during setup and added to wp-config.php
     *
     * @return string 64-character hexadecimal key
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Check if encryption is properly configured
     *
     * @return bool True if encryption key is configured and valid
     */
    public static function isConfigured(): bool
    {
        if (!defined('LIMPVIX_ENCRYPTION_KEY')) {
            return false;
        }

        $key = LIMPVIX_ENCRYPTION_KEY;

        return strlen($key) === 64 && ctype_xdigit($key);
    }

    /**
     * Test encryption/decryption round-trip
     *
     * @return bool True if encryption/decryption works correctly
     */
    public function testEncryption(): bool
    {
        try {
            $testData = 'test-token-' . wp_generate_password(32, true, true);
            $encrypted = $this->encrypt($testData);
            $decrypted = $this->decrypt($encrypted);

            return $decrypted === $testData;
        } catch (\Exception $e) {
            error_log('[LimpVix] Encryption test failed: ' . $e->getMessage());
            return false;
        }
    }
}
