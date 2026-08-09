<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use RuntimeException;

/**
 * Raised when a role is not visible to the acting tenant (WC-712 §2).
 *
 * A role is visible when it is the tenant's own or a global (NULL tenant_id)
 * role, per the WC-110 visibility model. Anything else — including a role that
 * exists but belongs to another tenant — is treated as ABSENT.
 *
 * Callers should surface this as 404, never 403: a "forbidden" answer would
 * confirm that the role id exists in some other tenant, which is precisely the
 * cross-tenant disclosure the guard exists to prevent. The message deliberately
 * carries only the id the caller already supplied.
 */
class RoleNotVisibleException extends RuntimeException
{
    /**
     * @param int $roleId The role id the caller asked for.
     */
    public static function forRole(int $roleId): self
    {
        return new self("Role {$roleId} not found");
    }
}
