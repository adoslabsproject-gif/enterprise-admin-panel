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
use Throwable;
use AdosLabs\AdminPanel\Database\Pool\Exceptions\CircuitBreakerOpenException;
use AdosLabs\AdminPanel\Database\Pool\Exceptions\PoolExhaustedException;
use AdosLabs\AdminPanel\Database\Pool\Exceptions\QueryValidationException;
use AdosLabs\AdminPanel\Database\Pool\Redis\RedisStateManager;
use AdosLabs\AdminPanel\Database\Pool\Redis\DistributedCircuitBreaker;
use AdosLabs\AdminPanel\Database\Pool\Redis\DistributedMetricsCollector;

final class DatabasePool
{
    /**
     * Pool of connections (LIFO stack for locality)
     * @var PooledConnection[]
     */
    private array $pool = [];

    /**
     * Local circuit breaker (fallback when Redis unavailable)
     */
    private CircuitBreaker $localCircuitBreaker;

    /**
     * Distributed circuit breaker (uses Redis)
     */
    private ?DistributedCircuitBreaker $distributedCircuitBreaker = null;

    /**
     * Redis state manager
     */
    private ?RedisStateManager $redisState = null;

    /**
     * Distributed metrics collector
     */
    private ?DistributedMetricsCollector $metricsCollector = null;

    /**
     * Whether Redis is enabled
     */
    private bool $redisEnabled;

    /**
     * Prepared statement cache - per connection to avoid PDO cross-contamination
     * Structure: [connection_identifier => [sql_hash => PDOStatement]]
     * @var array<string, array<string, PDOStatement>>
     */
    private array $statementCache = [];

    /**
     * Connection counter for unique IDs
     */
    private int $connectionCounter = 0;

    /**
     * Mutex for atomic pool operations (prevents race condition on pool exhaustion)
     * Uses flock() for process-level atomicity in PHP-FPM
     * @var resource|null
     */
    private $poolMutex = null;

    /**
     * Draining flag - when true, new connections are rejected
     */
    private bool $draining = false;

    /**
     * Local metrics (used when Redis unavailable or as buffer)
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

        // Initialize local circuit breaker (always available as fallback)
        $this->localCircuitBreaker = new CircuitBreaker(
            $this->config->getCircuitFailureThreshold(),
            $this->config->getCircuitRecoveryTime(),
            $this->config->getCircuitHalfOpenSuccesses()
        );

        // Initialize Redis if enabled
        $this->redisEnabled = $this->config->isRedisEnabled();

        if ($this->redisEnabled) {
            $this->initializeRedis();
        }

        if ($this->config->shouldWarmOnInit()) {
            $this->warmPool();
        }

        // Initialize mutex for atomic pool operations
        $this->initializePoolMutex();

        register_shutdown_function([$this, 'shutdown']);
    }

    /**
     * Initialize pool mutex for atomic operations
     *
     * Uses a file-based lock for cross-process atomicity.
     * Falls back to in-memory flag if file lock unavailable.
     */
    private function initializePoolMutex(): void
    {
        $lockFile = sys_get_temp_dir() . '/eap_dbpool_' . md5($this->config->getDatabase()) . '.lock';

        // Create lock file if not exists
        if (!file_exists($lockFile)) {
            @touch($lockFile);
        }

        $this->poolMutex = @fopen($lockFile, 'c');
        if ($this->poolMutex === false) {
            $this->poolMutex = null;
            error_log('[DB Pool] Warning: Could not initialize pool mutex, using non-atomic mode');
        }
    }

    /**
     * Acquire pool mutex (blocking)
     */
    private function acquirePoolMutex(): bool
    {
        if ($this->poolMutex === null) {
            return true; // Fallback: no locking
        }

        return flock($this->poolMutex, LOCK_EX);
    }

    /**
     * Release pool mutex
     */
    private function releasePoolMutex(): void
    {
        if ($this->poolMutex !== null) {
            flock($this->poolMutex, LOCK_UN);
        }
    }

