<?php
/**
 * Enterprise Admin Panel - Database Cache Driver
 *
 * Fallback cache driver using database storage.
 *
 * Features:
 * - Automatic table creation
 * - TTL support with automatic cleanup
 * - Prepared statement caching
 * - Bulk operations
 *
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Cache\Drivers;

use PDO;
use PDOException;
use AdosLabs\AdminPanel\Core\Container;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

final class DatabaseDriver implements CacheDriverInterface
{
    private ?PDO $pdo = null;
    private array $config;
    private string $prefix;
    private string $table;
    private bool $tableVerified = false;

    /**
     * Cached prepared statements
     */
    private array $statements = [];

    public function __construct(array $config = [], string $prefix = 'eap_')
    {
        $this->config = array_merge([
            'table' => 'cache',
            'connection' => null,
        ], $config);

        $this->prefix = $prefix;
        $this->table = $this->config['table'];
    }

    /**
     * Get PDO connection
     *
     * @return PDO
     */
    private function getPdo(): PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }

        // Try to get from container (uses db pool)
        if (Container::has('db.manager')) {
            $manager = Container::get('db.manager');
            $this->pdo = $manager->connection($this->config['connection']);
        } elseif ($this->config['connection'] instanceof PDO) {
            $this->pdo = $this->config['connection'];
        } else {
            throw new \RuntimeException("No database connection available for cache driver");
        }

        // Ensure table exists
        if (!$this->tableVerified) {
            $this->ensureTableExists();
            $this->tableVerified = true;
        }

        return $this->pdo;
    }

    /**
     * Ensure cache table exists
     */
    private function ensureTableExists(): void
    {
        $pdo = $this->pdo;
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'pgsql' => "
                CREATE TABLE IF NOT EXISTS {$this->table} (
                    key VARCHAR(255) PRIMARY KEY,
                    value TEXT NOT NULL,
                    expires_at TIMESTAMP NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->table}_expires ON {$this->table}(expires_at);
            ",
            'mysql' => "
                CREATE TABLE IF NOT EXISTS {$this->table} (
                    `key` VARCHAR(255) PRIMARY KEY,
                    `value` LONGTEXT NOT NULL,
                    expires_at DATETIME NULL,
                    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_{$this->table}_expires (expires_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ",
            'sqlite' => "
                CREATE TABLE IF NOT EXISTS {$this->table} (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL,
                    expires_at INTEGER NULL,
                    created_at INTEGER DEFAULT (strftime('%s', 'now'))
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->table}_expires ON {$this->table}(expires_at);
            ",
            default => throw new \RuntimeException("Unsupported database driver for cache: {$driver}"),
        };

        // Split multiple statements and execute
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        foreach ($statements as $stmt) {
            if (!empty($stmt)) {
                try {
                    $pdo->exec($stmt);
                } catch (PDOException $e) {
                    // Ignore "already exists" errors
                    if (stripos($e->getMessage(), 'already exists') === false) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Get prefixed key
     *
     * @param string $key
     * @return string
     */
    private function prefixedKey(string $key): string
    {
        return $this->prefix . $key;
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): bool
    {
        try {
            $this->getPdo()->query('SELECT 1');
            return true;
        } catch (PDOException $e) {
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $pdo = $this->getPdo();
        $prefixedKey = $this->prefixedKey($key);

        $sql = "SELECT value, expires_at FROM {$this->table} WHERE key = ?";

        if (!isset($this->statements['get'])) {
            $this->statements['get'] = $pdo->prepare($sql);
        }

        $stmt = $this->statements['get'];
        $stmt->execute([$prefixedKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Check expiration
        if ($row['expires_at'] !== null) {
            $expiresAt = strtotime($row['expires_at']);
            if ($expiresAt !== false && $expiresAt < time()) {
                // Expired - delete and return null
                $this->delete($key);
                return null;
            }
        }

        // Use JSON decoding (safe) instead of unserialize (RCE vulnerability)
        $decoded = json_decode($row['value'], true);

        // Check for JSON decode error
        if (json_last_error() !== JSON_ERROR_NONE) {
            // Fallback: might be old serialized data, log and return null
            error_log("[DatabaseDriver] Invalid JSON in cache for key, possible legacy data");
            return null;
        }

        return $decoded;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $pdo = $this->getPdo();
        $prefixedKey = $this->prefixedKey($key);
        // Use JSON encoding (safe) instead of serialize (RCE vulnerability with unserialize)
        $serialized = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($serialized === false) {
            throw new \RuntimeException("Failed to JSON encode cache value: " . json_last_error_msg());
        }

        $expiresAt = $ttl !== null ? date('Y-m-d H:i:s', time() + $ttl) : null;

        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        // Upsert query
        $sql = match ($driver) {
            'pgsql' => "
                INSERT INTO {$this->table} (key, value, expires_at, created_at)
                VALUES (?, ?, ?, NOW())
                ON CONFLICT (key) DO UPDATE SET value = EXCLUDED.value, expires_at = EXCLUDED.expires_at
            ",
            'mysql' => "
                INSERT INTO {$this->table} (`key`, `value`, expires_at, created_at)
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE value = VALUES(value), expires_at = VALUES(expires_at)
            ",
            'sqlite' => "
                INSERT OR REPLACE INTO {$this->table} (key, value, expires_at, created_at)
                VALUES (?, ?, ?, strftime('%s', 'now'))
            ",
            default => throw new \RuntimeException("Unsupported driver: {$driver}"),
        };

        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$prefixedKey, $serialized, $expiresAt]);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        $pdo = $this->getPdo();
        $prefixedKey = $this->prefixedKey($key);

        $sql = "DELETE FROM {$this->table} WHERE key = ?";

        if (!isset($this->statements['delete'])) {
            $this->statements['delete'] = $pdo->prepare($sql);
        }

        return $this->statements['delete']->execute([$prefixedKey]);
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        $pdo = $this->getPdo();

        // Delete only keys with our prefix
        $sql = "DELETE FROM {$this->table} WHERE key LIKE ?";
        $stmt = $pdo->prepare($sql);
        return $stmt->execute([$this->prefix . '%']);
    }

    /**
     * {@inheritdoc}
     */
    public function many(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $result = [];
        foreach ($keys as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    /**
     * {@inheritdoc}
     */
    public function putMany(array $values, ?int $ttl = null): bool
    {
        if (empty($values)) {
            return true;
        }

        $pdo = $this->getPdo();

        // Use transaction for atomicity
        $pdo->beginTransaction();

        try {
            foreach ($values as $key => $value) {
                $this->set($key, $value, $ttl);
            }
            $pdo->commit();
            return true;
        } catch (PDOException $e) {
            $pdo->rollBack();
            Logger::channel('database')->error('Cache setMultiple transaction failed', [
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        $current = $this->get($key);

        if ($current === null) {
            $this->set($key, $value);
            return $value;
        }

        if (!is_numeric($current)) {
            return false;
        }

        $newValue = (int) $current + $value;
        $this->set($key, $newValue);

        return $newValue;
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->increment($key, -$value);
    }

    /**
     * Clean up expired entries
     *
     * Should be called periodically (e.g., via cron).
     *
     * @return int Number of entries deleted
     */
    public function cleanup(): int
    {
        $pdo = $this->getPdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        $sql = match ($driver) {
            'pgsql', 'mysql' => "DELETE FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at < NOW()",
            'sqlite' => "DELETE FROM {$this->table} WHERE expires_at IS NOT NULL AND expires_at < strftime('%s', 'now')",
            default => throw new \RuntimeException("Unsupported driver: {$driver}"),
        };

        $stmt = $pdo->prepare($sql);
        $stmt->execute();

        return $stmt->rowCount();
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        $pdo = $this->getPdo();

        // Count total entries
        $stmt = $pdo->query("SELECT COUNT(*) as total FROM {$this->table} WHERE key LIKE '{$this->prefix}%'");
        $total = (int) $stmt->fetchColumn();

        // Count expired entries
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $nowExpr = match ($driver) {
            'pgsql', 'mysql' => 'NOW()',
            'sqlite' => "strftime('%s', 'now')",
            default => 'NOW()',
        };

        $stmt = $pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE key LIKE '{$this->prefix}%' AND expires_at IS NOT NULL AND expires_at < {$nowExpr}");
        $expired = (int) $stmt->fetchColumn();

        return [
            'driver' => 'database',
            'table' => $this->table,
            'prefix' => $this->prefix,
            'total_entries' => $total,
            'expired_entries' => $expired,
            'active_entries' => $total - $expired,
        ];
    }
}
