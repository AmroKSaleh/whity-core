<?php

declare(strict_types=1);

namespace Whity\PluginHost;

/**
 * The offline host's single fixed caller identity — registered as an app()
 * service so plugin code resolving PermissionResolver has ids to pass into
 * it, mirroring TenantContext's own "one hardcoded value" shape.
 */
final class OfflineIdentity
{
    public function __construct(
        public readonly int $profileId,
        public readonly int $tenantId,
    ) {
    }
}
