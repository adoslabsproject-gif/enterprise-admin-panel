<?php

/**
 * Enterprise Admin Panel - Pooled Connection
 *
 * Wrapper for PDO connections with lifecycle tracking.
 * Tracks acquisition, release, health, and usage metrics.
 *
 * @package AdosLabs\AdminPanel\Database\Pool
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool;

use PDO;
use PDOException;

final class PooledConnection
{
    private readonly float $createdAt;
    private float $lastUsedAt;
    private float $lastValidatedAt;
    private ?float $idleSince = null;
    private bool $inUse = false;
    private bool $healthy = true;
    private ?string $lastError = null;
    private int $acquisitionCount = 0;
    private int $queryCount = 0;
    private float $totalQueryTime = 0.0;
    private bool $inTransaction = false;
    private bool $closed = false;

    /**
     * PDO connection (nullable to allow explicit closure)
     */
    private ?PDO $pdo;

    public function __construct(
        PDO $pdo,
        private readonly int $maxLifetime,
        private readonly string $identifier
    ) {
        $this->pdo = $pdo;
        $this->createdAt = microtime(true);
        $this->lastUsedAt = $this->createdAt;
        $this->lastValidatedAt = $this->createdAt;
        $this->idleSince = $this->createdAt;
    }

    /**
     * Get the underlying PDO connection
     *
     * @throws \RuntimeException If connection has been closed
     */
    public function getPdo(): PDO
    {
        if ($this->closed || $this->pdo === null) {
            throw new \RuntimeException('Connection has been closed');
        }
        return $this->pdo;
    }

    /**
     * Explicitly close the PDO connection
     *
     * This releases the database connection immediately rather than
     * waiting for garbage collection. Important for long-running processes.
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        // Rollback any uncommitted transaction
        if ($this->inTransaction && $this->pdo !== null) {
            try {
                $this->pdo->rollBack();
            } catch (PDOException $e) {
                // Ignore - connection may already be dead
            }
            $this->inTransaction = false;
        }

        // Release PDO reference (triggers connection close)
        $this->pdo = null;
        $this->closed = true;
        $this->healthy = false;
        $this->inUse = false;
    }

    /**
     * Check if connection has been closed
     */
    public function isClosed(): bool
    {
        return $this->closed;
    }

    /**
     * Destructor - ensure connection is closed
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * Get connection identifier
     */
    public function getIdentifier(): string
    {
        return $this->identifier;
    }

    /**
     * Check if connection is idle (not in use)
     */
    public function isIdle(): bool
    {
        return !$this->inUse;
    }

    /**
     * Check if connection is in use
     */
    public function isInUse(): bool
    {
        return $this->inUse;
    }

    /**
     * Check if connection is healthy
     */
    public function isHealthy(): bool
    {
        if (!$this->healthy) {
            return false;
        }

        if ($this->getAge() > $this->maxLifetime) {
            return false;
        }

        return true;
    }

    /**
     * Acquire the connection (mark as in use)
     */
    public function acquire(): void
    {
        $this->inUse = true;
        $this->idleSince = null;
        $this->lastUsedAt = microtime(true);
        $this->acquisitionCount++;
    }

    /**
     * Release the connection back to pool
     *
     * @throws PDOException If transaction rollback fails
     */
    public function release(): void
    {
        // Auto-rollback any uncommitted transaction
        if ($this->inTransaction) {
            try {
                $this->pdo->rollBack();
                $this->inTransaction = false;
            } catch (PDOException $e) {
                $this->markUnhealthy('Transaction rollback failed: ' . $e->getMessage());
                throw $e;
            }
        }

        $this->inUse = false;
        $this->idleSince = microtime(true);
    }

    /**
     * Mark connection as validated
     */
    public function markValidated(): void
    {
        $this->lastValidatedAt = microtime(true);
        $this->healthy = true;
        $this->lastError = null;
    }

    /**
     * Mark connection as unhealthy
     */
    public function markUnhealthy(string $reason): void
    {
        $this->healthy = false;
        $this->lastError = $reason;
    }

    /**
     * Mark transaction started
     */
    public function markTransactionStarted(): void
    {
        $this->inTransaction = true;
    }

    /**
     * Mark transaction ended
     */
    public function markTransactionEnded(): void
    {
        $this->inTransaction = false;
    }

    /**
     * Check if in transaction
     */
    public function isInTransaction(): bool
    {
        return $this->inTransaction;
    }

    /**
     * Record query execution
     */
    public function recordQuery(float $duration): void
    {
        $this->queryCount++;
        $this->totalQueryTime += $duration;
        $this->lastUsedAt = microtime(true);
    }

    /**
     * Ping connection to verify it's alive
     */
    public function ping(): bool
    {
        try {
            $this->pdo->query('SELECT 1');
            $this->markValidated();
            return true;
        } catch (PDOException $e) {
            $this->markUnhealthy($e->getMessage());
            return false;
        }
    }

    /**
     * Get connection age in seconds
     */
    public function getAge(): float
    {
        return microtime(true) - $this->createdAt;
    }

    /**
     * Get idle time in seconds
     */
    public function getIdleTime(): float
    {
        if ($this->inUse || $this->idleSince === null) {
            return 0.0;
        }

        return microtime(true) - $this->idleSince;
    }

    /**
     * Get seconds since last validation
     */
    public function getSecondsSinceLastValidation(): float
    {
        return microtime(true) - $this->lastValidatedAt;
    }

    /**
     * Get seconds since last use
     */
    public function getSecondsSinceLastUse(): float
    {
        return microtime(true) - $this->lastUsedAt;
    }

    /**
     * Check if connection should be refreshed
     */
    public function shouldRefresh(): bool
    {
        // 90% of max lifetime
        if ($this->getAge() > ($this->maxLifetime * 0.9)) {
            return true;
        }

        return !$this->healthy;
    }

    /**
     * Get connection statistics
     */
    public function getStats(): array
    {
        return [
            'identifier' => $this->identifier,
            'created_at' => $this->createdAt,
            'age_seconds' => round($this->getAge(), 3),
            'last_used_at' => $this->lastUsedAt,
            'last_validated_at' => $this->lastValidatedAt,
            'idle_since' => $this->idleSince,
            'idle_time_seconds' => round($this->getIdleTime(), 3),
            'in_use' => $this->inUse,
            'in_transaction' => $this->inTransaction,
            'healthy' => $this->healthy,
            'last_error' => $this->lastError,
            'acquisition_count' => $this->acquisitionCount,
            'query_count' => $this->queryCount,
            'total_query_time_ms' => round($this->totalQueryTime * 1000, 3),
            'avg_query_time_ms' => $this->queryCount > 0
                ? round(($this->totalQueryTime / $this->queryCount) * 1000, 3)
                : 0.0,
            'max_lifetime' => $this->maxLifetime,
            'remaining_lifetime' => max(0, $this->maxLifetime - $this->getAge()),
        ];
    }
}
