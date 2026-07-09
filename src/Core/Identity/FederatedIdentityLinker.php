<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Sdk\Auth\ExternalIdentity;

/**
 * Resolves a verified external (SSO/OIDC) identity to a local profile at first
 * login — linking to an existing account or provisioning a new one
 * (WC-f3b17bd2). This is the ANTI-TAKEOVER core of federated onboarding.
 *
 * TIERED TRUST
 * ------------
 * A configured IdP is trusted only as far as WHO configured it
 * ({@see FederatedProviderContext}), so the policy forks on the trust tier:
 *
 * GLOBAL-TRUST (operator IdP at the system tenant, e.g. real Google) — its
 * `email_verified` is authoritative over the global profile namespace:
 *   1. Existing global `(issuer, subject)` link → that profile ("existing").
 *   2. Unverified IdP email → never link/provision ("refused_unverified").
 *   3. Verified IdP email E:
 *      a. E matches a VERIFIED profile_email → link ("linked").
 *      b. E matches an UNVERIFIED profile_email → refuse ("refused_conflict"):
 *         auto-linking would let the IdP seize a half-registered account.
 *      c. E matches no profile_email → provision a passwordless profile + verified
 *         primary email + global link ("provisioned").
 *
 * TENANT-TRUST (a tenant's bring-your-own IdP) — trusted ONLY within its own
 * tenant; its assertions must never reach another tenant's accounts or the
 * global namespace:
 *   1. Existing `(provider_id, subject)` link → that profile ("existing").
 *   2. Unverified IdP email → refuse ("refused_unverified").
 *   3. Verified IdP email E matching a profile that is a MEMBER of the configuring
 *      tenant (active, or INVITED — in which case the pending invite is
 *      JIT-accepted, WC-635ee381):
 *      a. …with a VERIFIED profile_email → link in the tenant namespace ("linked").
 *      b. …with an UNVERIFIED profile_email → refuse ("refused_conflict").
 *   4. Any other case — E owned by a profile that is NOT a member of this tenant
 *      (incl. members of OTHER tenants), a SUSPENDED member, or no local account
 *      at all — is REFUSED ("refused_no_account"). A tenant-trust IdP CANNOT reach
 *      outside its tenant, and domain-claim JIT PROVISIONING of a brand-new
 *      profile is deferred until domain-ownership verification exists.
 *
 *   NOTE (in-tenant impersonation is by design): a tenant-trust IdP can link to
 *   any of its own active members. That is not an escalation — the tenant admin
 *   who runs the IdP already holds identity authority over its members. The
 *   invariant this class guarantees is that a tenant IdP can NEVER reach a
 *   non-member, and the session it yields is confined to the configuring tenant
 *   (see SsoAuthHandler::callback → completeFederatedLogin(..., restrictToTenantId)).
 *
 * The partial UNIQUE indexes (migration 047) make concurrent first-logins safe:
 * a losing racer's insert violates the constraint and is resolved to the winner's
 * now-existing link.
 */
final class FederatedIdentityLinker
{
    public function __construct(
        private readonly PDO $db,
        private readonly ExternalIdentityRepository $identities,
        private readonly ProfileEmailRepository $emails,
        private readonly MembershipRepository $memberships,
    ) {
    }

    /**
     * @return array{status: 'existing'|'linked'|'provisioned'|'refused_unverified'|'refused_conflict'|'refused_no_account', profile_id?: int}
     */
    public function resolveForLogin(ExternalIdentity $identity, FederatedProviderContext $ctx): array
    {
        // 1. Already linked (within this provider's trust namespace) → log in.
        $existing = $ctx->isGlobalTrust()
            ? $this->identities->findGlobalByIssuerSubject($identity->issuer, $identity->subject)
            : $this->identities->findByProviderSubject($ctx->providerId, $identity->subject);
        if ($existing !== null) {
            $this->identities->touchLastLogin((int) $existing['id']);
            return ['status' => 'existing', 'profile_id' => (int) $existing['profile_id']];
        }

        // 2. No email-based decision without a provider-VERIFIED email.
        if (!$identity->hasVerifiedEmail()) {
            return ['status' => 'refused_unverified'];
        }

        /** @var string $email */
        $email = $identity->normalizedEmail();
        $profileEmail = $this->emails->findByEmail($email);

        if ($ctx->isGlobalTrust()) {
            return $this->resolveGlobalTrust($identity, $ctx, $email, $profileEmail);
        }
        return $this->resolveTenantTrust($identity, $ctx, $email, $profileEmail);
    }

