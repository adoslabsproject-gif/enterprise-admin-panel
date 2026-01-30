<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Services;

use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

/**
 * Log Configuration Service
 *
 * Manages log channel configurations with:
 * - Per-channel minimum log level
 * - Enable/disable channels
 * - Auto-reset: automatically reset debug level to warning after 8 hours
 * - Telegram notification settings
 * - Multi-layer caching (static + Redis + database)
 *
 * The auto-reset feature prevents leaving channels at debug level indefinitely,
 * which could cause performance issues and fill up storage.
 *
 * @package adoslabs/enterprise-admin-panel
 */
class LogConfigService
{
    private const AUTO_RESET_HOURS = 8;
    private const DEFAULT_RESET_LEVEL = 'warning';
    private const CACHE_TTL = 300; // 5 minutes
    private const CACHE_PREFIX = 'log:config:';

    private static ?self $instance = null;
    private DatabasePool $db;
    private ?\Redis $redis = null;

    /** @var array<string, array> Static cache for current request */
    private array $channelCache = [];

    /** @var array|null Telegram config cache */
    private ?array $telegramCache = null;

    private function __construct(DatabasePool $db)
    {
        $this->db = $db;
        $this->initRedis();
    }

    /**
     * Get singleton instance
     */
    public static function getInstance(DatabasePool $db): self
    {
        if (self::$instance === null) {
            self::$instance = new self($db);
        }
        return self::$instance;
    }

    /**
     * Initialize Redis connection if available
     */
    private function initRedis(): void
    {
        try {
            $host = $_ENV['REDIS_HOST'] ?? null;
            if ($host) {
                $this->redis = new \Redis();
                $this->redis->connect(
                    $host,
                    (int) ($_ENV['REDIS_PORT'] ?? 6379),
                    2.0
                );
                if (!empty($_ENV['REDIS_PASSWORD'])) {
                    $this->redis->auth($_ENV['REDIS_PASSWORD']);
                }
                $this->redis->select((int) ($_ENV['REDIS_DATABASE'] ?? 0));
            }
        } catch (\Throwable $e) {
            $this->redis = null;
        }
    }

    /**
     * Get all log level names
     *
     * @return array<string>
     */
    public static function getLevelNames(): array
    {
        return ['debug', 'info', 'notice', 'warning', 'error', 'critical', 'alert', 'emergency'];
    }

    /**
     * Get level severity (higher = more severe)
     */
    public static function getLevelSeverity(string $level): int
    {
        $levels = [
            'debug' => 0,
            'info' => 1,
            'notice' => 2,
            'warning' => 3,
            'error' => 4,
            'critical' => 5,
            'alert' => 6,
            'emergency' => 7,
        ];
        return $levels[$level] ?? 0;
    }

    /**
     * Check if a log should be written
     *
     * This is the main function called by should_log() helper.
     * Uses multi-layer cache for performance.
     *
     * @param string $channel Log channel name
     * @param string $level Log level
     * @return bool True if log should be written
     */
    public function shouldLog(string $channel, string $level): bool
    {
        $config = $this->getChannelConfig($channel);

        // Channel disabled = don't log
        if (!($config['enabled'] ?? true)) {
            return false;
        }

        $minLevel = $config['min_level'] ?? 'debug';
        return self::getLevelSeverity($level) >= self::getLevelSeverity($minLevel);
    }

