<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * The tenants and permission-catalogue routes are gated on permission slugs, and
 * the seeded admin still reaches all of them (#990, migration 138).
 *
 * WHAT WAS WRONG
 * --------------
 * Five routes — the four `/api/tenants` verbs and `GET /api/permissions` — were
 * gated on the literal role string `admin`. A role is a row a deployment may
 * rename or replace; a permission slug is a contract. `PermissionResolver`
 * already makes that argument to plugin authors, and it binds a core route
 * harder than it binds a plugin: a tenant that renamed or restructured `admin`
 * lost its own tenants screen with nothing anywhere saying why.
 *
 * The slugs for all five already existed — `tenants:read/write/delete` since
 * migration 002, `permissions:read` since 013 — so nothing here invents
 * vocabulary. That is what makes this the mechanical half of #990; the nine
 * routes whose groups have NO slugs (email-domains, deployments, migrations,
 * admin/stats) are a naming and audience decision per group and are deliberately
 * not touched by the same change.
 *
 * THE TRAP THIS FILE EXISTS TO KEEP CLOSED
 * ----------------------------------------
 * `tenants:read` was in the permission catalogue and held by NOBODY — measured
 * on a freshly migrated and seeded database before anything was changed, and it
 * had been that way since migration 002 because nothing consulted it. Re-gating
 * `GET /api/tenants` onto it without migration 138 would have locked the seeded
 * admin out of the tenant list: it holds `tenants:write` and `tenants:delete`,
 * so it would have survived three of the four gates and failed the one every
 * other tenants screen starts from.
 *
 * That is the same failure #977 found in `roles:read` one group over, so it is
 * asserted directly rather than left to the route tests to imply.
 *
 * WHAT THIS ASSERTS AND WHAT THE GUARD ASSERTS
 * --------------------------------------------
 * Here: the grants exist on a real migrated database, the catalogue declares the
 * five gates, and public/index.php registers them. Not here: that every slug any
 * route gates on is held by somebody — that is
 * `scripts/ci-permission-holder-guard.php` and `whity-cli permissions:unheld`,
 * which ask the question of a deployment rather than of the tree, and which
 * exist precisely so this file does not have to grow a copy of it.
 */
final class TenantRouteGatesRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    /** Every slug the five routes now require. */
    private const REQUIRED_BY_ROUTES = [
        CorePermissions::TENANTS_READ,
        CorePermissions::TENANTS_WRITE,
        CorePermissions::TENANTS_DELETE,
        CorePermissions::PERMISSIONS_READ,
    ];

    /** @return list<string> Permission slugs a role holds. */
    private function slugsHeldBy(string $roleName): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT p.name
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE r.name = :role'
        );
        $stmt->execute([':role' => $roleName]);

        /** @var list<string> $slugs */
        $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $slugs;
    }

    /**
     * The lockout guard. If this fails, shipping locks administrators out of the
     * tenant list.
     */
    public function testTheSeededAdminHoldsEverySlugTheTenantRoutesNowRequire(): void
    {
        $held = $this->slugsHeldBy('admin');

        foreach (self::REQUIRED_BY_ROUTES as $slug) {
            self::assertContains(
                $slug,
                $held,
                "The seeded admin must hold {$slug}, or re-gating the tenants routes locks it out "
                . 'of a screen it administers today.'
            );
        }
    }

    /**
     * Migration 138 is the reason the assertion above passes, and it grants by
     * CAPABILITY rather than by role name — so a deployment running a custom
     * administrative role is covered without the migration guessing at its name.
     *
     * Both anchors are checked, not just `tenants:write`. `DELETE /api/tenants/{id}`
     * is gated on `tenants:delete`, which is separately grantable, and a role
     * holding only that one would otherwise be able to delete a tenant it had no
     * way to find — the id comes from the list this grant unlocks.
     *
     * @dataProvider audienceAnchors
     */
    public function testTenantsReadIsGrantedToEveryRoleThatMayAdministerTenants(string $anchor): void
    {
        $stmt = $this->pdo->prepare(
            'SELECT DISTINCT r.name
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = :anchor'
        );
        $stmt->execute([':anchor' => $anchor]);

        /** @var list<string> $names */
        $names = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotEmpty($names, "Fixture check: somebody must hold {$anchor}.");

        foreach ($names as $roleName) {
            self::assertContains(
                CorePermissions::TENANTS_READ,
                $this->slugsHeldBy($roleName),
                "'{$roleName}' holds {$anchor}, so it must be able to list tenants — there is no "
                . 'coherent deployment in which a role may change what it cannot see.'
            );
        }
    }

    /** @return array<string, array{string}> */
    public static function audienceAnchors(): array
    {
        return [
            'writers' => [CorePermissions::TENANTS_WRITE],
            'deleters' => [CorePermissions::TENANTS_DELETE],
        ];
    }

    /**
     * The payoff: reading the tenant list and deleting a tenant are different
     * questions, and the routes now let a deployment answer them differently.
     *
     * Before this, all four were `admin`, so "may see the tenants" and "may
     * delete a tenant" were one grant with one name.
     */
    public function testReadingAndDeletingATenantAreDifferentGates(): void
    {
        $gates = $this->routeGates('/api/tenants');

        $list = 'GET /api/tenants';
        $delete = 'DELETE /api/tenants/{id:\d+}';

        foreach ([$list, $delete] as $key) {
            self::assertArrayHasKey($key, $gates, "{$key} is not declared in CoreApiSchemas");
        }

        self::assertSame(CorePermissions::TENANTS_READ, $gates[$list]);
        self::assertSame(CorePermissions::TENANTS_DELETE, $gates[$delete]);

        self::assertNotSame(
            $gates[$list],
            $gates[$delete],
            'A deployment must be able to grant one without the other; that is the whole point.'
        );
    }

    /**
     * No re-gated route may go back to a role-name gate, in the CATALOGUE.
     *
     * @dataProvider reGatedRoutes
     */
    public function testEveryReGatedRouteIsDeclaredOnAPermissionAndNotARole(
        string $key,
        string $expected,
        string $prefix
    ): void {
        $declarations = $this->routeDeclarations($prefix);
        self::assertArrayHasKey($key, $declarations, "{$key} is not declared in CoreApiSchemas");

        self::assertNull(
            $declarations[$key]['requiredRole'] ?? null,
            "{$key} is gated on a role name; that couples every deployment to one seeded "
            . 'vocabulary, and a role is a row a tenant may rename while a slug is a contract.'
        );
        self::assertSame($expected, $declarations[$key]['requiredPermission'] ?? null, $key);
    }

    /**
     * And in the LIVE registration, which is the gate that actually refuses
     * callers. The catalogue above is a declaration ABOUT that gate: #977's
     * equivalent test asserted only the catalogue, and a catalogue that agreed
     * with a `public/index.php` nobody re-gated would be a green test over an
     * unchanged door.
     *
     * Read as source rather than by booting the router, for the reason
     * scripts/lib/core-route-table.php gives: public/index.php is a bootstrap,
     * and running it means a database, the plugin loader and the worker loop. One
     * `register()` call per line here, so a line match is exact — and the
     * assertion that the line was FOUND is what stops this passing silently if
     * somebody moves the registration.
     *
     * @dataProvider reGatedRoutes
     */
    public function testEveryReGatedRouteIsRegisteredOnAPermissionAndNotARole(
        string $key,
        string $expected,
        // Shared provider with the catalogue test above; the prefix is that
        // test's lookup key and has no use here.
        string $prefix
    ): void {
        unset($prefix);

        [$method, $path] = explode(' ', $key, 2);
        $needle = sprintf("register('%s', '%s',", $method, $path);

        $source = file_get_contents(dirname(__DIR__, 2) . '/public/index.php');
        self::assertIsString($source, 'public/index.php must be readable');

        $matches = array_values(array_filter(
            explode("\n", $source),
            static fn (string $line): bool => str_contains($line, $needle)
        ));

        self::assertCount(
            1,
            $matches,
            "Expected exactly one `\$router->{$needle}` registration in public/index.php; the gate "
            . 'this test is about is the one written there.'
        );

        self::assertStringNotContainsString(
            "'admin'",
            $matches[0],
            "{$key} still passes the 'admin' role name to register(). The permission argument is "
            . 'the sixth, so a leftover role name in the fourth would gate on BOTH.'
        );
        self::assertStringContainsString(
            'CorePermissions::' . self::constantFor($expected),
            $matches[0],
            "{$key} must register with the {$expected} slug"
        );
    }

    /** @return array<string, array{string, string, string}> */
    public static function reGatedRoutes(): array
    {
        return [
            'tenant list'   => ['GET /api/tenants', CorePermissions::TENANTS_READ, '/api/tenants'],
            'tenant create' => ['POST /api/tenants', CorePermissions::TENANTS_WRITE, '/api/tenants'],
            'tenant update' => ['PATCH /api/tenants/{id:\d+}', CorePermissions::TENANTS_WRITE, '/api/tenants'],
            'tenant delete' => ['DELETE /api/tenants/{id:\d+}', CorePermissions::TENANTS_DELETE, '/api/tenants'],
            'permission catalogue' => ['GET /api/permissions', CorePermissions::PERMISSIONS_READ, '/api/permissions'],
        ];
    }

    /**
     * The `CorePermissions` constant name for a slug, so the source assertion
     * checks the CONSTANT rather than a quoted string — registering
     * `'tenants:read'` as a literal would pass a slug check while stepping around
     * the one place the vocabulary is defined.
     */
    private static function constantFor(string $slug): string
    {
        return str_replace([':', '-'], ['_', '_'], strtoupper($slug));
    }

    /** @return array<string, string|null> "METHOD /path" => required permission */
    private function routeGates(string $prefix): array
    {
        return array_map(
            static fn (array $r): ?string => $r['requiredPermission'] ?? null,
            $this->routeDeclarations($prefix)
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function routeDeclarations(string $prefix): array
    {
        $byKey = [];
        foreach (CoreApiSchemas::routes() as $route) {
            // No shape guard: routes() declares method/path as always present,
            // and PHPStan proves a defensive isset() here dead. Trusting the
            // declared type is the point of having one.
            if (!str_starts_with($route['path'], $prefix)) {
                continue;
            }
            $byKey[$route['method'] . ' ' . $route['path']] = $route;
        }

        return $byKey;
    }
}
