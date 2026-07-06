<?php

declare(strict_types=1);

namespace Whity\Tests\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Cli\Commands\MigrationsCommand;
use Whity\Database\Database;

/**
 * Live forward + rollback cycle tests for the RBAC schema migrations (WC-12).
 *
 * These tests exercise the real MigrationsCommand against a PostgreSQL
 * database. They are skipped automatically when no database is reachable
 * (e.g. in CI, where there is no live PostgreSQL and the php:8.4-cli image
 * has no pdo_pgsql driver).
 *
 * To run locally against a disposable database, export:
 *   DB_HOST, DB_PORT, DB_NAME, DB_USER, DB_PASSWORD
 *
 * @requires extension pdo_pgsql
 */
final class MigrationCycleTest extends TestCase
{
    private const RBAC_TABLES = [
        'tenants',
        'roles',
        'users',
        'permissions',
        'role_permissions',
        'organizational_units',
        'ou_role_assignments',
        'permission_delegations',
    ];

    private ?Database $db = null;

    protected function setUp(): void
    {
        if (!extension_loaded('pdo_pgsql')) {
            $this->markTestSkipped('pdo_pgsql extension not available.');
        }

        $db = $this->connectOrSkip();
        $this->db = $db;

        // Start every test from a guaranteed-clean schema.
        $this->dropEverything($db);
    }

    protected function tearDown(): void
    {
        if ($this->db instanceof Database) {
            $this->dropEverything($this->db);
        }
        $this->db = null;
    }

    public function testForwardMigrationCreatesAllRbacTablesWithForeignKeys(): void
    {
        $this->runMigrate('run');

        foreach (self::RBAC_TABLES as $table) {
            $this->assertTrue($this->tableExists($table), "Expected table '{$table}' to exist after migrate run.");
        }

        // users.tenant_id must be NOT NULL with a FK to tenants(id).
        $this->assertColumnIsNotNull('users', 'tenant_id');
        $this->assertForeignKeyExists('users', 'tenant_id', 'tenants', 'id');
        $this->assertIndexExistsOn('users', 'tenant_id');

    }

    public function testMigrationRunIsIdempotent(): void
    {
        $this->runMigrate('run');
        $firstCount = $this->migrationCount();

        // Running again must apply nothing new and must not error.
        $output = $this->runMigrate('run');
        $secondCount = $this->migrationCount();

        $this->assertSame($firstCount, $secondCount, 'Re-running migrate must not record additional migrations.');
        $this->assertStringContainsStringIgnoringCase('already executed', $output);
    }

    public function testFullRollbackCycleRemovesEverything(): void
    {
        $this->runMigrate('run');
        $total = $this->migrationCount();
        $this->assertGreaterThanOrEqual(15, $total, 'Expected the full consolidated migration set to have run.');

        // Roll back every migration one at a time.
        for ($i = 0; $i < $total; $i++) {
            $this->runMigrate('rollback');
        }

        $this->assertSame(0, $this->migrationCount(), 'All migrations should be rolled back.');

        // Structural tables created by reversible migrations must be gone.
        foreach (['permission_delegations', 'ou_role_assignments', 'organizational_units', 'permissions', 'role_permissions', 'users', 'roles', 'tenants'] as $table) {
            $this->assertFalse($this->tableExists($table), "Table '{$table}' should be dropped after full rollback.");
        }
    }

    public function testForwardThenRollbackThenForwardSucceeds(): void
    {
        $this->runMigrate('run');
        $total = $this->migrationCount();

        for ($i = 0; $i < $total; $i++) {
            $this->runMigrate('rollback');
        }
        $this->assertSame(0, $this->migrationCount());

        // Re-applying on the now-clean database must succeed again.
        $this->runMigrate('run');
        $this->assertSame($total, $this->migrationCount());
        // user_roles was dropped by migration 039; it must not exist after a full
        // forward run.
        $this->assertFalse($this->tableExists('user_roles'), 'user_roles must not exist after migration 039 runs.');
    }

    public function testCascadingDeleteRemovesDependentRows(): void
    {
        $this->runMigrate('run');
        $db = $this->requireDb();

        $tenantId = (int) $db->query(
            "INSERT INTO tenants (name, created_at) VALUES ('cascade-tenant', NOW()) RETURNING id"
        )->fetch(PDO::FETCH_COLUMN);

        $roleId = (int) $db->query('SELECT id FROM roles WHERE name = :n', [':n' => 'admin'])->fetch(PDO::FETCH_COLUMN);

        // Identity is on the profile model (the legacy `users` table was retired by
        // migration 042). A membership carries the tenant FK, so the tenant→member
        // cascade is now proven through memberships.
        $profileId = (int) $db->query(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('cascade', 'x', false, 0, 0, NOW(), NOW()) RETURNING id"
        )->fetch(PDO::FETCH_COLUMN);

        $membershipId = (int) $db->query(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (:p, :t, :r, 'active', NOW()) RETURNING id",
            [':p' => $profileId, ':t' => $tenantId, ':r' => $roleId]
        )->fetch(PDO::FETCH_COLUMN);

        // Deleting the tenant must cascade to its memberships (ON DELETE CASCADE).
        // user_roles was dropped by migration 039; users by migration 042.
        $db->query('DELETE FROM tenants WHERE id = :t', [':t' => $tenantId]);

        $remaining = (int) $db->query(
            'SELECT COUNT(*) FROM memberships WHERE id = :m',
            [':m' => $membershipId]
        )->fetch(PDO::FETCH_COLUMN);

        $this->assertSame(0, $remaining, 'Memberships should cascade-delete with their tenant.');
    }

