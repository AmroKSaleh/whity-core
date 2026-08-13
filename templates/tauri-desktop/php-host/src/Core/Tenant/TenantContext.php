<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Single-tenant shim of production's Whity\Core\Tenant\TenantContext.
 *
 * SAME FQCN as production so plugin code (e.g. DemoCatalogApiHandler) needs
 * no changes to resolve it. Production's real class is JWT-derived,
 * request-scoped, and multi-tenant; this offline host has exactly one
 * tenant, so the API surface plugins actually call — setTenantId() /
 * getTenantId() / reset() — is kept, but multi-tenancy, locking-as-a-safety-
 * mechanism, and audit logging are not reimplemented (there is only ever one
 * tenant, so nothing to guard against).
 *
 * Usage per request (mirrors production's real lifecycle): the worker loop
 * calls setTenantId() before dispatching each request and reset() in a
 * finally block afterward, exactly like public/index.php does in production.
 */
final class TenantContext
{
    private static ?int $tenantId = null;

    public static function setTenantId(int $tenantId): void
    {
        self::$tenantId = $tenantId;
    }

    public static function getTenantId(): ?int
    {
        return self::$tenantId;
    }

    public static function reset(): void
    {
        self::$tenantId = null;
    }
}
