<?php

declare(strict_types=1);

namespace Tests\Unit;

use PHPUnit\Framework\TestCase;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionCompanions;

/**
 * The companion map (#1040) — the half of the fix that makes acquisition order
 * stop mattering.
 *
 * Migration 137 repairs the roles that hold `documents:route` TODAY. A role
 * granted it tomorrow is the case that migration cannot reach, and this map is
 * what covers it: the companion travels with the grant instead of being
 * back-filled by a migration that has already run.
 */
final class PermissionCompanionsTest extends TestCase
{
    public function testGrantingDocumentsRouteCarriesGroupsRead(): void
    {
        self::assertSame(
            [CorePermissions::DOCUMENTS_ROUTE, CorePermissions::GROUPS_READ],
            PermissionCompanions::expand([CorePermissions::DOCUMENTS_ROUTE]),
            'a route composer must be able to read the groups it routes to; migration 116 '
            . 'already decided that audience, and this is the same decision at grant time'
        );
    }

    public function testCompanionsComeAfterWhatAskedForThem(): void
    {
        // Ordering is a readability promise, not an implementation detail: a
        // caller logging the result should read "what was requested, then what
        // came with it".
        $expanded = PermissionCompanions::expand([
            CorePermissions::ROLES_WRITE,
            CorePermissions::DOCUMENTS_ROUTE,
        ]);

        self::assertSame(
            [
                CorePermissions::ROLES_WRITE,
                CorePermissions::DOCUMENTS_ROUTE,
                CorePermissions::GROUPS_READ,
            ],
            $expanded
        );
    }

    public function testAlreadyRequestedCompanionIsNotDuplicated(): void
    {
        $expanded = PermissionCompanions::expand([
            CorePermissions::GROUPS_READ,
            CorePermissions::DOCUMENTS_ROUTE,
        ]);

        self::assertSame(
            [CorePermissions::GROUPS_READ, CorePermissions::DOCUMENTS_ROUTE],
            $expanded,
            'a caller who asked for both must not get a duplicate id into an INSERT'
        );
    }

    public function testAnUnrelatedGrantPullsInNothing(): void
    {
        self::assertSame(
            [CorePermissions::USERS_READ],
            PermissionCompanions::expand([CorePermissions::USERS_READ])
        );
    }

    public function testExpandingNothingGivesNothing(): void
    {
        self::assertSame([], PermissionCompanions::expand([]));
    }

    /**
     * Every slug in the map is a real permission.
     *
     * A typo would make the entry silently inert — `expand()` would return the
     * primary unchanged and nothing would report a problem, which is the same
     * class of quiet nothing-happened as the by-name grants in #1047.
     */
    public function testEverySlugInTheMapIsInTheCatalogue(): void
    {
        $catalogue = CorePermissions::all();

        foreach (PermissionCompanions::primaries() as $primary) {
            self::assertContains($primary, $catalogue, "primary '{$primary}' is not a core permission");

            foreach (PermissionCompanions::forSlug($primary) as $companion) {
                self::assertContains(
                    $companion,
                    $catalogue,
                    "companion '{$companion}' of '{$primary}' is not a core permission"
                );
            }
        }
    }

    /**
     * No companion is itself a primary.
     *
     * `expand()` is deliberately one hop. A chain would still resolve here — the
     * second hop simply would not be followed — so the map has to be flat for
     * the one-hop rule to mean what it says, and this is what keeps it flat.
     */
    public function testTheMapIsOneHopDeep(): void
    {
        $primaries = PermissionCompanions::primaries();

        foreach ($primaries as $primary) {
            foreach (PermissionCompanions::forSlug($primary) as $companion) {
                self::assertNotContains(
                    $companion,
                    $primaries,
                    "'{$companion}' is a companion AND a primary, so expand() would need a second hop"
                );
            }
        }
    }
}
