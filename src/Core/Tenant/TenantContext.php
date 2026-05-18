<?php

namespace Whity\Core\Tenant;

/**
 * Request-scoped tenant context holder
 *
 * Maintains the current request's tenant ID. Once set by middleware, the context
 * is locked to prevent plugins from mutating it and escaping tenant boundaries.
 * This ensures strict tenant isolation during request processing.
 */
class TenantContext
{
    /**
     * The current tenant ID
     */
    private static ?int $tenantId = null;

    /**
     * Whether the context is locked (prevents further mutations)
     */
    private static bool $locked = false;

    /**
     * Set the current tenant ID for this request
     *
     * Once set, the context is locked and cannot be changed. Subsequent attempts
     * to set a different tenant ID will throw a RuntimeException.
     *
     * @param int $tenantId The tenant ID for this request
     * @return void
     * @throws RuntimeException If context is already locked
     */
    public static function setTenantId(int $tenantId): void
    {
        if (self::$locked) {
            throw new \RuntimeException('TenantContext is locked and cannot be mutated');
        }

        self::$tenantId = $tenantId;
        self::$locked = true;
    }

    /**
     * Get the current tenant ID
     *
     * @return int|null The tenant ID, or null if not set
     */
    public static function getTenantId(): ?int
    {
        return self::$tenantId;
    }

    /**
     * Check if a tenant is currently set
     *
     * @return bool True if a tenant ID is set, false otherwise
     */
    public static function hasTenant(): bool
    {
        return self::$tenantId !== null;
    }

    /**
     * Reset the context to initial state
     *
     * Clears the tenant ID and unlocks the context, allowing it to be set again.
     * Typically used between requests in persistent workers.
     *
     * @return void
     */
    public static function reset(): void
    {
        self::$tenantId = null;
        self::$locked = false;
    }
}
