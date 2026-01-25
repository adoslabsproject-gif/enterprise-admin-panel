<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;

/**
 * Log Configuration Service
 *
 * Provides ultra-fast log level filtering with multi-layer caching.
 * This service is the bridge between enterprise-admin-panel (UI config)
 * and enterprise-bootstrap (should_log() function).
 *
 * Cache Layers (fastest to slowest):
 * 1. Static array   - ~0.001μs (same request)
 * 2. APCu           - ~0.01μs  (shared memory, same server)
 * 3. Redis          - ~0.1μs   (network, multi-server)
 * 4. Database       - ~1-5ms   (cold start only)
 *
 * USAGE:
 * ```php
 * $service = LogConfigService::getInstance($pdo, $redis);
 * if ($service->shouldLog('security', 'warning')) {
 *     // Write log
 * }
 * ```
 *
 * @package adoslabs/enterprise-admin-panel
 */
final class LogConfigService
{
    private static ?self $instance = null;

    private ?DatabasePool $db;
    private mixed $redis;

    /** @var array<string, array{min_level: string, enabled: bool}> Static cache (L1) */
    private array $channelCache = [];

    /** @var array<string, bool> Decision cache - channel:level => bool */
    private array $decisionCache = [];

    /** @var bool Whether config has been loaded */
    private bool $loaded = false;

    /** @var int Cache TTL in seconds */
    private int $cacheTtl = 300; // 5 minutes

    /** @var string Cache key prefix */
    private const CACHE_PREFIX = 'log_config:';

    /** @var string APCu cache key for all channels */
    private const APCU_KEY = 'enterprise:log_channels';

    /** @var string Redis cache key for all channels */
    private const REDIS_KEY = 'enterprise:log_channels';

    /**
     * PSR-3 log levels with numeric values for comparison
     * Higher number = more severe
     */
    private const LEVEL_VALUES = [
        'debug'     => 100,
        'info'      => 200,
        'notice'    => 250,
        'warning'   => 300,
        'error'     => 400,
        'critical'  => 500,
        'alert'     => 550,
        'emergency' => 600,
    ];

    /**
     * Default channel configuration (used when channel not in database)
     */
    private const DEFAULT_CHANNEL_CONFIG = [
        'min_level' => 'debug',
        'enabled' => true,
    ];

    /**
     * Private constructor - use getInstance()
     */
    private function __construct(?DatabasePool $db = null, mixed $redis = null)
    {
        $this->db = $db;
        $this->redis = $redis;
    }