    /**
     * Get channel configuration
     *
     * Uses 3-layer cache:
     * 1. Static array (same request)
     * 2. Redis (cross-request)
     * 3. Database (source of truth)
     */
    public function getChannelConfig(string $channel): array
    {
        // Layer 1: Static cache
        if (isset($this->channelCache[$channel])) {
            return $this->channelCache[$channel];
        }

        // Layer 2: Redis cache
        if ($this->redis) {
            try {
                $cached = $this->redis->get(self::CACHE_PREFIX . 'channel:' . $channel);
                if ($cached !== false) {
                    $config = json_decode($cached, true);
                    if ($config) {
                        $this->channelCache[$channel] = $config;
                        return $config;
                    }
                }
            } catch (\Throwable $e) {
                // Redis error, continue to database
            }
        }

        // Layer 3: Database
        $rows = $this->db->query(
            'SELECT * FROM log_channels WHERE channel = ?',
            [$channel]
        );

        if (empty($rows)) {
            // Return default config for unknown channels
            $config = [
                'channel' => $channel,
                'min_level' => 'debug',
                'enabled' => true,
                'auto_reset_enabled' => false,
                'auto_reset_at' => null,
                'auto_reset_level' => self::DEFAULT_RESET_LEVEL,
            ];
        } else {
            $config = $rows[0];
            $config['enabled'] = (bool) ($config['enabled'] ?? true);
            $config['auto_reset_enabled'] = (bool) ($config['auto_reset_enabled'] ?? false);
        }

        // Check and apply auto-reset if needed
        $config = $this->checkAutoReset($config);

        // Cache in Redis
        if ($this->redis) {
            try {
                $this->redis->setex(
                    self::CACHE_PREFIX . 'channel:' . $channel,
                    self::CACHE_TTL,
                    json_encode($config)
                );
            } catch (\Throwable $e) {
                // Ignore cache errors
            }
        }

        // Cache in static array
        $this->channelCache[$channel] = $config;

        return $config;
    }

    /**
     * Get all channels with optional stats
     *
     * @param bool $withStats Include log count and last log time
     * @return array<array>
     */
    public function getAllChannels(bool $withStats = true): array
    {
        $sql = 'SELECT * FROM log_channels ORDER BY channel';
        $channels = $this->db->query($sql);

        if ($withStats) {
            foreach ($channels as &$channel) {
                // Get log count
                $countResult = $this->db->query(
                    'SELECT COUNT(*) as cnt FROM logs WHERE channel = ?',
                    [$channel['channel']]
                );
                $channel['log_count'] = (int) ($countResult[0]['cnt'] ?? 0);

                // Convert booleans
                $channel['enabled'] = (bool) ($channel['enabled'] ?? true);
                $channel['auto_reset_enabled'] = (bool) ($channel['auto_reset_enabled'] ?? false);
            }
        }

        return $channels;
    }

    /**
     * Create or update a channel
     */
    public function upsertChannel(
        string $channel,
        string $minLevel = 'debug',
        bool $enabled = true,
        bool $autoResetEnabled = false,
        ?string $description = null
    ): bool {
        // Validate level
        if (!in_array($minLevel, self::getLevelNames(), true)) {
            Logger::channel('error')->warning('Invalid log level provided for channel config', [
                'channel' => $channel,
                'level' => $minLevel,
            ]);
            return false;
        }

        // Calculate auto-reset time if enabled and level is below warning
        $autoResetAt = null;
        if ($autoResetEnabled && self::getLevelSeverity($minLevel) < self::getLevelSeverity(self::DEFAULT_RESET_LEVEL)) {
            $autoResetAt = date('Y-m-d H:i:s', time() + (self::AUTO_RESET_HOURS * 3600));
        }

        // Check if exists
        $existing = $this->db->query(
            'SELECT id FROM log_channels WHERE channel = ?',
            [$channel]
        );

        if (empty($existing)) {
            // Insert
            $this->db->execute(
                'INSERT INTO log_channels (channel, min_level, enabled, auto_reset_enabled, auto_reset_at, auto_reset_level, description, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$channel, $minLevel, $enabled, $autoResetEnabled, $autoResetAt, self::DEFAULT_RESET_LEVEL, $description]
            );
        } else {
            // Update
            $this->db->execute(
                'UPDATE log_channels
                 SET min_level = ?, enabled = ?, auto_reset_enabled = ?, auto_reset_at = ?, description = ?, updated_at = NOW()
                 WHERE channel = ?',
                [$minLevel, $enabled, $autoResetEnabled, $autoResetAt, $description, $channel]
            );
        }

        // Invalidate cache
        $this->invalidateChannelCache($channel);

        return true;
    }

