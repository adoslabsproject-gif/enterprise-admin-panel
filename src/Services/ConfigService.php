<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Enterprise Configuration Service
 *
 * Manages admin panel configuration including:
 * - Dynamic cryptographic base URL (ENCRYPTED in DB)
 * - HMAC secrets (ENCRYPTED in DB)
 * - Security settings
 * - Runtime configuration
 *
 * SECURITY: All sensitive values are encrypted with AES-256-GCM
 * using APP_KEY from environment. The key NEVER touches the database.
 *
 * @version 2.0.0
 */
final class ConfigService
{
    /**
     * Keys that are ALWAYS encrypted in the database
     */
    private const ENCRYPTED_KEYS = [
        'admin_base_path',
        'hmac_secret',
        'encryption_key_backup',
        'api_secret',
        'webhook_secret',
    ];

    private array $cache = [];
    private bool $cacheLoaded = false;
    private EncryptionService $encryption;

    public function __construct(
        private DatabasePool $db,
        private ?LoggerInterface $logger = null,
        ?EncryptionService $encryption = null
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->encryption = $encryption ?? new EncryptionService();
    }

    /**
     * Get configuration value
     *
     * Sensitive values are automatically decrypted.
     *
     * @param string $key Configuration key
     * @param mixed $default Default value if not found
     * @return mixed Configuration value (typed based on value_type)
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $this->loadCache();

        if (!isset($this->cache[$key])) {
            return $default;
        }

        $value = $this->cache[$key]['value'];
        $type = $this->cache[$key]['type'];

        // Decrypt if this is a sensitive key
        if ($this->isEncryptedKey($key) && $value !== null && $value !== '') {
            $decrypted = $this->encryption->decrypt($value);
            if ($decrypted === null) {
                $this->logger->error('Failed to decrypt config value', ['key' => $key]);
                return $default;
            }
            $value = $decrypted;
        }

        return $this->castValue($value, $type);
    }

    /**
     * Check if a key should be encrypted
     */
    private function isEncryptedKey(string $key): bool
    {
        return in_array($key, self::ENCRYPTED_KEYS, true);
    }