    /**
     * Get singleton instance
     *
     * @param DatabasePool|null $db Database pool connection (optional, for lazy loading)
     * @param mixed $redis Redis connection (optional)
     * @return self
     */
    public static function getInstance(?DatabasePool $db = null, mixed $redis = null): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db, $redis);
        }

        // Update connections if provided
        if ($db !== null) {
            self::$instance->db = $db;
        }
        if ($redis !== null) {
            self::$instance->redis = $redis;
        }

        return self::$instance;
    }

    /**
     * Check if logging should occur for a channel and level
     *
     * This is the main method called by should_log() in enterprise-bootstrap.
     * Optimized for ~0.001μs on cache hit (99%+ of calls).
     *
     * @param string $channel Channel name (e.g., 'security', 'api')
     * @param string $level PSR-3 log level
     * @return bool True if should log, false to skip
     */
    public function shouldLog(string $channel, string $level): bool
    {
        // L0: Decision cache (same request, same channel+level)
        $cacheKey = "{$channel}:{$level}";
        if (isset($this->decisionCache[$cacheKey])) {
            return $this->decisionCache[$cacheKey];
        }

        // Get channel config (uses multi-layer cache)
        $config = $this->getChannelConfig($channel);

        // Channel disabled = never log
        if (!$config['enabled']) {
            $this->decisionCache[$cacheKey] = false;
            return false;
        }

        // Compare levels
        $levelValue = self::LEVEL_VALUES[$level] ?? 200;
        $minLevelValue = self::LEVEL_VALUES[$config['min_level']] ?? 100;

        $result = $levelValue >= $minLevelValue;
        $this->decisionCache[$cacheKey] = $result;

        return $result;
    }

    /**
     * Get configuration for a specific channel
     *
     * Uses multi-layer caching for performance.
     *
     * @param string $channel Channel name
     * @return array{min_level: string, enabled: bool}
     */
    public function getChannelConfig(string $channel): array
    {
        // L1: Static cache (same request)
        if (isset($this->channelCache[$channel])) {
            return $this->channelCache[$channel];
        }

        // Ensure all channels are loaded
        $this->loadChannels();

        // Return channel config or default
        return $this->channelCache[$channel] ?? self::DEFAULT_CHANNEL_CONFIG;
    }

    /**
     * Get all channel configurations
     *
     * @return array<string, array{min_level: string, enabled: bool, description: string|null}>
     */
    public function getAllChannels(): array
    {
        $this->loadChannels();
        return $this->channelCache;
    }

    /**
     * Update channel configuration
     *
     * @param string $channel Channel name
     * @param string $minLevel Minimum log level
     * @param bool $enabled Whether channel is enabled
     * @param string|null $description Channel description
     * @return bool Success
     */
    public function updateChannel(
        string $channel,
        string $minLevel,
        bool $enabled,
        ?string $description = null
    ): bool {
        if ($this->db === null) {
            return false;
        }

        // Validate level
        if (!isset(self::LEVEL_VALUES[$minLevel])) {
            return false;
        }

        try {
            // Upsert channel - use PostgreSQL syntax (ON CONFLICT)
            $sql = "INSERT INTO log_channels (channel, min_level, enabled, description, updated_at)
                   VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
                   ON CONFLICT (channel) DO UPDATE SET
                       min_level = EXCLUDED.min_level,
                       enabled = EXCLUDED.enabled,
                       description = EXCLUDED.description,
                       updated_at = CURRENT_TIMESTAMP";

            $this->db->execute($sql, [
                $channel,
                $minLevel,
                $enabled ? 1 : 0,
                $description,
            ]);

            // Invalidate all caches
            $this->invalidateCache();

            return true;
        } catch (\Throwable $e) {
            error_log("[LogConfigService] Failed to update channel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a channel configuration
     *
     * @param string $channel Channel name
     * @return bool Success
     */
    public function deleteChannel(string $channel): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            $affectedRows = $this->db->execute(
                'DELETE FROM log_channels WHERE channel = ?',
                [$channel]
            );

            $this->invalidateCache();

            return $affectedRows > 0;
        } catch (\Throwable $e) {
            error_log("[LogConfigService] Failed to delete channel: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Invalidate all caches
     *
     * Call this after any configuration change.
     */
    public function invalidateCache(): void
    {
        // Clear static caches
        $this->channelCache = [];
        $this->decisionCache = [];
        $this->loaded = false;

        // Clear APCu
        if (function_exists('apcu_delete')) {
            apcu_delete(self::APCU_KEY);
        }

        // Clear Redis
        if ($this->redis !== null) {
            try {
                $this->redis->del(self::REDIS_KEY);
            } catch (\Throwable $e) {
                // Ignore Redis errors
            }
        }
    }

    /**
     * Get Telegram notification configuration
     *
     * @return array{enabled: bool, bot_token: string|null, chat_id: string|null, min_level: string, notify_channels: array}
     */
    public function getTelegramConfig(): array
    {
        $default = [
            'enabled' => false,
            'bot_token' => null,
            'chat_id' => null,
            'min_level' => 'error',
            'notify_channels' => ['*'],
            'rate_limit_per_minute' => 10,
        ];

        if ($this->db === null) {
            return $default;
        }

        try {
            $rows = $this->db->query('SELECT * FROM log_telegram_config WHERE id = 1 LIMIT 1');
            $row = $rows[0] ?? null;

            if (!$row) {
                return $default;
            }

            return [
                'enabled' => (bool)$row['enabled'],
                'bot_token' => $row['bot_token'],
                'chat_id' => $row['chat_id'],
                'min_level' => $row['min_level'] ?? 'error',
                'notify_channels' => json_decode($row['notify_channels'] ?? '["*"]', true) ?: ['*'],
                'rate_limit_per_minute' => (int)($row['rate_limit_per_minute'] ?? 10),
            ];
        } catch (\Throwable $e) {
            return $default;
        }
    }

    /**
     * Update Telegram configuration
     *
     * @param array $config Configuration array
     * @return bool Success
     */
    public function updateTelegramConfig(array $config): bool
    {
        if ($this->db === null) {
            return false;
        }

        try {
            $this->db->execute(
                'UPDATE log_telegram_config SET enabled = ?, bot_token = ?, chat_id = ?, min_level = ?, notify_channels = ?, rate_limit_per_minute = ? WHERE id = 1',
                [
                    ($config['enabled'] ?? false) ? 1 : 0,
                    $config['bot_token'] ?? null,
                    $config['chat_id'] ?? null,
                    $config['min_level'] ?? 'error',
                    json_encode($config['notify_channels'] ?? ['*']),
                    $config['rate_limit_per_minute'] ?? 10,
                ]
            );

            return true;
        } catch (\Throwable $e) {
            error_log("[LogConfigService] Failed to update Telegram config: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if should send Telegram notification
     *
     * @param string $channel Log channel
     * @param string $level Log level
     * @return bool True if should notify
     */
    public function shouldNotifyTelegram(string $channel, string $level): bool
    {
        $config = $this->getTelegramConfig();

        if (!$config['enabled']) {
            return false;
        }

        // Check if channel is in notify list
        $notifyChannels = $config['notify_channels'];
        if (!in_array('*', $notifyChannels, true) && !in_array($channel, $notifyChannels, true)) {
            return false;
        }

        // Check level (Telegram has SEPARATE min level)
        $levelValue = self::LEVEL_VALUES[$level] ?? 200;
        $minLevelValue = self::LEVEL_VALUES[$config['min_level']] ?? 400;

        return $levelValue >= $minLevelValue;
    }

    /**
     * Load all channels from cache or database
     */
    private function loadChannels(): void
    {
        if ($this->loaded) {
            return;
        }

        // Try L2: APCu cache
        if (function_exists('apcu_fetch')) {
            $cached = apcu_fetch(self::APCU_KEY, $success);
            if ($success && is_array($cached)) {
                $this->channelCache = $cached;
                $this->loaded = true;
                return;
            }
        }

        // Try L3: Redis cache
        if ($this->redis !== null) {
            try {
                $cached = $this->redis->get(self::REDIS_KEY);
                if ($cached !== false && $cached !== null) {
                    $decoded = json_decode($cached, true);
                    if (is_array($decoded)) {
                        $this->channelCache = $decoded;
                        $this->loaded = true;
                        // Backfill APCu
                        if (function_exists('apcu_store')) {
                            apcu_store(self::APCU_KEY, $decoded, $this->cacheTtl);
                        }
                        return;
                    }
                }
            } catch (\Throwable $e) {
                // Redis unavailable, continue to database
            }
        }

        // L4: Database (cold start)
        $this->loadFromDatabase();
    }

    /**
     * Load channels from database and populate caches
     */
    private function loadFromDatabase(): void
    {
        if ($this->db === null) {
            // No database - use defaults
            $this->channelCache = [];
            $this->loaded = true;
            return;
        }

        try {
            $rows = $this->db->query('SELECT channel, min_level, enabled, description FROM log_channels');

            $channels = [];
            foreach ($rows as $row) {
                $channels[$row['channel']] = [
                    'min_level' => $row['min_level'],
                    'enabled' => (bool)$row['enabled'],
                    'description' => $row['description'],
                ];
            }

            $this->channelCache = $channels;
            $this->loaded = true;

            // Populate caches
            if (function_exists('apcu_store')) {
                apcu_store(self::APCU_KEY, $channels, $this->cacheTtl);
            }

            if ($this->redis !== null) {
                try {
                    $this->redis->setex(self::REDIS_KEY, $this->cacheTtl, json_encode($channels));
                } catch (\Throwable $e) {
                    // Ignore Redis errors
                }
            }
        } catch (\Throwable $e) {
            error_log("[LogConfigService] Failed to load channels: " . $e->getMessage());
            $this->channelCache = [];
            $this->loaded = true;
        }
    }

    /**
     * Set DatabasePool connection
     */
    public function setDb(DatabasePool $db): void
    {
        $this->db = $db;
        $this->invalidateCache();
    }

    /**
     * Set Redis connection
     */
    public function setRedis(mixed $redis): void
    {
        $this->redis = $redis;
    }

    /**
     * Get available log levels
     *
     * @return array<string, int>
     */
    public static function getLevels(): array
    {
        return self::LEVEL_VALUES;
    }

    /**
     * Get level names ordered by severity (ascending)
     *
     * @return string[]
     */
    public static function getLevelNames(): array
    {
        return array_keys(self::LEVEL_VALUES);
    }

    /**
     * Reset singleton (for testing)
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}
