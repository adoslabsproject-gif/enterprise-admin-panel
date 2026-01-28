<?php

/**
 * Enterprise Admin Panel - Pool Exhausted Exception
 *
 * Thrown when all connections are in use and timeout exceeded.
 *
 * @package AdosLabs\AdminPanel\Database\Pool\Exceptions
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool\Exceptions;

final class PoolExhaustedException extends PoolException
{
    public function __construct(
        string $connectionName,
        int $poolSize,
        float $waitTimeout,
        ?string $reason = null
    ) {
        $message = $reason ?? sprintf(
            "Connection pool exhausted for '%s'. Pool size: %d. Wait timeout: %.1fs",
            $connectionName,
            $poolSize,
            $waitTimeout
        );

        parent::__construct($message);
    }
}
