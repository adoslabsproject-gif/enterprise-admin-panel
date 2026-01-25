<?php
/**
 * Enterprise Admin Panel - Array Cache Driver
 *
 * In-memory cache driver for testing and ultimate fallback.
 *
 * Features:
 * - TTL support
 * - Memory-only (lost on request end)
 * - Useful for testing and CLI scripts
 *
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Cache\Drivers;

final class ArrayDriver implements CacheDriverInterface
{
    /**
     * Cached values
     * @var array<string, mixed>
     */
    private array $storage = [];

    /**
     * Expiration times
     * @var array<string, int|null>
     */
    private array $expirations = [];

    private string $prefix;

    public function __construct(string $prefix = 'eap_')
    {
        $this->prefix = $prefix;
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
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function get(string $key): mixed
    {
        $prefixedKey = $this->prefixedKey($key);

        if (!array_key_exists($prefixedKey, $this->storage)) {
            return null;
        }

        // Check expiration
        if ($this->isExpired($prefixedKey)) {
            $this->delete($key);
            return null;
        }

        return $this->storage[$prefixedKey];
    }

    /**
     * {@inheritdoc}
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        $prefixedKey = $this->prefixedKey($key);

        $this->storage[$prefixedKey] = $value;
        $this->expirations[$prefixedKey] = $ttl !== null ? time() + $ttl : null;

        return true;
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
        $prefixedKey = $this->prefixedKey($key);

        unset($this->storage[$prefixedKey], $this->expirations[$prefixedKey]);

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function flush(): bool
    {
        // Only flush keys with our prefix
        foreach (array_keys($this->storage) as $key) {
            if (str_starts_with($key, $this->prefix)) {
                unset($this->storage[$key], $this->expirations[$key]);
            }
        }

        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function many(array $keys): array
    {
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
        foreach ($values as $key => $value) {
            $this->set($key, $value, $ttl);
        }

        return true;
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
     * Check if key is expired
     *
     * @param string $prefixedKey
     * @return bool
     */
    private function isExpired(string $prefixedKey): bool
    {
        $expires = $this->expirations[$prefixedKey] ?? null;

        if ($expires === null) {
            return false;
        }

        return time() >= $expires;
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        $total = 0;
        $expired = 0;

        foreach ($this->storage as $key => $value) {
            if (str_starts_with($key, $this->prefix)) {
                $total++;
                if ($this->isExpired($key)) {
                    $expired++;
                }
            }
        }

        return [
            'driver' => 'array',
            'prefix' => $this->prefix,
            'total_entries' => $total,
            'expired_entries' => $expired,
            'active_entries' => $total - $expired,
            'memory_usage' => memory_get_usage(true),
        ];
    }

    /**
     * Get all stored keys (for debugging)
     *
     * @return array
     */
    public function keys(): array
    {
        return array_keys($this->storage);
    }
}
