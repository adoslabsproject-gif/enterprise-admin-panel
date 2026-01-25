<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use RuntimeException;

/**
 * Encryption Service
 *
 * Provides symmetric encryption for sensitive data that needs to be
 * stored encrypted but also retrieved (unlike hashing).
 *
 * Uses: AES-256-GCM (authenticated encryption)
 *
 * Use cases:
 * - 2FA TOTP secrets (need to read to verify codes)
 * - API keys that need to be displayed once
 *
 * NOT for:
 * - Passwords (use Argon2id hashing instead)
 * - Tokens for verification (use hashing instead)
 *
 * @version 1.0.0
 */
final class EncryptionService
{
    /**
     * Encryption algorithm: AES-256-GCM (authenticated encryption)
     */
    private const CIPHER = 'aes-256-gcm';

    /**
     * Tag length for GCM (128 bits = 16 bytes)
     */
    private const TAG_LENGTH = 16;

    /**
     * Encryption key (derived from APP_KEY in .env)
     */
    private string $key;

    public function __construct(?string $key = null)
    {
        $key = $key ?? getenv('APP_KEY') ?: $this->loadKeyFromEnv();

        if (!$key) {
            throw new RuntimeException(
                'APP_KEY not found. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        // Key should be 64 hex chars (32 bytes = 256 bits)
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $this->key = hex2bin($key);
        } elseif (strlen($key) === 32) {
            // Already binary
            $this->key = $key;
        } else {
            // Hash the key to get consistent 256 bits
            $this->key = hash('sha256', $key, true);
        }
    }

    /**
     * Encrypt a value
     *
     * @param string $plaintext The value to encrypt
     * @return string Base64-encoded ciphertext (IV + tag + ciphertext)
     */
    public function encrypt(string $plaintext): string
    {
        // Generate random IV (12 bytes for GCM)
        $iv = random_bytes(12);

        // Encrypt with authenticated encryption
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            '', // AAD (additional authenticated data)
            self::TAG_LENGTH
        );

        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed: ' . openssl_error_string());
        }

        // Combine: IV (12) + tag (16) + ciphertext
        $combined = $iv . $tag . $ciphertext;

        return base64_encode($combined);
    }

    /**
     * Decrypt a value
     *
     * @param string $encrypted Base64-encoded ciphertext
     * @return string|null The decrypted value, or null if decryption fails
     */
    public function decrypt(string $encrypted): ?string
    {
        $combined = base64_decode($encrypted, true);

        if ($combined === false || strlen($combined) < 12 + self::TAG_LENGTH) {
            return null;
        }

        // Extract components
        $iv = substr($combined, 0, 12);
        $tag = substr($combined, 12, self::TAG_LENGTH);
        $ciphertext = substr($combined, 12 + self::TAG_LENGTH);

        // Decrypt
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($plaintext === false) {
            // Decryption failed (wrong key, tampered data, etc.)
            return null;
        }

        return $plaintext;
    }

    /**
     * Re-encrypt a value with a new key
     *
     * Useful for key rotation.
     *
     * @param string $encrypted Value encrypted with old key
     * @param string $oldKey The old encryption key
     * @return string|null Re-encrypted value with current key, or null if decryption fails
     */
    public function reEncrypt(string $encrypted, string $oldKey): ?string
    {
        $oldService = new self($oldKey);
        $plaintext = $oldService->decrypt($encrypted);

        if ($plaintext === null) {
            return null;
        }

        return $this->encrypt($plaintext);
    }

    /**
     * Load key from .env file
     */
    private function loadKeyFromEnv(): ?string
    {
        $envFile = dirname(__DIR__, 2) . '/.env';

        if (!file_exists($envFile)) {
            return null;
        }

        $content = file_get_contents($envFile);

        if (preg_match('/^APP_KEY=(.+)$/m', $content, $matches)) {
            return trim($matches[1]);
        }

        return null;
    }

    /**
     * Generate a new encryption key
     *
     * @return string Hex-encoded 256-bit key
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }
}
