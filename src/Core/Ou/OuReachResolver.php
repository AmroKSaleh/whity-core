<?php

declare(strict_types=1);

namespace Whity\Core\Ou;

use Closure;
use PDO;
use Whity\Core\RBAC\ResourceRoleAssignmentRepository;
use Whity\Core\RBAC\ResourceTypeRegistry;

/**
 * "Which units does this person have standing at?" — a profile's organisational
 * REACH in one tenant.
 *
 * The narrowing half of RBAC-scoped access to document templates and design
 * blocks. {@see \Whity\Core\Document\DocumentAccessPolicy} asks it whether a
 * caller reaches the unit a template is placed at; a dean's secretary standing
 * at the Faculty reaches every department beneath it, a department head's
 * secretary standing at one department reaches only that department, and so the
 * two see different template sets while holding the same `documents:write`.
 *
 * WHY THIS CANNOT BE `PermissionResolver` WITH A RESOURCE SCOPE
 * ------------------------------------------------------------
 * It is the first thing to try, and it cannot work. Resource-scoped resolution
 * is ADDITIVE BY CONSTRUCTION — {@see \Whity\Auth\RoleChecker} unions the
 * resource grants onto the profile's tenant-wide roles, and says so:
 * "a resource grant can only widen authority at that resource, never narrow it".
 * So `hasPermission($p, $t, 'documents:write', 'ou', $anyUnit)` is TRUE for
 * every unit the moment the profile holds `documents:write` tenant-wide, which
 * both secretaries do. An additive resolver can express an exception; it cannot
 * express a restriction, and "different sets for the same permission" is a
 * restriction.
 *
 * That is not a defect in the resolver — additivity is what makes a grant safe —
 * it just means the WHERE dimension has to be a second predicate, ANDed with the
 * capability gate rather than folded into it. Reach narrows, permission gates,
 * and both must pass.
 *
 * REACH IS DOWNWARD: MY UNIT, AND EVERYTHING BENEATH IT
 * ----------------------------------------------------
 * Authority in an organisation reaches down, so a template placed at or beneath
 * a unit I stand at is in my scope, and one placed ABOVE me is not. That is what
 * produces the requested asymmetry: the dean's secretary's subtree strictly
 * contains the department secretary's, so her template set strictly contains
 * theirs.
 *
 * The opposite reading — "I see what is filed at or above me", publication
 * cascading downward — was considered and rejected because it inverts the
 * requirement: the department secretary would see their own department's
 * templates PLUS the faculty's, and the dean's secretary only the faculty's,
 * making the junior of the two see strictly more. Tenant-wide artifacts are
 * served instead by leaving `owner_ou_id` NULL, which is what every existing row
 * already is.
 *
 * The walk itself is {@see OuSubtree::descendantIds()} — root-inclusive, bounded,
 * tenant-scoped. It is not re-implemented here; a second downward walk is how
 * "beneath my unit" comes to mean two different sets in two screens of one
 * product, which is the argument that class already records.
 *
 * TWO SOURCES OF STANDING
 * -----------------------
 *  1. MEMBERSHIP — every ACTIVE membership's unit. A person stands where they
 *     work. This is what makes the migration a pure addition: standing needs no
 *     configuration to exist, so an installation that starts placing templates
 *     does not first have to write a grant for everybody.
 *
 *  2. A ROLE GRANTED AT A UNIT — `resource_role_assignments` rows of type `ou`
 *     naming this profile (migration 088). This is the part that cannot be
 *     derived from the tree: "the dean's secretary also covers Materials
 *     Science" is a fact about a person, not about the shape of the
 *     organisation, and it must survive Materials Science being reparented.
 *     It is also the reason placement is not itself the access rule.
 *
 * Only PROFILE-ADDRESSED grants count. An everyone-grant (`profile_id IS NULL`)
 * means "everyone with access to this unit gets role R here" and is not itself
 * access — {@see ResourceRoleAssignmentRepository::resourceIdsForProfile()}
 * enforces that and records why. Treating one as standing would hand the whole
 * tenant authority at any unit carrying a single everyone-grant.
 *
 * `ou_role_assignments` (migration 008) is deliberately NOT a source. It has no
 * `profile_id` — it can only say "everyone in this unit gets role R" — so it
 * cannot express standing for one person, which is the entire case here. Its
 * rows still do their job unchanged: RoleChecker's ancestor walk turns them into
 * ROLES, and a role is a capability, which is the other predicate.
 *
 * A PROFILE WITH NO STANDING REACHES NOTHING
 * ------------------------------------------
 * No active membership and no grant means an empty reach, so every PLACED row is
 * invisible to them and every unplaced one is unaffected. Fails closed, and
 * costs nothing on an installation that has not placed anything.
 */
