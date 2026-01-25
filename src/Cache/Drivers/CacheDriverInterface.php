<?php
/**
 * Enterprise Admin Panel - Cache Driver Interface
 *
 * Contract for all cache drivers.
 *
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Cache\Drivers;

interface CacheDriverInterface
{
    /**
     * Verify connection is alive
     *
     * @return bool
     * @throws \Exception If connection fails
     */
    public function ping(): bool;

    /**
     * Get a cached value
     *
     * @param string $key Cache key
     * @return mixed|null Value or null if not found
     */
    public function get(string $key): mixed;

    /**
     * Store a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time-to-live in seconds (null = forever)
     * @return bool Success
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool;

    /**
     * Check if key exists
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool;

    /**
     * Delete a key
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete(string $key): bool;

    /**
     * Clear all cached values
     *
     * @return bool Success
     */
    public function flush(): bool;

    /**
     * Get multiple values at once
     *
     * @param array $keys Cache keys
     * @return array Key-value pairs (missing keys have null values)
     */
    public function many(array $keys): array;

    /**
     * Store multiple values at once
     *
     * @param array $values Key-value pairs
     * @param int|null $ttl Time-to-live in seconds
     * @return bool Success
     */
    public function putMany(array $values, ?int $ttl = null): bool;

    /**
     * Increment a numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment
     * @return int|bool New value or false on failure
     */
    public function increment(string $key, int $value = 1): int|bool;

    /**
     * Decrement a numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement
     * @return int|bool New value or false on failure
     */
    public function decrement(string $key, int $value = 1): int|bool;
}
