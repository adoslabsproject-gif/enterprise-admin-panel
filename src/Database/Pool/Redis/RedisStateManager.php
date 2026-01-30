<?php

/**
 * Enterprise Admin Panel - Redis State Manager
 *
 * Manages distributed state for the database pool using Redis.
 * Provides atomic operations for circuit breaker state and metrics.
 *
 * Features:
 * - Atomic state transitions with Lua scripts
 * - Automatic failover to local state if Redis unavailable
 * - Configurable TTL for all keys
 * - Cluster-aware key prefixing
 *
 * @package AdosLabs\AdminPanel\Database\Pool\Redis
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool\Redis;

use Redis;
use RedisException;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

final class RedisStateManager
{
    private ?Redis $redis = null;
    private bool $connected = false;
    private int $lastConnectionAttempt = 0;
    private const RECONNECT_INTERVAL = 5; // seconds

    /**
     * Lua script for atomic circuit breaker state transition
     *
     * ENTERPRISE FIX: Uses Redis TIME command instead of PHP time()
     * This prevents clock skew issues in distributed environments where
     * PHP servers may have slightly different clocks.
     */
    private const LUA_RECORD_FAILURE = <<<'LUA'
local key = KEYS[1]
local threshold = tonumber(ARGV[1])
local recovery_time = tonumber(ARGV[2])
-- ENTERPRISE FIX: Use Redis server time, not PHP time (ARGV[3] is ignored)
local time_result = redis.call('TIME')
local now = tonumber(time_result[1])

local state = redis.call('HGET', key, 'state') or 'closed'
local failure_count = tonumber(redis.call('HGET', key, 'failure_count') or '0')
local total_failures = tonumber(redis.call('HGET', key, 'total_failures') or '0')

failure_count = failure_count + 1
total_failures = total_failures + 1

redis.call('HSET', key, 'failure_count', failure_count)
redis.call('HSET', key, 'total_failures', total_failures)
redis.call('HSET', key, 'last_failure_time', now)

-- Trip to OPEN if threshold reached or in HALF_OPEN
if state == 'half_open' or failure_count >= threshold then
    redis.call('HSET', key, 'state', 'open')
    redis.call('HSET', key, 'opened_at', now)
    redis.call('HSET', key, 'success_count', 0)
    redis.call('HINCRBY', key, 'trip_count', 1)
    state = 'open'
end

redis.call('EXPIRE', key, 86400) -- 24h TTL
return {state, failure_count, total_failures}
LUA;

    /**
     * Lua script for atomic success recording
     *
     * ENTERPRISE FIX: Uses Redis TIME for consistency (though less critical here)
     */
    private const LUA_RECORD_SUCCESS = <<<'LUA'
local key = KEYS[1]
local half_open_threshold = tonumber(ARGV[1])
-- ENTERPRISE FIX: Use Redis server time for consistency
local time_result = redis.call('TIME')
local now = tonumber(time_result[1])

local state = redis.call('HGET', key, 'state') or 'closed'
local success_count = tonumber(redis.call('HGET', key, 'success_count') or '0')
local total_successes = tonumber(redis.call('HGET', key, 'total_successes') or '0')

total_successes = total_successes + 1
redis.call('HSET', key, 'total_successes', total_successes)
redis.call('HSET', key, 'last_success_time', now)

if state == 'half_open' then
    success_count = success_count + 1
    redis.call('HSET', key, 'success_count', success_count)

    if success_count >= half_open_threshold then
        -- Close the circuit
        redis.call('HSET', key, 'state', 'closed')
        redis.call('HSET', key, 'failure_count', 0)
        redis.call('HSET', key, 'success_count', 0)
        redis.call('HSET', key, 'closed_at', now)
        redis.call('HDEL', key, 'opened_at')
        state = 'closed'
    end
elseif state == 'closed' then
    -- Reset failure count on success
    redis.call('HSET', key, 'failure_count', 0)
end