    /**
     * Update channel level
     */
    public function updateChannelLevel(string $channel, string $level): bool
    {
        if (!in_array($level, self::getLevelNames(), true)) {
            Logger::channel('error')->warning('Invalid log level for channel update', [
                'channel' => $channel,
                'level' => $level,
            ]);
            return false;
        }

        // Get current config
        $config = $this->getChannelConfig($channel);

        // Calculate auto-reset if enabled
        $autoResetAt = null;
        if (($config['auto_reset_enabled'] ?? false) &&
            self::getLevelSeverity($level) < self::getLevelSeverity(self::DEFAULT_RESET_LEVEL)) {
            $autoResetAt = date('Y-m-d H:i:s', time() + (self::AUTO_RESET_HOURS * 3600));
        }

        $this->db->execute(
            'UPDATE log_channels SET min_level = ?, auto_reset_at = ?, updated_at = NOW() WHERE channel = ?',
            [$level, $autoResetAt, $channel]
        );

        $this->invalidateChannelCache($channel);
        return true;
    }

    /**
     * Update channel enabled status
     */
    public function updateChannelEnabled(string $channel, bool $enabled): bool
    {
        $this->db->execute(
            'UPDATE log_channels SET enabled = ?, updated_at = NOW() WHERE channel = ?',
            [$enabled, $channel]
        );

        $this->invalidateChannelCache($channel);
        return true;
    }

    /**
     * Update channel auto-reset setting
     */
    public function updateChannelAutoReset(string $channel, bool $enabled): bool
    {
        $config = $this->getChannelConfig($channel);

        $autoResetAt = null;
        if ($enabled && self::getLevelSeverity($config['min_level']) < self::getLevelSeverity(self::DEFAULT_RESET_LEVEL)) {
            $autoResetAt = date('Y-m-d H:i:s', time() + (self::AUTO_RESET_HOURS * 3600));
        }

        $this->db->execute(
            'UPDATE log_channels SET auto_reset_enabled = ?, auto_reset_at = ?, updated_at = NOW() WHERE channel = ?',
            [$enabled, $autoResetAt, $channel]
        );

        $this->invalidateChannelCache($channel);
        return true;
    }

    /**
     * Delete a channel
     */
    public function deleteChannel(string $channel): bool
    {
        $this->db->execute('DELETE FROM log_channels WHERE channel = ?', [$channel]);
        $this->invalidateChannelCache($channel);
        return true;
    }

    /**
     * Check and apply auto-reset if time has passed
     */
    private function checkAutoReset(array $config): array
    {
        if (!($config['auto_reset_enabled'] ?? false)) {
            return $config;
        }

        $resetAt = $config['auto_reset_at'] ?? null;
        if ($resetAt === null) {
            return $config;
        }

        $resetTime = strtotime($resetAt);
        if ($resetTime === false || $resetTime > time()) {
            return $config;
        }

        // Time to reset!
        $resetLevel = $config['auto_reset_level'] ?? self::DEFAULT_RESET_LEVEL;

        $this->db->execute(
            'UPDATE log_channels SET min_level = ?, auto_reset_at = NULL, updated_at = NOW() WHERE channel = ?',
            [$resetLevel, $config['channel']]
        );

        // Update config
        $config['min_level'] = $resetLevel;
        $config['auto_reset_at'] = null;

        // Invalidate cache
        $this->invalidateChannelCache($config['channel']);

        return $config;
    }

    /**
     * Process all channels for auto-reset (called by cron/worker)
     *
     * @return int Number of channels reset
     */
    public function processAutoResets(): int
    {
        $rows = $this->db->query(
            'SELECT channel, auto_reset_level FROM log_channels
             WHERE auto_reset_enabled = true AND auto_reset_at IS NOT NULL AND auto_reset_at <= NOW()'
        );

        $count = 0;
        foreach ($rows as $row) {
            $resetLevel = $row['auto_reset_level'] ?? self::DEFAULT_RESET_LEVEL;

            $this->db->execute(
                'UPDATE log_channels SET min_level = ?, auto_reset_at = NULL, updated_at = NOW() WHERE channel = ?',
                [$resetLevel, $row['channel']]
            );

            $this->invalidateChannelCache($row['channel']);
            $count++;
        }

        return $count;
    }

    // ========================================================================
    // TELEGRAM CONFIGURATION
    // ========================================================================

