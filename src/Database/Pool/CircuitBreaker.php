<?php

/**
 * Enterprise Admin Panel - Circuit Breaker
 *
 * Protects against cascading failures in database connections.
 * Implements the standard circuit breaker pattern with three states.
 *
 * @package AdosLabs\AdminPanel\Database\Pool
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool;

final class CircuitBreaker
{
    public const STATE_CLOSED = 'closed';
    public const STATE_OPEN = 'open';
    public const STATE_HALF_OPEN = 'half_open';

    private string $state = self::STATE_CLOSED;
    private int $failureCount = 0;
    private int $successCount = 0;
    private int $totalFailures = 0;
    private int $totalSuccesses = 0;
    private int $tripCount = 0;
    private ?float $lastFailureTime = null;
    private ?float $openedAt = null;

    public function __construct(
        private readonly int $failureThreshold = 5,
        private readonly int $recoveryTime = 30,
        private readonly int $halfOpenSuccessThreshold = 2
    ) {
    }

    /**
     * Check if request should be allowed
     */
    public function allowRequest(): bool
    {
        return match ($this->state) {
            self::STATE_CLOSED => true,
            self::STATE_OPEN => $this->shouldAttemptRecovery(),
            self::STATE_HALF_OPEN => true,
        };
    }

    /**
     * Record a successful operation
     */
    public function recordSuccess(): void
    {
        $this->totalSuccesses++;

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->successCount++;

            if ($this->successCount >= $this->halfOpenSuccessThreshold) {
                $this->close();
            }
        } else {
            // Reset failure count on success in closed state
            $this->failureCount = 0;
        }
    }

    /**
     * Record a failed operation
     */
    public function recordFailure(): void
    {
        $this->failureCount++;
        $this->totalFailures++;
        $this->lastFailureTime = microtime(true);

        if ($this->state === self::STATE_HALF_OPEN) {
            $this->trip();
        } elseif ($this->failureCount >= $this->failureThreshold) {
            $this->trip();
        }
    }

    /**
     * Trip the circuit breaker to OPEN state
     */
    private function trip(): void
    {
        $this->state = self::STATE_OPEN;
        $this->openedAt = microtime(true);
        $this->successCount = 0;
        $this->tripCount++;
    }

    /**
     * Close the circuit breaker
     */
    private function close(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->openedAt = null;
    }

    /**
     * Check if we should attempt recovery
     */
    private function shouldAttemptRecovery(): bool
    {
        if ($this->openedAt === null) {
            return true;
        }

        if ((microtime(true) - $this->openedAt) >= $this->recoveryTime) {
            $this->state = self::STATE_HALF_OPEN;
            $this->successCount = 0;
            return true;
        }

        return false;
    }

    /**
     * Force reset to closed state
     */
    public function reset(): void
    {
        $this->state = self::STATE_CLOSED;
        $this->failureCount = 0;
        $this->successCount = 0;
        $this->lastFailureTime = null;
        $this->openedAt = null;
    }

    /**
     * Force circuit breaker open
     */
    public function forceOpen(): void
    {
        $this->trip();
    }

    /**
     * Get current state
     */
    public function getState(): string
    {
        if ($this->state === self::STATE_OPEN) {
            $this->shouldAttemptRecovery();
        }

        return $this->state;
    }

    /**
     * Get failure count (current window)
     */
    public function getFailureCount(): int
    {
        return $this->failureCount;
    }

    /**
     * Get time until recovery attempt (seconds)
     */
    public function getTimeUntilRecovery(): ?float
    {
        if ($this->state !== self::STATE_OPEN || $this->openedAt === null) {
            return null;
        }

        $elapsed = microtime(true) - $this->openedAt;
        $remaining = $this->recoveryTime - $elapsed;

        return max(0.0, $remaining);
    }

    /**
     * Get comprehensive statistics
     */
    public function getStats(): array
    {
        return [
            'state' => $this->getState(),
            'failure_count' => $this->failureCount,
            'success_count' => $this->successCount,
            'total_failures' => $this->totalFailures,
            'total_successes' => $this->totalSuccesses,
            'trip_count' => $this->tripCount,
            'failure_threshold' => $this->failureThreshold,
            'recovery_time' => $this->recoveryTime,
            'time_until_recovery' => $this->getTimeUntilRecovery(),
            'last_failure_time' => $this->lastFailureTime,
            'opened_at' => $this->openedAt,
        ];
    }

    /**
     * Force circuit breaker to half-open state (for testing)
     *
     * This allows testing the half-open → closed transition
     * without waiting for the recovery timeout.
     */
    public function forceHalfOpen(): void
    {
        $this->state = self::STATE_HALF_OPEN;
        $this->successCount = 0;
        $this->openedAt = microtime(true) - $this->recoveryTime - 1; // Pretend we've waited
    }

    /**
     * Simulate failures (for testing)
     *
     * @param int $count Number of failures to simulate
     */
    public function simulateFailures(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->recordFailure();
        }
    }

    /**
     * Simulate successes (for testing)
     *
     * @param int $count Number of successes to simulate
     */
    public function simulateSuccesses(int $count): void
    {
        for ($i = 0; $i < $count; $i++) {
            $this->recordSuccess();
        }
    }

    /**
     * Check if circuit breaker is open
     */
    public function isOpen(): bool
    {
        return $this->getState() === self::STATE_OPEN;
    }

    /**
     * Check if circuit breaker is closed
     */
    public function isClosed(): bool
    {
        return $this->getState() === self::STATE_CLOSED;
    }

    /**
     * Check if circuit breaker is half-open
     */
    public function isHalfOpen(): bool
    {
        return $this->getState() === self::STATE_HALF_OPEN;
    }
}
