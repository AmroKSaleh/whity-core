<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

/**
 * Interface for accessing the current tenant context.
 *
 * Allows LanguageRegistry and other services to access tenant information
 * without being tightly coupled to the static TenantContext class.
 * This enables proper dependency injection and testability.
 */
interface TenantContextInterface
{
    /**
     * Get the current tenant ID.
     *
     * @return int|null The tenant ID (0 = system tenant), or null if not set.
     */
    public function getTenantId(): ?int;
}
