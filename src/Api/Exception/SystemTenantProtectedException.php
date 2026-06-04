<?php

declare(strict_types=1);

namespace Whity\Api\Exception;

use RuntimeException;

/**
 * Raised when an operation would compromise the system tenant (id=0).
 *
 * The system tenant anchors the multi-tenant infrastructure and must never be
 * deleted. This domain exception is caught by the API handler and translated
 * into a safe 400 response without leaking internal details to clients.
 */
class SystemTenantProtectedException extends RuntimeException
{
    /**
     * Create an exception describing a forbidden destructive action on the
     * system tenant.
     *
     * @param string $action The attempted action (e.g. "delete").
     * @return self
     */
    public static function forAction(string $action): self
    {
        return new self("Cannot {$action} system tenant");
    }
}
