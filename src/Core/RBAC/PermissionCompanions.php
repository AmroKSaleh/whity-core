<?php

declare(strict_types=1);

namespace Whity\Core\RBAC;

/**
 * Permissions that must travel with another one when it is GRANTED (#1040).
 *
 * THE DEFECT THIS CLOSES
 * ----------------------
 * Migration 116 grants `groups:read` to every role holding `documents:route`,
 * evaluated AT MIGRATION TIME. Migration 137 re-ran that against the audience as
 * it stood, which repairs existing installs and closes nothing: a role granted
 * `documents:route` AFTERWARDS still does not receive `groups:read`, so it can
 * compose a route and then take a 403 listing the groups it needs to route to.
 * A capability-based grant evaluated at one moment cannot cover anyone who
 * acquires the capability later.
 *
 * REAL GRANT ROWS, NOT AN IMPLICATION AT CHECK TIME
 * -------------------------------------------------
 * The obvious alternative is to teach permission resolution that
 * `documents:route` IMPLIES `groups:read`. This project has already rejected
 * that shape once, in {@see RecordSectionResolver::mayWrite()}: "the two are
 * independent slugs, and 'implied' is how a deployment that granted one without
 * the other gets a surprise."
 *
 * The objection is sound and applies here. An implication is invisible in the
 * role editor — the permission picker would show `groups:read` unchecked while
 * the holder effectively has it — and REVOKING it would do nothing, which is the
 * worst property a permission screen can have. So a companion is written as an
 * ordinary row: it appears in the picker, it is auditable, and an administrator
 * who genuinely wants the route composer blind to group names can take it back.
 *
 * That is also why nothing here runs on REVOKE. Removing `documents:route` does
 * not remove `groups:read`, because by then the grant is a fact somebody may
 * have come to rely on for its own sake, and silently retracting a permission
 * the administrator can see is the same surprise from the other direction.
 *
 * WHY A COMPANION RATHER THAN FOLDING THE SLUG AWAY
 * -------------------------------------------------
 * The third option #1040 lists is to stop requiring `groups:read` for the
 * picker at all and let `documents:route` cover it. That is a smaller permission
 * surface and it is not free: `requiredPermission` on a route descriptor is a
 * single string, so "either of" would have to be expressed in the route
 * catalogue, the frontend-features resolver and every consumer of both. And
 * migration 116 argued `groups:read` earns its own existence — the NAMES of a
 * tenant's groups are informative ("Under investigation" is a sentence), so
 * enumerating them is a capability rather than a courtesy. Keeping the slug and
 * making acquisition order stop mattering preserves that argument.
 *
 * ADDING AN ENTRY IS A PERMISSION DECISION. It widens what a grant means, so it
 * belongs to whoever owns both slugs, and the companion must be something the
 * holder of the primary genuinely cannot do their job without — not merely
 * something convenient.
 */
final class PermissionCompanions
{
    /**
     * Primary slug => the slugs granted alongside it.
     *
     * @var array<string, list<string>>
     */
    private const COMPANIONS = [
        // Composing a route means naming the audiences a step reaches, and the
        // group picker is how those are chosen. Migration 116 already decided
        // this audience should hold `groups:read`; this is the same decision
        // applied when the capability is acquired rather than only when the
        // migration ran.
        CorePermissions::DOCUMENTS_ROUTE => [CorePermissions::GROUPS_READ],
    ];

    /** Static only. */
    private function __construct()
    {
    }

    /**
     * The given slugs plus every companion they pull in.
     *
     * De-duplicated and order-preserving, with companions appended after the
     * slugs that caused them so a caller logging the result reads it as "what
     * was asked for, then what came with it".
     *
     * NOT transitive, deliberately: a companion does not pull in ITS companions.
     * One hop is auditable in a picker — "you also get this" — and a chain is
     * not, and nothing in the map today has a second hop to follow.
     *
     * @param list<string> $slugs
     * @return list<string>
     */
    public static function expand(array $slugs): array
    {
        $out = [];
        foreach ($slugs as $slug) {
            $out[$slug] = true;
        }

        foreach ($slugs as $slug) {
            foreach (self::COMPANIONS[$slug] ?? [] as $companion) {
                $out[$companion] = true;
            }
        }

        return array_keys($out);
    }

    /**
     * The companions a single slug carries, for a caller that wants to SAY so.
     *
     * @return list<string>
     */
    public static function forSlug(string $slug): array
    {
        return self::COMPANIONS[$slug] ?? [];
    }

    /**
     * Every slug that carries companions.
     *
     * For callers working in permission IDs rather than slugs — the roles API
     * resolves a request to ids before writing, and comparing ids is cheaper and
     * less error-prone than mapping every id back to its name to look it up
     * here.
     *
     * @return list<string>
     */
    public static function primaries(): array
    {
        return array_keys(self::COMPANIONS);
    }
}
