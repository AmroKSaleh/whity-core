<?php

declare(strict_types=1);

namespace Whity\Core\Database;

use RuntimeException;
use Throwable;

/**
 * Raised when a query cannot be safely scoped to the current tenant.
 *
 * This is a deliberate fail-closed boundary. Per the project's no-silent-fallback
 * security stance, a query is rejected (never run unscoped) whenever:
 *
 *  - The tenant context is UNRESOLVED (no tenant id set and system mode is off):
 *    running without a tenant filter would expose every tenant's rows, so we
 *    refuse rather than guess a default. {@see self::unresolvedContext()}
 *  - The SQL shape cannot be rewritten safely (unsupported statement type, or a
 *    statement we cannot deterministically inject a tenant predicate into): we
 *    refuse rather than risk producing a broken or under-scoped statement.
 *    {@see self::unsupportedStatement()}
 *  - A reserved tenant-scope placeholder is already present in the caller's
 *    parameters, which would let caller input collide with the injected
 *    predicate. {@see self::reservedParameterCollision()}
 *
 * Callers (middleware / API handlers) should translate this into a 5xx/4xx
 * without leaking internal SQL details to clients.
 */
class TenantScopeException extends RuntimeException
{
    /**
     * The tenant context is not resolved and system mode is not active.
     *
     * @return self
     */
    public static function unresolvedContext(): self
    {
        return new self(
            'Refusing to run query: tenant context is unresolved and system mode is not active. '
            . 'Resolve a tenant (or enable system mode for a trusted operation) before querying.'
        );
    }

    /**
     * The statement could not be safely rewritten to add a tenant predicate.
     *
     * @param string $reason Why the statement was rejected (for logs, not clients).
     * @return self
     */
    public static function unsupportedStatement(string $reason): self
    {
        return new self('Refusing to run query: cannot safely apply tenant scope: ' . $reason);
    }

    /**
     * The caller passed a parameter using the reserved tenant-scope placeholder.
     *
     * @param string $placeholder The reserved placeholder name that collided.
     * @return self
     */
    public static function reservedParameterCollision(string $placeholder): self
    {
        return new self(
            'Refusing to run query: parameter "' . $placeholder
            . '" is reserved for tenant scoping and must not be supplied by the caller.'
        );
    }

    /**
     * @param string         $message  Human-readable, client-safe error summary.
     * @param int            $code     Optional error code.
     * @param Throwable|null $previous Underlying exception, for logging only.
     */
    public function __construct(string $message, int $code = 0, ?Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
