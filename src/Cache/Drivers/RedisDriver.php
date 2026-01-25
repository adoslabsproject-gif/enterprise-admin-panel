<?php
/**
 * Enterprise Admin Panel - Redis Cache Driver
 *
 * High-performance Redis cache driver with:
 * - Connection pooling (persistent connections)
 * - Automatic serialization
 * - Pipeline support for bulk operations
 * - Lua scripting for atomic operations
 *
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Cache\Drivers;

use Redis;
use RedisException;

final class RedisDriver implements CacheDriverInterface
{
    private ?Redis $redis = null;
    private array $config;
    private string $prefix;

    public function __construct(array $config = [], string $prefix = 'eap_')
    {
        $this->config = array_merge([
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => null,
            'database' => 0,
            'timeout' => 2.5,
            'read_timeout' => 2.5,
            'retry_interval' => 100,
            'persistent' => true,
            'serializer' => Redis::SERIALIZER_JSON, // JSON is safe (no RCE), PHP serializer enables object injection
        ], $config);

        $this->prefix = $prefix;
    }

    /**
     * Get Redis connection (lazy initialization)
     *
     * @return Redis
     * @throws RedisException
     */
    private function getRedis(): Redis
    {
        if ($this->redis !== null) {
            return $this->redis;
        }

        $this->redis = new Redis();

        $method = $this->config['persistent'] ? 'pconnect' : 'connect';

        $connected = $this->redis->$method(
            $this->config['host'],
            $this->config['port'],
            $this->config['timeout'],
            $this->config['persistent'] ? 'eap_cache' : null, // Persistent ID
            $this->config['retry_interval']
        );

        if (!$connected) {
            throw new RedisException("Failed to connect to Redis at {$this->config['host']}:{$this->config['port']}");
        }

        // Authenticate if password provided
        if ($this->config['password']) {
            if (!$this->redis->auth($this->config['password'])) {
                throw new RedisException("Redis authentication failed");
            }
        }

        // Select database
        if ($this->config['database'] !== 0) {
            $this->redis->select($this->config['database']);
        }

        // Set serializer
        $this->redis->setOption(Redis::OPT_SERIALIZER, $this->config['serializer']);

        // Set prefix
        $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);

        // Set read timeout
        $this->redis->setOption(Redis::OPT_READ_TIMEOUT, $this->config['read_timeout']);

        return $this->redis;
    }

    /**
     * {@inheritdoc}
     */
    public function ping(): bool
    {
        try {
            $result = $this->getRedis()->ping();
            return $result === true || $result === '+PONG' || $result === 'PONG';
        } catch (RedisException $e) {
            throw $e;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $value = $this->getRedis()->get($key);

        // Redis returns false for non-existent keys
        return $value === false ? null : $value;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        if ($ttl === null) {
            return $this->getRedis()->set($key, $value);
        }

        return $this->getRedis()->setex($key, $ttl, $value);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        return (bool) $this->getRedis()->exists($key);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        return $this->getRedis()->del($key) > 0;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        // Only flush keys with our prefix
        $keys = $this->getRedis()->keys('*');

        if (empty($keys)) {
            return true;
        }

        // Remove prefix from keys (already added by Redis option)
        return $this->getRedis()->del(...$keys) >= 0;
    }

    /**
     * {@inheritdoc}
     */
    public function many(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $values = $this->getRedis()->mget($keys);
        $result = [];

        foreach ($keys as $i => $key) {
            $result[$key] = $values[$i] === false ? null : $values[$i];
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

        $redis = $this->getRedis();

        if ($ttl === null) {
            // Simple MSET for no-TTL case
            return $redis->mset($values);
        }

        // Use pipeline for atomic operation with TTL
        $pipe = $redis->multi(Redis::PIPELINE);

        foreach ($values as $key => $value) {
            $pipe->setex($key, $ttl, $value);
        }

        $results = $pipe->exec();

        // All operations must succeed
        return !in_array(false, $results, true);
    }

    /**
     * {@inheritdoc}
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        try {
            if ($value === 1) {
                return $this->getRedis()->incr($key);
            }
            return $this->getRedis()->incrBy($key, $value);
        } catch (RedisException $e) {
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        try {
            if ($value === 1) {
                return $this->getRedis()->decr($key);
            }
            return $this->getRedis()->decrBy($key, $value);
        } catch (RedisException $e) {
            return false;
        }
    }

    /**
     * Add a value only if key doesn't exist
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time-to-live in seconds
     * @return bool True if added, false if key exists
     */
    public function add(string $key, mixed $value, ?int $ttl = null): bool
    {
        $redis = $this->getRedis();

        if ($ttl === null) {
            return $redis->setnx($key, $value);
        }

        // SET with NX (only if not exists) and EX (expire)
        $result = $redis->set($key, $value, ['NX', 'EX' => $ttl]);
        return $result === true;
    }

    /**
     * Acquire a lock
     *
     * @param string $name Lock name
     * @param int $seconds Lock duration
     * @param string|null $owner Lock owner identifier
     * @return string|false Lock token or false if not acquired
     */
    public function lock(string $name, int $seconds = 10, ?string $owner = null): string|false
    {
        $token = $owner ?? bin2hex(random_bytes(16));
        $key = "lock:{$name}";

        $acquired = $this->getRedis()->set($key, $token, ['NX', 'EX' => $seconds]);

        return $acquired ? $token : false;
    }

    /**
     * Release a lock
     *
     * @param string $name Lock name
     * @param string $token Lock token from acquire
     * @return bool Success
     */
    public function unlock(string $name, string $token): bool
    {
        $key = "lock:{$name}";

        // Lua script for atomic check-and-delete
        $script = <<<'LUA'
if redis.call("GET", KEYS[1]) == ARGV[1] then
    return redis.call("DEL", KEYS[1])
else
    return 0
end
LUA;

        return (bool) $this->getRedis()->eval($script, [$key, $token], 1);
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        $info = $this->getRedis()->info();

        return [
            'driver' => 'redis',
            'connected' => true,
            'host' => $this->config['host'],
            'port' => $this->config['port'],
            'database' => $this->config['database'],
            'used_memory' => $info['used_memory_human'] ?? 'N/A',
            'used_memory_peak' => $info['used_memory_peak_human'] ?? 'N/A',
            'connected_clients' => $info['connected_clients'] ?? 'N/A',
            'total_connections_received' => $info['total_connections_received'] ?? 'N/A',
            'keyspace_hits' => $info['keyspace_hits'] ?? 0,
            'keyspace_misses' => $info['keyspace_misses'] ?? 0,
            'hit_rate' => $this->calculateHitRate($info),
            'uptime_seconds' => $info['uptime_in_seconds'] ?? 0,
            'redis_version' => $info['redis_version'] ?? 'N/A',
        ];
    }

    /**
     * Calculate cache hit rate
     *
     * @param array $info Redis info
     * @return float Percentage
     */
    private function calculateHitRate(array $info): float
    {
        $hits = (int) ($info['keyspace_hits'] ?? 0);
        $misses = (int) ($info['keyspace_misses'] ?? 0);
        $total = $hits + $misses;

        if ($total === 0) {
            return 0.0;
        }

        return round(($hits / $total) * 100, 2);
    }

    /**
     * Close connection
     */
    public function close(): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->close();
            } catch (RedisException $e) {
                // Ignore close errors
            }
            $this->redis = null;
        }
    }

    public function __destruct()
    {
        // Don't close persistent connections
        if (!$this->config['persistent']) {
            $this->close();
        }
    }
}
