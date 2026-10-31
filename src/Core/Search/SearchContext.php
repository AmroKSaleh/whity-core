<?php

declare(strict_types=1);

namespace Whity\Core\Search;

/**
 * Who is searching, and in which tenant (WHIT-783). The permission check is
 * passed in rather than looked up, so a source can ask about a second
 * permission (for example `documents:read:all`) through the same RBAC path the
 * route gate used.
 */
final class SearchContext
{
    /** @param \Closure(string): bool $hasPermission */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $profileId,
        private readonly \Closure $hasPermission,
    ) {
    }

    public function can(string $permission): bool
    {
        return ($this->hasPermission)($permission);
    }
}
