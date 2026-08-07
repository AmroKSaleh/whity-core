<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Adapter that wraps the static TenantContext for use in dependency injection.
 *
 * This allows services like LanguageRegistry to depend on TenantContextInterface
 * (which can be mocked in tests) while still accessing the request-scoped
 * static TenantContext at runtime.
 *
 * In production, instantiate this once and inject it into all services.
 * In tests, mock TenantContextInterface directly instead of using this adapter.
 */
final class StaticTenantContextAdapter implements TenantContextInterface
{
    /**
     * Get the current tenant ID from the static TenantContext.
     *
     * @return int|null The tenant ID (0 = system tenant), or null if not set.
     */
    public function getTenantId(): ?int
    {
        return TenantContext::getTenantId();
    }
}