    /**
     * Get Telegram configuration
     */
    public function getTelegramConfig(): array
    {
        // Check cache
        if ($this->telegramCache !== null) {
            return $this->telegramCache;
        }

        // Check Redis
        if ($this->redis) {
            try {
                $cached = $this->redis->get(self::CACHE_PREFIX . 'telegram');
                if ($cached !== false) {
                    $config = json_decode($cached, true);
                    if ($config) {
                        $this->telegramCache = $config;
                        return $config;
                    }
                }
            } catch (\Throwable $e) {
                // Continue to database
            }
        }

        // Database
        $rows = $this->db->query('SELECT * FROM log_telegram_config WHERE id = 1');

        if (empty($rows)) {
            $config = [
                'enabled' => false,
                'bot_token' => '',
                'chat_id' => '',
                'min_level' => 'error',
                'notify_channels' => ['*'],
                'rate_limit_per_minute' => 10,
            ];
        } else {
            $config = $rows[0];
            $config['enabled'] = (bool) ($config['enabled'] ?? false);
            $config['notify_channels'] = json_decode($config['notify_channels'] ?? '["*"]', true) ?: ['*'];
            $config['rate_limit_per_minute'] = (int) ($config['rate_limit_per_minute'] ?? 10);
        }

        // Cache
        if ($this->redis) {
            try {
                $this->redis->setex(
                    self::CACHE_PREFIX . 'telegram',
                    self::CACHE_TTL,
                    json_encode($config)
                );
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        $this->telegramCache = $config;
        return $config;
    }

    /**
     * Update Telegram configuration
     */
    public function updateTelegramConfig(array $config): bool
    {
        $enabled = (bool) ($config['enabled'] ?? false);
        $botToken = $config['bot_token'] ?? '';
        $chatId = $config['chat_id'] ?? '';
        $minLevel = $config['min_level'] ?? 'error';
        $notifyChannels = $config['notify_channels'] ?? ['*'];
        $rateLimit = (int) ($config['rate_limit_per_minute'] ?? 10);

        // Validate
        if (!in_array($minLevel, self::getLevelNames(), true)) {
            Logger::channel('error')->warning('Invalid Telegram min_level', [
                'level' => $minLevel,
            ]);
            return false;
        }

        if (!is_array($notifyChannels)) {
            $notifyChannels = ['*'];
        }

        $notifyChannelsJson = json_encode($notifyChannels);

        // Check if exists
        $existing = $this->db->query('SELECT id FROM log_telegram_config WHERE id = 1');

        if (empty($existing)) {
            $this->db->execute(
                'INSERT INTO log_telegram_config (id, enabled, bot_token, chat_id, min_level, notify_channels, rate_limit_per_minute, created_at, updated_at)
                 VALUES (1, ?, ?, ?, ?, ?, ?, NOW(), NOW())',
                [$enabled, $botToken, $chatId, $minLevel, $notifyChannelsJson, $rateLimit]
            );
        } else {
            $this->db->execute(
                'UPDATE log_telegram_config
                 SET enabled = ?, bot_token = ?, chat_id = ?, min_level = ?, notify_channels = ?, rate_limit_per_minute = ?, updated_at = NOW()
                 WHERE id = 1',
                [$enabled, $botToken, $chatId, $minLevel, $notifyChannelsJson, $rateLimit]
            );
        }

        // Invalidate cache
        $this->telegramCache = null;
        if ($this->redis) {
            try {
                $this->redis->del(self::CACHE_PREFIX . 'telegram');
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        return true;
    }

    /**
     * Check if should send Telegram notification
     */
    public function shouldNotifyTelegram(string $channel, string $level): bool
    {
        $config = $this->getTelegramConfig();

        if (!$config['enabled']) {
            return false;
        }

        // Check channel filter
        $notifyChannels = $config['notify_channels'] ?? ['*'];
        if (!in_array('*', $notifyChannels, true) && !in_array($channel, $notifyChannels, true)) {
            return false;
        }

        // Check level
        $minLevel = $config['min_level'] ?? 'error';
        return self::getLevelSeverity($level) >= self::getLevelSeverity($minLevel);
    }

    /**
     * Send Telegram message (with rate limiting)
     */
    public function sendTelegramMessage(string $channel, string $level, string $message, array $context = []): bool
    {
        $config = $this->getTelegramConfig();

        if (!$config['enabled'] || empty($config['bot_token']) || empty($config['chat_id'])) {
            return false;
        }

        // Check rate limit
        if (!$this->checkTelegramRateLimit($config['rate_limit_per_minute'])) {
            Logger::channel('api')->debug('Telegram rate limit exceeded', [
                'channel' => $channel,
                'level' => $level,
            ]);
            return false;
        }

        // Format message
        $emoji = $this->getLevelEmoji($level);
        $text = sprintf(
            "%s <b>[%s]</b> %s\n<code>%s</code>\n\n%s",
            $emoji,
            strtoupper($level),
            htmlspecialchars($channel),
            date('Y-m-d H:i:s'),
            htmlspecialchars(mb_substr($message, 0, 500))
        );

        if (!empty($context)) {
            $contextStr = json_encode($context, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
            if (strlen($contextStr) < 500) {
                $text .= "\n\n<pre>" . htmlspecialchars($contextStr) . "</pre>";
            }
        }

        // Send
        $url = "https://api.telegram.org/bot{$config['bot_token']}/sendMessage";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => [
                'chat_id' => $config['chat_id'],
                'text' => $text,
                'parse_mode' => 'HTML',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return $httpCode === 200;
    }

    /**
     * Check Telegram rate limit
     */
    private function checkTelegramRateLimit(int $maxPerMinute): bool
    {
        if (!$this->redis) {
            return true; // No rate limiting without Redis
        }

        $key = self::CACHE_PREFIX . 'telegram:rate';

        try {
            $count = (int) $this->redis->get($key);
            if ($count >= $maxPerMinute) {
                return false;
            }

            $this->redis->multi();
            $this->redis->incr($key);
            $this->redis->expire($key, 60);
            $this->redis->exec();

            return true;
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Get emoji for log level
     */
    private function getLevelEmoji(string $level): string
    {
        return match ($level) {
            'emergency' => "\xF0\x9F\x9A\xA8", // 🚨
            'alert' => "\xF0\x9F\x94\xB4",     // 🔴
            'critical' => "\xE2\x9D\x8C",       // ❌
            'error' => "\xE2\x9A\xA0\xEF\xB8\x8F", // ⚠️
            'warning' => "\xE2\x9A\xA1",        // ⚡
            'notice' => "\xF0\x9F\x93\xA2",     // 📢
            'info' => "\xE2\x84\xB9\xEF\xB8\x8F", // ℹ️
            'debug' => "\xF0\x9F\x94\xA7",      // 🔧
            default => "\xF0\x9F\x93\x9D",      // 📝
        };
    }

    // ========================================================================
    // STATISTICS
    // ========================================================================

    /**
     * Get log statistics
     */
    public function getStats(): array
    {
        // Today's logs
        $todayResult = $this->db->query(
            "SELECT COUNT(*) as cnt FROM logs WHERE created_at >= CURRENT_DATE"
        );
        $today = (int) ($todayResult[0]['cnt'] ?? 0);

        // Today's errors
        $errorsResult = $this->db->query(
            "SELECT COUNT(*) as cnt FROM logs
             WHERE created_at >= CURRENT_DATE
             AND level IN ('error', 'critical', 'alert', 'emergency')"
        );
        $errorsToday = (int) ($errorsResult[0]['cnt'] ?? 0);

        // Total logs
        $totalResult = $this->db->query("SELECT COUNT(*) as cnt FROM logs");
        $total = (int) ($totalResult[0]['cnt'] ?? 0);

        return [
            'today' => $today,
            'errors_today' => $errorsToday,
            'total' => $total,
        ];
    }

    // ========================================================================
    // CACHE MANAGEMENT
    // ========================================================================

    /**
     * Invalidate channel cache
     */
    private function invalidateChannelCache(string $channel): void
    {
        unset($this->channelCache[$channel]);

        if ($this->redis) {
            try {
                $this->redis->del(self::CACHE_PREFIX . 'channel:' . $channel);
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }

    /**
     * Invalidate all caches
     */
    public function invalidateAllCaches(): void
    {
        $this->channelCache = [];
        $this->telegramCache = null;

        if ($this->redis) {
            try {
                $keys = $this->redis->keys(self::CACHE_PREFIX . '*');
                if (!empty($keys)) {
                    $this->redis->del($keys);
                }
            } catch (\Throwable $e) {
                // Ignore
            }
        }
    }
}