    /**
     * Set configuration value
     *
     * Sensitive values are automatically encrypted before storage.
     *
     * @param string $key Configuration key
     * @param mixed $value Configuration value
     * @param string|null $type Value type (auto-detected if null)
     * @return bool Success
     */
    public function set(string $key, mixed $value, ?string $type = null): bool
    {
        $type = $type ?? $this->detectType($value);
        $stringValue = $this->serializeValue($value, $type);

        // Encrypt sensitive values BEFORE storing
        $storageValue = $stringValue;
        if ($this->isEncryptedKey($key)) {
            $storageValue = $this->encryption->encrypt($stringValue);
        }

        try {
            // Check if exists
            $existing = $this->db->query(
                'SELECT id FROM admin_config WHERE config_key = ?',
                [$key]
            );

            // Mark sensitive keys
            $isSensitive = $this->isEncryptedKey($key);

            if (!empty($existing)) {
                // Update
                $this->db->execute(
                    'UPDATE admin_config SET config_value = ?, value_type = ?, is_sensitive = ?, updated_at = NOW() WHERE config_key = ?',
                    [$storageValue, $type, $isSensitive, $key]
                );
            } else {
                // Insert
                $this->db->execute(
                    'INSERT INTO admin_config (config_key, config_value, value_type, is_sensitive) VALUES (?, ?, ?, ?)',
                    [$key, $storageValue, $type, $isSensitive]
                );
            }

            // Update cache with ENCRYPTED value (will be decrypted on read)
            $this->cache[$key] = ['value' => $storageValue, 'type' => $type];

            $this->logger->info('Configuration updated', [
                'key' => $key,
                'type' => $type,
                'encrypted' => $isSensitive,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to set configuration', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * URL prefix - always /x- for consistency
     * Never changes - makes URLs predictable in format but unpredictable in token
     */
    private const URL_PREFIX = '/x-';

    /**
     * Get admin panel base path (cryptographic URL)
     *
     * Format: /x-{32 hex chars} = /x- + 16 bytes = 128 bits of entropy
     *
     * @return string Base path (e.g., "/x-a7f3b2c8d9e4f1a6b3c2d5e8f9a0b1c2")
     */
    public function getAdminBasePath(): string
    {
        $path = $this->get('admin_base_path');

        if ($path === null) {
            // Generate new one if not exists
            $path = self::URL_PREFIX . bin2hex(random_bytes(16));
            $this->set('admin_base_path', $path, 'string');
        }

        return $path;
    }

    /**
     * Regenerate admin base path (for security rotation)
     *
     * Always uses /x- prefix for consistency.
     *
     * @return string New base path
     */
    public function rotateAdminBasePath(): string
    {
        $oldPath = $this->get('admin_base_path', '');
        $newPath = self::URL_PREFIX . bin2hex(random_bytes(16));

        $this->set('admin_base_path', $newPath, 'string');

        $this->logger->warning('Admin base path rotated', [
            'old_prefix' => substr($oldPath, 0, 10) . '...',
            'new_prefix' => substr($newPath, 0, 10) . '...',
        ]);

        return $newPath;
    }

    /**
     * Get HMAC secret key
     *
     * @return string 256-bit hex secret
     */
    public function getHmacSecret(): string
    {
        $secret = $this->get('hmac_secret');

        if ($secret === null) {
            // Generate new one if not exists
            $secret = bin2hex(random_bytes(32));
            $this->set('hmac_secret', $secret, 'string');
        }

        return $secret;
    }

    /**
     * Check if path matches admin base path
     *
     * @param string $requestPath Request path (e.g., "/x-abc123/login")
     * @return bool True if path starts with admin base path
     */
    public function isAdminPath(string $requestPath): bool
    {
        $basePath = $this->getAdminBasePath();
        return str_starts_with($requestPath, $basePath);
    }

    /**
     * Get relative path within admin panel
     *
     * @param string $requestPath Full request path
     * @return string|null Path relative to admin base, or null if not admin path
     */
    public function getAdminRelativePath(string $requestPath): ?string
    {
        $basePath = $this->getAdminBasePath();

        if (!str_starts_with($requestPath, $basePath)) {
            return null;
        }

        $relativePath = substr($requestPath, strlen($basePath));

        // Ensure starts with /
        if ($relativePath === '' || $relativePath === false) {
            return '/';
        }

        if (!str_starts_with($relativePath, '/')) {
            $relativePath = '/' . $relativePath;
        }

        return $relativePath;
    }

    /**
     * Build full admin URL
     *
     * @param string $relativePath Relative path (e.g., "/login", "/dashboard")
     * @return string Full path with base (e.g., "/x-abc123/login")
     */
    public function buildAdminUrl(string $relativePath): string
    {
        $basePath = $this->getAdminBasePath();

        // Remove leading slash from relative path
        $relativePath = ltrim($relativePath, '/');

        return $basePath . '/' . $relativePath;
    }

    /**
     * Get all configuration values
     *
     * @param bool $includeSensitive Include sensitive values (masked)
     * @return array<string, array{value: mixed, type: string, sensitive: bool}>
     */
    public function getAll(bool $includeSensitive = false): array
    {
        try {
            $rows = $this->db->query(
                'SELECT config_key, config_value, value_type, is_sensitive, description FROM admin_config ORDER BY config_key'
            );

            $configs = [];

            foreach ($rows as $row) {
                $value = $this->castValue($row['config_value'], $row['value_type']);

                // Mask sensitive values
                if ($row['is_sensitive'] && !$includeSensitive) {
                    $value = '********';
                }

                $configs[$row['config_key']] = [
                    'value' => $value,
                    'type' => $row['value_type'],
                    'sensitive' => (bool) $row['is_sensitive'],
                    'description' => $row['description'],
                ];
            }

            return $configs;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to get all configurations', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }
    }

    /**
     * Load configuration cache from database
     */
    private function loadCache(): void
    {
        if ($this->cacheLoaded) {
            return;
        }

        try {
            $rows = $this->db->query('SELECT config_key, config_value, value_type FROM admin_config');

            foreach ($rows as $row) {
                $this->cache[$row['config_key']] = [
                    'value' => $row['config_value'],
                    'type' => $row['value_type'],
                ];
            }

            $this->cacheLoaded = true;
        } catch (\Throwable $e) {
            $this->logger->error('Failed to load configuration cache', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Clear configuration cache
     */
    public function clearCache(): void
    {
        $this->cache = [];
        $this->cacheLoaded = false;
    }

    /**
     * Cast string value to proper type
     */
    private function castValue(string $value, string $type): mixed
    {
        return match ($type) {
            'int', 'integer' => (int) $value,
            'float', 'double' => (float) $value,
            'bool', 'boolean' => in_array(strtolower($value), ['true', '1', 'yes'], true),
            'json', 'array' => json_decode($value, true) ?? [],
            default => $value,
        };
    }

    /**
     * Serialize value to string for storage
     */
    private function serializeValue(mixed $value, string $type): string
    {
        return match ($type) {
            'bool', 'boolean' => $value ? 'true' : 'false',
            'json', 'array' => json_encode($value),
            default => (string) $value,
        };
    }

    /**
     * Auto-detect value type
     */
    private function detectType(mixed $value): string
    {
        return match (true) {
            is_bool($value) => 'bool',
            is_int($value) => 'int',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }
}
