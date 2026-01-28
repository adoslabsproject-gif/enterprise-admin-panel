<?php

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Cache\Drivers;

/**
 * APCu Cache Driver - Shared Memory (L0 Cache)
 *
 * PERFORMANCE: ~0.01μs read/write (shared memory, no network)
 *
 * USE CASES:
 * - should_log() decisions (called 100s of times per request)
 * - Configuration caching
 * - Hot data that changes rarely
 *
 * LIMITATIONS:
 * - Per-server (not shared across load balancer)
 * - Lost on PHP-FPM restart
 * - Limited size (usually 32-128MB)
 *
 * BEST PRACTICE: Use as L0 cache in front of Redis (L1)
 */
final class ApcuDriver implements CacheDriverInterface
{
    /**
     * Key prefix to avoid collisions
     */
    private string $prefix;

    /**
     * Whether APCu is available
     */
    private bool $available;

    /**
     * Create APCu cache instance
     *
     * @param string $prefix Key prefix
     */
    public function __construct(string $prefix = 'eap:')
    {
        $this->prefix = $prefix;
        $this->available = extension_loaded('apcu') && apcu_enabled();
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key, mixed $default = null): mixed
    {
        if (!$this->available) {
            return $default;
        }

        $success = false;
        $value = apcu_fetch($this->prefix . $key, $success);

        return $success ? $value : $default;
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, int $ttl = 3600): bool
    {
        if (!$this->available) {
            return false;
        }

        return apcu_store($this->prefix . $key, $value, $ttl);
    }

    /**
     * {@inheritdoc}
     */
    public function delete(string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        return apcu_delete($this->prefix . $key);
    }

    /**
     * {@inheritdoc}
     */
    public function has(string $key): bool
    {
        if (!$this->available) {
            return false;
        }

        return apcu_exists($this->prefix . $key);
    }

    /**
     * {@inheritdoc}
     */
    public function clear(): bool
    {
        if (!$this->available) {
            return false;
        }

        // Clear only our prefixed keys
        $iterator = new \APCUIterator('/^' . preg_quote($this->prefix, '/') . '/', APC_ITER_KEY);

        foreach ($iterator as $item) {
            apcu_delete($item['key']);
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function getMultiple(array $keys, mixed $default = null): array
    {
        $results = [];

        foreach ($keys as $key) {
            $results[$key] = $this->get($key, $default);
        }

        return $results;
    }

    /**
     * {@inheritdoc}
     */
    public function setMultiple(array $values, int $ttl = 3600): bool
    {
        if (!$this->available) {
            return false;
        }

        $prefixed = [];
        foreach ($values as $key => $value) {
            $prefixed[$this->prefix . $key] = $value;
        }

        $failed = apcu_store($prefixed, null, $ttl);

        return empty($failed);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteMultiple(array $keys): bool
    {
        if (!$this->available) {
            return false;
        }

        foreach ($keys as $key) {
            apcu_delete($this->prefix . $key);
        }

        return true;
    }

    /**
     * Increment a counter atomically
     *
     * @param string $key Counter key
     * @param int $step Increment step
     * @return int|false New value or false on failure
     */
    public function increment(string $key, int $step = 1): int|false
    {
        if (!$this->available) {
            return false;
        }

        return apcu_inc($this->prefix . $key, $step);
    }

    /**
     * Decrement a counter atomically
     *
     * @param string $key Counter key
     * @param int $step Decrement step
     * @return int|false New value or false on failure
     */
    public function decrement(string $key, int $step = 1): int|false
    {
        if (!$this->available) {
            return false;
        }

        return apcu_dec($this->prefix . $key, $step);
    }

    /**
     * Check if APCu is available
     */
    public function isAvailable(): bool
    {
        return $this->available;
    }

    /**
     * Get APCu memory info
     *
     * @return array{memory: int, hits: int, misses: int, entries: int}|null
     */
    public function getStats(): ?array
    {
        if (!$this->available) {
            return null;
        }

        $info = apcu_cache_info(true);
        $sma = apcu_sma_info(true);

        if ($info === false || $sma === false) {
            return null;
        }

        return [
            'memory' => $sma['seg_size'] ?? 0,
            'hits' => $info['num_hits'] ?? 0,
            'misses' => $info['num_misses'] ?? 0,
            'entries' => $info['num_entries'] ?? 0,
        ];
    }

    /**
     * Delete keys by pattern (used for cache invalidation)
     *
     * @param string $pattern Regex pattern (without prefix)
     * @return int Number of deleted keys
     */
    public function deleteByPattern(string $pattern): int
    {
        if (!$this->available) {
            return 0;
        }

        $fullPattern = '/^' . preg_quote($this->prefix, '/') . $pattern . '/';
        $iterator = new \APCUIterator($fullPattern, APC_ITER_KEY);

        $deleted = 0;
        foreach ($iterator as $item) {
            if (apcu_delete($item['key'])) {
                $deleted++;
            }
        }

        return $deleted;
    }
}
