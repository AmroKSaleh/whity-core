<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

use PDO;

/**
 * Which permissions THIS deployment's roles actually hold (#1047).
 *
 * WHY AN OPERATOR-FACING REPORT AND NOT A CI GATE
 * -----------------------------------------------
 * `scripts/ci-permission-holder-guard.php` already checks that every gated slug
 * has a holder — and it cannot see this class of problem, because of WHERE it
 * runs. CI migrates, SEEDS, and then checks: a fresh install whose administrative
 * role is named `admin`. Every `SELECT id FROM roles WHERE name = 'admin'` grant
 * lands there, so every slug looks held.
 *
 * On a deployment whose administrator is called `superuser`, `operator`, or
 * anything else, those same migrations granted NOBODY — silently, because "no
 * role called admin" is indistinguishable from "already granted" — and the
 * capability surfaces as a screen that answers 200 and has no way to reach it.
 * Twenty-seven migrations resolve a role by name; `ci-grant-by-role-name-guard`
 * stops the twenty-eighth, and this reports the damage the first twenty-seven
 * may already have done HERE.
 *
 * That guard's own docblock says where this belongs:
 *
 *   "If occupancy is worth enforcing it belongs in an operator-facing health
 *    check against a LIVE database, where the answer is about that deployment
 *    and someone can act on it."
 *
 * WHY IT DOES NOT RE-GRANT ANYTHING
 * ---------------------------------
 * Because identifying "the administrator" requires an anchor capability, and
 * every anchor available bottoms out in another by-name grant — `settings:manage`
 * itself is granted only by migration 026, by name. So an automatic repair would
 * be a guess, and guessing wrong hands `users:write` and `security:manage` to
 * whoever happens to hold the anchor. A report names the gap and leaves the
 * grant to somebody who knows which role is theirs.
 *
 * HELD MEANS HELD BY ANY ROLE, IN ANY TENANT. A route gate is instance-wide, so
 * one role anywhere holding the slug means the gate is answerable by somebody.
 * Narrowing this per tenant would report a slug as missing for tenants that
 * never wanted it, which is noise rather than a finding.
 */
final class PermissionOccupancy
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Slugs from `$gated` that NO role holds.
     *
     * Returned in the caller's order so a report reads the same way twice.
     *
     * @param list<string> $gated slugs something gates on
     * @return list<string>
     */
    public function unheld(array $gated): array
    {
        if ($gated === []) {
            return [];
        }

        $held = $this->heldSlugs($gated);

        return array_values(array_filter($gated, static fn (string $slug): bool => !isset($held[$slug])));
    }

    /**
     * Which of the given slugs are held, as a set, in one statement.
     *
     * @param list<string> $slugs
     * @return array<string, true>
     */
    public function heldSlugs(array $slugs): array
    {
        if ($slugs === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($slugs), '?'));
        $stmt = $this->db->prepare(
            "SELECT DISTINCT p.name
               FROM permissions p
               JOIN role_permissions rp ON rp.permission_id = p.id
              WHERE p.name IN ({$placeholders})"
        );
        $stmt->execute(array_values($slugs));

        $held = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $name) {
            $held[(string) $name] = true;
        }

        return $held;
    }

    /**
     * Whether a role literally named `admin` exists.
     *
     * THE SPECIFIC DIAGNOSIS for this deployment. Twenty-seven migrations grant
     * with `WHERE name = 'admin'`, so the answer decides whether those grants
     * landed at all — and it turns a list of unheld slugs from a puzzle into a
     * cause. Without it an operator sees "these fifteen permissions are held by
     * nobody" and has no reason to connect that to having renamed a role.
     */
    public function hasRoleNamedAdmin(): bool
    {
        // The twenty-seven by-name migrations run
        // `SELECT id FROM roles WHERE name = 'admin'` with NO tenant predicate
        // either, so a per-tenant answer here would report "no admin role" for
        // tenants whose grants those migrations DID land on — and would miss the
        // deployment-wide fact the operator needs. Mirroring the unqualified
        // lookup is what makes the diagnosis true about what actually happened.
        //
        // (The tag goes LAST: the scanner reads at most three lines above the
        // statement, so an annotation opening a longer block never reaches it.)
        // @tenant-guard-ignore: mirrors the unqualified lookup the by-name grant migrations themselves perform
        $stmt = $this->db->prepare("SELECT 1 FROM roles WHERE name = 'admin' LIMIT 1");
        $stmt->execute();

        return $stmt->fetchColumn() !== false;
    }

    /**
     * Slugs in the catalogue that no role holds AND nothing gates on.
     *
     * Reported SEPARATELY from {@see unheld()} and never as a failure: a
     * catalogue entry nothing consults yet is fine, and there is one in the tree
     * on purpose (`tenants:read`, kept for a re-gate #990 has not made). Folding
     * the two together would make an operator chase a slug that is doing exactly
     * what it should.
     *
     * @param list<string> $gated
     * @return list<string>
     */
    public function unheldAndUngated(array $gated): array
    {
        $stmt = $this->db->query('SELECT name FROM permissions ORDER BY name');
        if ($stmt === false) {
            return [];
        }

        /** @var list<string> $all */
        $all = array_map(static fn ($n): string => (string) $n, $stmt->fetchAll(PDO::FETCH_COLUMN));
        $gatedSet = array_fill_keys($gated, true);
        $candidates = array_values(array_filter($all, static fn (string $s): bool => !isset($gatedSet[$s])));

        return $this->unheld($candidates);
    }
}
