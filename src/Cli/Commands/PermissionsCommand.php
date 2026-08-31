<?php

declare(strict_types=1);

namespace Whity\Cli\Commands;

use PDO;
use Whity\Core\RBAC\PermissionOccupancy;
use Whity\Database\Database;

/**
 * `permissions:unheld` — which gated permissions nobody in THIS deployment holds
 * (#1047).
 *
 * WHAT IT IS FOR. A permission slug that a route gates on and no role holds is
 * not a permission check: it is a lockout. The gate refuses every caller there
 * is, including the administrator it was written for, and it does so silently —
 * the screen answers 200 to a direct request and simply has no nav entry,
 * because the nav filters out what the caller cannot reach.
 *
 * WHY CI CANNOT ANSWER THIS. `scripts/ci-permission-holder-guard.php` asks the
 * same question of the database CI builds — migrated, SEEDED, administrative
 * role named `admin`. Twenty-seven migrations grant with
 * `SELECT id FROM roles WHERE name = 'admin'`, so on that database every grant
 * lands and every slug looks held. On a deployment whose administrator is called
 * something else, those migrations granted NOBODY and said nothing, because "no
 * role called admin" is indistinguishable from "already granted".
 *
 * So the answer is a property of a deployment, not of the code, and it belongs
 * where the deployment is. `ci-permission-holder-guard.php`'s own docblock says
 * exactly that.
 *
 * IT REPORTS AND DOES NOT REPAIR, deliberately. Restoring those grants means
 * choosing a capability that identifies "the administrator" here — and every
 * anchor available bottoms out in another by-name grant (`settings:manage` is
 * itself granted only by migration 026, by name). An automatic repair would be a
 * guess, and guessing wrong hands `users:write` and `security:manage` to whoever
 * happens to hold the anchor. The operator knows which role is theirs; this
 * tells them what is missing from it.
 *
 * EXIT CODE. 1 when a gated slug is unheld, so this can drive monitoring rather
 * than only being read. 0 when everything gated is answerable by somebody.
 */
final class PermissionsCommand implements CliCommand, CommandHelp
{
    /**
     * THE CONSTRUCTOR CONNECTS TO NOTHING. `CliRunner` builds the command before
     * it looks for `--help`, so reaching for a database here would make
     * `permissions:unheld --help` fail with a connection error on exactly the
     * machine where somebody is reading the help. `queue:work` and
     * `schedule:run` did that once; this does not.
     */
    public function __construct(private readonly ?PDO $pdo = null)
    {
    }

    public function printHelp(string $commandName): bool
    {
        echo "Usage: whity-cli permissions:unheld\n\n";
        echo "Reports permission slugs that a route GATES ON and no role in this\n";
        echo "deployment holds. Such a gate refuses every caller, including the\n";
        echo "administrator it was written for, and shows up as a screen with no\n";
        echo "way to reach it rather than as an error.\n\n";
        echo "Exits 1 when any gated slug is unheld, so it can drive monitoring.\n";

        return true;
    }

    /** @return list<string>|null */
    public function knownFlags(): ?array
    {
        return [];
    }

    public function execute(array $argv): int
    {
        $pdo = $this->pdo ?? Database::connect()->getPdo();
        $occupancy = new PermissionOccupancy($pdo);

        $gated = $this->gatedSlugs();
        if ($gated === null) {
            fwrite(STDERR, "Could not read the route table, so there is nothing to check against.\n");
            fwrite(
                STDERR,
                "This command compares the routes' permission gates with what roles hold. Without\n"
                . "the route table it could only report the catalogue, and a report that quietly\n"
                . "narrows its own subject is worse than no report.\n"
            );

            return 2;
        }

        $unheld = $occupancy->unheld($gated);

        if ($unheld === []) {
            printf(
                "OK: every one of the %d gated permission(s) is held by at least one role.\n",
                count($gated)
            );
            $this->reportUngated($occupancy, $gated);

            return 0;
        }

        printf("FAIL: %d gated permission(s) are held by NO role in this deployment.\n\n", count($unheld));
        foreach ($unheld as $slug) {
            echo "  {$slug}\n";
        }

        echo "\nEvery route gated on one of these refuses every caller. The screen behind it\n";
        echo "still answers 200 to a direct request, so this shows up as a menu with a hole\n";
        echo "in it rather than as an error anybody can search for.\n";

        if (!$occupancy->hasRoleNamedAdmin()) {
            echo "\nLIKELY CAUSE: this deployment has no role named `admin`.\n";
            echo "Twenty-seven migrations grant permissions with `WHERE name = 'admin'` and\n";
            echo "return early when that finds nothing — granting nobody, and reporting no error\n";
            echo "because \"no role called admin\" is indistinguishable from \"already granted\".\n";
            echo "Grant the slugs above to whichever role administers this instance.\n";
        }

        $this->reportUngated($occupancy, $gated);

        return 1;
    }

    /**
     * Catalogue entries nobody holds that nothing gates on — reported, never
     * failed on.
     *
     * A slug nothing consults yet is FINE. Failing on one would be wrong, and
     * folding it in with the real findings would send an operator chasing a slug
     * that is doing exactly what it should.
     *
     * Core's own catalogue has none left as of #990 — `tenants:read` was the
     * last, and `GET /api/tenants` now gates on it with migration 138 behind it
     * — but a plugin may seed a slug ahead of the route that will consult it,
     * and an operator may define one for a role they have not built yet.
     *
     * @param list<string> $gated
     */
    private function reportUngated(PermissionOccupancy $occupancy, array $gated): void
    {
        $ungated = $occupancy->unheldAndUngated($gated);
        if ($ungated === []) {
            return;
        }

        printf(
            "\nAlso unheld, but nothing gates on them yet (not a problem): %s\n",
            implode(', ', $ungated)
        );
    }

    /**
     * The slugs core's routes gate on, from the ONE parser the CI guards use.
     *
     * `scripts/lib/core-route-table.php` rather than a second reader of
     * `public/index.php`: #1020 was the cost of letting one rule have several
     * implementations, and a route table this command parsed differently from
     * the guard would let the two disagree about which gates exist.
     *
     * @return list<string>|null null when the table cannot be read
     */
    private function gatedSlugs(): ?array
    {
        $lib = dirname(__DIR__, 3) . '/scripts/lib/core-route-table.php';
        if (!is_file($lib)) {
            return null;
        }
        require_once $lib;

        $index = dirname(__DIR__, 3) . '/public/index.php';
        try {
            /** @var list<array{requiredPermission: ?string}> $routes */
            $routes = whity_core_route_table($index);
        } catch (\Throwable) {
            return null;
        }

        $slugs = [];
        foreach ($routes as $route) {
            $slug = $route['requiredPermission'] ?? null;
            if (is_string($slug) && $slug !== '') {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }
}
