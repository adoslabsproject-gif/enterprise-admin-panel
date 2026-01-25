<?php

/**
 * Enterprise Admin Panel - Query Validation Exception
 *
 * Thrown when query validation fails (DoS protection).
 *
 * @package AdosLabs\AdminPanel\Database\Pool\Exceptions
 * @version 1.0.0
 */

declare(strict_types=1);

namespace AdosLabs\AdminPanel\Database\Pool\Exceptions;

final class QueryValidationException extends PoolException
{
    public static function querySizeExceeded(int $size, int $maxSize): self
    {
        return new self(sprintf(
            'Query size (%d bytes) exceeds maximum allowed (%d bytes)',
            $size,
            $maxSize
        ));
    }

    public static function parameterCountExceeded(int $count, int $maxCount): self
    {
        return new self(sprintf(
            'Parameter count (%d) exceeds maximum allowed (%d)',
            $count,
            $maxCount
        ));
    }
}
