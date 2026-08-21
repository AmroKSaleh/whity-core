<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;
use Whity\Core\Hooks\HookManager;

/**
 * Repository for the `memberships` table (WC-101).
 *
 * `memberships` is the explicit profile-to-tenant binding introduced by ADR
 * 0005. It replaces the implicit `users.tenant_id` FK with a lifecycle-managed
 * row whose status (active | invited | suspended) controls access.
 *
 * Tenant scoping
 * --------------
 * All methods that read or mutate a specific membership accept a `tenantId`
 * parameter and include `AND tenant_id = :tenant_id` in every statement. This
 * is the tenant-predicate pattern enforced across the platform; a cross-tenant
 * read or write therefore touches zero rows (findById returns null; update/
 * delete returns 0 affected rows).
 *
 * The one deliberate exception is {@see findForProfile()}, used only by the
 * login flow to enumerate a profile's memberships across ALL tenants — it is
 * intentionally unscoped and must not be called from tenant-scoped handlers
 * (see ADR 0005 §6, login flow step 4).
 */
final class MembershipRepository
{
    public const STATUS_ACTIVE    = 'active';
    public const STATUS_INVITED   = 'invited';
    public const STATUS_SUSPENDED = 'suspended';

    private PDO $db;

    /**
     * Optional hook manager used to ANNOUNCE membership writes (#889).
     *
     * Optional, not required, and the reason is the fan-in. Three production
     * callers reach {@see self::insert()} — SSO tenant-trust JIT, SSO
     * first-login provisioning and verified-email domain policy — and none of
     * them held a HookManager, so all three granted real authority in complete
     * silence. Announcing from HERE rather than from each of them means the
     * event exists once, and the next service that writes a membership through
     * this repository is audited without anyone remembering to add it. That is
     * the failure mode being fixed: the hole did not come from a decision, it
     * came from three call sites that each had no reason to think about it.
     *
     * Null-tolerant because the repository is constructed in tests and CLI
     * contexts with no hook manager wired, and a membership write must not
     * depend on one existing.
     */
    private ?HookManager $hooks;

    public function __construct(PDO $db, ?HookManager $hooks = null)
    {
        $this->db = $db;
        $this->hooks = $hooks;
    }

