<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Database;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Core\Database\ScopesToTenant;
use Whity\Core\Database\TenantScopeException;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Tests for the ScopesToTenant trait.
 *
 * Tenant ids are integers in this codebase (the acceptance criteria's example
 * uses a string id; that deviation is documented on the trait and TenantContext).
 * Tenant id 0 is the system tenant and is a valid, scopeable value.
 */
class ScopesToTenantTest extends TestCase
{
    /**
     * Reset the request-scoped static context after each test so state does not
     * leak across tests (the context mimics the persistent-worker model).
     */
    protected function tearDown(): void
    {
        TenantContext::reset();
        TenantContext::setLogger(null);
    }

    // ------------------------------------------------------------------
    // Trait wiring (backward-compatibility guards)
    // ------------------------------------------------------------------

    public function testTraitIsAttachedToConsumingClass(): void
    {
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->assertArrayHasKey(ScopesToTenant::class, class_uses($repo));
    }

    public function testBootHookExistsAndIsCallable(): void
    {
        $reflection = new \ReflectionClass(ScopedRepository::class);

        $this->assertTrue($reflection->hasMethod('bootScopesToTenant'));

        // Invoking the no-op boot hook must not throw.
        $boot = $reflection->getMethod('bootScopesToTenant');
        $boot->invoke(null);
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------
    // AC 1: SELECT is scoped with a parameterised tenant predicate
    // ------------------------------------------------------------------

    public function testSelectIsScopedWithParameterisedTenantId(): void
    {
        TenantContext::setTenantId(7);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        [$sql, $params] = $repo->scope('SELECT * FROM users');

        $this->assertMatchesRegularExpression('/WHERE\s+tenant_id\s*=\s*:\w+/i', $sql);
        // The tenant id must be bound, never interpolated into the SQL text.
        $this->assertStringNotContainsString('= 7', $sql);
        $this->assertContains(7, array_values($params));
        $this->assertCount(1, $params);
    }

    public function testSystemTenantZeroIsScopedNotTreatedAsUnresolved(): void
    {
        TenantContext::setTenantId(0);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        [$sql, $params] = $repo->scope('SELECT * FROM users');

        $this->assertMatchesRegularExpression('/WHERE\s+tenant_id\s*=\s*:\w+/i', $sql);
        $this->assertContains(0, array_values($params));
    }

    public function testScopedSelectReturnsOnlyCurrentTenantRows(): void
    {
        $db = self::makeSqliteDatabase();
        TenantContext::setTenantId(2);
        $repo = new ScopedRepository($db);

        $rows = $repo->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);

        // Seed data has tenant 1 (alice) and tenant 2 (bob, carol).
        $this->assertEqualsCanonicalizing(['bob', 'carol'], $rows);
    }

    // ------------------------------------------------------------------
    // WHERE-clause merging
    // ------------------------------------------------------------------

    public function testExistingWhereClauseIsMergedWithAnd(): void
    {
        TenantContext::setTenantId(5);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        [$sql] = $repo->scope('SELECT * FROM users WHERE name = :name', ['name' => 'bob']);

        $this->assertMatchesRegularExpression('/WHERE\s*\(\s*name = :name\s*\)\s*AND\s+tenant_id\s*=/i', $sql);
    }

    public function testExistingWhereMergeStillFiltersCorrectlyOnRealQuery(): void
    {
        $db = self::makeSqliteDatabase();
        TenantContext::setTenantId(2);
        $repo = new ScopedRepository($db);

        // alice belongs to tenant 1; scoping to tenant 2 must exclude her even
        // though the name matches.
        $rows = $repo->query('SELECT name FROM users WHERE name = :name', ['name' => 'alice'])
            ->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame([], $rows);
    }

    public function testWhereIsInsertedBeforeTrailingClauses(): void
    {
        TenantContext::setTenantId(3);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        [$sql] = $repo->scope('SELECT * FROM users ORDER BY name LIMIT 10');

        $this->assertMatchesRegularExpression(
            '/WHERE\s+tenant_id\s*=\s*:\w+\s+ORDER BY name LIMIT 10/i',
            $sql
        );
    }

    public function testWhereWithExistingConditionAndTrailingClauseMerges(): void
    {
        $db = self::makeSqliteDatabase();
        TenantContext::setTenantId(2);
        $repo = new ScopedRepository($db);

        $rows = $repo->query(
            'SELECT name FROM users WHERE name <> :n ORDER BY name',
            ['n' => 'carol']
        )->fetchAll(PDO::FETCH_COLUMN);

        $this->assertSame(['bob'], $rows);
    }

    // ------------------------------------------------------------------
    // UPDATE / DELETE scoping
    // ------------------------------------------------------------------

    public function testUpdateIsScoped(): void
    {
        TenantContext::setTenantId(9);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        [$sql, $params] = $repo->scope('UPDATE users SET name = :name WHERE id = :id', [
            'name' => 'x',
            'id' => 1,
        ]);

        $this->assertMatchesRegularExpression('/WHERE\s*\(\s*id = :id\s*\)\s*AND\s+tenant_id\s*=/i', $sql);
        $this->assertContains(9, array_values($params));
    }

