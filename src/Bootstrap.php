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
use AdosLabs\AdminPanel\Logging\LogBuffer;
use AdosLabs\AdminPanel\Logging\LogFlusher;
use AdosLabs\EnterprisePSR3Logger\LoggerFacade;
use AdosLabs\EnterprisePSR3Logger\LoggerFactory;

final class Bootstrap
{
    private static bool $initialized = false;
    private static ?string $basePath = null;

    /**
     * Registered hooks to run after initialization
     * @var callable[]
     */
    private static array $afterInitHooks = [];

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
        self::registerLogFlusher();
        self::registerLoggerFacade();

        self::$initialized = true;

        // Run after-init hooks (allows packages to register additional services)
        foreach (self::$afterInitHooks as $hook) {
            try {
                $hook();
            } catch (\Throwable $e) {
                error_log("[Bootstrap] After-init hook failed: " . $e->getMessage());
            }
        }
    }

    /**
     * Register a hook to run after initialization
     *
     * If Bootstrap is already initialized, the hook runs immediately.
     * This allows packages to register services without modifying Bootstrap.
     *
     * @param callable $hook Function to run (receives no parameters)
     */
    public static function afterInit(callable $hook): void
    {
        if (self::$initialized) {
            // Already initialized - run immediately
            try {
                $hook();
            } catch (\Throwable $e) {
                error_log("[Bootstrap] After-init hook failed: " . $e->getMessage());
            }
            return;
        }

        // Queue for later
        self::$afterInitHooks[] = $hook;
    }

    /**
     * Detect base path of the application
     *
     * Finds the PROJECT root (not the package root).
     * Skips composer.json files that belong to this package.
     */
    private static function detectBasePath(): string
    {
        // Start from current working directory (most reliable for project root)
        $cwd = getcwd();
        if ($cwd !== false && file_exists($cwd . '/composer.json') && file_exists($cwd . '/vendor')) {
            return $cwd;
        }

        // Fallback: walk up from __DIR__ but skip package's own composer.json
        $dir = __DIR__;
        while ($dir !== '/') {
            if (file_exists($dir . '/composer.json')) {
                $composerJson = json_decode(file_get_contents($dir . '/composer.json'), true);
                // Skip if this is the package itself
                if (($composerJson['name'] ?? '') !== 'ados-labs/enterprise-admin-panel') {
                    return $dir;
                }
            }
            $dir = dirname($dir);
        }

        // Last resort: 4 levels up from src/ (src -> package -> ados-labs -> vendor -> project)
        return dirname(__DIR__, 4);
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
            // Validate required environment variables - NO hardcoded passwords
            if (empty($_ENV['DB_PASSWORD']) && !isset($configOverride['password'])) {
                throw new \RuntimeException(
                    'DB_PASSWORD environment variable is required. ' .
                    'Set it in your .env file or pass password in configOverride.'
                );
            }

            $config = array_merge([
                'driver' => $_ENV['DB_DRIVER'] ?? 'pgsql',
                'host' => $_ENV['DB_HOST'] ?? 'localhost',
                'port' => (int) ($_ENV['DB_PORT'] ?? 5432),
                'database' => $_ENV['DB_DATABASE'] ?? 'admin_panel',
                'username' => $_ENV['DB_USERNAME'] ?? 'admin',
                'password' => $_ENV['DB_PASSWORD'] ?? '',
                'charset' => $_ENV['DB_CHARSET'] ?? 'utf8',
                'pool_min' => (int) ($_ENV['DB_POOL_MIN'] ?? 2),
                'pool_max' => (int) ($_ENV['DB_POOL_MAX'] ?? 10),
                'idle_timeout' => (int) ($_ENV['DB_IDLE_TIMEOUT'] ?? 300),
                'ssl' => filter_var($_ENV['DB_SSL'] ?? false, FILTER_VALIDATE_BOOLEAN),
                'ssl_ca' => $_ENV['DB_SSL_CA'] ?? null,
                // Redis for distributed circuit breaker and metrics
                'redis_enabled' => !empty($_ENV['REDIS_HOST']),
                'redis_host' => $_ENV['REDIS_HOST'] ?? 'localhost',
                'redis_port' => (int) ($_ENV['REDIS_PORT'] ?? 6379),
                'redis_password' => $_ENV['REDIS_PASSWORD'] ?? null,
                'redis_database' => (int) ($_ENV['REDIS_DATABASE'] ?? 0),
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
            return LogConfigService::getInstance($pool);
        });
    }

    /**
     * Register log flusher for buffered logging
     *
     * Flushes logs to Redis queue (async DB write) or file fallback
     * at request end.
     */
    private static function registerLogFlusher(): void
    {
        // Register shutdown handler to flush logs
        register_shutdown_function(function (): void {
            $buffer = LogBuffer::getInstance();

            if ($buffer->getBufferSize() === 0) {
                return;
            }

            // Get Redis from cache manager if available
            $redis = null;
            try {
                if (Container::has('cache')) {
                    $cacheManager = Container::get('cache');
                    if (method_exists($cacheManager, 'getRedis')) {
                        $redis = $cacheManager->getRedis();
                    }
                }
            } catch (\Throwable $e) {
                // Redis not available
            }

            // Log path
            $logPath = self::$basePath . '/storage/logs';

            $flusher = new LogFlusher($redis, $logPath);
            $flusher->flush($buffer->getBuffer());
        });
    }

    /**
     * Configure LoggerFacade to create loggers with file handlers
     */
    private static function registerLoggerFacade(): void
    {
        $logDir = self::$basePath . '/storage/logs';

        LoggerFacade::setLoggerFactory(function (string $channel) use ($logDir): \AdosLabs\EnterprisePSR3Logger\Logger {
            return LoggerFactory::minimal($channel, "{$logDir}/{$channel}-" . date('Y-m-d') . '.log');
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
        self::$afterInitHooks = [];
    }
}
