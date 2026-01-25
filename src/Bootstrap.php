<?php

/**
 * Enterprise Admin Panel - Bootstrap
 *
 * Initializes the framework:
 * - Loads environment variables
 * - Configures the DI Container
 * - Registers database pool
 * - Registers cache manager
 * - Sets up logging decision layer
 *
 * This is the SINGLE entry point for framework initialization.
 *
 * @package AdosLabs\AdminPanel
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel;

use AdosLabs\AdminPanel\Core\Container;
use AdosLabs\AdminPanel\Database\Pool\DatabasePool;
use AdosLabs\AdminPanel\Database\Pool\PoolConfig;
use AdosLabs\AdminPanel\Cache\CacheManager;
use AdosLabs\AdminPanel\Services\LogConfigService;

final class Bootstrap
{
    private static bool $initialized = false;
    private static ?string $basePath = null;

    /**
     * Initialize the framework
     *
     * @param string|null $basePath Base path of the application
     * @param array $config Optional configuration override
     */
    public static function init(?string $basePath = null, array $config = []): void
    {
        if (self::$initialized) {
            return;
        }

        self::$basePath = $basePath ?? self::detectBasePath();

        // Load environment
        self::loadEnvironment();

        // Register services
        self::registerDatabasePool($config['database'] ?? []);
        self::registerCacheManager($config['cache'] ?? []);
        self::registerLogDecider();

        self::$initialized = true;
    }

    /**
     * Detect base path of the application
     */
    private static function detectBasePath(): string
    {
        // Find project root (where composer.json is)
        $dir = __DIR__;
        while ($dir !== '/' && !file_exists($dir . '/composer.json')) {
            $dir = dirname($dir);
        }

        return $dir !== '/' ? $dir : dirname(__DIR__, 3);
    }

    /**
     * Load environment variables from .env file
     */
    private static function loadEnvironment(): void
    {
        $envPath = self::$basePath . '/.env';

        if (!file_exists($envPath)) {
            return;
        }

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);

            // Remove quotes
            if (preg_match('/^(["\'])(.*)\\1$/', $value, $matches)) {
                $value = $matches[2];
            }

            // Type casting
            $value = match (strtolower($value)) {
                'true', '(true)' => true,
                'false', '(false)' => false,
                'null', '(null)' => null,
                'empty', '(empty)' => '',
                default => $value,
            };

            $_ENV[$key] = $value;
            if (is_string($value) || is_numeric($value)) {
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Register database pool in container
     */
    private static function registerDatabasePool(array $configOverride = []): void
    {
        Container::singleton('db.pool', function () use ($configOverride) {
            $config = array_merge([
                'driver' => $_ENV['DB_DRIVER'] ?? 'pgsql',
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => (int) ($_ENV['DB_PORT'] ?? 5432),
                'database' => $_ENV['DB_DATABASE'] ?? 'admin_panel',
                'username' => $_ENV['DB_USERNAME'] ?? 'admin',
                'password' => $_ENV['DB_PASSWORD'] ?? 'secret',
                'charset' => $_ENV['DB_CHARSET'] ?? 'utf8',
                'pool_min' => (int) ($_ENV['DB_POOL_MIN'] ?? 2),
                'pool_max' => (int) ($_ENV['DB_POOL_MAX'] ?? 10),
                'idle_timeout' => (int) ($_ENV['DB_IDLE_TIMEOUT'] ?? 300),
                'ssl' => filter_var($_ENV['DB_SSL'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'ssl_ca' => $_ENV['DB_SSL_CA'] ?? null,
            ], $configOverride);

            return DatabasePool::create($config);
        });

        // Alias for backward compatibility
        Container::singleton('db', function () {
            return Container::get('db.pool');
        });
    }

    /**
     * Register cache manager in container
     */
    private static function registerCacheManager(array $configOverride = []): void
    {
        Container::singleton('cache', function () use ($configOverride) {
            $config = array_merge([
                'default' => $_ENV['CACHE_DRIVER'] ?? 'redis',
                'fallback' => $_ENV['CACHE_FALLBACK'] ?? 'database',
                'prefix' => $_ENV['CACHE_PREFIX'] ?? 'eap_',
                'redis' => [
                    'host' => $_ENV['REDIS_HOST'] ?? 'localhost',
                    'port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
                    'password' => $_ENV['REDIS_PASSWORD'] ?? null,
                    'database' => (int) ($_ENV['REDIS_DATABASE'] ?? 0),
                    'timeout' => 2.5,
                ],
                'database' => [
                    'table' => 'cache',
                ],
            ], $configOverride);

            return new CacheManager($config);
        });
    }

    /**
     * Register log decider for should_log() function
     */
    private static function registerLogDecider(): void
    {
        Container::singleton('log.decider', function () {
            $pool = Container::get('db.pool');
            $cache = Container::get('cache');

            return new LogConfigService($pool, $cache);
        });
    }

    /**
     * Get base path
     */
    public static function getBasePath(): string
    {
        return self::$basePath ?? self::detectBasePath();
    }

    /**
     * Check if initialized
     */
    public static function isInitialized(): bool
    {
        return self::$initialized;
    }

    /**
     * Reset (for testing)
     */
    public static function reset(): void
    {
        Container::flush();
        self::$initialized = false;
        self::$basePath = null;
    }
}
