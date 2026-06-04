<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use RuntimeException;
use Throwable;

/**
 * Raised when the current tenant cannot be resolved for a request.
 *
 * This is a hard failure by design: an unauthenticated request, an
 * invalid/expired JWT, or a token that is missing a valid tenant_id claim must
 * never fall back to a default tenant, as that would be a cross-tenant
 * data-leak vector. Callers (e.g. middleware) should translate this into a
 * 401/403 response without leaking internal details to clients.
 */
class TenantResolutionException extends RuntimeException
{
    /**
     * No authentication token was present on the request.
     *
     * @return self
     */
    public static function missingToken(): self
    {
        return new self('Tenant could not be resolved: no authentication token present');
    }

    /**
     * The token was present but failed signature/expiry validation.
     *
     * @return self
     */
    public static function invalidToken(): self
    {
        return new self('Tenant could not be resolved: invalid or expired token');
    }

    /**
     * The token validated but did not carry a usable tenant_id claim.
     *
     * @param string $detail Why the claim was rejected (missing / non-numeric).
     * @return self
     */
    public static function invalidTenantClaim(string $detail): self
    {
        return new self('Tenant could not be resolved: ' . $detail);
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