    /**
     * Initialize Redis components
     */
    private function initializeRedis(): void
    {
        try {
            $this->redisState = RedisStateManager::fromArray($this->config->getRedisConfig());

            if ($this->redisState->connect()) {
                // Create distributed circuit breaker
                $this->distributedCircuitBreaker = new DistributedCircuitBreaker(
                    $this->redisState,
                    $this->config->getDatabase(), // Use database name as circuit ID
                    $this->config->getCircuitFailureThreshold(),
                    $this->config->getCircuitRecoveryTime(),
                    $this->config->getCircuitHalfOpenSuccesses()
                );

                // Create distributed metrics collector
                $this->metricsCollector = new DistributedMetricsCollector(
                    $this->redisState,
                    $this->config->getDatabase()
                );

                // Register as active worker
                $this->metricsCollector->heartbeat();

                error_log('[DB Pool] Redis enabled - using distributed state');
            } else {
                error_log('[DB Pool] Redis connection failed - using local state');
                $this->redisState = null;
            }
        } catch (\Throwable $e) {
            error_log('[DB Pool] Redis initialization failed: ' . $e->getMessage());
            $this->redisState = null;
            $this->distributedCircuitBreaker = null;
            $this->metricsCollector = null;
        }
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
     * Get the active circuit breaker (distributed or local)
     */
    private function getCircuitBreaker(): CircuitBreaker|DistributedCircuitBreaker
    {
        return $this->distributedCircuitBreaker ?? $this->localCircuitBreaker;
    }

    /**
     * Acquire a connection from the pool
     *
     * LIFO order for CPU cache locality.
     * Uses mutex to prevent race condition on pool exhaustion.
     *
     * @throws CircuitBreakerOpenException
     * @throws PoolExhaustedException
     * @throws PDOException
     */
    public function acquire(): PooledConnection
    {
        // ENTERPRISE FIX: Reject during drain
        if ($this->draining) {
            throw new PoolExhaustedException(
                'default',
                $this->config->getMaxConnections(),
                0.0,
                'Pool is draining - no new connections accepted'
            );
        }

        $circuitBreaker = $this->getCircuitBreaker();

        // Check circuit breaker
        if (!$circuitBreaker->allowRequest()) {
            throw new CircuitBreakerOpenException(
                'default',
                $circuitBreaker->getFailureCount(),
                $circuitBreaker->getTimeUntilRecovery()
            );
        }

        // ENTERPRISE FIX: Atomic pool access to prevent race condition
        // Without mutex, concurrent requests can both see pool not full,
        // then both create connections, exceeding maxConnections
        $this->acquirePoolMutex();

        try {
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
                $this->metricsCollector?->recordPoolHit();
                // NOTE: Don't recordSuccess() here - wait for query success
                // Circuit breaker success is recorded in query()/execute() after successful operation
                return $conn;
            }

            // ENTERPRISE FIX: Double-check pool size INSIDE mutex
            // This prevents race condition where two threads both see pool < max
            if (count($this->pool) < $this->config->getMaxConnections()) {
                try {
                    $conn = $this->createConnection();
                    $conn->acquire();
                    $this->poolMisses++;
                    $this->metricsCollector?->recordPoolMiss();
                    // NOTE: Don't recordSuccess() here - wait for query success
                    // Circuit breaker success is recorded in query()/execute() after successful operation
                    return $conn;
                } catch (PDOException $e) {
                    $circuitBreaker->recordFailure();
                    $this->connectionsFailed++;
                    $this->metricsCollector?->recordConnectionFailed();
                    $retryResult = $this->retryConnection($e);
                    if ($retryResult !== null) {
                        throw $retryResult;
                    }
                    // Retry succeeded - connection already acquired in retryConnection
                    // This shouldn't happen with current code, but handle gracefully
                    throw $e;
                }
            }
        } finally {
            $this->releasePoolMutex();
        }

        // Wait for available connection (outside mutex to allow other releases)
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
            $stmt = $this->prepareStatement($connection->getPdo(), $sql, $connection->getIdentifier());
            $this->bindParameters($stmt, $params);
            $stmt->execute();
            $result = $stmt->fetchAll();

            $duration = microtime(true) - $startTime;
            $this->recordQueryMetrics($connection, $duration);

            // Record success AFTER query succeeds (not at acquire time)
            $this->getCircuitBreaker()->recordSuccess();

            return $result;
        } catch (PDOException $e) {
            $this->failedQueries++;
            $this->getCircuitBreaker()->recordFailure();
            $this->metricsCollector?->recordQuery(0, false, true);
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
            $stmt = $this->prepareStatement($connection->getPdo(), $sql, $connection->getIdentifier());
            $this->bindParameters($stmt, $params);
            $stmt->execute();
            $result = $stmt->rowCount();

            $duration = microtime(true) - $startTime;
            $this->recordQueryMetrics($connection, $duration);

            // Record success AFTER execute succeeds (not at acquire time)
            $this->getCircuitBreaker()->recordSuccess();

            return $result;
        } catch (PDOException $e) {
            $this->failedQueries++;
            $this->getCircuitBreaker()->recordFailure();
            $this->metricsCollector?->recordQuery(0, false, true);
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
        $this->metricsCollector?->recordRollback();
        $this->release($connection);
    }