    public function testUpdateOnlyAffectsCurrentTenantRows(): void
    {
        $db = self::makeSqliteDatabase();
        TenantContext::setTenantId(1);
        $repo = new ScopedRepository($db);

        // Try to rename every user; scope must restrict it to tenant 1 (alice).
        $repo->query('UPDATE users SET name = :new', ['new' => 'renamed']);

        // Verify against the raw connection in system mode.
        TenantContext::reset();
        TenantContext::setSystemMode(true, 'test');
        $renamed = $repo->query('SELECT COUNT(*) FROM users WHERE name = :n', ['n' => 'renamed'])
            ->fetchColumn();

        $this->assertSame(1, (int) $renamed);
    }

    public function testDeleteIsScoped(): void
    {
        $db = self::makeSqliteDatabase();
        TenantContext::setTenantId(2);
        $repo = new ScopedRepository($db);

        $repo->query('DELETE FROM users');

        TenantContext::reset();
        TenantContext::setSystemMode(true, 'test');
        $remaining = $repo->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);

        // Only tenant-1 alice should survive a tenant-2-scoped delete.
        $this->assertSame(['alice'], $remaining);
    }

    // ------------------------------------------------------------------
    // INSERT auto-population
    // ------------------------------------------------------------------

    public function testInsertAutoPopulatesTenantId(): void
    {
        TenantContext::setTenantId(4);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        [$sql, $params] = $repo->scope(
            'INSERT INTO users (id, name, tenant_id_placeholder) VALUES (:id, :name, :p)',
            ['id' => 10, 'name' => 'z', 'p' => 1]
        );

        // tenant_id column added to the column list and a bound value supplied.
        $this->assertMatchesRegularExpression('/\(\s*id,\s*name,\s*tenant_id_placeholder,\s*tenant_id\s*\)/i', $sql);
        $this->assertContains(4, array_values($params));
    }

    public function testInsertAutoPopulationPersistsTenantId(): void
    {
        $db = self::makeSqliteDatabase();
        TenantContext::setTenantId(42);
        $repo = new ScopedRepository($db);

        $repo->query('INSERT INTO users (id, name) VALUES (:id, :name)', ['id' => 99, 'name' => 'new']);

        TenantContext::reset();
        TenantContext::setSystemMode(true, 'test');
        $tenant = $repo->query('SELECT tenant_id FROM users WHERE id = :id', ['id' => 99])->fetchColumn();

        $this->assertSame(42, (int) $tenant);
    }

    public function testInsertWithExplicitTenantColumnIsLeftUntouched(): void
    {
        TenantContext::setTenantId(4);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $original = 'INSERT INTO users (id, name, tenant_id) VALUES (:id, :name, :t)';
        [$sql, $params] = $repo->scope($original, ['id' => 1, 'name' => 'a', 't' => 8]);

        $this->assertSame($original, $sql);
        $this->assertSame(['id' => 1, 'name' => 'a', 't' => 8], $params);
    }

    public function testInsertSelectIsRefused(): void
    {
        TenantContext::setTenantId(1);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $repo->scope('INSERT INTO users (id, name) SELECT id, name FROM other');
    }

    // ------------------------------------------------------------------
    // AC 2: system-mode bypass
    // ------------------------------------------------------------------

    public function testSystemModeBypassesScopingForSelect(): void
    {
        TenantContext::setSystemMode(true, 'migration');
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $original = 'SELECT * FROM users';
        [$sql, $params] = $repo->scope($original);

        $this->assertSame($original, $sql);
        $this->assertSame([], $params);
    }

    public function testSystemModeReturnsAllTenantsRows(): void
    {
        $db = self::makeSqliteDatabase();
        TenantContext::setSystemMode(true, 'migration');
        $repo = new ScopedRepository($db);

        $rows = $repo->query('SELECT name FROM users')->fetchAll(PDO::FETCH_COLUMN);

        $this->assertEqualsCanonicalizing(['alice', 'bob', 'carol'], $rows);
    }

    public function testSystemModeBypassesInsertAutoPopulation(): void
    {
        TenantContext::setSystemMode(true, 'migration');
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $original = 'INSERT INTO users (id, name) VALUES (:id, :name)';
        [$sql] = $repo->scope($original);

        $this->assertSame($original, $sql);
    }

    // ------------------------------------------------------------------
    // Unresolved-context behaviour (fail closed)
    // ------------------------------------------------------------------

    public function testUnresolvedContextThrowsForSelect(): void
    {
        // No tenant set, system mode off.
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $this->expectExceptionMessageMatches('/unresolved/i');
        $repo->scope('SELECT * FROM users');
    }

    public function testUnresolvedContextThrowsForInsert(): void
    {
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $repo->scope('INSERT INTO users (id) VALUES (:id)', ['id' => 1]);
    }

    public function testUnresolvedContextDoesNotExecuteQuery(): void
    {
        $db = self::makeSqliteDatabase();
        $repo = new ScopedRepository($db);

        try {
            $repo->query('DELETE FROM users');
            $this->fail('Expected TenantScopeException');
        } catch (TenantScopeException) {
            // Confirm nothing was deleted: data is intact.
            TenantContext::setSystemMode(true, 'test');
            $count = $repo->query('SELECT COUNT(*) FROM users')->fetchColumn();
            $this->assertSame(3, (int) $count);
        }
    }

    // ------------------------------------------------------------------
    // Unsafe statement shapes are refused (never run unscoped)
    // ------------------------------------------------------------------

    public function testJoinSelectIsRefused(): void
    {
        TenantContext::setTenantId(1);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $repo->scope('SELECT * FROM users u JOIN roles r ON r.user_id = u.id');
    }

    public function testSubquerySelectIsRefused(): void
    {
        TenantContext::setTenantId(1);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $repo->scope('SELECT * FROM users WHERE id IN (SELECT user_id FROM roles)');
    }

    public function testUnknownStatementTypeIsRefused(): void
    {
        TenantContext::setTenantId(1);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $repo->scope('TRUNCATE TABLE users');
    }

    public function testReservedParameterCollisionIsRefused(): void
    {
        TenantContext::setTenantId(1);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $this->expectExceptionMessageMatches('/reserved/i');
        $repo->scope('SELECT * FROM users', ['whity_scope_tenant_id' => 999]);
    }

    // ------------------------------------------------------------------
    // Persist / boundary helpers
    // ------------------------------------------------------------------

    public function testSetTenantIdBeforePersistFillsFromContext(): void
    {
        TenantContext::setTenantId(11);
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $repo->callSetTenantIdBeforePersist();

        $this->assertSame(11, $repo->tenant_id);
    }

    public function testSetTenantIdBeforePersistDoesNotOverrideExisting(): void
    {
        TenantContext::setTenantId(11);
        $repo = new ScopedRepository(self::makeSqliteDatabase());
        $repo->tenant_id = 3;

        $repo->callSetTenantIdBeforePersist();

        $this->assertSame(3, $repo->tenant_id);
    }

    public function testSetTenantIdBeforePersistFailsClosedWhenUnresolved(): void
    {
        $repo = new ScopedRepository(self::makeSqliteDatabase());

        $this->expectException(TenantScopeException::class);
        $repo->callSetTenantIdBeforePersist();
    }

    public function testValidateTenantBoundaryRejectsForeignTenant(): void
    {
        TenantContext::setTenantId(1);
        $repo = new ScopedRepository(self::makeSqliteDatabase());
        $repo->tenant_id = 2;

        $this->expectException(TenantScopeException::class);
        $repo->callValidateTenantBoundary();
    }

    public function testValidateTenantBoundaryAllowsSystemMode(): void
    {
        TenantContext::setSystemMode(true, 'migration');
        $repo = new ScopedRepository(self::makeSqliteDatabase());
        $repo->tenant_id = 99;

        $repo->callValidateTenantBoundary();
        $this->addToAssertionCount(1);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a Database backed by a seeded in-memory SQLite connection.
     *
     * SQLite shares the parameterised `:name` placeholder syntax and the
     * WHERE/ORDER BY/LIMIT grammar used by the rewriter, so it exercises that the
     * rewritten SQL is genuinely valid and applies the tenant filter — not just a
     * string match.
     */
    private static function makeSqliteDatabase(): Database
    {
        $factory = static function (): PDO {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, tenant_id INTEGER)');
            $pdo->exec("INSERT INTO users (id, name, tenant_id) VALUES (1, 'alice', 1)");
            $pdo->exec("INSERT INTO users (id, name, tenant_id) VALUES (2, 'bob', 2)");
            $pdo->exec("INSERT INTO users (id, name, tenant_id) VALUES (3, 'carol', 2)");
            return $pdo;
        };

        // maxLifetime large so the connection is never recycled mid-test (a
        // reconnect would open a fresh, empty SQLite ":memory:" db); ping
        // disabled (-1 via a large interval) for the same reason — the seeded
        // connection must persist for the whole test.
        return Database::withFactory($factory, 86400, 86400);
    }
}

/**
 * Repository-style consumer of the trait used to drive the tests.
 *
 * Exposes the protected trait methods so the test can assert directly on the
 * scoping logic and on the persist/boundary helpers.
 */
class ScopedRepository
{
    use ScopesToTenant;

    public ?int $tenant_id = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * @param array<string, mixed> $params
     * @return array{0: string, 1: array<string, mixed>}
     */
    public function scope(string $sql, array $params = []): array
    {
        return $this->applyTenantScope($sql, $params);
    }

    /**
     * @param array<string, mixed> $params
     */
    public function query(string $sql, array $params = []): \PDOStatement
    {
        return $this->tenantScopedQuery($this->db, $sql, $params);
    }

    public function callSetTenantIdBeforePersist(): void
    {
        $this->setTenantIdBeforePersist();
    }

    public function callValidateTenantBoundary(): void
    {
        $this->validateTenantBoundary();
    }
}
