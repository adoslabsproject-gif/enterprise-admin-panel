<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use RuntimeException;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

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

    /**
     * Minimum entropy required for non-hex keys (in bytes)
     * 16 bytes = 128 bits minimum (still secure, but we prefer 256)
     */
    private const MIN_KEY_LENGTH = 16;

    public function __construct(?string $key = null)
    {
        $key = $key ?? getenv('APP_KEY') ?: $this->loadKeyFromEnv();

        if (!$key) {
            throw new RuntimeException(
                'APP_KEY not found. Generate one with: php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        // Key should be 64 hex chars (32 bytes = 256 bits) - RECOMMENDED
        if (strlen($key) === 64 && ctype_xdigit($key)) {
            $this->key = hex2bin($key);
        } elseif (strlen($key) === 32 && !ctype_print($key)) {
            // Already binary 256-bit key
            $this->key = $key;
        } else {
            // SECURITY: Validate minimum key length to prevent weak keys
            if (strlen($key) < self::MIN_KEY_LENGTH) {
                throw new RuntimeException(
                    'APP_KEY is too short (minimum ' . self::MIN_KEY_LENGTH . ' characters). ' .
                    'Generate a secure key with: php -r "echo bin2hex(random_bytes(32));"'
                );
            }

            // Log warning about non-standard key format
            Logger::channel('security')->warning('APP_KEY is not in recommended format (64 hex chars)', [
                'key_length' => strlen($key),
                'recommendation' => 'Generate with: php -r "echo bin2hex(random_bytes(32));"',
            ]);

            // Derive 256-bit key using HKDF (better than raw SHA256)
            // HKDF extracts entropy and expands to desired length
            $this->key = hash_hkdf('sha256', $key, 32, 'aes-256-gcm-encryption');
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
            // Security log: malformed encrypted data
            Logger::channel('security')->warning('Decryption failed - malformed data', [
                'input_length' => strlen($encrypted),
                'decoded_length' => $combined !== false ? strlen($combined) : 0,
                'expected_min_length' => 12 + self::TAG_LENGTH,
            ]);
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
            // Security log: potential key mismatch, data corruption, or tampering attempt
            Logger::channel('security')->error('Decryption failed - authentication failed', [
                'error' => openssl_error_string() ?: 'unknown',
                'ciphertext_length' => strlen($ciphertext),
            ]);
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
            Logger::channel('security')->error('Re-encryption failed - decryption with old key failed');
            return null;
        }

        return $this->encrypt($plaintext);
    }

    /**
     * Load key from .env file
     *
     * Searches for .env in multiple locations:
     * 1. Current working directory (project root)
     * 2. Parent of CWD (when php -S runs with -t public)
     * 3. Project root via vendor path calculation
     * 4. Package root (when running standalone)
     */
    private function loadKeyFromEnv(): ?string
    {
        $cwd = getcwd() ?: '';
        $possiblePaths = [
            // Current working directory
            $cwd . '/.env',
            // Parent of CWD (when running php -S -t public, CWD is public/)
            dirname($cwd) . '/.env',
            // Project root (5 levels up from src/Services/ when in vendor)
            dirname(__DIR__, 5) . '/.env',
            // Package root (2 levels up from src/Services/)
            dirname(__DIR__, 2) . '/.env',
        ];

        foreach ($possiblePaths as $envFile) {
            if (file_exists($envFile)) {
                $content = file_get_contents($envFile);

                if (preg_match('/^APP_KEY=(.+)$/m', $content, $matches)) {
                    return trim($matches[1]);
                }
            }
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
