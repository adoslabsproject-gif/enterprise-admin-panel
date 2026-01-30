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

        // Set timezone from environment or default
        self::configureTimezone();

        // Configure PHP error logging
        self::configurePhpErrorLog();

        // Register services
        self::registerDatabasePool($config['database'] ?? []);
        self::registerCacheManager($config['cache'] ?? []);
        self::registerLogDecider();
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
            $composerPath = $dir . '/composer.json';
            if (file_exists($composerPath)) {
                $content = file_get_contents($composerPath);
                if ($content !== false) {
                    $composerJson = json_decode($content, true);
                    // Check for JSON parse errors
                    if (json_last_error() === JSON_ERROR_NONE && is_array($composerJson)) {
                        // Skip if this is the package itself
                        if (($composerJson['name'] ?? '') !== 'ados-labs/enterprise-admin-panel') {
                            return $dir;
                        }
                    }
                }
            }
            $dir = dirname($dir);
        }

        // Last resort: 4 levels up from src/ (src -> package -> ados-labs -> vendor -> project)
        return dirname(__DIR__, 4);
    }

    /**
     * Configure timezone from environment or default
     */
    private static function configureTimezone(): void
    {
        $timezone = $_ENV['APP_TIMEZONE'] ?? getenv('APP_TIMEZONE') ?: null;

        if ($timezone === null) {
            $iniTimezone = ini_get('date.timezone');
            if ($iniTimezone) {
                $timezone = $iniTimezone;
            }
        }

        if ($timezone === null) {
            $timezone = 'Europe/Rome';
        }

        try {
            date_default_timezone_set($timezone);
        } catch (\Throwable $e) {
            date_default_timezone_set('Europe/Rome');
        }
    }

    /**
     * Configure PHP error logging
     *
     * ENTERPRISE: Always enabled by default for enterprise apps.
     * Set LOG_PHP_ERRORS=false to disable.
     *
     * Reads LOG_PHP_ERRORS and PHP_ERROR_LOG from environment.
     * If PHP_ERROR_LOG is not absolute, it's relative to storage/logs.
     */
    private static function configurePhpErrorLog(): void
    {
        // Default to TRUE for enterprise apps - PHP errors should always be logged
        $logPhpErrors = $_ENV['LOG_PHP_ERRORS'] ?? getenv('LOG_PHP_ERRORS');

        // If explicitly set to false, skip
        if ($logPhpErrors !== null && $logPhpErrors !== '' && !filter_var($logPhpErrors, FILTER_VALIDATE_BOOLEAN)) {
            return;
        }

        // Get error log path
        $errorLog = $_ENV['PHP_ERROR_LOG'] ?? getenv('PHP_ERROR_LOG') ?: null;

        if ($errorLog === null || $errorLog === '') {
            // Default to storage/logs/php_errors.log
            $errorLog = self::$basePath . '/storage/logs/php_errors.log';
        } elseif (!str_starts_with($errorLog, '/')) {
            // Relative path - make it relative to storage/logs
            $errorLog = self::$basePath . '/storage/logs/' . $errorLog;
        }

        // Ensure directory exists
        $logDir = dirname($errorLog);
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        // Configure PHP error logging
        ini_set('log_errors', '1');
        ini_set('error_log', $errorLog);
        ini_set('display_errors', '0'); // Don't display, log instead

        // Report all errors in error log (regardless of display_errors)
        error_reporting(E_ALL);
    }

    /**
     * Load environment variables from .env file
     *
     * ENTERPRISE: Robust .env parser with:
     * - Comment support (# prefix)
     * - Quoted values (single/double quotes)
     * - Type casting (true, false, null, empty)
     * - Escape sequence handling in double quotes
     * - Whitespace tolerance
     * - Invalid line skipping (no crash)
     */
    private static function loadEnvironment(): void
    {
        $envPath = self::$basePath . '/.env';

        if (!file_exists($envPath)) {
            return;
        }

        $content = file_get_contents($envPath);
        if ($content === false) {
            return;
        }

        // Split into lines, preserving empty lines for accurate error reporting
        $lines = explode("\n", $content);

        foreach ($lines as $lineNumber => $line) {
            // Remove carriage return (Windows compatibility)
            $line = rtrim($line, "\r");

            // Trim leading/trailing whitespace
            $line = trim($line);

            // Skip empty lines and comments
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            // Must contain = sign
            $equalsPos = strpos($line, '=');
            if ($equalsPos === false) {
                continue;
            }

            // Extract key and value
            $key = trim(substr($line, 0, $equalsPos));
            $value = substr($line, $equalsPos + 1);

            // Validate key (must be valid env var name)
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key)) {
                continue;
            }

            // Process value
            $value = self::parseEnvValue($value);

            // Type casting for string values
            if (is_string($value)) {
                $lowerValue = strtolower($value);
                $value = match ($lowerValue) {
                    'true', '(true)' => true,
                    'false', '(false)' => false,
                    'null', '(null)' => null,
                    'empty', '(empty)' => '',
                    default => $value,
                };
            }

            $_ENV[$key] = $value;
            if (is_string($value) || is_numeric($value)) {
                putenv("{$key}={$value}");
            }
        }
    }

    /**
     * Parse a single .env value with proper quote handling
     */
    private static function parseEnvValue(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '';
        }

        // Double-quoted value: process escape sequences
        if (str_starts_with($value, '"') && str_ends_with($value, '"') && strlen($value) >= 2) {
            $value = substr($value, 1, -1);
            // Process escape sequences
            $value = str_replace(
                ['\\n', '\\r', '\\t', '\\"', '\\\\'],
                ["\n", "\r", "\t", '"', '\\'],
                $value
            );
            return $value;
        }

        // Single-quoted value: no escape processing (literal)
        if (str_starts_with($value, "'") && str_ends_with($value, "'") && strlen($value) >= 2) {
            return substr($value, 1, -1);
        }

        // Unquoted value: strip inline comments
        $commentPos = strpos($value, ' #');
        if ($commentPos !== false) {
            $value = rtrim(substr($value, 0, $commentPos));
        }

        return $value;
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
     * Configure LoggerFacade to create loggers with file handlers
     *
     * Special handling for 'security' channel:
     * - Writes to file (like all channels)
     * - ALSO writes to database table 'security_log' for audit compliance
     */
    private static function registerLoggerFacade(): void
    {
        $logDir = self::$basePath . '/storage/logs';

        // Ensure logs directory exists
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }

        LoggerFacade::setLoggerFactory(function (string $channel) use ($logDir): \AdosLabs\EnterprisePSR3Logger\Logger {
            // File handler for all channels
            $fileHandler = new \AdosLabs\EnterprisePSR3Logger\Handlers\StreamHandler(
                "{$logDir}/{$channel}-" . date('Y-m-d') . '.log',
                \Monolog\Level::Debug,
                useLocking: true
            );
            $fileHandler->setFormatter(new \AdosLabs\EnterprisePSR3Logger\Formatters\DetailedLineFormatter(multiLine: false));

            $handlers = [$fileHandler];

            // Security channel: also write to database for audit trail
            if ($channel === 'security') {
                try {
                    /** @var \AdosLabs\AdminPanel\Database\Pool\DatabasePool $pool */
                    $pool = Container::get('db.pool');
                    $connection = $pool->acquire();
                    $pdo = $connection->getPdo();

                    // SecurityDatabaseHandler writes to security_log table with
                    // attacker identification columns (ip_address, user_id, user_email, etc.)
                    $dbHandler = new \AdosLabs\EnterprisePSR3Logger\Handlers\SecurityDatabaseHandler(
                        $pdo,
                        \Monolog\Level::Debug,
                        true // bubble
                    );
                    $handlers[] = $dbHandler;

                    // Note: Connection is NOT released here - it stays in the handler
                    // The pool will reclaim it when the request ends
                } catch (\Throwable $e) {
                    // Database not available - log to file only
                    error_log("[Bootstrap] Security DB handler failed: " . $e->getMessage());
                }
            }

            $logger = new \AdosLabs\EnterprisePSR3Logger\Logger($channel, $handlers);

            // Add request processor for context
            $logger->addProcessor(new \AdosLabs\EnterprisePSR3Logger\Processors\RequestProcessor());

            return $logger;
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
