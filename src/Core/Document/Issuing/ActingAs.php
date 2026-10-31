<?php

declare(strict_types=1);

namespace Whity\Core\Document\Issuing;

/**
 * Who issues (DOCUMENT-AUTOMATION.md 3.7): the tenant, the person, and their
 * permissions, which records, charts and images are read with. A plugin
 * acting in a request acts as the person and never widens their rights.
 */
final class ActingAs
{
    /** @var callable(string): bool */
    public readonly mixed $hasPermission;

    /** @param callable(string): bool $hasPermission */
    public function __construct(
        public readonly int $tenantId,
        public readonly int $profileId,
        callable $hasPermission,
    ) {
        $this->hasPermission = $hasPermission;
    }
}