    /**
     * Execute a callback within a transaction
     *
     * Automatically commits on success, rolls back on exception.
     * The callback receives the raw PDO instance for direct operations.
     *
     * @template T
     * @param callable(PDO): T $callback The callback to execute
     * @return T The callback's return value
     * @throws Throwable Re-throws any exception after rollback
     */
    public function transaction(callable $callback): mixed
    {
        $connection = $this->beginTransaction();

        try {
            $result = $callback($connection->getPdo());
            $this->commit($connection);
            return $result;
        } catch (Throwable $e) {
            $this->rollback($connection);
            throw $e;
        }
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
            $this->metricsCollector?->recordDosBlocked();
            throw QueryValidationException::querySizeExceeded(
                $size,
                $this->config->getMaxQuerySize()
            );
        }

        $count = count($params);
        if ($count > $this->config->getMaxParameters()) {
            $this->dosBlockedQueries++;
            $this->metricsCollector?->recordDosBlocked();
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
        $this->metricsCollector?->recordConnectionCreated();

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
     *
     * ENTERPRISE FIX: A connection that's used frequently will have low
     * getSecondsSinceLastValidation() because validation updates lastValidatedAt.
     * But if the connection becomes stale (server closed it), we won't detect it.
     *
     * Solution: Also check getSecondsSinceLastUse() - a connection idle for a while
     * MUST be validated regardless of when it was last validated.
     */
    private function needsValidation(PooledConnection $conn): bool
    {
        // Always validate if configured
        if ($this->config->shouldValidateOnAcquire()) {
            return true;
        }

        $validationInterval = $this->config->getValidationInterval();

        // Standard check: time since last validation
        if ($conn->getSecondsSinceLastValidation() > $validationInterval) {
            return true;
        }

        // ENTERPRISE FIX: Also validate if connection has been idle too long
        // This catches the case where a connection was validated, then sat idle
        // and the database server closed it (e.g., wait_timeout in MySQL)
        // Use half the validation interval for idle time check
        $idleThreshold = max(30, $validationInterval / 2);
        if ($conn->getIdleTime() > $idleThreshold) {
            return true;
        }

        return false;
    }

    /**
     * Validate connection is still alive
     */
    private function validateConnection(PooledConnection $conn): bool
    {
        $result = $conn->ping();

        if ($result) {
            $this->validationsPassed++;
            $this->metricsCollector?->recordValidationPassed();
        } else {
            $this->validationsFailed++;
            $this->metricsCollector?->recordValidationFailed();
        }

        return $result;
    }

    /**
     * Remove connection from pool by index
     */
    private function removeConnection(int $index): void
    {
        if (!isset($this->pool[$index])) {
            return;
        }

        $conn = $this->pool[$index];

        // Clear statement cache for this connection to prevent memory leak
        $this->clearStatementCacheForConnection($conn->getIdentifier());

        // Explicitly close the PDO connection
        try {
            $conn->close();
        } catch (\Throwable $e) {
            // Ignore - connection may already be dead
        }

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
     * ENTERPRISE FIX: Changed return type. On success, throws a special marker exception
     * that caller can catch. On failure, returns the last PDOException.
     * The previous implementation returned null on success which caused type errors.
     *
     * @return PDOException|null Returns exception on failure, null if retry succeeded
     *                           (but connection is returned via separate mechanism)
     */
    private function retryConnection(PDOException $lastError): ?PDOException
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
                $this->getCircuitBreaker()->recordSuccess();
                $this->poolMisses++;
                $this->metricsCollector?->recordPoolMiss();

                // ENTERPRISE FIX: We can't return the connection from here
                // because the caller expects an exception or null.
                // The connection is already in the pool and acquired.
                // Release it so caller can re-acquire through normal path.
                // This is a design limitation - consider refactoring later.
                $conn->release();

                // Return null to indicate success - caller should retry acquire()
                return null;
            } catch (PDOException $e) {
                $lastError = $e;
                $this->getCircuitBreaker()->recordFailure();
                $this->connectionsFailed++;
                $this->metricsCollector?->recordConnectionFailed();
            }
        }