final class OuReachResolver
{
    public function __construct(
        private readonly PDO $db,
        private readonly ResourceRoleAssignmentRepository $resourceRoles,
    ) {
    }

    /**
     * Every unit this profile has standing at, in this tenant.
     *
     * @return list<int> Unordered; callers that need determinism sort.
     */
    public function reachableOuIds(int $tenantId, int $profileId): array
    {
        $roots = $this->standingOuIds($tenantId, $profileId);
        if ($roots === []) {
            return [];
        }

        return OuSubtree::descendantIds($this->db, $tenantId, $roots);
    }

    /**
     * The reach as a CLOSURE with the tenant and profile already bound, resolved
     * ONCE.
     *
     * The shape {@see \Whity\Core\Document\Organizer\DocumentViewContext} argues
     * for and gets: a capability that can answer "do you reach this unit?" and
     * nothing else, of this tenant and this profile and no other. Handing the
     * policy this resolver plus a tenant id instead would put a connection —
     * and the means to run any query at all — behind an interface whose whole
     * job is to answer one boolean.
     *
     * Resolved eagerly rather than per call because a list request asks it once
     * per row, and a per-row query would make visibility filtering cost O(rows)
     * round trips for an answer that cannot change inside one request.
     *
     * @return Closure(int): bool
     */
    public function reachFor(int $tenantId, int $profileId): Closure
    {
        $reach = array_fill_keys($this->reachableOuIds($tenantId, $profileId), true);

        return static fn (int $ouId): bool => isset($reach[$ouId]);
    }

    /**
     * Whether a unit id is one of this tenant's units — the check a caller runs
     * before FILING something at it.
     *
     * It lives on this class rather than as a fourth private one-liner in a
     * handler ({@see \Whity\Api\OusApiHandler}, {@see \Whity\Api\DocumentsApiHandler}
     * and {@see \Whity\Api\TwoFactorPoliciesApiHandler} each carry a copy) because
     * the designer handlers already hold this collaborator, so reusing it costs
     * no wiring — and because a placement guard that drifts from the reach
     * calculation it guards is how a row comes to be filed somewhere reach can
     * never look.
     */
    public function existsInTenant(int $tenantId, int $ouId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM organizational_units WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => $ouId, ':tenant_id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The units this profile stands AT, before the downward walk — their active
     * memberships' units, plus the units a role is granted to them at.
     *
     * @return list<int>
     */
    private function standingOuIds(int $tenantId, int $profileId): array
    {
        $ids = [];
        foreach ($this->activeMembershipOuIds($tenantId, $profileId) as $ouId) {
            $ids[$ouId] = true;
        }
        foreach (
            $this->resourceRoles->resourceIdsForProfile(
                $tenantId,
                ResourceTypeRegistry::TYPE_OU,
                $profileId
            ) as $ouId
        ) {
            $ids[$ouId] = true;
        }

        return array_keys($ids);
    }

    /**
     * Every ACTIVE membership's unit for this profile in this tenant.
     *
     * NOT {@see PrimaryMembershipOu::forProfile()}, which answers a deliberately
     * SINGULAR question — "which unit is this person acting FROM?" — because its
     * callers stamp one id onto a record. Reach is a set: a doctor attending in
     * Emergency and part-timing in Family Medicine stands in both, and taking
     * only the primary would hide the second unit's templates from them with
     * nothing to say why.
     *
     * Not {@see \Whity\Auth\RoleChecker} either: its equivalent read is private
     * and returns roles, and exposing it would widen a security-critical class's
     * surface to serve a read that is one literal statement here — where the
     * tenant predicate is spelled out for the CI predicate scanner to verify.
     *
     * @return list<int>
     */
    private function activeMembershipOuIds(int $tenantId, int $profileId): array
    {
        $stmt = $this->db->prepare(
            "SELECT DISTINCT ou_id FROM memberships
              WHERE tenant_id = :tenant_id
                AND profile_id = :profile_id
                AND status = 'active'
                AND ou_id IS NOT NULL"
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);

        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) ?: [] as $ouId) {
            $out[] = (int) $ouId;
        }

        return $out;
    }
}
