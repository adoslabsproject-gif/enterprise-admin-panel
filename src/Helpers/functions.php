<?php

/**
 * Enterprise Admin Panel - Global Helper Functions
 *
 * Ultra-fast helper functions available globally.
 * All functions use the DI Container for lazy loading.
 *
 * IMPORTANT: This file must be included in composer.json autoload.files
 *
 * @package AdosLabs\AdminPanel\Helpers
 * @version 1.0.0
 */

declare(strict_types=1);

use AdosLabs\AdminPanel\Bootstrap;
use AdosLabs\AdminPanel\Core\Container;
use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use AdosLabs\AdminPanel\Database\Pool\PooledConnection;
use AdosLabs\AdminPanel\Cache\CacheManager;

// ============================================================================
// BOOTSTRAP
// ============================================================================

if (!function_exists('bootstrap')) {
    /**
     * Initialize the framework
     *
     * @param string|null $basePath Base path override
     * @param array $config Configuration override
     */
    function bootstrap(?string $basePath = null, array $config = []): void
    {
        Bootstrap::init($basePath, $config);
    }
}

// ============================================================================
// DATABASE
// ============================================================================

if (!function_exists('db')) {
    /**
     * Get the database pool instance
     *
     * @return DatabasePool
     */
    function db(): DatabasePool
    {
        if (!Bootstrap::isInitialized()) {
            Bootstrap::init();
        }

        return Container::get('db.pool');
    }
}

if (!function_exists('query')) {
    /**
     * Execute a SELECT query
     *
     * @param string $sql SQL query with ? or :name placeholders
     * @param array $params Query parameters
     * @return array<int, array<string, mixed>> Results
     */
    function query(string $sql, array $params = []): array
    {
        return db()->query($sql, $params);
    }
}

if (!function_exists('execute')) {
    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     *
     * @param string $sql SQL statement
     * @param array $params Statement parameters
     * @return int Affected rows
     */
    function execute(string $sql, array $params = []): int
    {
        return db()->execute($sql, $params);
    }
}

if (!function_exists('transaction')) {
    /**
     * Execute code in a transaction
     *
     * @param callable $callback Callback receiving PooledConnection
     * @return mixed Callback return value
     *
     * @throws \Throwable
     */
    function transaction(callable $callback): mixed
    {
        $pool = db();
        $connection = $pool->beginTransaction();

        try {
            $result = $callback($connection);
            $pool->commit($connection);
            return $result;
        } catch (\Throwable $e) {
            $pool->rollback($connection);
            throw $e;
        }
    }
}

// ============================================================================
// CACHE
// ============================================================================

if (!function_exists('cache')) {
    /**
     * Get the cache manager instance
     *
     * @return CacheManager
     */
    function cache(): CacheManager
    {
        if (!Bootstrap::isInitialized()) {
            Bootstrap::init();
        }

        return Container::get('cache');
    }
}

if (!function_exists('cache_get')) {
    /**
     * Get a cached value
     *
     * @param string $key Cache key
     * @param mixed $default Default value
     * @return mixed
     */
    function cache_get(string $key, mixed $default = null): mixed
    {
        return cache()->get($key, $default);
    }
}

if (!function_exists('cache_set')) {
    /**
     * Store a value in cache
     *
     * @param string $key Cache key
     * @param mixed $value Value to store
     * @param int|null $ttl TTL in seconds
     * @return bool Success
     */
    function cache_set(string $key, mixed $value, ?int $ttl = null): bool
    {
        return cache()->set($key, $value, $ttl);
    }
}

if (!function_exists('cache_remember')) {
    /**
     * Get or set a cached value
     *
     * @param string $key Cache key
     * @param int|null $ttl TTL in seconds
     * @param callable $callback Callback to generate value
     * @return mixed
     */
    function cache_remember(string $key, ?int $ttl, callable $callback): mixed
    {
        return cache()->remember($key, $ttl, $callback);
    }
}

// ============================================================================
// LOGGING
// ============================================================================

if (!function_exists('should_log')) {
    /**
     * Determine if a log entry should be written
     *
     * Uses multi-layer caching for ~0.001ms decisions:
     * 1. Static array (same request)
     * 2. Redis (cross-request)
     * 3. Database (source of truth)
     *
     * @param string $channel Log channel
     * @param string $level Log level
     * @return bool
     */
    function should_log(string $channel, string $level): bool
    {
        // Layer 1: Static cache (same request)
        static $decisions = [];
        $key = "{$channel}:{$level}";

        if (isset($decisions[$key])) {
            return $decisions[$key];
        }

        // If not initialized, allow all logs
        if (!Bootstrap::isInitialized()) {
            $decisions[$key] = true;
            return true;
        }

        // Get decider from container
        if (!Container::has('log.decider')) {
            $decisions[$key] = true;
            return true;
        }

        try {
            $decider = Container::get('log.decider');
            $decisions[$key] = $decider->shouldLog($channel, $level);
        } catch (\Throwable $e) {
            // On any error, allow logging
            $decisions[$key] = true;
        }

        return $decisions[$key];
    }
}

// ============================================================================
// ENVIRONMENT
// ============================================================================

if (!function_exists('env')) {
    /**
     * Get environment variable with type casting
     *
     * @param string $key Variable name
     * @param mixed $default Default value
     * @return mixed
     */
    function env(string $key, mixed $default = null): mixed
    {
        $value = $_ENV[$key] ?? getenv($key);

        if ($value === false || $value === null) {
            return $default;
        }

        return match (strtolower((string) $value)) {
            'true', '(true)' => true,
            'false', '(false)' => false,
            'null', '(null)' => null,
            'empty', '(empty)' => '',
            default => $value,
        };
    }
}

// ============================================================================
// CONFIGURATION
// ============================================================================

if (!function_exists('config')) {
    /**
     * Get configuration value using dot notation
     *
     * @param string $key Config key (e.g., 'database.host')
     * @param mixed $default Default value
     * @return mixed
     */
    function config(string $key, mixed $default = null): mixed
    {
        static $cache = [];

        $parts = explode('.', $key);
        $file = array_shift($parts);

        if (!isset($cache[$file])) {
            $basePath = Bootstrap::getBasePath();
            $paths = [
                $basePath . "/config/{$file}.php",
            ];

            foreach ($paths as $path) {
                if (file_exists($path)) {
                    $cache[$file] = require $path;
                    break;
                }
            }

            if (!isset($cache[$file])) {
                return $default;
            }
        }

        $value = $cache[$file];
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return $default;
            }
            $value = $value[$part];
        }

        return $value;
    }
}

// ============================================================================
// PATHS
// ============================================================================

if (!function_exists('base_path')) {
    /**
     * Get base path of the application
     *
     * @param string $path Relative path to append
     * @return string
     */
    function base_path(string $path = ''): string
    {
        $basePath = Bootstrap::getBasePath();
        return $basePath . ($path ? '/' . ltrim($path, '/') : '');
    }
}

if (!function_exists('storage_path')) {
    /**
     * Get storage path
     *
     * @param string $path Relative path
     * @return string
     */
    function storage_path(string $path = ''): string
    {
        return base_path('storage' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}

if (!function_exists('public_path')) {
    /**
     * Get public path
     *
     * @param string $path Relative path
     * @return string
     */
    function public_path(string $path = ''): string
    {
        return base_path('public' . ($path ? '/' . ltrim($path, '/') : ''));
    }
}

// ============================================================================
// SECURITY
// ============================================================================

if (!function_exists('e')) {
    /**
     * Escape HTML entities
     *
     * @param string|null $value
     * @return string
     */
    function e(?string $value): string
    {
        return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8', false);
    }
}