redis.call('EXPIRE', key, 86400)
return {state, success_count, total_successes}
LUA;

    /**
     * Lua script for checking if request should be allowed
     *
     * ENTERPRISE FIX: Uses Redis TIME for recovery timer comparison.
     * This is CRITICAL - without it, clock skew between PHP and Redis
     * can cause premature or delayed recovery.
     */
    private const LUA_ALLOW_REQUEST = <<<'LUA'
local key = KEYS[1]
local recovery_time = tonumber(ARGV[1])
-- ENTERPRISE FIX: Use Redis server time, not PHP time
local time_result = redis.call('TIME')
local now = tonumber(time_result[1])

local state = redis.call('HGET', key, 'state') or 'closed'

if state == 'closed' then
    return {1, state, now}
end

if state == 'half_open' then
    return {1, state, now}
end

-- State is OPEN - check if recovery time elapsed
local opened_at = tonumber(redis.call('HGET', key, 'opened_at') or '0')
local time_remaining = recovery_time - (now - opened_at)

if time_remaining <= 0 then
    redis.call('HSET', key, 'state', 'half_open')
    redis.call('HSET', key, 'success_count', 0)
    redis.call('HSET', key, 'half_open_at', now)
    return {1, 'half_open', now}
end

-- Return time remaining for better debugging
return {0, state, time_remaining}
LUA;

    /**
     * Lua script for incrementing metrics atomically
     */
    private const LUA_INCREMENT_METRICS = <<<'LUA'
local key = KEYS[1]
local field = ARGV[1]
local amount = tonumber(ARGV[2])
local ttl = tonumber(ARGV[3])

local result = redis.call('HINCRBYFLOAT', key, field, amount)
redis.call('EXPIRE', key, ttl)
return result
LUA;

    /**
     * Lua script for atomic lock release (prevents TOCTOU race)
     *
     * ENTERPRISE FIX: The previous implementation had a TOCTOU vulnerability:
     * 1. Thread A: GET lock owner = "A" (correct)
     * 2. Thread B: SET lock owner = "B" (lock acquired)
     * 3. Thread A: DEL lock (deletes B's lock!)
     *
     * This Lua script makes the check-and-delete atomic.
     */
    private const LUA_RELEASE_LOCK = <<<'LUA'
local key = KEYS[1]
local expected_owner = ARGV[1]

local current_owner = redis.call('GET', key)
if current_owner == expected_owner then
    redis.call('DEL', key)
    return 1
