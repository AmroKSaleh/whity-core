<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Database\ScopesToTenant;
use Whity\Core\Database\TenantScopeException;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Tenant data-isolation suite (WC-22, issue #10).
 *
 * Where the existing tests live and what this file deliberately does NOT repeat:
 *  - {@see \Tests\Unit\Core\Database\ScopesToTenantTest} (WC-19) proves the SQL
 *    rewriter shape (WHERE merge, INSERT auto-populate, fail-closed) on a 3-row
 *    seed. This file instead proves the *data-leak* acceptance criteria at a
 *    realistic scale (Tenant A: 10 rows, Tenant B: 5 rows) and adds the
 *    deletion-cascade / orphaned-record / system-tenant edge cases that the unit
 *    test does not cover.
 *  - {@see \Tests\Integration\TenantIsolationTest} and
 *    {@see TenantManagementRbacTest} prove middleware/handler behaviour with
 *    array-filter or fully-mocked PDO stand-ins. This file drives a *real* SQL
 *    engine (in-memory SQLite) through the {@see ScopesToTenant} trait so the
 *    isolation is enforced by the actual query rewrite, not by a test double.
 *
 * Tenant ids are integers in this codebase; tenant id 0 is the system tenant
 * (see {@see TenantContext}). The acceptance criteria's string-id example is the
 * documented deviation.
 *
 * SQLite is used because it shares the parameterised `:name` placeholder syntax
 * and the WHERE/ORDER BY/LIMIT grammar the rewriter targets, so a passing query
 * is genuine proof the tenant predicate was injected AND applied — CI has no
 * live PostgreSQL.
 */
class TenantDataIsolationTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TENANT_A_USERS = 10;
    private const TENANT_B_USERS = 5;

    /**
     * Reset the request-scoped static context after each test so no tenant or
     * system-mode state leaks across tests (mirrors the persistent-worker model).
     */
    protected function tearDown(): void
    {
        TenantContext::reset();
        TenantContext::setLogger(null);
    }

    // ------------------------------------------------------------------
    // AC1: Tenant A (10 users) vs Tenant B (5 users)
    // ------------------------------------------------------------------

    /**
     * AC1: Tenant A's list returns exactly its 10 users and none of Tenant B's.
     *
     * The repository issues an unqualified `SELECT * FROM users`; the trait must
     * rewrite it into a tenant-scoped query so the shared table yields only the
     * caller's rows.
     */
    public function testTenantAListReturnsExactlyItsOwnTenUsers(): void
    {
        $repo = new IsolatedUserRepository($this->seededDatabase());

        TenantContext::setTenantId(self::TENANT_A);
        $rows = $repo->all();

        $this->assertCount(self::TENANT_A_USERS, $rows, 'Tenant A must see exactly its 10 users');
        foreach ($rows as $row) {
            $this->assertSame(self::TENANT_A, (int) $row['tenant_id']);
        }

        // Not a single Tenant B row may appear.
        $names = array_column($rows, 'name');
        $this->assertNotContains('b-user-1', $names, "Tenant B's rows must never appear for Tenant A");
    }

    /**
     * AC1 (mirror): Tenant B sees exactly its 5 users, never Tenant A's 10.
     */
    public function testTenantBListReturnsExactlyItsOwnFiveUsers(): void
    {
        $repo = new IsolatedUserRepository($this->seededDatabase());

        TenantContext::setTenantId(self::TENANT_B);
        $rows = $repo->all();

        $this->assertCount(self::TENANT_B_USERS, $rows, 'Tenant B must see exactly its 5 users');
        foreach ($rows as $row) {
            $this->assertSame(self::TENANT_B, (int) $row['tenant_id']);
        }

        $names = array_column($rows, 'name');
        $this->assertNotContains('a-user-1', $names, "Tenant A's rows must never appear for Tenant B");
    }

    /**
     * AC1 (count proof): the two tenants' row counts are disjoint and sum to the
     * full table, proving no overlap and no missing rows.
     */
    public function testTenantCountsAreDisjointAndComplete(): void
    {
        $db = $this->seededDatabase();
        $repo = new IsolatedUserRepository($db);

        TenantContext::setTenantId(self::TENANT_A);
        $countA = $repo->count();

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $countB = $repo->count();

        $this->assertSame(self::TENANT_A_USERS, $countA);
        $this->assertSame(self::TENANT_B_USERS, $countB);

        // Confirm against the raw table total via system mode.
        TenantContext::reset();
        TenantContext::setSystemMode(true, 'test');
        $total = (int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn();
        $this->assertSame(self::TENANT_A_USERS + self::TENANT_B_USERS, $total);
    }

    /**
     * A scoped SELECT that already carries a WHERE clause still cannot reach a
     * foreign tenant's row even when the value matches: searching Tenant B for a
     * name that only exists in Tenant A returns nothing.
     */
    public function testScopedSearchCannotMatchForeignTenantRow(): void
    {
        $repo = new IsolatedUserRepository($this->seededDatabase());

        TenantContext::setTenantId(self::TENANT_B);
        $rows = $repo->findByName('a-user-1'); // belongs to Tenant A only

        $this->assertSame([], $rows, 'A Tenant-A-only name must be invisible to Tenant B');
    }

    // ------------------------------------------------------------------
    // Edge case: write isolation (UPDATE / DELETE never cross tenants)
    // ------------------------------------------------------------------

    /**
     * A tenant-scoped bulk UPDATE only touches the caller's rows; the other
     * tenant's data is left intact (no cross-tenant write leak).
     */
    public function testScopedBulkUpdateOnlyAffectsCallerTenant(): void
    {
        $db = $this->seededDatabase();
        $repo = new IsolatedUserRepository($db);

        TenantContext::setTenantId(self::TENANT_A);
        $repo->renameAll('changed');

        // Every Tenant A row was renamed; no Tenant B row was.
        TenantContext::reset();
        TenantContext::setSystemMode(true, 'test');
        $changedA = (int) $db->query(
            "SELECT COUNT(*) FROM users WHERE tenant_id = 1 AND name = 'changed'"
        )->fetchColumn();
        $changedB = (int) $db->query(
            "SELECT COUNT(*) FROM users WHERE tenant_id = 2 AND name = 'changed'"
        )->fetchColumn();

        $this->assertSame(self::TENANT_A_USERS, $changedA);
        $this->assertSame(0, $changedB, 'A Tenant A update must never rename Tenant B rows');
    }

    /**
     * A tenant-scoped DELETE only removes the caller's rows; the other tenant's
     * data survives untouched.
     */
    public function testScopedDeleteOnlyRemovesCallerTenant(): void
    {
        $db = $this->seededDatabase();
        $repo = new IsolatedUserRepository($db);

        TenantContext::setTenantId(self::TENANT_A);
        $repo->deleteAll();

        TenantContext::reset();
        TenantContext::setSystemMode(true, 'test');
        $remainingA = (int) $db->query('SELECT COUNT(*) FROM users WHERE tenant_id = 1')->fetchColumn();
        $remainingB = (int) $db->query('SELECT COUNT(*) FROM users WHERE tenant_id = 2')->fetchColumn();

        $this->assertSame(0, $remainingA, 'Tenant A rows should be gone');
        $this->assertSame(self::TENANT_B_USERS, $remainingB, 'Tenant B rows must survive a Tenant A delete');
    }

    // ------------------------------------------------------------------
    // Edge case: system tenant (id 0) cross-tenant visibility
    // ------------------------------------------------------------------

    /**
     * System mode (the trusted cross-tenant bypass used by migrations/admin
     * tooling) sees every tenant's rows: the union of A and B, unscoped.
     */
    public function testSystemModeSeesAllTenantsRows(): void
    {
        $repo = new IsolatedUserRepository($this->seededDatabase());

        TenantContext::setSystemMode(true, 'migration');
        $rows = $repo->all();

        $this->assertCount(self::TENANT_A_USERS + self::TENANT_B_USERS, $rows);
        $tenantIds = array_unique(array_map('intval', array_column($rows, 'tenant_id')));
        sort($tenantIds);
        $this->assertSame([self::TENANT_A, self::TENANT_B], $tenantIds);
    }

    /**
     * The system tenant id (0) is a real, scopeable tenant — not the "unset"
     * state. A caller resolved to tenant 0 (without system mode) sees only the
     * rows that literally belong to tenant 0, not everyone's.
     */
    public function testSystemTenantZeroIsScopedToItsOwnRows(): void
    {
        $db = $this->seededDatabase();
        // Add two system-tenant rows to the shared table.
        TenantContext::setSystemMode(true, 'seed');
        $db->query("INSERT INTO users (name, tenant_id) VALUES ('sys-user-1', 0)");
        $db->query("INSERT INTO users (name, tenant_id) VALUES ('sys-user-2', 0)");
        TenantContext::reset();

        $repo = new IsolatedUserRepository($db);
        TenantContext::setTenantId(0); // system tenant, but NOT system mode

        $rows = $repo->all();

        $this->assertCount(2, $rows, 'Tenant 0 (scoped) sees only its own two rows, not all tenants');
        foreach ($rows as $row) {
            $this->assertSame(0, (int) $row['tenant_id']);
        }
    }

    // ------------------------------------------------------------------
    // Edge case: orphaned records (rows of a deleted tenant)
    // ------------------------------------------------------------------

    /**
     * Orphaned rows — rows whose tenant_id references a tenant that no longer
     * exists — never surface for a live tenant. Tenant scoping keys on the
     * caller's id, so a dangling tenant_id (99) is simply never matched.
     */
    public function testOrphanedRowsNeverLeakIntoLiveTenant(): void
    {
        $db = $this->seededDatabase();
        // Simulate rows left behind by a deleted tenant 99.
        TenantContext::setSystemMode(true, 'seed');
        $db->query("INSERT INTO users (name, tenant_id) VALUES ('orphan-1', 99)");
        $db->query("INSERT INTO users (name, tenant_id) VALUES ('orphan-2', 99)");
        TenantContext::reset();

        $repo = new IsolatedUserRepository($db);

        // A live tenant never sees the orphans.
        TenantContext::setTenantId(self::TENANT_A);
        $namesA = array_column($repo->all(), 'name');
        $this->assertNotContains('orphan-1', $namesA);
        $this->assertNotContains('orphan-2', $namesA);
        $this->assertCount(self::TENANT_A_USERS, $repo->all());
    }

    // ------------------------------------------------------------------
    // Edge case: fail-closed when the tenant is unresolved
    // ------------------------------------------------------------------

    /**
     * With no tenant resolved and system mode off, a list query is refused rather
     * than run unscoped — a defence against accidentally returning every tenant's
     * rows. Crucially, the database is never touched.
     */
    public function testUnresolvedContextRefusesQueryAndLeaksNothing(): void
    {
        $repo = new IsolatedUserRepository($this->seededDatabase());

        // No tenant set, system mode off.
        $this->expectException(TenantScopeException::class);
        $repo->all();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a Database backed by a seeded in-memory SQLite connection holding
     * 10 Tenant A users and 5 Tenant B users in a shared `users` table.
     */
    private function seededDatabase(): Database
    {
        $factory = static function (): PDO {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, tenant_id INTEGER)');

            for ($i = 1; $i <= self::TENANT_A_USERS; $i++) {
                $pdo->exec("INSERT INTO users (name, tenant_id) VALUES ('a-user-{$i}', 1)");
            }
            for ($i = 1; $i <= self::TENANT_B_USERS; $i++) {
                $pdo->exec("INSERT INTO users (name, tenant_id) VALUES ('b-user-{$i}', 2)");
            }

            return $pdo;
        };

        // Large lifetime / ping interval so the seeded ":memory:" connection is
        // never recycled mid-test (a reconnect would open a fresh empty db).
        return Database::withFactory($factory, 86400, 86400);
    }
}

/**
 * Minimal repository that enforces tenant isolation via {@see ScopesToTenant}.
 *
 * Defined in this uniquely-named test file (not a shared support file) so a
 * concurrent agent's fixtures cannot collide with it.
 */
class IsolatedUserRepository
{
    use ScopesToTenant;

    public ?int $tenant_id = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function all(): array
    {
        return $this->tenantScopedQuery($this->db, 'SELECT id, name, tenant_id FROM users')
            ->fetchAll(PDO::FETCH_ASSOC);
    }

    public function count(): int
    {
        return (int) $this->tenantScopedQuery($this->db, 'SELECT COUNT(*) FROM users')->fetchColumn();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function findByName(string $name): array
    {
        return $this->tenantScopedQuery(
            $this->db,
            'SELECT id, name, tenant_id FROM users WHERE name = :name',
            ['name' => $name]
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function renameAll(string $newName): void
    {
        $this->tenantScopedQuery($this->db, 'UPDATE users SET name = :name', ['name' => $newName]);
    }

    public function deleteAll(): void
    {
        $this->tenantScopedQuery($this->db, 'DELETE FROM users');
    }
}
