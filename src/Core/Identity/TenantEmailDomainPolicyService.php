<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

/**
 * Applies the tenant email-domain policy when a profile verifies an email (WC-9b87).
 *
 * Called from the email-verification flow after a profile_email row is marked
 * verified. For each tenant that has registered the verified email's domain:
 *
 *   1. If a pending ('invited') membership already exists → accept it (status:
 *      active). An explicit invite is trusted regardless of domain ownership.
 *   2. Else if auto_provision = true AND the domain is ownership-VERIFIED and no
 *      membership exists → insert an 'active' membership with the registration's
 *      default_role_id. Ownership verification is mandatory here (WC-628738f5):
 *      without it a tenant could claim a domain it does not own and harvest a
 *      membership for every user who verifies an email on that domain.
 *   3. Otherwise (already a member, auto_provision off, or domain UNVERIFIED) → no-op.
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
            $isVerified    = ($policy['verified_at'] ?? null) !== null;

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
                    // Accepting an EXPLICIT invite is safe regardless of domain
                    // ownership — the tenant admin already named this person.
                    $this->memberships->accept($existing['id'], $tenantId);
                }
                // Active or suspended: no-op.
                continue;
            }

            // AUTO-PROVISION (no prior membership) requires PROVEN domain ownership.
            // Without it, a tenant could register a domain it does not own (e.g.
            // gmail.com) and harvest a membership for every verifying user — the
            // cross-tenant harvesting hole this guard closes (WC-628738f5). An
            // unverified claim silently does nothing here; the tenant must pass the
            // DNS TXT challenge (DomainOwnershipVerifier) first.
            if ($autoProvision && $isVerified) {
                try {
                    $this->memberships->insert($profileId, $tenantId, $defaultRoleId);
                } catch (\PDOException $e) {
                    // Check-then-insert race: two verifications of the same email
                    // (or a verification concurrent with a JIT/federated provision)
                    // can both pass the findByProfile() null-check above and both
                    // INSERT. The second loses on UNIQUE(profile_id, tenant_id).
                    // That collision is benign — the member row now exists, which
                    // is exactly the desired end state — so swallow ONLY a unique
                    // violation. Any other DB error (bad role FK, connection, etc.)
                    // must surface, not be silently lost. Mirrors
                    // FederatedIdentityLinker::addTenantMember (WC-628738f5).
                    if (!self::isUniqueViolation($e)) {
                        throw $e;
                    }
                }
            }
        }
    }

    /**
     * Whether a PDOException is a UNIQUE / integrity-constraint violation (a benign
     * "row already exists"), as opposed to any other database failure. Portable
     * across PostgreSQL (SQLSTATE 23505) and SQLite (23000).
     */
    private static function isUniqueViolation(\PDOException $e): bool
    {
        $sqlState = (string) $e->getCode();

        return $sqlState === '23505' || $sqlState === '23000';
    }
}