end
return 0
LUA;

    public function __construct(
        private readonly string $host = 'localhost',
        private readonly int $port = 6379,
        private readonly ?string $password = null,
        private readonly int $database = 0,
        private readonly string $prefix = 'eap:dbpool:',
        private readonly float $timeout = 2.5,
        private readonly int $stateTtl = 86400, // 24 hours
        private readonly int $metricsTtl = 3600 // 1 hour
    ) {
    }

    /**
     * Get the underlying Redis instance (for read operations only)
     *
     * SECURITY: This exposes the Redis connection for monitoring/metrics.
     * Do not use for write operations - use the atomic methods instead.
     *
     * @return Redis|null The Redis instance or null if not connected
     */
    public function getRedis(): ?Redis
    {
        if (!$this->connected || $this->redis === null) {
            return null;
        }

        return $this->redis;
    }

    /**
     * Get the key prefix used for all Redis keys
     */
    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * Create from array configuration
     */
    public static function fromArray(array $config): self
    {
        return new self(
            host: $config['host'] ?? 'localhost',
            port: (int) ($config['port'] ?? 6379),
            password: $config['password'] ?? null,
            database: (int) ($config['database'] ?? 0),
            prefix: $config['prefix'] ?? 'eap:dbpool:',
            timeout: (float) ($config['timeout'] ?? 2.5),
            stateTtl: (int) ($config['state_ttl'] ?? 86400),
            metricsTtl: (int) ($config['metrics_ttl'] ?? 3600)
        );
    }

    /**
     * Connect to Redis
     */
    public function connect(): bool
    {
        if ($this->connected) {
            return true;
        }

        // Rate limit reconnection attempts
        $now = time();
        if ($now - $this->lastConnectionAttempt < self::RECONNECT_INTERVAL) {
            return false;
        }
        $this->lastConnectionAttempt = $now;

        try {
            $this->redis = new Redis();

            $connected = $this->redis->connect(
                $this->host,
                $this->port,
                $this->timeout
            );

            if (!$connected) {
                $this->redis = null;
                return false;
            }

            if ($this->password !== null && $this->password !== '') {
                $this->redis->auth($this->password);
            }

            if ($this->database !== 0) {
                $this->redis->select($this->database);
            }

            // Use JSON serializer for safety (no PHP object injection)
            $this->redis->setOption(Redis::OPT_SERIALIZER, Redis::SERIALIZER_JSON);
            $this->redis->setOption(Redis::OPT_PREFIX, $this->prefix);

            $this->connected = true;
            return true;

        } catch (RedisException $e) {
            Logger::channel('database')->error('RedisStateManager connection failed', [
                'host' => $this->host,
                'port' => $this->port,
                'error' => $e->getMessage(),
            ]);
            $this->redis = null;
            $this->connected = false;
            return false;
        }
    }

    /**
     * Check if connected to Redis
     */
    public function isConnected(): bool
    {
        if (!$this->connected || $this->redis === null) {
            return false;
        }

        try {
            $this->redis->ping();
            return true;
        } catch (RedisException $e) {
            Logger::channel('database')->warning('Redis ping failed - marking disconnected', [
                'error' => $e->getMessage(),
            ]);
            $this->connected = false;
            return false;
        }
    }

    /**
     * Record a failure in the circuit breaker
     *
     * @param string $circuitId Circuit identifier
     * @param int $threshold Failure threshold to trip
     * @param int $recoveryTime Recovery time in seconds
     * @return array{state: string, failure_count: int, total_failures: int}
     */
    public function recordFailure(string $circuitId, int $threshold, int $recoveryTime): array
    {
        if (!$this->ensureConnected()) {
            return ['state' => 'unknown', 'failure_count' => 0, 'total_failures' => 0];
        }

        try {
            // phpredis 6.x signature: eval(script, [keys..., args...], numKeys)
            $result = $this->redis->eval(
                self::LUA_RECORD_FAILURE,
                ["circuit:{$circuitId}", $threshold, $recoveryTime, time()],
                1 // 1 key, rest are ARGV
            );

            return [
                'state' => $result[0] ?? 'closed',
                'failure_count' => (int) ($result[1] ?? 0),
                'total_failures' => (int) ($result[2] ?? 0),
            ];
        } catch (RedisException $e) {
            $this->handleError($e);
            return ['state' => 'unknown', 'failure_count' => 0, 'total_failures' => 0];
        }
    }

    /**
     * Record a success in the circuit breaker
     *
     * @param string $circuitId Circuit identifier
     * @param int $halfOpenThreshold Successes needed to close from half-open
     * @return array{state: string, success_count: int, total_successes: int}
     */
    public function recordSuccess(string $circuitId, int $halfOpenThreshold): array
    {
        if (!$this->ensureConnected()) {
            return ['state' => 'unknown', 'success_count' => 0, 'total_successes' => 0];
        }

        try {
            // phpredis 6.x signature: eval(script, [keys..., args...], numKeys)
            $result = $this->redis->eval(
                self::LUA_RECORD_SUCCESS,
                ["circuit:{$circuitId}", $halfOpenThreshold, time()],
                1 // 1 key, rest are ARGV
            );

            return [
                'state' => $result[0] ?? 'closed',
                'success_count' => (int) ($result[1] ?? 0),
                'total_successes' => (int) ($result[2] ?? 0),
            ];
        } catch (RedisException $e) {
            $this->handleError($e);
            return ['state' => 'unknown', 'success_count' => 0, 'total_successes' => 0];
        }
    }

    /**
     * Check if a request should be allowed through the circuit breaker
     *
     * @param string $circuitId Circuit identifier
     * @param int $recoveryTime Recovery time in seconds
     * @return array{allowed: bool, state: string}
     */
    public function allowRequest(string $circuitId, int $recoveryTime): array
    {
        if (!$this->ensureConnected()) {
            // Fail open - allow requests if Redis is unavailable
            return ['allowed' => true, 'state' => 'unknown'];
        }

        try {
            // phpredis 6.x signature: eval(script, [keys..., args...], numKeys)
            $result = $this->redis->eval(
                self::LUA_ALLOW_REQUEST,
                ["circuit:{$circuitId}", $recoveryTime, time()],
                1 // 1 key, rest are ARGV
            );

            return [
                'allowed' => (bool) ($result[0] ?? true),
                'state' => $result[1] ?? 'closed',
            ];
        } catch (RedisException $e) {
            $this->handleError($e);
            return ['allowed' => true, 'state' => 'unknown'];
        }
    }

    /**
     * Get circuit breaker state
     *
     * @param string $circuitId Circuit identifier
     * @return array<string, mixed>
     */
    public function getCircuitState(string $circuitId): array
    {
        if (!$this->ensureConnected()) {
            return $this->getDefaultCircuitState();
        }

        try {
            $data = $this->redis->hGetAll("circuit:{$circuitId}");

            if (empty($data)) {
                return $this->getDefaultCircuitState();
            }

            return [
                'state' => $data['state'] ?? 'closed',
                'failure_count' => (int) ($data['failure_count'] ?? 0),
                'success_count' => (int) ($data['success_count'] ?? 0),
                'total_failures' => (int) ($data['total_failures'] ?? 0),
                'total_successes' => (int) ($data['total_successes'] ?? 0),
                'trip_count' => (int) ($data['trip_count'] ?? 0),
                'last_failure_time' => isset($data['last_failure_time']) ? (float) $data['last_failure_time'] : null,
                'last_success_time' => isset($data['last_success_time']) ? (float) $data['last_success_time'] : null,
                'opened_at' => isset($data['opened_at']) ? (float) $data['opened_at'] : null,
                'half_open_at' => isset($data['half_open_at']) ? (float) $data['half_open_at'] : null,
                'closed_at' => isset($data['closed_at']) ? (float) $data['closed_at'] : null,
            ];
        } catch (RedisException $e) {
            $this->handleError($e);
            return $this->getDefaultCircuitState();
        }
    }

    /**
     * Force circuit breaker to open state
     */
    public function forceOpen(string $circuitId): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $this->redis->hMSet("circuit:{$circuitId}", [
                'state' => 'open',
                'opened_at' => time(),
                'success_count' => 0,
            ]);
            $this->redis->hIncrBy("circuit:{$circuitId}", 'trip_count', 1);
            $this->redis->expire("circuit:{$circuitId}", $this->stateTtl);
            return true;
        } catch (RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * Reset circuit breaker to closed state
     */
    public function reset(string $circuitId): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $this->redis->del("circuit:{$circuitId}");
            return true;
        } catch (RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * Increment a metric counter
     *
     * @param string $metricName Metric name
     * @param float $amount Amount to increment
     * @param string $workerId Worker identifier for per-worker metrics
     * @return float New value
     */
    public function incrementMetric(string $metricName, float $amount = 1.0, string $workerId = 'global'): float
    {
        if (!$this->ensureConnected()) {
            return 0.0;
        }

        try {
            // Increment global metric
            $globalKey = "metrics:global";
            $this->redis->hIncrByFloat($globalKey, $metricName, $amount);
            $this->redis->expire($globalKey, $this->metricsTtl);

            // Increment per-worker metric
            if ($workerId !== 'global') {
                $workerKey = "metrics:worker:{$workerId}";
                $result = $this->redis->hIncrByFloat($workerKey, $metricName, $amount);
                $this->redis->expire($workerKey, $this->metricsTtl);
                return $result;
            }

            return $this->redis->hGet($globalKey, $metricName) ?: 0.0;
        } catch (RedisException $e) {
            $this->handleError($e);
            return 0.0;
        }
    }

    /**
     * Get aggregated metrics from all workers
     *
     * @return array<string, float>
     */
    public function getAggregatedMetrics(): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        try {
            $metrics = $this->redis->hGetAll("metrics:global");

            // Convert to proper types
            $result = [];
            foreach ($metrics as $key => $value) {
                $result[$key] = (float) $value;
            }

            return $result;
        } catch (RedisException $e) {
            $this->handleError($e);
            return [];
        }
    }

    /**
     * Get metrics for a specific worker
     *
     * @param string $workerId Worker identifier
     * @return array<string, float>
     */
    public function getWorkerMetrics(string $workerId): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        try {
            $metrics = $this->redis->hGetAll("metrics:worker:{$workerId}");

            $result = [];
            foreach ($metrics as $key => $value) {
                $result[$key] = (float) $value;
            }

            return $result;
        } catch (RedisException $e) {
            $this->handleError($e);
            return [];
        }
    }

    /**
     * Register a worker as active
     *
     * @param string $workerId Worker identifier
     * @param int $ttl Time to live in seconds
     */
    public function registerWorker(string $workerId, int $ttl = 60): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            $this->redis->setex(
                "worker:{$workerId}",
                $ttl,
                json_encode([
                    'registered_at' => time(),
                    'pid' => getmypid(),
                    'hostname' => gethostname(),
                ])
            );
            return true;
        } catch (RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * Get list of active workers
     *
     * @return array<string, array>
     */
    public function getActiveWorkers(): array
    {
        if (!$this->ensureConnected()) {
            return [];
        }

        try {
            $keys = $this->redis->keys("worker:*");
            $workers = [];

            foreach ($keys as $key) {
                // Remove prefix that Redis adds
                $workerId = str_replace($this->prefix . 'worker:', '', $key);
                $data = $this->redis->get("worker:{$workerId}");
                if ($data) {
                    $workers[$workerId] = json_decode($data, true);
                }
            }

            return $workers;
        } catch (RedisException $e) {
            $this->handleError($e);
            return [];
        }
    }

    /**
     * Acquire a distributed lock
     *
     * @param string $lockName Lock name
     * @param int $ttl Lock TTL in seconds
     * @param string $owner Lock owner identifier
     * @return bool True if lock acquired
     */
    public function acquireLock(string $lockName, int $ttl = 10, string $owner = ''): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        if ($owner === '') {
            $owner = uniqid('lock_', true);
        }

        try {
            $result = $this->redis->set(
                "lock:{$lockName}",
                $owner,
                ['NX', 'EX' => $ttl]
            );
            return $result === true;
        } catch (RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * Release a distributed lock
     *
     * ENTERPRISE FIX: Uses atomic Lua script to prevent TOCTOU race condition.
     * Without this, another process could acquire the lock between our GET and DEL.
     *
     * @param string $lockName Lock name
     * @param string $owner Lock owner (only owner can release)
     * @return bool True if lock released
     */
    public function releaseLock(string $lockName, string $owner): bool
    {
        if (!$this->ensureConnected()) {
            return false;
        }

        try {
            // ENTERPRISE FIX: Atomic check-and-delete using Lua script
            $result = $this->redis->eval(
                self::LUA_RELEASE_LOCK,
                ["lock:{$lockName}", $owner],
                1 // 1 key
            );

            return $result === 1;
        } catch (RedisException $e) {
            $this->handleError($e);
            return false;
        }
    }

    /**
     * Ensure Redis connection is established
     */
    private function ensureConnected(): bool
    {
        if ($this->isConnected()) {
            return true;
        }
        return $this->connect();
    }

    /**
     * Handle Redis error
     */
    private function handleError(RedisException $e): void
    {
        Logger::channel('database')->warning('RedisStateManager operation failed', [
            'error' => $e->getMessage(),
        ]);
        $this->connected = false;
    }

    /**
     * Get default circuit state
     */
    private function getDefaultCircuitState(): array
    {
        return [
            'state' => 'closed',
            'failure_count' => 0,
            'success_count' => 0,
            'total_failures' => 0,
            'total_successes' => 0,
            'trip_count' => 0,
            'last_failure_time' => null,
            'last_success_time' => null,
            'opened_at' => null,
            'half_open_at' => null,
            'closed_at' => null,
        ];
    }

    /**
     * Close Redis connection
     */
    public function close(): void
    {
        if ($this->redis !== null) {
            try {
                $this->redis->close();
            } catch (RedisException $e) {
                // Ignore
            }
            $this->redis = null;
            $this->connected = false;
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
