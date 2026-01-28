<?php
/**
 * Enterprise Admin Panel - Cache Manager
 *
 * Redis-first caching with automatic fallback to database.
 *
 * Features:
 * - Redis as primary cache
 * - Automatic database fallback if Redis unavailable
 * - Consistent API regardless of driver
 * - TTL support
 * - Tagging for bulk invalidation
 *
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Cache;

use AdosLabs\AdminPanel\Cache\Drivers\CacheDriverInterface;
use AdosLabs\AdminPanel\Cache\Drivers\RedisDriver;
use AdosLabs\AdminPanel\Cache\Drivers\DatabaseDriver;
use AdosLabs\AdminPanel\Cache\Drivers\ArrayDriver;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade as Logger;

final class CacheManager
{
    private ?CacheDriverInterface $driver = null;
    private ?CacheDriverInterface $fallbackDriver = null;
    private array $config;
    private bool $usingFallback = false;

    public function __construct(array $config = [])
    {
        $this->config = array_merge([
            'default' => 'redis',
            'fallback' => 'database',
            'prefix' => 'eap_',
            'redis' => [
                'host' => 'localhost',
                'port' => 6379,
                'password' => null,
                'database' => 0,
                'timeout' => 0.5,
            ],
            'database' => [
                'table' => 'cache',
                'connection' => null,
            ],
        ], $config);
    }

    /**
     * Get active driver
     *
     * @return CacheDriverInterface
     */
    private function getDriver(): CacheDriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        // Try primary driver
        try {
            $this->driver = $this->createDriver($this->config['default']);
            $this->driver->ping(); // Verify connection
            return $this->driver;
        } catch (\Exception $e) {
            // Log primary driver failure
            Logger::channel('default')->warning( 'Cache primary driver failed, attempting fallback', [
                'driver' => $this->config['default'],
                'error' => $e->getMessage(),
            ]);

            // Primary failed, try fallback
            if ($this->config['fallback']) {
                try {
                    $this->driver = $this->createDriver($this->config['fallback']);
                    $this->usingFallback = true;
                    Logger::channel('default')->info( 'Cache using fallback driver', [
                        'fallback' => $this->config['fallback'],
                    ]);
                    return $this->driver;
                } catch (\Exception $e2) {
                    // Both failed, log and use array driver
                    Logger::channel('error')->error( 'Cache fallback driver also failed', [
                        'fallback' => $this->config['fallback'],
                        'error' => $e2->getMessage(),
                    ]);
                }
            }
        }

        // Ultimate fallback: memory-only
        $this->driver = new ArrayDriver();
        $this->usingFallback = true;
        Logger::channel('default')->warning( 'Cache using memory-only ArrayDriver (no persistence)');
        return $this->driver;
    }

    /**
     * Create a cache driver instance
     *
     * @param string $driver Driver name
     * @return CacheDriverInterface
     */
    private function createDriver(string $driver): CacheDriverInterface
    {
        return match ($driver) {
            'redis' => new RedisDriver($this->config['redis'], $this->config['prefix']),
            'database' => new DatabaseDriver($this->config['database'], $this->config['prefix']),
            'array' => new ArrayDriver($this->config['prefix']),
            default => throw new \InvalidArgumentException("Unknown cache driver: {$driver}"),
        };
    }

    /**
     * Get a cached value
     *
     * @param string $key Cache key
     * @param mixed $default Default value if not found
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->getDriver()->get($key);
        return $value !== null ? $value : $default;
    }

    /**
     * Store a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time-to-live in seconds (null = forever)
     * @return bool Success
     */
    public function set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->getDriver()->set($key, $value, $ttl);
    }

    /**
     * Store a value in cache (alias for set)
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl Time-to-live in seconds
     * @return bool Success
     */
    public function put(string $key, mixed $value, ?int $ttl = null): bool
    {
        return $this->set($key, $value, $ttl);
    }

    /**
     * Check if key exists in cache
     *
     * @param string $key Cache key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->getDriver()->has($key);
    }

    /**
     * Remove a key from cache
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function forget(string $key): bool
    {
        return $this->getDriver()->delete($key);
    }

    /**
     * Remove a key from cache (alias for forget)
     *
     * @param string $key Cache key
     * @return bool Success
     */
    public function delete(string $key): bool
    {
        return $this->forget($key);
    }

    /**
     * Get and delete a key
     *
     * @param string $key Cache key
     * @param mixed $default Default value
     * @return mixed
     */
    public function pull(string $key, mixed $default = null): mixed
    {
        $value = $this->get($key, $default);
        $this->forget($key);
        return $value;
    }

    /**
     * Store value forever (no expiration)
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @return bool Success
     */
    public function forever(string $key, mixed $value): bool
    {
        return $this->set($key, $value, null);
    }

    /**
     * Get or set a cached value
     *
     * @param string $key Cache key
     * @param int|null $ttl Time-to-live
     * @param callable $callback Callback to generate value if not cached
     * @return mixed
     */
    public function remember(string $key, ?int $ttl, callable $callback): mixed
    {
        $value = $this->get($key);

        if ($value !== null) {
            return $value;
        }

        $value = $callback();
        $this->set($key, $value, $ttl);

        return $value;
    }

    /**
     * Get or set a cached value forever
     *
     * @param string $key Cache key
     * @param callable $callback Callback to generate value
     * @return mixed
     */
    public function rememberForever(string $key, callable $callback): mixed
    {
        return $this->remember($key, null, $callback);
    }

    /**
     * Increment a numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to increment
     * @return int|bool New value or false on failure
     */
    public function increment(string $key, int $value = 1): int|bool
    {
        return $this->getDriver()->increment($key, $value);
    }

    /**
     * Decrement a numeric value
     *
     * @param string $key Cache key
     * @param int $value Amount to decrement
     * @return int|bool New value or false on failure
     */
    public function decrement(string $key, int $value = 1): int|bool
    {
        return $this->getDriver()->decrement($key, $value);
    }

    /**
     * Clear all cached values
     *
     * @return bool Success
     */
    public function flush(): bool
    {
        return $this->getDriver()->flush();
    }

    /**
     * Get multiple values at once
     *
     * @param array $keys Cache keys
     * @return array Key-value pairs
     */
    public function many(array $keys): array
    {
        return $this->getDriver()->many($keys);
    }

    /**
     * Store multiple values at once
     *
     * @param array $values Key-value pairs
     * @param int|null $ttl Time-to-live
     * @return bool Success
     */
    public function putMany(array $values, ?int $ttl = null): bool
    {
        return $this->getDriver()->putMany($values, $ttl);
    }

    /**
     * Check if using fallback driver
     *
     * @return bool
     */
    public function isUsingFallback(): bool
    {
        $this->getDriver(); // Ensure driver is initialized
        return $this->usingFallback;
    }

    /**
     * Get current driver name
     *
     * @return string
     */
    public function getDriverName(): string
    {
        $driver = $this->getDriver();
        return match (true) {
            $driver instanceof RedisDriver => 'redis',
            $driver instanceof DatabaseDriver => 'database',
            $driver instanceof ArrayDriver => 'array',
            default => 'unknown',
        };
    }

    /**
     * Get cache statistics
     *
     * @return array
     */
    public function getStats(): array
    {
        return [
            'driver' => $this->getDriverName(),
            'using_fallback' => $this->usingFallback,
            'prefix' => $this->config['prefix'],
        ];
    }
}
