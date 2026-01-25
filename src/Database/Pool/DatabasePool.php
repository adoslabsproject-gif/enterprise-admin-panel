<?php

/**
 * Enterprise Admin Panel - Database Connection Pool
 *
 * Enterprise-grade connection pool with:
 * - Real connection pooling with LIFO stack
 * - Circuit breaker for failure isolation
 * - Exponential backoff retry
 * - Prepared statement caching
 * - Query validation (DoS protection)
 * - Slow query detection
 * - SSL/TLS support
 * - Comprehensive metrics
 *
 * @package AdosLabs\AdminPanel\Database\Pool
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool;

use PDO;
use PDOException;
use PDOStatement;
use AdosLabs\AdminPanel\Database\Pool\Exceptions\CircuitBreakerOpenException;
use AdosLabs\AdminPanel\Database\Pool\Exceptions\PoolExhaustedException;
use AdosLabs\AdminPanel\Database\Pool\Exceptions\QueryValidationException;

final class DatabasePool
{
    /**
     * Pool of connections (LIFO stack for locality)
     * @var PooledConnection[]
     */
    private array $pool = [];

    /**
     * Circuit breaker instance
     */
    private CircuitBreaker $circuitBreaker;

    /**
     * Prepared statement cache
     * @var array<string, PDOStatement>
     */
    private array $statementCache = [];

    /**
     * Connection counter for unique IDs
     */
    private int $connectionCounter = 0;

    /**
     * Metrics
     */
    private int $totalQueries = 0;
    private int $slowQueries = 0;
    private int $failedQueries = 0;
    private int $poolHits = 0;
    private int $poolMisses = 0;
    private int $connectionsCreated = 0;
    private int $connectionsFailed = 0;
    private int $validationsPassed = 0;
    private int $validationsFailed = 0;
    private int $transactionRollbacks = 0;
    private int $dosBlockedQueries = 0;
    private float $totalQueryTime = 0.0;

    /**
     * PDO options for maximum performance and security
     */
    private const PDO_OPTIONS = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => false,
    ];

    public function __construct(
        private readonly PoolConfig $config
    ) {
        $this->config->validate();

        $this->circuitBreaker = new CircuitBreaker(
            $this->config->getCircuitFailureThreshold(),
            $this->config->getCircuitRecoveryTime(),
            $this->config->getCircuitHalfOpenSuccesses()
        );

        if ($this->config->shouldWarmOnInit()) {
            $this->warmPool();
        }

        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * Create pool from configuration array
     */
    public static function create(array $config): self
    {
        return new self(PoolConfig::fromArray($config));
    }

    /**
     * Warm the pool with minimum connections
     */
    public function warmPool(): void
    {
        $min = $this->config->getMinConnections();

        for ($i = count($this->pool); $i < $min; $i++) {
            try {
                $this->createConnection();
            } catch (PDOException $e) {
                // Log but don't fail - pool can grow on demand
                error_log("[DB Pool] Warm-up failed: " . $e->getMessage());
                break;
            }
        }
    }

    /**
     * Acquire a connection from the pool
     *
     * LIFO order for CPU cache locality.
     *
     * @throws CircuitBreakerOpenException
     * @throws PoolExhaustedException
     * @throws PDOException
     */
    public function acquire(): PooledConnection
    {
        // Check circuit breaker
        if (!$this->circuitBreaker->allowRequest()) {
            throw new CircuitBreakerOpenException(
                'default',
                $this->circuitBreaker->getFailureCount(),
                $this->circuitBreaker->getTimeUntilRecovery()
            );
        }

        // Try to get idle connection from pool (LIFO)
        for ($i = count($this->pool) - 1; $i >= 0; $i--) {
            $conn = $this->pool[$i];

            if (!$conn->isIdle()) {
                continue;
            }

            // Validate if needed
            if ($this->needsValidation($conn)) {
                if (!$this->validateConnection($conn)) {
                    $this->removeConnection($i);
                    continue;
                }
            }

            $conn->acquire();
            $this->poolHits++;
            $this->circuitBreaker->recordSuccess();
            return $conn;
        }

        // Create new connection if pool not full
        if (count($this->pool) < $this->config->getMaxConnections()) {
            try {
                $conn = $this->createConnection();
                $conn->acquire();
                $this->poolMisses++;
                $this->circuitBreaker->recordSuccess();
                return $conn;
            } catch (PDOException $e) {
                $this->circuitBreaker->recordFailure();
                $this->connectionsFailed++;
                throw $this->retryConnection($e);
            }
        }

        // Wait for available connection
        return $this->waitForConnection();
    }

    /**
     * Release a connection back to the pool
     */
    public function release(PooledConnection $connection): void
    {
        try {
            $connection->release();
        } catch (PDOException $e) {
            // Transaction rollback failed, remove connection
            $this->transactionRollbacks++;
            $this->removeConnectionByIdentifier($connection->getIdentifier());
        }
    }

    /**
     * Execute a query with full validation and metrics
     *
     * @param string $sql SQL query
     * @param array<string|int, mixed> $params Parameters
     * @return array<int, array<string, mixed>> Results
     *
     * @throws QueryValidationException
     * @throws PDOException
     */
    public function query(string $sql, array $params = []): array
    {
        $this->validateQuery($sql, $params);

        $startTime = microtime(true);
        $connection = $this->acquire();

        try {
            $stmt = $this->prepareStatement($connection->getPdo(), $sql);
            $this->bindParameters($stmt, $params);
            $stmt->execute();
            $result = $stmt->fetchAll();

            $duration = microtime(true) - $startTime;
            $this->recordQueryMetrics($connection, $duration);

            return $result;
        } catch (PDOException $e) {
            $this->failedQueries++;
            $this->circuitBreaker->recordFailure();
            throw $e;
        } finally {
            $this->release($connection);
        }
    }

    /**
     * Execute a statement (INSERT, UPDATE, DELETE)
     *
     * @param string $sql SQL statement
     * @param array<string|int, mixed> $params Parameters
     * @return int Affected rows
     *
     * @throws QueryValidationException
     * @throws PDOException
     */
    public function execute(string $sql, array $params = []): int
    {
        $this->validateQuery($sql, $params);

        $startTime = microtime(true);
        $connection = $this->acquire();

        try {
            $stmt = $this->prepareStatement($connection->getPdo(), $sql);
            $this->bindParameters($stmt, $params);
            $stmt->execute();
            $result = $stmt->rowCount();

            $duration = microtime(true) - $startTime;
            $this->recordQueryMetrics($connection, $duration);

            return $result;
        } catch (PDOException $e) {
            $this->failedQueries++;
            $this->circuitBreaker->recordFailure();
            throw $e;
        } finally {
            $this->release($connection);
        }
    }

    /**
     * Begin a transaction
     */
    public function beginTransaction(): PooledConnection
    {
        $connection = $this->acquire();
        $connection->getPdo()->beginTransaction();
        $connection->markTransactionStarted();
        return $connection;
    }

    /**
     * Commit a transaction
     */
    public function commit(PooledConnection $connection): void
    {
        $connection->getPdo()->commit();
        $connection->markTransactionEnded();
        $this->release($connection);
    }

    /**
     * Rollback a transaction
     */
    public function rollback(PooledConnection $connection): void
    {
        $connection->getPdo()->rollBack();
        $connection->markTransactionEnded();
        $this->transactionRollbacks++;
        $this->release($connection);
    }

    /**
     * Validate query for DoS protection
     *
     * @throws QueryValidationException
     */
    private function validateQuery(string $sql, array $params): void
    {
        $size = strlen($sql);
        if ($size > $this->config->getMaxQuerySize()) {
            $this->dosBlockedQueries++;
            throw QueryValidationException::querySizeExceeded(
                $size,
                $this->config->getMaxQuerySize()
            );
        }

        $count = count($params);
        if ($count > $this->config->getMaxParameters()) {
            $this->dosBlockedQueries++;
            throw QueryValidationException::parameterCountExceeded(
                $count,
                $this->config->getMaxParameters()
            );
        }
    }

    /**
     * Create a new pooled connection
     */
    private function createConnection(): PooledConnection
    {
        $startTime = microtime(true);

        $options = self::PDO_OPTIONS;

        if ($this->config->isPersistent()) {
            $options[PDO::ATTR_PERSISTENT] = 'pool_' . $this->connectionCounter;
        }

        // SSL options
        if ($this->config->isSslEnabled()) {
            $options = $this->applySslOptions($options);
        }

        $pdo = new PDO(
            $this->config->buildDsn(),
            $this->config->getUsername(),
            $this->config->getPassword(),
            $options
        );

        $this->configureConnection($pdo);

        $identifier = 'conn_' . (++$this->connectionCounter);
        $pooled = new PooledConnection(
            $pdo,
            $this->config->getMaxLifetime(),
            $identifier
        );

        $this->pool[] = $pooled;
        $this->connectionsCreated++;

        // Log slow connection creation
        $duration = microtime(true) - $startTime;
        if ($duration > 1.0) {
            error_log("[DB Pool] Slow connection creation: {$duration}s");
        }

        return $pooled;
    }

    /**
     * Apply SSL options based on driver
     */
    private function applySslOptions(array $options): array
    {
        $driver = $this->config->getDriver();

        if ($driver === 'mysql') {
            if ($this->config->getSslCa()) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $this->config->getSslCa();
            }
            if ($this->config->getSslCert()) {
                $options[PDO::MYSQL_ATTR_SSL_CERT] = $this->config->getSslCert();
            }
            if ($this->config->getSslKey()) {
                $options[PDO::MYSQL_ATTR_SSL_KEY] = $this->config->getSslKey();
            }
            if ($this->config->shouldSslVerify()) {
                $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
            }
        }

        // PostgreSQL SSL is configured via DSN, not PDO options

        return $options;
    }

    /**
     * Configure connection with driver-specific optimizations
     */
    private function configureConnection(PDO $pdo): void
    {
        $driver = $this->config->getDriver();
        $timezone = $this->config->getTimezone();
        $appName = $this->config->getApplicationName();

        if ($driver === 'pgsql') {
            $pdo->exec("SET client_encoding = 'UTF8'");
            $pdo->exec("SET timezone = '{$timezone}'");
            $pdo->exec("SET application_name = '{$appName}'");
        }

        if ($driver === 'mysql') {
            $charset = $this->config->getCharset();
            $pdo->exec("SET NAMES {$charset} COLLATE {$charset}_unicode_ci");
            $pdo->exec("SET time_zone = '+00:00'");
            $pdo->exec("SET SESSION sql_mode = 'STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ZERO_IN_DATE,ERROR_FOR_DIVISION_BY_ZERO'");
        }

        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA foreign_keys = ON');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        }
    }

    /**
     * Check if connection needs validation
     */
    private function needsValidation(PooledConnection $conn): bool
    {
        if ($this->config->shouldValidateOnAcquire()) {
            return true;
        }

        return $conn->getSecondsSinceLastValidation() > $this->config->getValidationInterval();
    }

    /**
     * Validate connection is still alive
     */
    private function validateConnection(PooledConnection $conn): bool
    {
        $result = $conn->ping();

        if ($result) {
            $this->validationsPassed++;
        } else {
            $this->validationsFailed++;
        }

        return $result;
    }

    /**
     * Remove connection from pool by index
     */
    private function removeConnection(int $index): void
    {
        array_splice($this->pool, $index, 1);
    }

    /**
     * Remove connection by identifier
     */
    private function removeConnectionByIdentifier(string $identifier): void
    {
        foreach ($this->pool as $i => $conn) {
            if ($conn->getIdentifier() === $identifier) {
                $this->removeConnection($i);
                return;
            }
        }
    }

    /**
     * Wait for an available connection
     *
     * @throws PoolExhaustedException
     */
    private function waitForConnection(): PooledConnection
    {
        $timeout = $this->config->getWaitTimeout();
        $startTime = microtime(true);

        while ((microtime(true) - $startTime) < $timeout) {
            foreach ($this->pool as $conn) {
                if ($conn->isIdle()) {
                    $conn->acquire();
                    $this->poolHits++;
                    return $conn;
                }
            }

            usleep(10000); // 10ms
        }

        throw new PoolExhaustedException(
            'default',
            $this->config->getMaxConnections(),
            $timeout
        );
    }

    /**
     * Retry connection with exponential backoff
     *
     * @throws PDOException
     */
    private function retryConnection(PDOException $lastError): PDOException
    {
        $attempts = $this->config->getRetryAttempts();
        $delay = $this->config->getRetryDelayMs();
        $maxDelay = $this->config->getRetryMaxDelayMs();

        // Check if error is retryable (not authentication errors)
        $message = strtolower($lastError->getMessage());
        if (
            str_contains($message, 'access denied') ||
            str_contains($message, 'authentication')
        ) {
            return $lastError;
        }

        for ($i = 1; $i <= $attempts; $i++) {
            // Exponential backoff with jitter
            $sleepMs = min($delay * (2 ** ($i - 1)), $maxDelay);
            $sleepMs += random_int(0, (int) ($sleepMs * 0.1));

            usleep($sleepMs * 1000);

            try {
                $conn = $this->createConnection();
                $conn->acquire();
                $this->circuitBreaker->recordSuccess();
                return $lastError; // This won't be reached
            } catch (PDOException $e) {
                $lastError = $e;
                $this->circuitBreaker->recordFailure();
            }
        }

        return $lastError;
    }

    /**
     * Prepare statement with caching
     */
    private function prepareStatement(PDO $pdo, string $sql): PDOStatement
    {
        $cacheKey = md5($sql);

        if (isset($this->statementCache[$cacheKey])) {
            return $this->statementCache[$cacheKey];
        }

        $stmt = $pdo->prepare($sql);

        // LRU eviction if cache full
        if (count($this->statementCache) >= $this->config->getStatementCacheSize()) {
            array_shift($this->statementCache);
        }

        $this->statementCache[$cacheKey] = $stmt;

        return $stmt;
    }

    /**
     * Bind parameters with type awareness
     *
     * @param array<string|int, mixed> $params
     */
    private function bindParameters(PDOStatement $stmt, array $params): void
    {
        foreach ($params as $key => $value) {
            $type = match (true) {
                is_null($value) => PDO::PARAM_NULL,
                is_bool($value) => PDO::PARAM_BOOL,
                is_int($value) => PDO::PARAM_INT,
                default => PDO::PARAM_STR,
            };

            if (is_int($key)) {
                $stmt->bindValue($key + 1, $value, $type);
            } else {
                $stmt->bindValue($key, $value, $type);
            }
        }
    }

    /**
     * Record query metrics
     */
    private function recordQueryMetrics(PooledConnection $connection, float $duration): void
    {
        $this->totalQueries++;
        $this->totalQueryTime += $duration;

        $connection->recordQuery($duration);

        if ($duration > $this->config->getSlowQueryThreshold()) {
            $this->slowQueries++;
            error_log(sprintf('[DB Pool] Slow query: %.3fs', $duration));
        }
    }

    /**
     * Clean up idle connections
     */
    public function cleanupIdleConnections(): int
    {
        $closed = 0;
        $idleTimeout = $this->config->getIdleTimeout();
        $minConnections = $this->config->getMinConnections();

        for ($i = count($this->pool) - 1; $i >= 0; $i--) {
            if (count($this->pool) <= $minConnections) {
                break;
            }

            $conn = $this->pool[$i];

            if (!$conn->isIdle()) {
                continue;
            }

            if ($conn->getIdleTime() > $idleTimeout || $conn->shouldRefresh()) {
                $this->removeConnection($i);
                $closed++;
            }
        }

        return $closed;
    }

    /**
     * Get pool statistics
     */
    public function getStats(): array
    {
        $idle = 0;
        $inUse = 0;

        foreach ($this->pool as $conn) {
            if ($conn->isIdle()) {
                $idle++;
            } else {
                $inUse++;
            }
        }

        return [
            'config' => [
                'driver' => $this->config->getDriver(),
                'host' => $this->config->getHost(),
                'database' => $this->config->getDatabase(),
                'min_connections' => $this->config->getMinConnections(),
                'max_connections' => $this->config->getMaxConnections(),
            ],
            'pool' => [
                'total' => count($this->pool),
                'idle' => $idle,
                'in_use' => $inUse,
                'available' => $this->config->getMaxConnections() - count($this->pool),
            ],
            'metrics' => [
                'total_queries' => $this->totalQueries,
                'slow_queries' => $this->slowQueries,
                'failed_queries' => $this->failedQueries,
                'pool_hits' => $this->poolHits,
                'pool_misses' => $this->poolMisses,
                'hit_rate' => $this->poolHits + $this->poolMisses > 0
                    ? round($this->poolHits / ($this->poolHits + $this->poolMisses) * 100, 2)
                    : 0,
                'connections_created' => $this->connectionsCreated,
                'connections_failed' => $this->connectionsFailed,
                'validations_passed' => $this->validationsPassed,
                'validations_failed' => $this->validationsFailed,
                'transaction_rollbacks' => $this->transactionRollbacks,
                'dos_blocked_queries' => $this->dosBlockedQueries,
                'total_query_time_ms' => round($this->totalQueryTime * 1000, 3),
                'avg_query_time_ms' => $this->totalQueries > 0
                    ? round(($this->totalQueryTime / $this->totalQueries) * 1000, 3)
                    : 0,
            ],
            'circuit_breaker' => $this->circuitBreaker->getStats(),
        ];
    }

    /**
     * Reset circuit breaker
     */
    public function resetCircuitBreaker(): void
    {
        $this->circuitBreaker->reset();
    }

    /**
     * Drain the pool for graceful shutdown (zero-downtime deployments)
     *
     * Waits for all active connections to be released, then closes them.
     * New connection requests during drain will fail immediately.
     *
     * @param int $timeout Maximum seconds to wait for connections to drain
     * @return bool True if all connections drained, false if timeout
     */
    public function drain(int $timeout = 30): bool
    {
        $draining = true;
        $startTime = microtime(true);

        error_log('[DB Pool] Starting graceful drain (timeout: ' . $timeout . 's)');

        // First, force circuit breaker open to reject new requests
        $this->circuitBreaker->forceOpen();

        // Wait for active connections to be released
        while ((microtime(true) - $startTime) < $timeout) {
            $activeCount = 0;

            foreach ($this->pool as $conn) {
                if ($conn->isInUse()) {
                    $activeCount++;
                }
            }

            if ($activeCount === 0) {
                // All connections idle - safe to close
                $this->shutdown();
                error_log('[DB Pool] Drain completed successfully');
                return true;
            }

            // Log progress
            if ((int)(microtime(true) - $startTime) % 5 === 0) {
                error_log("[DB Pool] Draining... {$activeCount} connections still active");
            }

            usleep(100000); // 100ms
        }

        // Timeout - force close remaining
        error_log('[DB Pool] Drain timeout - forcing shutdown');
        $this->shutdown();
        return false;
    }

    /**
     * Schedule periodic idle connection cleanup
     *
     * This method should be called periodically (e.g., every 60 seconds)
     * in long-running processes like Swoole/RoadRunner workers.
     *
     * For PHP-FPM, this is less critical as connections are cleaned up
     * at the end of each request anyway.
     *
     * @param int $intervalSeconds Cleanup interval (default: 60)
     * @return callable Returns a cleanup function for use with timers
     */
    public function getPeriodicCleanupCallback(int $intervalSeconds = 60): callable
    {
        return function () use ($intervalSeconds): void {
            $closed = $this->cleanupIdleConnections();
            if ($closed > 0) {
                error_log("[DB Pool] Periodic cleanup: closed {$closed} idle connections");
            }
        };
    }

    /**
     * Shutdown handler - close all connections
     */
    public function shutdown(): void
    {
        foreach ($this->pool as $conn) {
            if ($conn->isInTransaction()) {
                try {
                    $conn->getPdo()->rollBack();
                    $this->transactionRollbacks++;
                } catch (PDOException $e) {
                    // Ignore
                }
            }
        }

        $this->pool = [];
        $this->statementCache = [];
    }

    /**
     * Check if pool is healthy and accepting connections
     */
    public function isHealthy(): bool
    {
        return $this->circuitBreaker->getState() !== CircuitBreaker::STATE_OPEN;
    }

    /**
     * Get pool health summary for monitoring
     */
    public function getHealthSummary(): array
    {
        $stats = $this->getStats();

        return [
            'healthy' => $this->isHealthy(),
            'circuit_breaker_state' => $stats['circuit_breaker']['state'],
            'pool_utilization' => $stats['pool']['total'] > 0
                ? round($stats['pool']['in_use'] / $stats['pool']['total'] * 100, 2)
                : 0,
            'available_connections' => $stats['pool']['idle'] + $stats['pool']['available'],
            'error_rate' => $stats['metrics']['total_queries'] > 0
                ? round($stats['metrics']['failed_queries'] / $stats['metrics']['total_queries'] * 100, 2)
                : 0,
            'avg_query_time_ms' => $stats['metrics']['avg_query_time_ms'],
        ];
    }
}
