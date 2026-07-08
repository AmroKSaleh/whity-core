<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Applies the tenant email-domain policy when a profile verifies an email (WC-9b87).
 *
 * Called from the email-verification flow after a profile_email row is marked
 * verified. For each tenant that has registered the verified email's domain:
 *
 *   1. If a pending ('invited') membership already exists → accept it (status: active).
 *   2. Else if auto_provision = true and no membership exists → insert an 'active'
 *      membership with the domain registration's default_role_id.
 *   3. Otherwise (membership already active, or auto_provision = false with no invite) → no-op.
 *
 * This is stateless and safe for FrankenPHP worker persistence.
 */
final class TenantEmailDomainPolicyService
{
    private TenantEmailDomainsRepository $domains;
    private MembershipRepository $memberships;

    public function __construct(
        TenantEmailDomainsRepository $domains,
        MembershipRepository $memberships,
    ) {
        $this->domains     = $domains;
        $this->memberships = $memberships;
    }

    /**
     * Apply the domain policy for a newly-verified email address.
     *
     * Extracts the domain part of `$email`, queries every tenant that has
     * registered that domain, and performs the appropriate membership action.
     *
     * @param string $email     The fully-verified email address (e.g. "alice@acme.com").
     * @param int    $profileId The profile that verified the email.
     */
    public function applyToVerifiedEmail(string $email, int $profileId): void
    {
        $atPos = strrpos($email, '@');
        if ($atPos === false) {
            return;
        }

        $domain = strtolower(substr($email, $atPos + 1));
        $claimingTenants = $this->domains->findTenantsByDomain($domain);

        foreach ($claimingTenants as $policy) {
            $tenantId      = (int) $policy['tenant_id'];
            $defaultRoleId = (int) $policy['default_role_id'];
            $autoProvision = (bool) $policy['auto_provision'];

            // Never auto-provision or accept into the system tenant (id 0): a
            // tenant-0 membership carries platform-wide authority and must be
            // granted explicitly, never by an email-domain match. Defense in
            // depth — creating a tenant-0 domain claim is already system-admin
            // gated (TenantEmailDomainApiHandler binds tenant_id from context) —
            // but MembershipRepository::insert has no tenant-0 guard of its own,
            // and this is exactly the untrusted auto-provision caller its note flags.
            if ($tenantId <= 0) {
                continue;
            }

            $existing = $this->memberships->findByProfile($profileId, $tenantId);

            if ($existing !== null) {
                if ($existing['status'] === MembershipRepository::STATUS_INVITED) {
                    $this->memberships->accept($existing['id'], $tenantId);
                }
                // Active or suspended: no-op.
                continue;
            }

            if ($autoProvision) {
                $this->memberships->insert($profileId, $tenantId, $defaultRoleId);
            }
        }
    }
}