    /**
     * GLOBAL-TRUST branch 3: the operator IdP may act on the global namespace.
     *
     * @param array<string, mixed>|null $profileEmail
     * @return array{status: 'existing'|'linked'|'provisioned'|'refused_conflict', profile_id?: int}
     */
    private function resolveGlobalTrust(
        ExternalIdentity $identity,
        FederatedProviderContext $ctx,
        string $email,
        ?array $profileEmail,
    ): array {
        if ($profileEmail !== null) {
            if ($profileEmail['verified'] !== true) {
                return ['status' => 'refused_conflict'];
            }
            return $this->linkOrResolve($identity, $ctx, $email, (int) $profileEmail['profile_id'], null);
        }
        return $this->provision($identity, $ctx, $email);
    }

    /**
     * TENANT-TRUST branch: link only to a member of the CONFIGURING tenant —
     * either already active, or explicitly INVITED by that tenant's admin (whose
     * pending invite we JIT-accept, WC-635ee381). Anything else — a profile that
     * is not a member of this tenant (incl. members of OTHER tenants), a suspended
     * member, or no local account — is refused. This is the "an IdP bound to
     * tenant X can never mint a membership in tenant Y" guarantee: this branch
     * only ever touches memberships in `$ctx->tenantId`.
     *
     * (Domain-claim JIT provisioning of a brand-new profile is deferred until
     * domain-OWNERSHIP verification exists — a self-asserted `tenant_email_domains`
     * claim must not let a tenant harvest a domain it does not own.)
     *
     * @param array<string, mixed>|null $profileEmail
     * @return array{status: 'existing'|'linked'|'refused_conflict'|'refused_no_account', profile_id?: int}
     */
    private function resolveTenantTrust(
        ExternalIdentity $identity,
        FederatedProviderContext $ctx,
        string $email,
        ?array $profileEmail,
    ): array {
        if ($profileEmail === null) {
            return ['status' => 'refused_no_account'];
        }
        $profileId = (int) $profileEmail['profile_id'];

        // The owning profile must be a member of THIS tenant (active or invited);
        // otherwise a tenant-trust IdP would reach a foreign account (the
        // cross-tenant takeover this tier defends against).
        $membership = $this->memberships->findByProfile($profileId, $ctx->tenantId);
        if ($membership === null) {
            return ['status' => 'refused_no_account'];
        }
        if ($profileEmail['verified'] !== true) {
            return ['status' => 'refused_conflict'];
        }

        $status = (string) $membership['status'];
        if ($status === MembershipRepository::STATUS_SUSPENDED) {
            // Suspended in this tenant → no login (fails closed, same as a non-member).
            return ['status' => 'refused_no_account'];
        }
        if ($status === MembershipRepository::STATUS_INVITED) {
            // JIT-accept the invite tenant X's admin already extended.
            $this->memberships->accept((int) $membership['id'], $ctx->tenantId);
        }
        return $this->linkOrResolve($identity, $ctx, $email, $profileId, $ctx->providerId);
    }

