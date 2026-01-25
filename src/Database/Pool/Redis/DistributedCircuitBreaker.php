<?php

/**
 * Enterprise Admin Panel - Distributed Circuit Breaker
 *
 * Circuit breaker with Redis-backed state sharing across processes/servers.
 * Falls back to local state if Redis is unavailable (fail-open).
 *
 * Architecture:
 * - Primary: Redis for distributed state
 * - Fallback: Local in-memory state
 * - Hybrid: Uses Redis when available, local when not
 *
 * @package AdosLabs\AdminPanel\Database\Pool\Redis
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool\Redis;

use AdosLabs\AdminPanel\Database\Pool\CircuitBreaker;

final class DistributedCircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    /**
     * Local circuit breaker for fallback
     */
    private CircuitBreaker $localBreaker;

    /**
     * Cached state from Redis (reduces Redis calls)
     */
    private ?array $cachedState = null;
    private float $cacheExpiry = 0;
    private const CACHE_TTL = 1.0; // 1 second cache

    public function __construct(
        private readonly RedisStateManager $redis,
        private readonly string $circuitId = 'default',
        private readonly int $failureThreshold = 5,
        private readonly int $recoveryTime = 30,
        private readonly int $halfOpenSuccessThreshold = 2
    ) {
        // Initialize local fallback
        $this->localBreaker = new CircuitBreaker(
            $failureThreshold,
            $recoveryTime,
            $halfOpenSuccessThreshold
        );
    }

    /**
     * Check if request should be allowed
     */
    public function allowRequest(): bool
    {
        // Try Redis first
        if ($this->redis->isConnected() || $this->redis->connect()) {
            $result = $this->redis->allowRequest($this->circuitId, $this->recoveryTime);

            if ($result['state'] !== 'unknown') {
                $this->syncLocalState($result['state']);
                return $result['allowed'];
            }
        }

        // Fallback to local
        return $this->localBreaker->allowRequest();
    }

    /**
     * Record a successful operation
     */
    public function recordSuccess(): void
    {
        // Always record locally for immediate consistency
        $this->localBreaker->recordSuccess();

        // Try Redis
        if ($this->redis->isConnected() || $this->redis->connect()) {
            $result = $this->redis->recordSuccess($this->circuitId, $this->halfOpenSuccessThreshold);

            if ($result['state'] !== 'unknown') {
                $this->invalidateCache();
            }
        }
    }

    /**
     * Record a failed operation
     */
    public function recordFailure(): void
    {
        // Always record locally
        $this->localBreaker->recordFailure();

        // Try Redis
        if ($this->redis->isConnected() || $this->redis->connect()) {
            $result = $this->redis->recordFailure(
                $this->circuitId,
                $this->failureThreshold,
                $this->recoveryTime
            );

            if ($result['state'] !== 'unknown') {
                $this->invalidateCache();
            }
        }
    }

    /**
     * Get current state
     */
    public function getState(): string
    {
        // Check cache first
        if ($this->cachedState !== null && microtime(true) < $this->cacheExpiry) {
            return $this->cachedState['state'];
        }

        // Try Redis
        if ($this->redis->isConnected() || $this->redis->connect()) {
            $state = $this->redis->getCircuitState($this->circuitId);

            if ($state['state'] !== 'unknown') {
                $this->cachedState = $state;
                $this->cacheExpiry = microtime(true) + self::CACHE_TTL;
                $this->syncLocalState($state['state']);
                return $state['state'];
            }
        }

        // Fallback to local
        return $this->localBreaker->getState();
    }

    /**
     * Get failure count
     */
    public function getFailureCount(): int
    {
        if ($this->cachedState !== null && microtime(true) < $this->cacheExpiry) {
            return $this->cachedState['failure_count'];
        }

        // Try Redis
        if ($this->redis->isConnected() || $this->redis->connect()) {
            $state = $this->redis->getCircuitState($this->circuitId);
            if (isset($state['failure_count'])) {
                return $state['failure_count'];
            }
        }

        return $this->localBreaker->getFailureCount();
    }

    /**
     * Get time until recovery
     */
    public function getTimeUntilRecovery(): ?float
    {
        $state = $this->getState();

        if ($state !== self::STATE_OPEN) {
            return null;
        }

        // Try Redis
        if ($this->redis->isConnected() || $this->redis->connect()) {
            $circuitState = $this->redis->getCircuitState($this->circuitId);

            if ($circuitState['opened_at'] !== null) {
                $elapsed = time() - $circuitState['opened_at'];
                $remaining = $this->recoveryTime - $elapsed;
                return max(0.0, (float) $remaining);
            }
        }

        return $this->localBreaker->getTimeUntilRecovery();
    }

    /**
     * Force circuit breaker open
     */
    public function forceOpen(): void
    {
        $this->localBreaker->forceOpen();

        if ($this->redis->isConnected() || $this->redis->connect()) {
            $this->redis->forceOpen($this->circuitId);
            $this->invalidateCache();
        }
    }

    /**
     * Reset circuit breaker
     */
    public function reset(): void
    {
        $this->localBreaker->reset();

        if ($this->redis->isConnected() || $this->redis->connect()) {
            $this->redis->reset($this->circuitId);
            $this->invalidateCache();
        }
    }

    /**
     * Get comprehensive statistics
     */
    public function getStats(): array
    {
        // Try Redis first
        if ($this->redis->isConnected() || $this->redis->connect()) {
            $redisState = $this->redis->getCircuitState($this->circuitId);

            if ($redisState['state'] !== 'unknown') {
                return [
                    'state' => $redisState['state'],
                    'failure_count' => $redisState['failure_count'],
                    'success_count' => $redisState['success_count'],
                    'total_failures' => $redisState['total_failures'],
                    'total_successes' => $redisState['total_successes'],
                    'trip_count' => $redisState['trip_count'],
                    'failure_threshold' => $this->failureThreshold,
                    'recovery_time' => $this->recoveryTime,
                    'time_until_recovery' => $this->getTimeUntilRecovery(),
                    'last_failure_time' => $redisState['last_failure_time'],
                    'opened_at' => $redisState['opened_at'],
                    'backend' => 'redis',
                    'circuit_id' => $this->circuitId,
                ];
            }
        }

        // Fallback to local stats
        $localStats = $this->localBreaker->getStats();
        $localStats['backend'] = 'local';
        $localStats['circuit_id'] = $this->circuitId;
        return $localStats;
    }

    /**
     * Check if using Redis backend
     */
    public function isUsingRedis(): bool
    {
        return $this->redis->isConnected();
    }

    /**
     * Force half-open state (for testing)
     */
    public function forceHalfOpen(): void
    {
        $this->localBreaker->forceHalfOpen();
        // Redis state will transition naturally on next allowRequest()
        $this->invalidateCache();
    }

    /**
     * Simulate failures (for testing)
     */
    public function simulateFailures(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->recordFailure();
        }
    }

    /**
     * Simulate successes (for testing)
     */
    public function simulateSuccesses(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->recordSuccess();
        }
    }

    /**
     * Check if circuit breaker is open
     */
    public function isOpen(): bool
    {
        return $this->getState() === self::STATE_OPEN;
    }

    /**
     * Check if circuit breaker is closed
     */
    public function isClosed(): bool
    {
        return $this->getState() === self::STATE_CLOSED;
    }

    /**
     * Check if circuit breaker is half-open
     */
    public function isHalfOpen(): bool
    {
        return $this->getState() === self::STATE_HALF_OPEN;
    }

    /**
     * Sync local breaker state with Redis state
     */
    private function syncLocalState(string $redisState): void
    {
        $localState = $this->localBreaker->getState();

        // If Redis says open but local says closed, force local open
        if ($redisState === self::STATE_OPEN && $localState === self::STATE_CLOSED) {
            $this->localBreaker->forceOpen();
        }
        // If Redis says closed but local says open, reset local
        elseif ($redisState === self::STATE_CLOSED && $localState === self::STATE_OPEN) {
            $this->localBreaker->reset();
        }
    }

    /**
     * Invalidate cached state
     */
    private function invalidateCache(): void
    {
        $this->cachedState = null;
        $this->cacheExpiry = 0;
    }
}
