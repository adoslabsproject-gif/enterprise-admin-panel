<?php

/**
 * Enterprise Admin Panel - Database Pool Configuration
 *
 * Fluent builder for connection pool configuration.
 * Validates all parameters and provides sensible defaults.
 *
 * @package AdosLabs\AdminPanel\Database\Pool
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool;

use InvalidArgumentException;

final class PoolConfig
{
    // Connection settings
    private string $driver = 'pgsql';
    private string $host = 'localhost';
    private int $port = 5432;
    private string $database = '';
    private string $username = '';
    private string $password = '';
    private string $charset = 'utf8';

    // Pool sizing
    private int $minConnections = 2;
    private int $maxConnections = 10;
    private int $idleTimeout = 300;
    private int $maxLifetime = 3600;
    private int $waitTimeout = 5;

    // Health checks
    private int $validationInterval = 30;
    private bool $validateOnAcquire = false;

    // Retry logic
    private int $retryAttempts = 3;
    private int $retryDelayMs = 100;
    private int $retryMaxDelayMs = 2000;

    // Circuit breaker
    private int $circuitFailureThreshold = 5;
    private int $circuitRecoveryTime = 30;
    private int $circuitHalfOpenSuccesses = 2;

    // Performance
    private bool $persistent = true;
    private int $statementCacheSize = 100;
    private bool $warmOnInit = true;

    // Query protection
    private int $maxQuerySize = 1048576; // 1MB
    private int $maxParameters = 65535;
    private float $slowQueryThreshold = 1.0; // seconds

    // SSL/TLS
    private bool $sslEnabled = false;
    private ?string $sslCa = null;
    private ?string $sslCert = null;
    private ?string $sslKey = null;
    private bool $sslVerify = true;

    // Application metadata
    private string $applicationName = 'enterprise-admin-panel';
    private string $timezone = 'UTC';

    // Redis integration (DISABLED by default - enable explicitly if Redis is available)
    private bool $redisEnabled = false;
    private string $redisHost = 'localhost';
    private int $redisPort = 6379;
    private ?string $redisPassword = null;
    private int $redisDatabase = 0;
    private string $redisPrefix = 'eap:dbpool:';
    private float $redisTimeout = 2.5;

    /**
     * Create from DSN string
     */
    public static function fromDsn(string $dsn, string $username = '', string $password = ''): self
    {
        $config = new self();

        // Parse DSN: driver:host=x;port=y;dbname=z
        if (!preg_match('/^(\w+):/', $dsn, $matches)) {
            throw new InvalidArgumentException('Invalid DSN format');
        }

        $config->driver = $matches[1];

        // Extract components
        if (preg_match('/host=([^;]+)/', $dsn, $m)) {
            $config->host = $m[1];
        }
        if (preg_match('/port=(\d+)/', $dsn, $m)) {
            $config->port = (int) $m[1];
        }
        if (preg_match('/dbname=([^;]+)/', $dsn, $m)) {
            $config->database = $m[1];
        }
        if (preg_match('/charset=([^;]+)/', $dsn, $m)) {
            $config->charset = $m[1];
        }

        $config->username = $username;
        $config->password = $password;

        return $config;
    }

    /**
     * Create from array (Laravel/Symfony style)
     */
    public static function fromArray(array $config): self
    {
        $instance = new self();

        $mapping = [
            'driver' => 'driver',
            'host' => 'host',
            'port' => 'port',
            'database' => 'database',
            'username' => 'username',
            'password' => 'password',
            'charset' => 'charset',
            'pool_min' => 'minConnections',
            'pool_max' => 'maxConnections',
            'pool_size' => 'maxConnections',
            'idle_timeout' => 'idleTimeout',
            'max_lifetime' => 'maxLifetime',
            'wait_timeout' => 'waitTimeout',
            'validation_interval' => 'validationInterval',
            'validate_on_acquire' => 'validateOnAcquire',
            'retry_attempts' => 'retryAttempts',
            'retry_delay' => 'retryDelayMs',
            'circuit_failure_threshold' => 'circuitFailureThreshold',
            'circuit_recovery_time' => 'circuitRecoveryTime',
            'persistent' => 'persistent',
            'statement_cache_size' => 'statementCacheSize',
            'warm_on_init' => 'warmOnInit',
            'slow_query_threshold' => 'slowQueryThreshold',
            'ssl' => 'sslEnabled',
            'ssl_ca' => 'sslCa',
            'ssl_cert' => 'sslCert',
            'ssl_key' => 'sslKey',
            'ssl_verify' => 'sslVerify',
            'application_name' => 'applicationName',
            'timezone' => 'timezone',
            // Redis options
            'redis_enabled' => 'redisEnabled',
            'redis' => 'redisEnabled', // Alias
            'redis_host' => 'redisHost',
            'redis_port' => 'redisPort',
            'redis_password' => 'redisPassword',
            'redis_database' => 'redisDatabase',
            'redis_prefix' => 'redisPrefix',
            'redis_timeout' => 'redisTimeout',
        ];

        foreach ($mapping as $key => $property) {
            if (isset($config[$key])) {
                $instance->$property = $config[$key];
            }
        }

        return $instance;
    }

    /**
     * Validate configuration
     *
     * @throws InvalidArgumentException
     */
    public function validate(): void
    {
        if (empty($this->database)) {
            throw new InvalidArgumentException('Database name is required');
        }

        if ($this->minConnections < 0) {
            throw new InvalidArgumentException('Min connections must be >= 0');
        }

        if ($this->maxConnections < 1) {
            throw new InvalidArgumentException('Max connections must be >= 1');
        }

        if ($this->minConnections > $this->maxConnections) {
            throw new InvalidArgumentException('Min connections cannot exceed max connections');
        }

        if ($this->idleTimeout < 0) {
            throw new InvalidArgumentException('Idle timeout must be >= 0');
        }

        if ($this->maxLifetime < 0) {
            throw new InvalidArgumentException('Max lifetime must be >= 0');
        }

        // Max lifetime should be >= idle timeout (connection shouldn't die idle before max lifetime)
        if ($this->maxLifetime > 0 && $this->idleTimeout > 0 && $this->maxLifetime < $this->idleTimeout) {
            throw new InvalidArgumentException('Max lifetime must be >= idle timeout');
        }

        if ($this->waitTimeout <= 0) {
            throw new InvalidArgumentException('Wait timeout must be > 0');
        }

        if ($this->validationInterval < 0) {
            throw new InvalidArgumentException('Validation interval must be >= 0');
        }

        // Validation interval should be <= idle timeout to catch stale connections
        if ($this->validationInterval > 0 && $this->idleTimeout > 0 && $this->validationInterval > $this->idleTimeout) {
            throw new InvalidArgumentException('Validation interval should be <= idle timeout');
        }

        if (!in_array($this->driver, ['pgsql', 'mysql', 'sqlite'], true)) {
            throw new InvalidArgumentException("Unsupported driver: {$this->driver}");
        }
    }

    /**
     * Build PDO DSN string
     */
    public function buildDsn(): string
    {
        return match ($this->driver) {
            'pgsql' => $this->buildPgsqlDsn(),
            'mysql' => sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->host,
                $this->port,
                $this->database,
                $this->charset
            ),
            'sqlite' => sprintf('sqlite:%s', $this->database),
            default => throw new InvalidArgumentException("Unsupported driver: {$this->driver}"),
        };
    }

    /**
     * Build PostgreSQL DSN with SSL and TCP keepalive support
     *
     * TCP keepalives prevent "server closed the connection" errors
     * when connections sit idle behind firewalls/NAT or when
     * PostgreSQL's idle_session_timeout is configured.
     */
    private function buildPgsqlDsn(): string
    {
        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s',
            $this->host,
            $this->port,
            $this->database
        );

        // Connection timeout (5 seconds default)
        $dsn .= ';connect_timeout=5';

        // TCP keepalives - prevent idle connection closures
        // keepalives=1 enables TCP keepalives
        // keepalives_idle=30 sends first keepalive after 30s idle
        // keepalives_interval=10 sends keepalive every 10s after first
        // keepalives_count=3 closes connection after 3 failed keepalives
        $dsn .= ';keepalives=1;keepalives_idle=30;keepalives_interval=10;keepalives_count=3';

        // PostgreSQL SSL is configured via DSN parameters
        if ($this->sslEnabled) {
            // sslmode: disable, allow, prefer, require, verify-ca, verify-full
            $sslMode = $this->sslVerify ? 'verify-full' : 'require';
            $dsn .= ";sslmode={$sslMode}";

            if ($this->sslCa) {
                $dsn .= ";sslrootcert={$this->sslCa}";
            }
            if ($this->sslCert) {
                $dsn .= ";sslcert={$this->sslCert}";
            }
            if ($this->sslKey) {
                $dsn .= ";sslkey={$this->sslKey}";
            }
        }

        return $dsn;
    }

    // Fluent setters

    public function driver(string $driver): self
    {
        $this->driver = $driver;
        return $this;
    }

    public function host(string $host): self
    {
        $this->host = $host;
        return $this;
    }

    public function port(int $port): self
    {
        $this->port = $port;
        return $this;
    }

    public function database(string $database): self
    {
        $this->database = $database;
        return $this;
    }

    public function credentials(string $username, string $password): self
    {
        $this->username = $username;
        $this->password = $password;
        return $this;
    }

    public function poolSize(int $min, int $max): self
    {
        $this->minConnections = $min;
        $this->maxConnections = $max;
        return $this;
    }

    public function timeouts(int $idle, int $maxLifetime, int $wait): self
    {
        $this->idleTimeout = $idle;
        $this->maxLifetime = $maxLifetime;
        $this->waitTimeout = $wait;
        return $this;
    }

    public function circuitBreaker(int $threshold, int $recoveryTime): self
    {
        $this->circuitFailureThreshold = $threshold;
        $this->circuitRecoveryTime = $recoveryTime;
        return $this;
    }

    public function ssl(bool $enabled, ?string $ca = null, bool $verify = true): self
    {
        $this->sslEnabled = $enabled;
        $this->sslCa = $ca;
        $this->sslVerify = $verify;
        return $this;
    }

    public function sslCertificates(string $cert, string $key): self
    {
        $this->sslCert = $cert;
        $this->sslKey = $key;
        return $this;
    }

    public function applicationName(string $name): self
    {
        $this->applicationName = $name;
        return $this;
    }

    // Getters

    public function getDriver(): string
    {
        return $this->driver;
    }

    public function getHost(): string
    {
        return $this->host;
    }

    public function getPort(): int
    {
        return $this->port;
    }

    public function getDatabase(): string
    {
        return $this->database;
    }

    public function getUsername(): string
    {
        return $this->username;
    }

    public function getPassword(): string
    {
        return $this->password;
    }

    public function getCharset(): string
    {
        return $this->charset;
    }

    public function getMinConnections(): int
    {
        return $this->minConnections;
    }

    public function getMaxConnections(): int
    {
        return $this->maxConnections;
    }

    public function getIdleTimeout(): int
    {
        return $this->idleTimeout;
    }

    public function getMaxLifetime(): int
    {
        return $this->maxLifetime;
    }

    public function getWaitTimeout(): int
    {
        return $this->waitTimeout;
    }

    public function getValidationInterval(): int
    {
        return $this->validationInterval;
    }

    public function shouldValidateOnAcquire(): bool
    {
        return $this->validateOnAcquire;
    }

    public function getRetryAttempts(): int
    {
        return $this->retryAttempts;
    }

    public function getRetryDelayMs(): int
    {
        return $this->retryDelayMs;
    }

    public function getRetryMaxDelayMs(): int
    {
        return $this->retryMaxDelayMs;
    }

    public function getCircuitFailureThreshold(): int
    {
        return $this->circuitFailureThreshold;
    }

    public function getCircuitRecoveryTime(): int
    {
        return $this->circuitRecoveryTime;
    }

    public function getCircuitHalfOpenSuccesses(): int
    {
        return $this->circuitHalfOpenSuccesses;
    }

    public function isPersistent(): bool
    {
        return $this->persistent;
    }

    public function getStatementCacheSize(): int
    {
        return $this->statementCacheSize;
    }

    public function shouldWarmOnInit(): bool
    {
        return $this->warmOnInit;
    }

    public function getMaxQuerySize(): int
    {
        return $this->maxQuerySize;
    }

    public function getMaxParameters(): int
    {
        return $this->maxParameters;
    }

    public function getSlowQueryThreshold(): float
    {
        return $this->slowQueryThreshold;
    }

    public function isSslEnabled(): bool
    {
        return $this->sslEnabled;
    }

    public function getSslCa(): ?string
    {
        return $this->sslCa;
    }

    public function getSslCert(): ?string
    {
        return $this->sslCert;
    }

    public function getSslKey(): ?string
    {
        return $this->sslKey;
    }

    public function shouldSslVerify(): bool
    {
        return $this->sslVerify;
    }

    public function getApplicationName(): string
    {
        return $this->applicationName;
    }

    public function getTimezone(): string
    {
        return $this->timezone;
    }

    // Redis getters and setters

    public function isRedisEnabled(): bool
    {
        return $this->redisEnabled;
    }

    public function getRedisHost(): string
    {
        return $this->redisHost;
    }

    public function getRedisPort(): int
    {
        return $this->redisPort;
    }

    public function getRedisPassword(): ?string
    {
        return $this->redisPassword;
    }

    public function getRedisDatabase(): int
    {
        return $this->redisDatabase;
    }

    public function getRedisPrefix(): string
    {
        return $this->redisPrefix;
    }

    public function getRedisTimeout(): float
    {
        return $this->redisTimeout;
    }

    /**
     * Configure Redis connection
     *
     * @param bool $enabled Enable Redis (true by default)
     * @param string $host Redis host
     * @param int $port Redis port
     * @param string|null $password Redis password
     * @param int $database Redis database number
     * @return self
     */
    public function redis(
        bool $enabled = true,
        string $host = 'localhost',
        int $port = 6379,
        ?string $password = null,
        int $database = 0
    ): self {
        $this->redisEnabled = $enabled;
        $this->redisHost = $host;
        $this->redisPort = $port;
        $this->redisPassword = $password;
        $this->redisDatabase = $database;
        return $this;
    }

    /**
     * Disable Redis (use local-only mode)
     */
    public function disableRedis(): self
    {
        $this->redisEnabled = false;
        return $this;
    }

    /**
     * Get Redis configuration array for RedisStateManager
     */
    public function getRedisConfig(): array
    {
        return [
            'host' => $this->redisHost,
            'port' => $this->redisPort,
            'password' => $this->redisPassword,
            'database' => $this->redisDatabase,
            'prefix' => $this->redisPrefix,
            'timeout' => $this->redisTimeout,
        ];
    }
}
