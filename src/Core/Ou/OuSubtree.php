<?php

declare(strict_types=1);

namespace Whity\Core\Ou;

use PDO;

/**
 * "This unit and everything beneath it" — the downward walk over
 * `organizational_units.parent_id`.
 *
 * The reverse direction of {@see \Whity\Auth\RoleChecker}'s upward ancestor
 * walk, and the shape every scoped-to-my-part-of-the-organisation question
 * takes: a 2FA policy set on a faculty applying to its departments
 * ({@see \Whity\Api\TwoFactorPoliciesApiHandler}), a `role_below_actor` routing
 * step resolving to the units under whoever is acting
 * ({@see \Whity\Core\Document\Routing\RoleBelowActorRuleResolver}), and #947
 * item 5's "everything below my unit" folder.
 *
 * It lives here rather than being written a third time because the two existing
 * copies already differed in one respect that matters — whether the roots
 * themselves are included — and a third copy is how "below my unit" comes to
 * mean two different sets of people in two screens of the same product.
 *
 * ROOTS ARE INCLUDED
 * ------------------
 * `descendantIds([5])` returns 5 and everything under it. "Below" in the
 * organisational sense means "within my scope of authority", which contains my
 * own unit: a department head routing to the technicians *below* them means the
 * technicians in their department, and a strict-descendants reading resolves to
 * nobody for every leaf unit in the tree while still reporting success. That is
 * exactly the silent-omission failure #947 item 3 is written against, so the
 * inclusive reading is the safe default and the exclusive one is available by
 * subtracting the roots.
 *
 * BOUNDED, BECAUSE THE DATA CAN BE WRONG
 * --------------------------------------
 * A cycle in `parent_id` is impossible through the API ({@see \Whity\Api\OusApiHandler}
 * refuses a move under a descendant) and perfectly possible in a database
 * somebody has restored, migrated or edited by hand. The walk therefore tracks
 * visited ids AND stops at {@see MAX_DEPTH}, mirroring RoleChecker: a malformed
 * hierarchy costs a truncated answer, never a hung request.
 *
 * ONE QUERY, THEN AN IN-MEMORY BFS
 * --------------------------------
 * The tenant's whole `(id, parent_id)` projection is fetched once and walked in
 * PHP, rather than issuing a query per level or a recursive CTE. Two reasons:
 * the projection is two integers per unit and an organisation with ten thousand
 * units is a large one, so it is small; and a recursive CTE would be the third
 * place in the codebase whose behaviour differs between PostgreSQL and the
 * SQLite the offline/desktop engine runs on. A tenant-scoped fetch also means
 * the walk physically cannot cross into another tenant's tree, whatever root
 * ids it is handed.
 *
 * Stateless — worker-safe.
 */
final class OuSubtree
{
    /**
     * Belt-and-braces bound on the walk, mirroring
     * {@see \Whity\Auth\RoleChecker}'s hierarchy ceiling. An organisation
     * nesting deeper than this has a data problem, not a modelling one.
     */
    public const MAX_DEPTH = 64;

    /**
     * Every unit id reachable downward from `$rootIds`, INCLUDING the roots.
     *
     * Tenant-scoped: units belonging to another tenant are not in the fetched
     * projection, so a root id from another tenant contributes only itself and
     * reaches nothing — which callers turn into "resolved to nobody" rather
     * than a leak.
     *
     * @param list<int> $rootIds
     * @return list<int> Unordered; callers that need determinism sort.
     */
    public static function descendantIds(PDO $db, int $tenantId, array $rootIds): array
    {
        if ($rootIds === []) {
            return [];
        }

        $stmt = $db->prepare('SELECT id, parent_id FROM organizational_units WHERE tenant_id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);

        /** @var array<int, list<int>> $childrenByParent */
        $childrenByParent = [];
        /** @var array<int, true> $known */
        $known = [];
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $row) {
            $known[(int) $row['id']] = true;
            if ($row['parent_id'] !== null) {
                $childrenByParent[(int) $row['parent_id']][] = (int) $row['id'];
            }
        }

        // Roots that are not this tenant's units are dropped rather than
        // returned as themselves: a caller handed another tenant's id would
        // otherwise get it back and could read that as "this unit is in scope".
        $queue = array_values(array_filter($rootIds, static fn (int $id): bool => isset($known[$id])));

        /** @var array<int, true> $visited */
        $visited = [];
        $depth = 0;
        while ($queue !== [] && $depth < self::MAX_DEPTH) {
            $next = [];
            foreach ($queue as $ouId) {
                if (isset($visited[$ouId])) {
                    continue;
                }
                $visited[$ouId] = true;
                foreach ($childrenByParent[$ouId] ?? [] as $childId) {
                    $next[] = $childId;
                }
            }
            $queue = $next;
            $depth++;
        }

        return array_keys($visited);
    }
}
