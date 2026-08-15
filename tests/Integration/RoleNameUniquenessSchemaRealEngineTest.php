<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;

/**
 * Real-engine tests for migration 093 — role names are unique PER TENANT (#712).
 *
 * These drive the constraint at the DATABASE layer, below any handler, because
 * that is the layer the defect lived in: migration 001 created `roles.name` as
 * `VARCHAR(255) NOT NULL UNIQUE` while `roles` had no owning tenant, and the
 * rule never moved when `tenant_id` arrived. One tenant naming a role
 * "Supervisor" therefore denied that word to every other tenant on the install.
 *
 * The replacement is two PARTIAL unique indexes rather than a single
 * `UNIQUE(tenant_id, name)`, and the two halves of that choice are what the
 * assertions below pin:
 *
 *  - tenant-owned names are scoped to their tenant and to nothing else;
 *  - GLOBAL (NULL-tenant) names stay as unique as they were, which
 *    `UNIQUE(tenant_id, name)` alone would NOT deliver, because PostgreSQL
 *    treats NULLs as distinct and would happily accept a second global `admin`.
 *
 * The second case is the one worth having a test for: it passes trivially today
 * and fails the moment somebody "simplifies" the two indexes into one composite.
 */
final class RoleNameUniquenessSchemaRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (2, 'tenant-b', datetime('now'))"
        );
        SchemaFromMigrations::syncSequences($this->pdo);
    }

    // ── the defect #712 reports ─────────────────────────────────────────────

    public function testTwoTenantsMayEachOwnARoleWithTheSameName(): void
    {
        $this->insertRole('Supervisor', 1);
        $this->insertRole('Supervisor', 2);

        self::assertSame(
            2,
            $this->countRolesNamed('Supervisor'),
            'A role name must be claimable independently by each tenant.'
        );
    }

    public function testTheSameTenantStillCannotOwnTheNameTwice(): void
    {
        $this->insertRole('Supervisor', 1);

        $this->expectException(\PDOException::class);
        $this->insertRole('Supervisor', 1);
    }

    // ── the half a plain UNIQUE(tenant_id, name) would silently lose ────────

    public function testGlobalRoleNamesRemainUnique(): void
    {
        // `admin` is seeded global by migration 001. A second one would make
        // every by-name base-role lookup (Seeder, PluginRoleSeeder, RoleChecker)
        // resolve to an arbitrary row of two.
        $this->expectException(\PDOException::class);
        $this->insertRole('admin', null);
    }

    public function testSeededBaseRolesSurviveTheMigration(): void
    {
        // The SQLite path rebuilds the table to shed an inline UNIQUE that
        // cannot be dropped in place; the rows, their ids and their NULL tenant
        // must come through it untouched.
        /** @var list<array<string, mixed>> $rows */
        $rows = $this->query('SELECT id, name, tenant_id FROM roles ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);

        self::assertSame(
            [['id' => 1, 'name' => 'admin', 'tenant_id' => null], ['id' => 2, 'name' => 'user', 'tenant_id' => null]],
            array_map(
                static fn (array $r): array => [
                    'id' => (int) $r['id'],
                    'name' => (string) $r['name'],
                    'tenant_id' => $r['tenant_id'] === null ? null : (int) $r['tenant_id'],
                ],
                $rows
            )
        );
    }

    public function testRolesStillCascadeWhenItsTenantIsDeleted(): void
    {
        // The FK is the part of the table most easily lost to a rebuild, and its
        // absence would show up only as orphaned roles months later.
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $this->insertRole('Doomed', 2);

        $this->pdo->exec('DELETE FROM tenants WHERE id = 2');

        self::assertSame(
            0,
            $this->countRolesNamed('Doomed'),
            "Deleting a tenant must still cascade to the roles it owns."
        );
    }

    public function testRolePermissionsStillCascadeFromItsRole(): void
    {
        // role_permissions.role_id REFERENCES roles(id) — the reference the
        // SQLite rebuild would break if it let SQLite re-point the FK at the
        // renamed table.
        $this->pdo->exec('PRAGMA foreign_keys = ON');
        $roleId = $this->insertRole('Temp', 1);
        $permissionId = (int) $this->query('SELECT id FROM permissions ORDER BY id LIMIT 1')->fetchColumn();

        $this->execute(
            'INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())',
            [$roleId, $permissionId]
        );

        $this->pdo->exec('DELETE FROM roles WHERE id = ' . $roleId);

        $remaining = (int) $this->query(
            'SELECT COUNT(*) FROM role_permissions WHERE role_id = ' . $roleId
        )->fetchColumn();
        self::assertSame(0, $remaining, "A deleted role must still take its permission grants with it.");
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    private function insertRole(string $name, ?int $tenantId): int
    {
        $this->execute(
            'INSERT INTO roles (name, description, tenant_id, created_at) VALUES (?, \'\', ?, NOW())',
            [$name, $tenantId]
        );

        return (int) $this->pdo->lastInsertId();
    }

    private function countRolesNamed(string $name): int
    {
        return (int) $this->execute('SELECT COUNT(*) FROM roles WHERE name = ?', [$name])->fetchColumn();
    }

    /**
     * Prepare + execute, failing loudly rather than returning a `false` a caller
     * would then dereference.
     *
     * @param array<int, mixed> $params
     */
    private function execute(string $sql, array $params): \PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Fixture statement failed to prepare: ' . $sql);
        }
        $stmt->execute($params);

        return $stmt;
    }

    private function query(string $sql): \PDOStatement
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            throw new \RuntimeException('Fixture query failed: ' . $sql);
        }

        return $stmt;
    }
}