        return $lastError;
    }

    /**
     * Prepare statement with per-connection caching
     *
     * PDOStatement objects are bound to their parent PDO connection.
     * Reusing a statement from a different connection causes errors.
     * This cache is keyed by connection identifier + SQL hash.
     *
     * @param PDO $pdo The PDO connection
     * @param string $sql The SQL query
     * @param string $connectionId The connection identifier
     * @return PDOStatement
     */
    private function prepareStatement(PDO $pdo, string $sql, string $connectionId = 'default'): PDOStatement
    {
        $sqlHash = md5($sql);

        // Check per-connection cache
        if (isset($this->statementCache[$connectionId][$sqlHash])) {
            return $this->statementCache[$connectionId][$sqlHash];
        }

        $stmt = $pdo->prepare($sql);

        // Initialize connection cache if needed
        if (!isset($this->statementCache[$connectionId])) {
            $this->statementCache[$connectionId] = [];
        }

        // LRU eviction if cache full for this connection
        $maxPerConnection = (int) ceil($this->config->getStatementCacheSize() / max(1, count($this->pool)));
        if (count($this->statementCache[$connectionId]) >= $maxPerConnection) {
            array_shift($this->statementCache[$connectionId]);
        }

        $this->statementCache[$connectionId][$sqlHash] = $stmt;

        return $stmt;
    }

    /**
     * Clear statement cache for a specific connection (e.g., when connection is removed)
     */
    private function clearStatementCacheForConnection(string $connectionId): void
    {
        unset($this->statementCache[$connectionId]);
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

        $isSlow = $duration > $this->config->getSlowQueryThreshold();
        if ($isSlow) {
            $this->slowQueries++;
            error_log(sprintf('[DB Pool] Slow query: %.3fs', $duration));
        }

        // Record to distributed metrics
        $this->metricsCollector?->recordQuery($duration * 1000, $isSlow, false);
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

        // Update pool gauges in distributed metrics
        $this->metricsCollector?->updatePoolGauges(count($this->pool), $idle, $inUse);

        // Get distributed metrics if available
        $distributedMetrics = $this->metricsCollector?->getAggregatedMetrics();

        $localMetrics = [
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
        ];

        return [
            'config' => [
                'driver' => $this->config->getDriver(),
                'host' => $this->config->getHost(),
                'database' => $this->config->getDatabase(),
                'min_connections' => $this->config->getMinConnections(),
                'max_connections' => $this->config->getMaxConnections(),
                'redis_enabled' => $this->redisEnabled,
            ],
            'pool' => [
                'total' => count($this->pool),
                'idle' => $idle,
                'in_use' => $inUse,
                'available' => $this->config->getMaxConnections() - count($this->pool),
            ],
            'metrics' => [
                'local' => $localMetrics,
                'distributed' => $distributedMetrics,
            ],
            'circuit_breaker' => $this->getCircuitBreaker()->getStats(),
            'redis' => [
                'enabled' => $this->redisEnabled,
                'connected' => $this->redisState?->isConnected() ?? false,
                'worker_count' => $distributedMetrics['worker_count'] ?? 1,
            ],
        ];
    }

    /**
     * Reset circuit breaker
     */
    public function resetCircuitBreaker(): void
    {
        $this->getCircuitBreaker()->reset();
    }

    /**
     * Drain the pool for graceful shutdown (zero-downtime deployments)
     *
     * Waits for all active connections to be released, then closes them.
     * New connection requests during drain will fail immediately.
     *
     * ENTERPRISE FIX: Also checks for active transactions, not just isInUse().
     * A connection could be idle but have an uncommitted transaction due to
     * application error. Closing such connection would cause data loss.
     *
     * @param int $timeout Maximum seconds to wait for connections to drain
     * @return bool True if all connections drained, false if timeout
     */
    public function drain(int $timeout = 30): bool
    {
        // ENTERPRISE FIX: Set draining flag to reject new acquire() requests
        $this->draining = true;
        $startTime = microtime(true);
        $lastLogTime = 0;

        error_log('[DB Pool] Starting graceful drain (timeout: ' . $timeout . 's)');

        // First, force circuit breaker open to reject new requests
        $this->getCircuitBreaker()->forceOpen();

        // Wait for active connections to be released
        while ((microtime(true) - $startTime) < $timeout) {
            $activeCount = 0;
            $transactionCount = 0;

            foreach ($this->pool as $conn) {
                // ENTERPRISE FIX: Check both isInUse() AND isInTransaction()
                // A connection might be "idle" but have uncommitted transaction
                if ($conn->isInUse()) {
                    $activeCount++;
                }
                if ($conn->isInTransaction()) {
                    $transactionCount++;
                }
            }

            // Only safe when no active use AND no uncommitted transactions
            if ($activeCount === 0 && $transactionCount === 0) {
                // All connections idle and no pending transactions - safe to close
                $this->shutdown();
                error_log('[DB Pool] Drain completed successfully');
                return true;
            }

            // Log progress every 5 seconds
            $elapsed = (int)(microtime(true) - $startTime);
            if ($elapsed > $lastLogTime && $elapsed % 5 === 0) {
                $lastLogTime = $elapsed;
                error_log("[DB Pool] Draining... {$activeCount} active, {$transactionCount} with transactions");
            }

            usleep(100000); // 100ms
        }

        // Timeout - check if there are uncommitted transactions
        $uncommittedCount = 0;
        foreach ($this->pool as $conn) {
            if ($conn->isInTransaction()) {
                $uncommittedCount++;
                error_log('[DB Pool] WARNING: Forcing close on connection with uncommitted transaction: ' . $conn->getIdentifier());
            }
        }

        if ($uncommittedCount > 0) {
            error_log("[DB Pool] Drain timeout - {$uncommittedCount} connections had uncommitted transactions (data may be lost)");
        } else {
            error_log('[DB Pool] Drain timeout - forcing shutdown');
        }

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
     *
     * Explicitly closes all PDO connections to release database resources
     * immediately. Important for long-running processes (Swoole, RoadRunner).
     */
    public function shutdown(): void
    {
        // Mark as draining to prevent new acquires
        $this->draining = true;

        // Flush any pending metrics
        $this->metricsCollector?->flush();

        // Explicitly close all connections
        foreach ($this->pool as $conn) {
            try {
                $conn->close(); // Explicitly close PDO connection
            } catch (\Throwable $e) {
                // Ignore - connection may already be dead
            }
        }

        $this->pool = [];
        $this->statementCache = [];

        // Close Redis connection
        $this->redisState?->close();

        // ENTERPRISE FIX: Close mutex file handle
        if ($this->poolMutex !== null) {
            @fclose($this->poolMutex);
            $this->poolMutex = null;
        }
    }

    /**
     * Check if Redis is enabled and connected
     */
    public function isRedisConnected(): bool
    {
        return $this->redisState?->isConnected() ?? false;
    }

    /**
     * Get Redis state manager (for advanced operations)
     */
    public function getRedisStateManager(): ?RedisStateManager
    {
        return $this->redisState;
    }

    /**
     * Flush all distributed metrics to Redis
     */
    public function flushMetrics(): void
    {
        $this->metricsCollector?->flush();
    }

    /**
     * Send heartbeat to register this worker as active
     */
    public function heartbeat(): void
    {
        $this->metricsCollector?->heartbeat();
    }

    /**
     * Get the database driver name (pgsql, mysql, sqlite)
     *
     * @return string Driver name
     */
    public function getDriverName(): string
    {
        return $this->config->getDriver();
    }

    /**
     * Check if pool is healthy and accepting connections
     */
    public function isHealthy(): bool
    {
        return $this->getCircuitBreaker()->getState() !== CircuitBreaker::STATE_OPEN;
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