    // ---- helpers ---------------------------------------------------------

    private function runMigrate(string $action): string
    {
        $command = new MigrationsCommand();
        ob_start();
        $exit = $command->execute([$action]);
        $output = (string) ob_get_clean();

        $this->assertSame(0, $exit, "migrate {$action} failed:\n{$output}");

        return $output;
    }

    private function migrationCount(): int
    {
        if (!$this->tableExists('core_schema_migrations')) {
            return 0;
        }

        return (int) $this->requireDb()
            ->query('SELECT COUNT(*) FROM core_schema_migrations')
            ->fetch(PDO::FETCH_COLUMN);
    }

    private function tableExists(string $table): bool
    {
        $stmt = $this->requireDb()->query(
            "SELECT EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = :t)",
            [':t' => $table]
        );

        return (bool) $stmt->fetch(PDO::FETCH_COLUMN);
    }

    private function assertColumnIsNotNull(string $table, string $column): void
    {
        $nullable = $this->requireDb()->query(
            'SELECT is_nullable FROM information_schema.columns WHERE table_name = :t AND column_name = :c',
            [':t' => $table, ':c' => $column]
        )->fetch(PDO::FETCH_COLUMN);

        $this->assertSame('NO', $nullable, "{$table}.{$column} must be NOT NULL.");
    }

    private function assertIndexExistsOn(string $table, string $column): void
    {
        $count = (int) $this->requireDb()->query(
            "SELECT COUNT(*)
             FROM pg_indexes
             WHERE schemaname = 'public' AND tablename = :t AND indexdef ILIKE :col",
            [':t' => $table, ':col' => '%(' . $column . '%']
        )->fetch(PDO::FETCH_COLUMN);

        $this->assertGreaterThan(0, $count, "Expected an index covering {$table}.{$column}.");
    }

    private function assertForeignKeyExists(string $table, string $column, string $refTable, string $refColumn): void
    {
        $count = (int) $this->requireDb()->query(
            "SELECT COUNT(*)
             FROM information_schema.table_constraints tc
             JOIN information_schema.key_column_usage kcu
               ON tc.constraint_name = kcu.constraint_name AND tc.table_schema = kcu.table_schema
             JOIN information_schema.constraint_column_usage ccu
               ON tc.constraint_name = ccu.constraint_name AND tc.table_schema = ccu.table_schema
             WHERE tc.constraint_type = 'FOREIGN KEY'
               AND tc.table_name = :t
               AND kcu.column_name = :c
               AND ccu.table_name = :rt
               AND ccu.column_name = :rc",
            [':t' => $table, ':c' => $column, ':rt' => $refTable, ':rc' => $refColumn]
        )->fetch(PDO::FETCH_COLUMN);

        $this->assertGreaterThan(
            0,
            $count,
            "Expected a foreign key {$table}.{$column} -> {$refTable}.{$refColumn}."
        );
    }

    private function connectOrSkip(): Database
    {
        $user = $_ENV['DB_USER'] ?? getenv('DB_USER') ?: null;
        $password = $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD') ?: null;

        if ($user === null || $password === null || $user === '' || $password === '') {
            $this->markTestSkipped('Database credentials not configured; skipping live migration cycle.');
        }

        try {
            $db = Database::connect();
            // Database::connect() is lazy since WC-21: no socket is opened until
            // the first query. Force the connection open inside this guard so an
            // unreachable server is detected here and skips the suite, instead of
            // surfacing as a ConnectionException error in setUp()'s first query.
            $db->forceConnect();

            return $db;
        } catch (\Throwable $e) {
            $this->markTestSkipped('Database not reachable: ' . $e->getMessage());
        }
    }

    private function requireDb(): Database
    {
        if (!$this->db instanceof Database) {
            $this->fail('Database connection was not established.');
        }

        return $this->db;
    }

    /**
     * Drop the full public schema so each test starts clean.
     */
    private function dropEverything(Database $db): void
    {
        $db->exec('DROP SCHEMA public CASCADE');
        $db->exec('CREATE SCHEMA public');
    }
}