    /**
     * Link an identity to an existing profile in the given trust namespace
     * (`$providerId` NULL = global, non-null = tenant); on a concurrent
     * constraint violation, resolve to the racer's link instead.
     *
     * @return array{status: 'existing'|'linked', profile_id: int}
     */
    private function linkOrResolve(
        ExternalIdentity $identity,
        FederatedProviderContext $ctx,
        string $email,
        int $profileId,
        ?int $providerId,
    ): array {
        try {
            $id = $this->identities->link(
                $profileId,
                $ctx->providerKey,
                $identity->issuer,
                $identity->subject,
                $email,
                $providerId,
            );
            $this->identities->touchLastLogin($id);
            return ['status' => 'linked', 'profile_id' => $profileId];
        } catch (\PDOException) {
            $row = $this->existingLink($identity, $ctx);
            if ($row !== null) {
                $this->identities->touchLastLogin((int) $row['id']);
                return ['status' => 'existing', 'profile_id' => (int) $row['profile_id']];
            }
            throw new \RuntimeException('federated link failed');
        }
    }

    /**
     * Provision a new passwordless profile (GLOBAL-TRUST only: empty password_hash
     * so no password can ever verify), a verified primary email, and the global
     * identity link — atomically. On a race, resolve to the existing link.
     *
     * @return array{status: 'existing'|'provisioned', profile_id: int}
     */
    private function provision(ExternalIdentity $identity, FederatedProviderContext $ctx, string $email): array
    {
        $displayName = $identity->displayName ?? '';
        if ($displayName === '') {
            $at = strpos($email, '@');
            $displayName = $at !== false ? substr($email, 0, $at) : $email;
        }

        $ownTx = !$this->db->inTransaction();
        if ($ownTx) {
            $this->db->beginTransaction();
        }
        try {
            // Passwordless profile: empty password_hash means password_verify() can
            // never succeed, so this account is reachable ONLY via its linked IdP.
            $profileId = $this->insertReturningId(
                "INSERT INTO profiles
                    (display_name, password_hash, two_factor_enabled, two_factor_secret,
                     two_factor_backup_codes_version, token_epoch, created_at, updated_at)
                 VALUES (:dn, '', false, NULL, 0, 0, NOW(), NOW())",
                [':dn' => $displayName]
            );
            $this->emails->insert($profileId, $email, true, true);
            // Global-trust provision → global namespace (provider_id NULL).
            $linkId = $this->identities->link(
                $profileId,
                $ctx->providerKey,
                $identity->issuer,
                $identity->subject,
                $email,
                null,
            );

            if ($ownTx) {
                $this->db->commit();
            }
            $this->identities->touchLastLogin($linkId);
            return ['status' => 'provisioned', 'profile_id' => $profileId];
        } catch (\PDOException $e) {
            // Flow analysis over-narrows $ownTx to always-true here; the guard is
            // still correct for the nested-transaction case (only roll back a tx
            // WE started).
            // @phpstan-ignore booleanAnd.leftAlwaysTrue
            if ($ownTx && $this->db->inTransaction()) {
                $this->db->rollBack();
            }
            // Lost a race on the global unique index → resolve to whatever now
            // exists rather than erroring the login.
            $row = $this->existingLink($identity, $ctx);
            if ($row !== null) {
                $this->identities->touchLastLogin((int) $row['id']);
                return ['status' => 'existing', 'profile_id' => (int) $row['profile_id']];
            }
            throw new \RuntimeException('federated provision failed', 0, $e);
        }
    }

    /**
     * Tier-appropriate existing-link lookup (used for race resolution).
     *
     * @return array<string, mixed>|null
     */
    private function existingLink(ExternalIdentity $identity, FederatedProviderContext $ctx): ?array
    {
        return $ctx->isGlobalTrust()
            ? $this->identities->findGlobalByIssuerSubject($identity->issuer, $identity->subject)
            : $this->identities->findByProviderSubject($ctx->providerId, $identity->subject);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function insertReturningId(string $sql, array $params): int
    {
        $driver = (string) $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'pgsql') {
            $stmt = $this->db->prepare($sql . ' RETURNING id');
            $stmt->execute($params);
            return (int) $stmt->fetchColumn();
        }
        $this->db->prepare($sql)->execute($params);
        return (int) $this->db->lastInsertId();
    }
}
