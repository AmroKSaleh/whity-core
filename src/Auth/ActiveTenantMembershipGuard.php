<?php

declare(strict_types=1);

namespace Whity\Auth;

use PDO;
use Whity\Auth\Exception\InvalidMembershipException;

/**
 * Membership gate for the new {profile_id, active_tenant_id} JWT claims
 * (WC-d4340daf, ADR 0005 §5).
 *
 * The JWT claim model is migrating from the legacy {user_id, tenant_id} pair to
 * {profile_id, active_tenant_id}. A new-claims token declares which tenant the
 * session is acting in (active_tenant_id); that declaration is only trustworthy
 * when the profile actually holds a LIVE membership there. This guard performs
 * that check:
 *
 *  - LEGACY tokens (neither new claim present) pass unchecked — during the
 *    dual-claim window, pre-migration users have no profiles/memberships rows
 *    yet (the users→profiles data migration is the next task in the identity
 *    flow), so the legacy path must keep today's behaviour exactly.
 *  - NEW-CLAIMS tokens (both claims present, integer-valued) require an
 *    `active` memberships row for (profile_id, active_tenant_id) — this is the
 *    mechanism that enforces per-membership suspension without waiting for
 *    token expiry (ADR 0005 §5).
 *  - active_tenant_id = 0 is the SYSTEM tenant: by the platform id-0
 *    convention it carries cross-tenant authority and needs no membership row.
 *  - PARTIAL claim sets (exactly one of the two new claims, or a non-integer
 *    value) are never issued by this codebase and fail closed.
 *
 * Stateless service (per-request instance data only — FrankenPHP worker-safe;
 * no static request state). Callers translate a refusal into a typed HTTP
 * response (401 from token validation, 403 from the isolation middleware);
 * raw exception text is never surfaced.
 */
final class ActiveTenantMembershipGuard
{
    /** The reserved identifier for the system tenant (cross-tenant authority). */
    private const SYSTEM_TENANT_ID = 0;

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Decide whether a decoded claim set may act in its declared active tenant.
     *
     * Boolean form of {@see self::assert()}: used by TokenValidator, where any
     * refusal collapses to a null return (the handlers' generic 401).
     *
     * @param array<string, mixed> $claims Decoded JWT claims.
     * @return bool True to allow; false to refuse (caller emits typed 401/403).
     */
    public function allows(array $claims): bool
    {
        try {
            $this->assert($claims);

            return true;
        } catch (InvalidMembershipException) {
            return false;
        }
    }

    /**
     * Assert that a decoded claim set may act in its declared active tenant.
     *
     * Typed-refusal form used at the HTTP layer (EnforceTenantIsolation):
     *  - {@see InvalidMembershipException} with httpStatus 401 when the new
     *    claim set is malformed/partial (identity not resolvable — this shape
     *    is never issued, so it is treated like an invalid token);
     *  - httpStatus 403 when the identity is known but holds no ACTIVE
     *    membership in the declared tenant (suspension/revocation, ADR 0005 §5).
     *
     * The exception messages are for logs only and are never surfaced to
     * clients (callers emit generic error bodies).
     *
     * @param array<string, mixed> $claims Decoded JWT claims.
     * @throws InvalidMembershipException When the claim set must be refused.
     */
    public function assert(array $claims): void
    {
        $hasProfileId = array_key_exists('profile_id', $claims);
        $hasActiveTenantId = array_key_exists('active_tenant_id', $claims);

        // Legacy token: no new claims at all — the dual-window fallback path.
        // Behaviour must match the pre-WC-d4340daf validator exactly (no
        // membership lookup: pre-migration users have no membership rows).
        if (!$hasProfileId && !$hasActiveTenantId) {
            return;
        }

        // Partial or malformed new-claim sets are never issued: fail closed.
        $profileId = $this->intClaim($claims['profile_id'] ?? null);
        $activeTenantId = $this->intClaim($claims['active_tenant_id'] ?? null);
        if ($profileId === null || $activeTenantId === null) {
            throw new InvalidMembershipException(
                401,
                'Partial or non-integer {profile_id, active_tenant_id} claim set'
            );
        }

        // System tenant (id 0): cross-tenant authority by platform convention —
        // no membership row required (mirrors EnforceTenantIsolation's bypass).
        if ($activeTenantId === self::SYSTEM_TENANT_ID) {
            return;
        }

        if (!$this->hasActiveMembership($profileId, $activeTenantId)) {
            throw new InvalidMembershipException(
                403,
                'No active membership for the declared active tenant'
            );
        }
    }

    /**
     * Whether an `active` memberships row exists for (profile, tenant).
     *
     * `memberships` is tenant-owned: the lookup is scoped to BOTH the profile
     * id and the tenant id (tenant-predicate invariant). 'invited' and
     * 'suspended' rows do not grant access (ADR 0005 §3). Fails closed on any
     * database error.
     */
    private function hasActiveMembership(int $profileId, int $tenantId): bool
    {
        try {
            $stmt = $this->db->prepare(
                "SELECT 1 FROM memberships
                 WHERE profile_id = ? AND tenant_id = ? AND status = 'active'
                 LIMIT 1"
            );
            $stmt->execute([$profileId, $tenantId]);

            return (bool) $stmt->fetchColumn();
        } catch (\Exception) {
            // Fail closed: an unreadable membership table must not grant access.
            return false;
        }
    }

    /**
     * Coerce a claim value to a non-negative int, or null when invalid.
     *
     * Accepts ints and pure decimal-digit strings (matching TenantContext's
     * tenant-claim coercion); everything else is rejected.
     */
    private function intClaim(mixed $value): ?int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        return null;
    }
}
