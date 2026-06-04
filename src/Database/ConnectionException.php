<?php

declare(strict_types=1);

namespace Whity\Database;

use RuntimeException;
use Throwable;

/**
 * Connection-level database error.
 *
 * Raised when a PostgreSQL connection cannot be established (or re-established)
 * after the configured retry attempts. This is a domain exception that allows
 * callers to distinguish connection failures from query/logic errors without
 * leaking the raw PDO error message or stack trace to API clients.
 */
class ConnectionException extends RuntimeException
{
    /**
     * @param string         $message  Human-readable, client-safe error summary.
     * @param int            $code     Optional error code.
     * @param Throwable|null $previous Underlying driver exception (for logging only).
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
