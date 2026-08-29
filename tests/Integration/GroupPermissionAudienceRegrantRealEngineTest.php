<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\RegrantGroupPermissionsToCurrentAudiences;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Migration 137 (#1040), asserted against a database built the way a real one is.
 *
 * THE DEFECT WAS EMPIRICAL, so the check has to be too. Migration 116 grants
 * `groups:read` to every role holding `documents:route` — evaluated AT MIGRATION
 * TIME — and the demo roles receive `documents:route` afterwards. The result on
 * a fresh install was `documents:route` held by four roles and `groups:read` by
 * one, so three route-capable roles composed a route and then took a 403 listing
 * the groups they needed to route to.
 *
 * A unit test over the migration's constant would prove the audience is spelled
 * right. Only running it against a migrated schema proves grant rows appear on
 * the roles that were missing them.
 *
 * WHAT THIS DOES NOT ASSERT, AND WHY THAT IS NOT AN OVERSIGHT
 * ----------------------------------------------------------
 * A role granted `documents:route` AFTER this migration runs still does not
 * receive `groups:read` — the hole reopens, because a capability-based grant
 * evaluated at migration time cannot cover anyone who acquires the capability
 * later. That is the known, documented limitation recorded in the migration's
 * docblock and tracked in #1040, whose two candidate answers are decisions about
 * what `documents:route` means.
 *
 * It is deliberately NOT asserted here. A test that pinned the hole in place
 * would fail the day somebody closes it properly, presenting a fix as a
 * regression — and the reader of a red test is rarely in a position to tell
 * those apart. The comment carries it instead.
 */
final class GroupPermissionAudienceRegrantRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private int $tenantId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        // Same construction SchemaFromMigrations uses to run the migrations in
        // the first place — the constructor is private, and wrapping the live
        // handle is how a test drives a migration against it.
        $this->db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $this->tenantId = $this->makeTenant();
    }

    /**
     * A tenant of this test's own, rather than an assumed `tenant_id = 1`.
     *
     * `roles.tenant_id` has a foreign key, and which tenants exist after the
     * migrations differs BY ENGINE: the SQLite path this suite takes by default
     * leaves a row with id 1 behind, and the real PostgreSQL path CI runs
     * (`PHPUNIT_PG_DSN`) does not. A hardcoded 1 therefore passes locally and
     * fails every PG shard with a foreign-key violation — which is exactly how
     * it did fail, after passing here.
     */
    private function makeTenant(): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, created_at) VALUES (:name, NOW())');
        $stmt->execute([':name' => 'regrant-fixture']);

        return (int) $this->pdo->lastInsertId();
    }

    /**
     * A role holding only the audience capability — the shape the demo seeder
     * leaves behind by granting `documents:route` after migration 116 ran.
     */
    private function roleHolding(string $name, string $permission): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, '', ?, NOW())"
        );
        $stmt->execute([$name, $this->tenantId]);
        $roleId = (int) $this->pdo->lastInsertId();

        $grant = $this->pdo->prepare(
            'INSERT INTO role_permissions (role_id, permission_id, created_at)
             SELECT ?, id, NOW() FROM permissions WHERE name = ?'
        );
        $grant->execute([$roleId, $permission]);

        self::assertTrue(
            $this->holds($roleId, $permission),
            "fixture failed to grant {$permission}: the catalogue row is missing, "
                . 'so every assertion below would be vacuous'
        );

        return $roleId;
    }

    private function holds(int $roleId, string $permission): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ? AND p.name = ?'
        );
        $stmt->execute([$roleId, $permission]);

        return $stmt->fetchColumn() !== false;
    }

    /** THE DEFECT: a route-capable role that cannot list the groups it routes to. */
    public function testGrantsGroupsReadToARoleThatAlreadyHeldDocumentsRoute(): void
    {
        $roleId = $this->roleHolding('late-router', CorePermissions::DOCUMENTS_ROUTE);

        self::assertFalse(
            $this->holds($roleId, CorePermissions::GROUPS_READ),
            'the fixture is meant to reproduce the hole; if the role already holds '
                . 'groups:read then something else granted it and this test proves nothing'
        );

        RegrantGroupPermissionsToCurrentAudiences::up($this->db);

        self::assertTrue($this->holds($roleId, CorePermissions::GROUPS_READ));
    }

    /**
     * The same hole one line away, against the other audience.
     *
     * `groups:write` has an identical timing defect against `roles:write`; it is
     * simply rarer to hit, because a role granted `roles:write` after 116 is
     * less common than the demo seeder running after it.
     */
    public function testGrantsGroupsWriteToARoleThatAlreadyHeldRolesWrite(): void
    {
        $roleId = $this->roleHolding('late-role-editor', CorePermissions::ROLES_WRITE);

        self::assertFalse($this->holds($roleId, CorePermissions::GROUPS_WRITE));

        RegrantGroupPermissionsToCurrentAudiences::up($this->db);

        self::assertTrue($this->holds($roleId, CorePermissions::GROUPS_WRITE));
        // `roles:write` is an audience for BOTH slugs in migration 116.
        self::assertTrue($this->holds($roleId, CorePermissions::GROUPS_READ));
    }

    /** Grants nothing to a role that holds neither audience capability. */
    public function testLeavesAnUnrelatedRoleAlone(): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, '', ?, NOW())"
        );
        $stmt->execute(['bystander', $this->tenantId]);
        $roleId = (int) $this->pdo->lastInsertId();

        RegrantGroupPermissionsToCurrentAudiences::up($this->db);

        self::assertFalse($this->holds($roleId, CorePermissions::GROUPS_READ));
        self::assertFalse($this->holds($roleId, CorePermissions::GROUPS_WRITE));
    }

    /**
     * Idempotent — it runs on databases where 116 already granted the same rows,
     * and a migration that threw on its own conflict clause would break every
     * upgrade rather than the few with the hole.
     */
    public function testIsIdempotent(): void
    {
        $roleId = $this->roleHolding('twice-router', CorePermissions::DOCUMENTS_ROUTE);

        RegrantGroupPermissionsToCurrentAudiences::up($this->db);
        RegrantGroupPermissionsToCurrentAudiences::up($this->db);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*)
               FROM role_permissions rp
               JOIN permissions p ON p.id = rp.permission_id
              WHERE rp.role_id = ? AND p.name = ?'
        );
        $stmt->execute([$roleId, CorePermissions::GROUPS_READ]);

        self::assertSame(1, (int) $stmt->fetchColumn());
    }
}
