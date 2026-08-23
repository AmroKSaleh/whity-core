<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * The roles routes are gated on permission slugs, and the seeded admin still
 * reaches all of them (#977, migrations 111).
 *
 * WHAT WAS WRONG
 * --------------
 * All nine roles routes were gated on the literal role string `admin`. #975
 * gives the role record page three states per region — hidden, read-only,
 * editable — resolved server-side against `permissions:read` and `roles:manage`.
 * That machinery was correct and unreachable: the door in front of it admitted
 * only holders of one seeded role name, so no deployment could express "may
 * read roles but not grant permissions" however it built its roles.
 *
 * A role name also couples every deployment to one vocabulary. `PermissionResolver`
 * already makes that argument to plugin authors; it binds a core route harder
 * than it binds a plugin, because a tenant that renames or restructures `admin`
 * loses its own roles screen.
 *
 * THE TRAP THIS FILE EXISTS TO KEEP CLOSED
 * ----------------------------------------
 * `roles:read` was in the permission catalogue and held by NOBODY — verified
 * before the change, and it had been that way since 013 because nothing
 * consulted it. Re-gating `GET /api/roles` onto it without migration 111 would
 * have locked the seeded admin out of the roles list: it holds `roles:write`,
 * `roles:delete`, `roles:manage` and `permissions:read`, so it would have
 * survived eight of the nine gates and failed the most-used one.
 *
 * That is the single way this change goes badly, so it is asserted directly
 * rather than left to the route tests to imply.
 */
final class RolesRouteGatesRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    /** Every slug the nine routes now require. */
    private const REQUIRED_BY_ROUTES = [
        CorePermissions::ROLES_READ,
        CorePermissions::ROLES_WRITE,
        CorePermissions::ROLES_DELETE,
        CorePermissions::ROLES_MANAGE,
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
     * The lockout guard. If this fails, shipping locks administrators out of
     * their own roles screen.
     */
    public function testTheSeededAdminHoldsEverySlugTheRolesRoutesNowRequire(): void
    {
        $held = $this->slugsHeldBy('admin');

        foreach (self::REQUIRED_BY_ROUTES as $slug) {
            self::assertContains(
                $slug,
                $held,
                "The seeded admin must hold {$slug}, or re-gating the roles routes locks it out of "
                . 'a screen it administers today.'
            );
        }
    }

    /**
     * Migration 111 is the reason the assertion above passes, and it grants by
     * CAPABILITY rather than by role name — so a deployment running a custom
     * administrative role is covered without the migration guessing at its name.
     */
    public function testRolesReadIsGrantedToEveryRoleThatMayWriteRoles(): void
    {
        $writers = $this->pdo->query(
            "SELECT DISTINCT r.name
               FROM role_permissions rp
               JOIN roles r ON r.id = rp.role_id
               JOIN permissions p ON p.id = rp.permission_id
              WHERE p.name = '" . CorePermissions::ROLES_WRITE . "'"
        );
        self::assertNotFalse($writers);

        /** @var list<string> $names */
        $names = $writers->fetchAll(PDO::FETCH_COLUMN);
        self::assertNotEmpty($names, 'Fixture check: somebody must be able to write roles.');

        foreach ($names as $roleName) {
            self::assertContains(
                CorePermissions::ROLES_READ,
                $this->slugsHeldBy($roleName),
                "'{$roleName}' may write roles, so it must be able to list them — there is no "
                . 'coherent deployment in which a role may write what it cannot see.'
            );
        }
    }

    /**
     * The payoff: reading a role's permissions and changing them are different
     * questions, and the routes now draw the line #975 already draws per region.
     *
     * Before this, both were `admin`, so the record page was strictly more
     * granular than the API behind it.
     */
    public function testSeeingAndChangingARolesPermissionsAreDifferentGates(): void
    {
        $gates = $this->routeGates();

        $read = 'GET /api/roles/{id:\d+}/permissions';
        $grant = 'POST /api/roles/{id:\d+}/permissions';
        $revoke = 'DELETE /api/roles/{id:\d+}/permissions';

        foreach ([$read, $grant, $revoke] as $key) {
            self::assertArrayHasKey($key, $gates, "{$key} is not declared in CoreApiSchemas");
        }

        self::assertSame(CorePermissions::PERMISSIONS_READ, $gates[$read]);
        self::assertSame(CorePermissions::ROLES_MANAGE, $gates[$grant]);
        self::assertSame(CorePermissions::ROLES_MANAGE, $gates[$revoke]);

        self::assertNotSame(
            $gates[$read],
            $gates[$grant],
            'A deployment must be able to grant one without the other; that is the whole point.'
        );
    }

    /**
     * No roles route may go back to a role-name gate.
     *
     * @dataProvider rolesRoutes
     */
    public function testEveryRolesRouteIsGatedOnAPermissionAndNotARole(string $key, string $expected): void
    {
        $declaration = $this->routeDeclaration($key);

        self::assertNull(
            $declaration['requiredRole'],
            "{$key} is gated on a role name; that couples every deployment to one seeded "
            . 'vocabulary and hides the per-region granularity behind an all-or-nothing door.'
        );
        self::assertSame($expected, $declaration['requiredPermission'], $key);
    }

    /** @return array<string, array{string, string}> */
    public static function rolesRoutes(): array
    {
        return [
            'list'              => ['GET /api/roles', CorePermissions::ROLES_READ],
            'create'            => ['POST /api/roles', CorePermissions::ROLES_WRITE],
            'get'               => ['GET /api/roles/{id:\d+}', CorePermissions::ROLES_READ],
            'update'            => ['PATCH /api/roles/{id:\d+}', CorePermissions::ROLES_WRITE],
            'delete'            => ['DELETE /api/roles/{id:\d+}', CorePermissions::ROLES_DELETE],
            'assignments'       => ['GET /api/roles/{id:\d+}/assignments', CorePermissions::ROLES_READ],
            'permissions read'  => ['GET /api/roles/{id:\d+}/permissions', CorePermissions::PERMISSIONS_READ],
            'permissions grant' => ['POST /api/roles/{id:\d+}/permissions', CorePermissions::ROLES_MANAGE],
            'permissions revoke' => ['DELETE /api/roles/{id:\d+}/permissions', CorePermissions::ROLES_MANAGE],
        ];
    }

    /**
     * The catalogue's declaration for one route.
     *
     * @return array{requiredRole: ?string, requiredPermission: ?string}
     */
    private function routeDeclaration(string $key): array
    {
        $declarations = $this->routeDeclarations();
        self::assertArrayHasKey($key, $declarations, "{$key} is not declared in CoreApiSchemas");

        return [
            'requiredRole' => $declarations[$key]['requiredRole'] ?? null,
            'requiredPermission' => $declarations[$key]['requiredPermission'] ?? null,
        ];
    }

    /** @return array<string, string|null> "METHOD /path" => required permission */
    private function routeGates(): array
    {
        return array_map(
            static fn (array $r): ?string => $r['requiredPermission'] ?? null,
            $this->routeDeclarations()
        );
    }

    /** @return array<string, array<string, mixed>> */
    private function routeDeclarations(): array
    {
        $byKey = [];
        foreach (CoreApiSchemas::routes() as $route) {
            // No shape guard: routes() declares method/path as always present,
            // and PHPStan proves a defensive isset() here dead. Trusting the
            // declared type is the point of having one.
            if (!str_starts_with($route['path'], '/api/roles')) {
                continue;
            }
            $byKey[$route['method'] . ' ' . $route['path']] = $route;
        }

        return $byKey;
    }
}
