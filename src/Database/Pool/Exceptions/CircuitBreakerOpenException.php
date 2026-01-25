<?php

/**
 * Enterprise Admin Panel - Circuit Breaker Open Exception
 *
 * Thrown when circuit breaker is open and blocking requests.
 *
 * @package AdosLabs\AdminPanel\Database\Pool\Exceptions
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool\Exceptions;

final class CircuitBreakerOpenException extends PoolException
{
    public function __construct(
        string $connectionName,
        int $failureCount,
        ?float $timeUntilRecovery
    ) {
        $message = sprintf(
            "Circuit breaker OPEN for connection '%s'. Failures: %d. Recovery in: %.1fs",
            $connectionName,
            $failureCount,
            $timeUntilRecovery ?? 0
        );

        parent::__construct($message);
    }
}
