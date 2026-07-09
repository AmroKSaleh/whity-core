<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * The trust context of the IdP that authenticated a federated login
 * (WC-f3b17bd2). Passed to {@see FederatedIdentityLinker::resolveForLogin()} so
 * the linker applies the tier-appropriate anti-takeover policy.
 *
 * The tier is derived from WHO configured the provider:
 *   - GLOBAL-TRUST — configured at the system tenant (id 0) by the deployment
 *     operator (e.g. real Google). Its `email_verified` assertion is
 *     authoritative over the global profile namespace.
 *   - TENANT-TRUST — a tenant's own bring-your-own IdP. Trusted only WITHIN the
 *     configuring tenant; its assertions must never reach another tenant's
 *     accounts or the global namespace.
 *
 * Immutable value object.
 */
final class FederatedProviderContext
{
    public function __construct(
        /** The configured provider row id (`identity_providers.id`). */
        public readonly int $providerId,
        /** The provider key (e.g. `google`), recorded on the link for UX. */
        public readonly string $providerKey,
        /** The tenant whose config drives this flow (0 = system/operator). */
        public readonly int $tenantId,
    ) {
    }

    /**
     * True when the operator configured this provider (system tenant 0), so its
     * identity claims may act on the global profile namespace. False for a
     * tenant's bring-your-own IdP, which is confined to its own tenant.
     */
    public function isGlobalTrust(): bool
    {
        return $this->tenantId === 0;
    }
}
