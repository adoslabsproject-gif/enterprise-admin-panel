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
            'pgsql' => sprintf(
                'pgsql:host=%s;port=%d;dbname=%s',
                $this->host,
                $this->port,
                $this->database
            ),
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
}
