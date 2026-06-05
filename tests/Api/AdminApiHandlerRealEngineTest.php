<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Whity\Api\AdminApiHandler;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Request;
use Whity\Database\Database;

/**
 * Real-engine (in-memory SQLite) tests for {@see AdminApiHandler::stats()} (WC-50).
 *
 * Before this fix every aggregate was an unfiltered global COUNT, so any tenant's
 * admin saw platform-wide totals: every tenant's user count, the total number of
 * tenants, all roles, and the global growth timelines. These tests drive the
 * handler against a genuine SQL engine seeded with two tenants' data and assert:
 *
 *  - a regular tenant's admin sees ONLY its own figures (tenants fixed at 1, its
 *    own user count, only the roles it can see), and
 *  - the system user (tenant 0) still sees the platform-wide totals.
 *
 * The handler issues a few PostgreSQL-only statements for the database-health and
 * growth sections (`NOW()`, `pg_size_pretty(...)`, `SHOW server_version`). A thin
 * PDO subclass rewrites the two health statements to SQLite equivalents and a
 * `NOW()` UDF is registered, so the whole `stats()` body runs unmodified end to
 * end against the real engine — the tenant-scoping branch is what is under test.
 */
final class AdminApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * A regular tenant's admin sees ONLY its own tenant's figures: the tenant
     * total is fixed at 1, the user count is scoped to its tenant, and the global
     * platform totals are never disclosed. This FAILS on pre-fix main, where the
     * counts are unfiltered platform-wide aggregates.
     */
    public function testRegularTenantSeesOnlyOwnScopedCounts(): void
    {
        // Tenant 1: 2 users. Tenant 2: 3 users. Platform total = 5 users, 2 tenants.
        $this->seedUser(1, 1, 1);
        $this->seedUser(2, 1, 1);
        $this->seedUser(3, 2, 1);
        $this->seedUser(4, 2, 1);
        $this->seedUser(5, 2, 1);

        MockRequestFactory::setTestTenant(1);
        $stats = $this->stats();

        $this->assertSame(1, $stats['totals']['tenants'], 'A regular tenant must only ever see itself (1).');
        $this->assertSame(2, $stats['totals']['users'], "Only the tenant's own users are counted.");
        $this->assertNotSame(5, $stats['totals']['users'], 'The platform-wide user count must not leak.');
    }

    /**
     * Roles are scoped to the tenant's own plus globals (NULL tenant_id), per the
     * WC-110 visibility model — never another tenant's private roles.
     */
    public function testRegularTenantRoleCountExcludesForeignRoles(): void
    {
        MockRequestFactory::setTestTenant(1);
        $stats = $this->stats();

        // Visible to tenant 1: global 'admin' (id 1) + own 'tenant-a-role' (id 100) = 2.
        // Tenant 2's private 'tenant-b-role' (id 200) is excluded.
        $this->assertSame(2, $stats['totals']['roles']);

        $roleNames = array_column($stats['breakdown']['users_per_role'], 'name');
        $this->assertContains('admin', $roleNames);
        $this->assertContains('tenant-a-role', $roleNames);
        $this->assertNotContains('tenant-b-role', $roleNames, "Another tenant's role must not appear.");
    }

    /**
     * The system user (tenant 0) retains the platform-wide totals.
     */
    public function testSystemUserSeesGlobalTotals(): void
    {
        $this->seedUser(1, 1, 1);
        $this->seedUser(2, 2, 1);
        $this->seedUser(3, 2, 1);

        MockRequestFactory::setTestTenant(0);
        $stats = $this->stats();

        $this->assertSame(3, $stats['totals']['users'], 'The system user sees every tenant\'s users.');
        $this->assertSame(3, $stats['totals']['tenants'], 'The system user sees every tenant (incl. system).');
        $this->assertSame(3, $stats['totals']['roles'], 'The system user sees all roles.');

        $roleNames = array_column($stats['breakdown']['users_per_role'], 'name');
        $this->assertContains('tenant-b-role', $roleNames, 'The system user sees every tenant\'s roles.');
    }

    /**
     * A null/unresolved tenant context is treated as a regular (non-system) caller
     * and must not fall through to the global counts.
     */
    public function testUnresolvedContextDoesNotLeakGlobalTotals(): void
    {
        $this->seedUser(1, 1, 1);
        $this->seedUser(2, 2, 1);

        TenantContext::reset();
        $stats = $this->stats();

        $this->assertSame(1, $stats['totals']['tenants'], 'An unresolved context must not expose the tenant count.');
    }

    // ==================== Helpers ====================

    /**
     * Run stats() and return the decoded `stats` payload.
     *
     * @return array<string, mixed>
     */
    private function stats(): array
    {
        $pdo = $this->pdo;
        $database = Database::withFactory(static fn (): PDO => $pdo);

        $handler = new AdminApiHandler($database, sys_get_temp_dir());
        $response = $handler->stats(new Request('GET', '/api/admin/stats', []));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        return json_decode($response->getBody(), true)['stats'];
    }

    private function seedUser(int $id, int $tenantId, int $roleId): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at)
             VALUES (?, ?, ?, 'x', ?, datetime('now'))"
        );
        $stmt->execute([$id, $tenantId, "u{$id}@example.com", $roleId]);
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = new class ('sqlite::memory:') extends PDO {
            /**
             * Translate the handler's unchanged PostgreSQL SQL fragments to
             * SQLite-safe equivalents so the whole stats() body runs against the
             * real engine. Only dialect — never the tenant-scoping logic under
             * test — is rewritten:
             *  - `NOW() - INTERVAL '7 days'` (PG interval) → SQLite datetime().
             *  - `pg_size_pretty(...)` / `SHOW server_version` (PG health probes).
             */
            private static function translate(string $sql): string
            {
                if (str_contains($sql, 'pg_size_pretty')) {
                    return "SELECT '1 MB'";
                }
                if (str_contains($sql, 'server_version')) {
                    return "SELECT '16.0'";
                }

                return (string) preg_replace(
                    "/NOW\(\)\s*-\s*INTERVAL\s*'7 days'/i",
                    "datetime('now', '-7 days')",
                    $sql
                );
            }

            public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
            {
                $query = self::translate($query);

                return $fetchMode === null
                    ? parent::query($query)
                    : parent::query($query, $fetchMode, ...$fetchModeArgs);
            }

            public function prepare(string $query, array $options = []): \PDOStatement|false
            {
                return parent::prepare(self::translate($query), $options);
            }
        };

        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        // The growth queries use PostgreSQL's NOW(); register a UDF for the
        // remaining bare NOW() calls. SQLite's native DATE() covers DATE(created_at).
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('CREATE TABLE tenants (id INTEGER PRIMARY KEY, name TEXT, created_at TEXT)');
        $pdo->exec("
            INSERT INTO tenants (id, name, created_at) VALUES
                (0, 'system',   datetime('now')),
                (1, 'tenant-a', datetime('now')),
                (2, 'tenant-b', datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec("
            INSERT INTO roles (id, name, tenant_id, created_at) VALUES
                (1,   'admin',         NULL, datetime('now')),
                (100, 'tenant-a-role', 1,    datetime('now')),
                (200, 'tenant-b-role', 2,    datetime('now'))
        ");

        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY,
                name TEXT NOT NULL UNIQUE
            )
        ');
        $pdo->exec("INSERT INTO permissions (id, name) VALUES (1, 'users:read'), (2, 'roles:read')");

        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER,
                created_at TEXT
            )
        ');

        // The handler also queries the migrations table (unscoped, infra metadata).
        $pdo->exec('CREATE TABLE core_schema_migrations (id INTEGER PRIMARY KEY, name TEXT)');

        return $pdo;
    }
}
