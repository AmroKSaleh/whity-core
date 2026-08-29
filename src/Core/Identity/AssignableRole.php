<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Whether a tenant may put somebody into a given role — asked in one place,
 * because it was being answered in two and they disagreed.
 *
 * THE UNDERLYING GAP. `tenant_email_domains.default_role_id` is a foreign key to
 * `roles(id)` with NO tenant constraint. Roles are either tenant-owned or global
 * (`tenant_id IS NULL`), so the database is satisfied by ANY role in the
 * installation — including one belonging to a different tenant. The stored value
 * decides what a new arrival on that domain is provisioned into, so "any role in
 * the installation" is the wrong set.
 *
 * WHY THIS IS A CLASS AND NOT A LINE OF SQL IN TWO FILES. Both readers of that
 * column reached opposite conclusions about trusting it:
 * {@see FederatedIdentityLinker} checked the role's tenant and fell back to least
 * privilege; {@see TenantEmailDomainPolicyService} cast the column to int and
 * provisioned. A shared answer is the only version of this fix that cannot drift
 * apart again the next time somebody adds a third consumer.
 *
 * TWO ANSWERS, DELIBERATELY. Reading and writing want different things from the
 * same question:
 *
 *   {@see isAssignable()} — for a WRITE. An administrator is CHOOSING a role;
 *   quietly substituting a different one would be a worse answer than telling
 *   them the choice is unavailable. Callers refuse.
 *
 *   {@see resolveSafe()} — for a READ. There is a row already stored and a person
 *   waiting on it, so failing shut would strand somebody over a configuration
 *   mistake made earlier. Callers get the global `user` role instead: the
 *   membership still happens, at the lowest privilege the installation defines.
 *
 * The write path is the one that stops NEW bad rows. The read path exists for
 * rows written before it did.
 */
final class AssignableRole
{
    /** The floor a read falls back to: the base global role, by name. */
    public const FALLBACK_ROLE_NAME = 'user';

    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Whether this tenant may assign this role: its own, or a global one.
     *
     * Says nothing about WHY a role is unassignable. A tenant probing ids must
     * not be able to tell "belongs to somebody else" from "does not exist", or
     * this becomes a cross-tenant role enumerator.
     */
    public function isAssignable(int $roleId, int $tenantId): bool
    {
        if ($roleId <= 0) {
            return false;
        }

        // @tenant-guard-ignore: `roles` is scoped in the predicate itself — the
        // query's whole purpose is to ask whether this role is the tenant's own
        // OR global, so a bare `tenant_id = :tid` would answer a different and
        // wrong question by excluding every global role.
        $stmt = $this->db->prepare(
            'SELECT 1 FROM roles WHERE id = :rid AND (tenant_id = :tid OR tenant_id IS NULL)'
        );
        $stmt->execute([':rid' => $roleId, ':tid' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The role to actually use for a stored claim: the claimed one when this
     * tenant may assign it, otherwise the base global role.
     *
     * Returns null only when the installation has no global `user` role at all,
     * which is a broken installation rather than a refusal — callers treat it as
     * "cannot provision" rather than papering over it with some other role.
     */
    public function resolveSafe(int $claimedRoleId, int $tenantId): ?int
    {
        if ($this->isAssignable($claimedRoleId, $tenantId)) {
            return $claimedRoleId;
        }

        // @tenant-guard-ignore: reads the GLOBAL fallback role by definition —
        // `tenant_id IS NULL` is the predicate, and scoping it to a tenant would
        // select the wrong row or none.
        $stmt = $this->db->prepare(
            'SELECT id FROM roles WHERE name = :name AND tenant_id IS NULL LIMIT 1'
        );
        $stmt->execute([':name' => self::FALLBACK_ROLE_NAME]);
        $id = $stmt->fetchColumn();

        return $id !== false ? (int) $id : null;
    }
}
