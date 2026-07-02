<?php

declare(strict_types=1);

namespace Whity\Auth\Exception;

use RuntimeException;

/**
 * Thrown when a JWT's membership claim cannot be validated.
 *
 * httpStatus 401 = profile/tenant not resolvable (identity unknown).
 * httpStatus 403 = identity known but membership status is not active.
 *
 * ADR 0005 §5 — identity rewrite Phase B.
 */
class InvalidMembershipException extends RuntimeException
{
    /**
     * @param int    $httpStatus 401 or 403
     * @param string $message    Human-readable reason (never leaks to clients).
     */
    public function __construct(
        public readonly int $httpStatus,
        string $message
    ) {
        parent::__construct($message);
    }
}