    /**
     * Create a membership with an explicit status (default: active).
     *
     * FOLLOW-UP (WC-c35c4ce0 review, membership-API step): this method has NO
     * `tenant_id > 0` guard, so a caller could create a membership in the system
     * tenant (id 0). The LOGIN path is already hardened (system-tenant memberships
     * are excluded from selection/auto-login, see AuthHandler::listActiveMemberships),
     * so this cannot be exploited to log in with system authority today. But the
     * membership-management API (a later step) should reject/authorise tenant-0
     * membership creation explicitly rather than relying solely on the login-side
     * filter. Not fixed here to avoid expanding this step's scope.
     *
     * @return int The new row's id.
     */
    public function insert(
        int $profileId,
        int $tenantId,
        int $roleId,
        ?int $ouId = null,
        string $status = self::STATUS_ACTIVE,
    ): int {
        $stmt = $this->db->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (:profile_id, :tenant_id, :role_id, :ou_id, :status, NOW())'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id'  => $tenantId,
            ':role_id'    => $roleId,
            ':ou_id'      => $ouId,
            ':status'     => $status,
        ]);
        $membershipId = (int) $this->db->lastInsertId();

        // Announce the grant so it reaches the audit trail (#889). `status` is
        // carried rather than filtered on: an `invited` row is a STAGED grant,
        // not access, and the trail should say which one happened instead of
        // this method deciding that a pending invitation is not worth recording.
        $this->announce('user.membership.added', [
            'profile_id'    => $profileId,
            'tenant_id'     => $tenantId,
            'membership_id' => $membershipId,
            'role_id'       => $roleId,
            'role_name'     => $this->roleName($roleId, $tenantId),
            'ou_id'         => $ouId,
            'status'        => $status,
        ]);

        return $membershipId;
    }

    /**
     * Create an invitation (status = 'invited').
     *
     * The profile cannot log in to this tenant until {@see accept()} is called.
     *
     * @return int The new row's id.
     */
    public function invite(int $profileId, int $tenantId, int $roleId, ?int $ouId = null): int
    {
        return $this->insert($profileId, $tenantId, $roleId, $ouId, self::STATUS_INVITED);
    }

    /**
     * Find a membership by primary key, scoped to a tenant.
     *
     * @return array<string, mixed>|null
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM memberships WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * The ONE membership row that speaks for a profile in a tenant — whatever
     * its status — or null when the profile has no membership there.
     *
     * DELIBERATELY STATUS-AGNOSTIC, and the name is the reason this needs saying:
     * "find by profile" promises a lookup, not a gate. Both core callers read the
     * status themselves and would fail OPEN if rows were hidden from them. A
     * suspended or invited member that reads back as null is indistinguishable
     * from a stranger, and both callers treat a stranger as somebody to onboard:
     * {@see FederatedIdentityLinker::resolveTenantTrust()} would fall through to
     * its domain-claim JIT branch and re-admit the member an admin just
     * suspended, and {@see TenantEmailDomainPolicyService::applyToVerifiedEmail()}
     * would try to provision a fresh membership over the top of the existing one.
     * Callers asking "may this person act here?" — resolving a caller's OU, say —
     * want {@see findActiveByProfile()} or {@see hasActiveMembership()}.
     *
     * ORDER BY, not a bare LIMIT 1: migration 094 traded the table-wide
     * UNIQUE(profile_id, tenant_id) for a partial index over the primary rows
     * only, so a profile may legally hold several memberships in one tenant.
     * Unordered, the engine returns whichever row the plan reaches first, and the
     * `ou_id` every query is then scoped by varies between calls. The primary row
     * is the answer and `id` breaks ties, so the result stays stable even if the
     * partial index were ever missing — identical to
     * {@see \Whity\Auth\RoleChecker::getMembershipRow()}, which was given this
     * ordering when 094 landed while this method was overlooked.
     *
     * @return array<string, mixed>|null
     */
    public function findByProfile(int $profileId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM memberships
             WHERE profile_id = :profile_id AND tenant_id = :tenant_id
             ORDER BY is_primary DESC, id ASC
             LIMIT 1'
        );
        $stmt->execute([':profile_id' => $profileId, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * The ONE ACTIVE membership row that speaks for a profile in a tenant, or
     * null when the profile holds no active membership there.
     *
     * The gate to reach for whenever the answer decides what somebody may see or
     * do — resolving a caller's OU scope, most of all. {@see findByProfile()}
     * reports invited and suspended rows too, so using it for that keeps a
     * suspended member's `ou_id` scoping their every query. This mirrors what
     * resource-grant resolution already does, where an ACTIVE membership is
     * required before grants are consulted at all.
     *
     * Status is judged PER ROW, matching
     * {@see \Whity\Auth\RoleChecker::getActiveMembershipRows()}: suspending
     * somebody's primary role stops THAT role speaking for them without silencing
     * a second role they still legitimately hold. So the filter runs before the
     * ordering, and this returns the primary row only when the primary is active.
     *
     * @return array<string, mixed>|null
     */
    public function findActiveByProfile(int $profileId, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM memberships
             WHERE profile_id = :profile_id AND tenant_id = :tenant_id AND status = :status
             ORDER BY is_primary DESC, id ASC
             LIMIT 1'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id'  => $tenantId,
            ':status'     => self::STATUS_ACTIVE,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Return ALL memberships for a profile across every tenant.
     *
     * INTENTIONALLY UNSCOPED — used only by the login flow (ADR 0005 §6 step 4)
     * to enumerate which tenants a profile belongs to so the caller can determine
     * whether to auto-select a tenant or present a tenant-selection screen.
     * Must NOT be called from tenant-scoped request handlers.
     *
     * @return list<array<string, mixed>>
     */
    public function findForProfile(int $profileId): array
    {
        // @tenant-guard-ignore: login flow — enumerates all tenant memberships for one profile (ADR 0005 §6)
        $stmt = $this->db->prepare(
            'SELECT * FROM memberships WHERE profile_id = :profile_id ORDER BY created_at ASC'
        );
        $stmt->execute([':profile_id' => $profileId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * True when the profile holds an ACTIVE membership in the given tenant.
     *
     * Tenant-scoped existence check (WC-f3b17bd2): the tenant-trust federated
     * login uses it to confirm an IdP may only resolve to one of ITS OWN active
     * members before linking — the cross-tenant-takeover guard.
     */
    public function hasActiveMembership(int $profileId, int $tenantId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM memberships
             WHERE profile_id = :profile_id AND tenant_id = :tenant_id AND status = :status
             LIMIT 1'
        );
        $stmt->execute([
            ':profile_id' => $profileId,
            ':tenant_id'  => $tenantId,
            ':status'     => self::STATUS_ACTIVE,
        ]);
        return $stmt->fetchColumn() !== false;
    }

    /**
     * List memberships in a tenant, optionally filtered by status.
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId, ?string $status = null): array
    {
        if ($status !== null) {
            $stmt = $this->db->prepare(
                'SELECT * FROM memberships WHERE tenant_id = :tenant_id AND status = :status ORDER BY created_at ASC'
            );
            $stmt->execute([':tenant_id' => $tenantId, ':status' => $status]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT * FROM memberships WHERE tenant_id = :tenant_id ORDER BY created_at ASC'
            );
            $stmt->execute([':tenant_id' => $tenantId]);
        }
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map($this->normalizeRow(...), $rows);
    }

    /**
     * Count memberships in a tenant.
     */
    public function countForTenant(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM memberships WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Transition 'invited' → 'active'. Scoped to the tenant so a cross-tenant
     * accept returns 0 and leaves the foreign row intact.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function accept(int $id, int $tenantId): int
    {
        return $this->setStatus($id, $tenantId, self::STATUS_ACTIVE);
    }

    /**
     * Transition any status → 'suspended'. Scoped to the tenant.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function suspend(int $id, int $tenantId): int
    {
        return $this->setStatus($id, $tenantId, self::STATUS_SUSPENDED);
    }

    /**
     * Transition 'suspended' → 'active'. Scoped to the tenant.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function reactivate(int $id, int $tenantId): int
    {
        return $this->setStatus($id, $tenantId, self::STATUS_ACTIVE);
    }

    /**
     * Remove a membership row. Scoped to the tenant so a cross-tenant delete
     * returns 0 and leaves the foreign row intact.
     *
     * @return int Rows affected (1 on success, 0 if not found / wrong tenant).
     */
    public function delete(int $id, int $tenantId): int
    {
        // Read BEFORE the delete (#889). Afterwards there is nothing left to
        // read, and a revocation the trail cannot describe is barely better
        // than one it never saw. This method has no production caller today —
        // every live delete is inline SQL — which is exactly why it is wired
        // now rather than later: the first caller to adopt it should inherit a
        // complete row, not discover the omission from an empty incident
        // timeline.
        $before = $this->findById($id, $tenantId);

        $stmt = $this->db->prepare(
            'DELETE FROM memberships WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $affected = $stmt->rowCount();

        // Only a delete that actually removed something is a revocation. A
        // cross-tenant or not-found call touches zero rows, and recording it
        // would put a revocation that never happened into an append-only trail.
        if ($affected > 0 && $before !== null) {
            $roleId = isset($before['role_id']) ? (int) $before['role_id'] : null;
            $this->announce('user.membership.removed', [
                'profile_id'    => isset($before['profile_id']) ? (int) $before['profile_id'] : null,
                'tenant_id'     => $tenantId,
                'membership_id' => $id,
                'role_id'       => $roleId,
                'role_name'     => $roleId !== null ? $this->roleName($roleId, $tenantId) : null,
                'ou_id'         => isset($before['ou_id']) ? (int) $before['ou_id'] : null,
                'status'        => isset($before['status']) ? (string) $before['status'] : null,
                'granted_at'    => isset($before['created_at']) ? (string) $before['created_at'] : null,
            ]);
        }

        return $affected;
    }

    /**
     * The NAME of a role visible to a tenant — its own, or a global one.
     *
     * Captured into the hook payload beside `role_id` so the audit row stays
     * readable after the role itself is gone. `memberships.role_id` is
     * `ON DELETE CASCADE`, so deleting a role removes every membership holding
     * it with no per-row signal at all; an id recorded without a name is then a
     * pointer into a table that no longer has the row, and "membership removed,
     * role 47" is not an answer to "what access was taken away".
     *
     * The tenant predicate is the platform's ordinary role-visibility rule
     * (own-or-global), so this cannot read another tenant's role name.
     */
    private function roleName(int $roleId, int $tenantId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT name FROM roles WHERE id = :id AND (tenant_id = :tenant_id OR tenant_id IS NULL) LIMIT 1'
        );
        $stmt->execute([':id' => $roleId, ':tenant_id' => $tenantId]);
        $name = $stmt->fetchColumn();

        return $name === false || $name === null ? null : (string) $name;
    }

    /**
     * Dispatch a membership lifecycle event when a hook manager is wired.
     *
     * FAIL-SOFT, deliberately: the audit trail must never be the reason a
     * membership write fails. {@see \Whity\Core\Audit\AuditLogger::record()}
     * already swallows its own write errors for the same reason, but a
     * DIFFERENT listener on the same event — a plugin's — has no such promise,
     * and a plugin throwing must not roll back an SSO login's provisioning.
     *
     * TRANSACTIONS. Unlike the handler-level emitters, which dispatch after
     * their own commit, this fires wherever the CALLER happens to be — and
     * {@see FederatedIdentityLinker::provisionTenantMember()} calls insert()
     * inside a transaction. That is safe rather than merely tolerable: the
     * audit writer holds the SAME PDO connection, so its row is part of the
     * caller's transaction and rolls back with the membership it describes.
     * The trail therefore cannot end up asserting a grant the database
     * discarded, which is the property the post-commit emitters buy explicitly
     * and this one inherits.
     *
     * @param array<string, mixed> $payload
     */
    private function announce(string $event, array $payload): void
    {
        if ($this->hooks === null) {
            return;
        }

        try {
            $this->hooks->dispatch($event, $payload);
        } catch (\Throwable) {
            // Intentionally swallowed; see the method docblock.
        }
    }

    /**
     * Internal helper: set status with tenant predicate.
     */
    private function setStatus(int $id, int $tenantId, string $status): int
    {
        $stmt = $this->db->prepare(
            'UPDATE memberships SET status = :status WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':status' => $status, ':id' => $id, ':tenant_id' => $tenantId]);
        return $stmt->rowCount();
    }

    /**
     * Cast PDO string columns to proper PHP types.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'profile_id' => (int) $row['profile_id'],
            'tenant_id'  => (int) $row['tenant_id'],
            'role_id'    => (int) $row['role_id'],
            'ou_id'      => $row['ou_id'] !== null ? (int) $row['ou_id'] : null,
            'status'     => (string) $row['status'],
            'created_at' => (string) $row['created_at'],
        ];
    }
}
