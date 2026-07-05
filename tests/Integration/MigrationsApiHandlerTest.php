<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\MigrationsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;
use Whity\Sdk\Http\Response;

/**
 * Integration tests for MigrationsApiHandler (WC-22f8596f).
 *
 * Coverage:
 *  1. Admin-only RBAC on GET /api/migrations: an admin reaches the handler;
 *     a non-admin principal is denied 403; unauthenticated callers get 401.
 *  2. Tenant-scoping / system-tenant semantics: a system-tenant (id=0) admin
 *     can reach the route when TenantContext carries tenant 0; a regular-tenant
 *     user whose role is not 'admin' is denied even if they hold any other
 *     permission.
 *  3. Response-shape assertions for list().
 *  4. Direct handler tests for run() and rollback() against an isolated in-memory
 *     migration directory (run and rollback are NOT registered as HTTP routes;
 *     they are CLI-only, so they are driven through the public handler API directly
 *     against an isolated schema).
 *  5. Per-migration rollback targeting (#194): rollback always removes the most
 *     recently executed migration (by executed_at DESC); applying N migrations and
 *     rolling back removes them in strict reverse order.
 *
 * Fixture migration file names use the "mah_" infix
 * (MigrationsApiHandlerTest-specific) to avoid PHP "cannot redeclare class" fatals
 * when other test suites in the same process use the same bare names
 * (e.g. MigrationsCommandTargetedRollbackTest uses 001_create_alpha).
 *
 * Uses {@see SchemaFromMigrations} so the test schema IS the production schema,
 * and runs on both SQLite (default) and real PostgreSQL (PHPUNIT_PG_DSN CI lane).
 * No production code is changed.
 */
class MigrationsApiHandlerTest extends TestCase
{
    private const SECRET = 'test-secret-key-padded-for-hs256-min-32-byte-key';
    private const TENANT  = 1;

    private JwtParser $jwtParser;
    private PermissionRegistry $registry;
    private PDO $pdo;
    private Database $db;
    private RoleChecker $roleChecker;
    private RbacMiddleware $middleware;
    private Router $router;

    /** Temporary directory for fixture migration files. */
    private string $migrationDir;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);

        $this->jwtParser = new JwtParser(self::SECRET);
        $this->registry  = new PermissionRegistry();

        $this->pdo = $this->makeSchema();
        $this->db  = $this->wrapPdo($this->pdo);
        $this->roleChecker = new RoleChecker($this->db, $this->registry);
        $this->middleware  = new RbacMiddleware($this->jwtParser, $this->roleChecker);
        $this->router      = new Router('');

        $this->migrationDir = sys_get_temp_dir() . '/whity_mah_test_' . bin2hex(random_bytes(6));
        mkdir($this->migrationDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->migrationDir);
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ─── Schema helpers ──────────────────────────────────────────────────────

    /**
     * Build a fresh schema PDO and seed tenant 1.
     *
     * Migration 001 seeds the 'admin' and 'user' roles.
     * Migration 010 seeds the system tenant (id=0).
     * No manual role seeding needed.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        // Seed tenant 1 so users.tenant_id FK is satisfied on PostgreSQL.
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-test')");

        return $pdo;
    }

    private function wrapPdo(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();
        return $db;
    }

    // ─── Routing + dispatch helpers ──────────────────────────────────────────

    /**
     * Register GET /api/migrations exactly as public/index.php does:
     * gated by requiredRole = 'admin', no requiredPermission.
     */
    private function registerRoute(MigrationsApiHandler $handler): void
    {
        $this->router->register(
            'GET',
            '/api/migrations',
            [$handler, 'list'],
            'admin'   // requiredRole — no permission constant
        );
    }

    /**
     * Dispatch a request through the Router + RbacMiddleware exactly as the
     * HTTP kernel does.
     */
    private function dispatch(Request $request): Response
    {
        $match = $this->router->match($request);
        if ($match === null) {
            return Response::error('Not Found', 404);
        }

        $params  = $match['params'];
        $handler = $match['handler'];
        $next    = static fn(Request $req): Response => $handler($req, $params);

        return $this->middleware->handle(
            $request,
            $next,
            $match['requiredRole'],
            $match['requiredPermission']
        );
    }

    // ─── User / token seeding ────────────────────────────────────────────────

    /**
     * Seed a user in the given tenant whose direct role matches $roleName.
     *
     * The 'admin' and 'user' roles are seeded by migration 001.
     *
     * @param int    $userId    Unique user id.
     * @param int    $tenantId  Tenant the user belongs to.
     * @param string $roleName  'admin' or 'user'.
     */
    private function seedUser(int $userId, int $tenantId, string $roleName): void
    {
        $stmt = $this->pdo->prepare('SELECT id FROM roles WHERE name = ?');
        $stmt->execute([$roleName]);
        $roleId = $stmt->fetchColumn();
        self::assertIsNotBool($roleId, "Role '{$roleName}' must be seeded by migrations.");

        $this->pdo->prepare(
            "INSERT OR IGNORE INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)"
        )->execute([$userId, "user{$userId}"]);

        $this->pdo->prepare(
            "INSERT OR IGNORE INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([$userId, $tenantId, $roleId]);
    }

    /**
     * Build a signed JWT for the given user and tenant.
     */
    private function tokenFor(int $userId, int $tenantId = self::TENANT): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $userId,
            'email'            => "user{$userId}@example.com",
            'active_tenant_id' => $tenantId,
            'token_epoch'      => 0,
        ]);
    }

    // ─── Migration fixture helpers ───────────────────────────────────────────

    /**
     * Write a minimal fixture migration PHP file to $migrationDir.
     *
     * All fixture class names carry the "Mah" infix so they cannot collide with
     * fixture classes from other test suites in the same PHP process.
     *
     * @param string $filename Filename WITHOUT .php extension (e.g. '001_mah_create_foo').
     * @param string $upSql    SQL executed in up() — defaults to a no-op SELECT.
     * @param string $downSql  SQL executed in down() — defaults to a no-op SELECT.
     */
    private function writeMigration(
        string $filename,
        string $upSql   = 'SELECT 1',
        string $downSql = 'SELECT 1'
    ): void {
        $parts     = explode('_', $filename);
        array_shift($parts);
        $className = implode('', array_map('ucfirst', $parts));

        $code = <<<PHP
<?php
namespace Database\\Migrations;
use Whity\\Database\\Database;
class {$className} {
    public static function up(Database \$db): void   { \$db->exec('{$upSql}'); }
    public static function down(Database \$db): void { \$db->exec('{$downSql}'); }
}
PHP;
        file_put_contents($this->migrationDir . '/' . $filename . '.php', $code);
    }

    /**
     * Ensure the core_schema_migrations table exists in the test PDO.
     * Migration 003 already creates it; this is a no-op safety guard.
     */
    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            "CREATE TABLE IF NOT EXISTS core_schema_migrations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                migration_name TEXT NOT NULL UNIQUE,
                executed_at TIMESTAMP NOT NULL DEFAULT (datetime('now')),
                execution_time_ms INTEGER NOT NULL DEFAULT 0
            )"
        );
    }

    // =========================================================================
    // SECTION 1: Admin-only RBAC on GET /api/migrations
    // =========================================================================

    /**
     * An admin user reaches the handler and receives a 200 with the data key.
     */
    public function testListAsAdminReturns200WithDataKey(): void
    {
        $userId  = 100;
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);
        $this->seedUser($userId, self::TENANT, 'admin');

        $request  = new Request('GET', '/api/migrations', [
            'Authorization' => 'Bearer ' . $this->tokenFor($userId),
        ]);
        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body, 'Response body must be valid JSON.');
        self::assertArrayHasKey('data', $body, 'Response must contain a top-level "data" key.');
        self::assertIsArray($body['data'], '"data" must be an array.');
    }

    /**
     * A user with the 'user' role (not 'admin') is denied with a 403.
     */
    public function testListAsNonAdminIsForbidden(): void
    {
        $userId  = 101;
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);
        $this->seedUser($userId, self::TENANT, 'user');

        $request  = new Request('GET', '/api/migrations', [
            'Authorization' => 'Bearer ' . $this->tokenFor($userId),
        ]);
        $response = $this->dispatch($request);

        self::assertSame(403, $response->getStatusCode());

        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body);
        self::assertArrayHasKey('error', $body);
    }

    /**
     * An unauthenticated request (no Authorization header) gets a 401.
     */
    public function testListWithoutTokenIsUnauthorized(): void
    {
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);

        $response = $this->dispatch(new Request('GET', '/api/migrations'));

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A request with a malformed token (not a valid JWT) gets a 401.
     */
    public function testListWithInvalidTokenIsUnauthorized(): void
    {
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);

        $request  = new Request('GET', '/api/migrations', [
            'Authorization' => 'Bearer not.a.valid.jwt',
        ]);
        $response = $this->dispatch($request);

        self::assertSame(401, $response->getStatusCode());
    }

    /**
     * A principal that holds a permission (e.g. users:read) but whose role is
     * NOT 'admin' must still be denied — permission grants do not substitute for
     * the role check.
     */
    public function testListWithNonAdminRoleIsForbidden(): void
    {
        $userId = 102;
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);
        $this->seedUser($userId, self::TENANT, 'user');

        $request  = new Request('GET', '/api/migrations', [
            'Authorization' => 'Bearer ' . $this->tokenFor($userId),
        ]);
        $response = $this->dispatch($request);

        self::assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // SECTION 2: Tenant-scoping / system-tenant semantics
    // =========================================================================

    /**
     * A system-tenant (id=0) admin can reach GET /api/migrations when
     * TenantContext carries tenant 0 — system admins are not locked to any
     * regular tenant.
     */
    public function testSystemTenantAdminCanListMigrations(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(0); // system tenant

        $userId  = 110;
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);

        // System tenant (id=0) is seeded by migration 010.
        $this->seedUser($userId, 0, 'admin');

        $request  = new Request('GET', '/api/migrations', [
            'Authorization' => 'Bearer ' . $this->tokenFor($userId, 0),
        ]);
        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * RbacMiddleware uses TenantContext as the authoritative tenant for role
     * checks.  A user seeded in tenant 2 with the 'admin' role satisfies the
     * check when TenantContext also resolves to tenant 2.
     */
    public function testRoleCheckUsesToTenantContext(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(2);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (2, 'tenant-two')");

        $userId  = 111;
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);
        $this->seedUser($userId, 2, 'admin');

        $request = new Request('GET', '/api/migrations', [
            'Authorization' => 'Bearer ' . $this->tokenFor($userId, 2),
        ]);
        $response = $this->dispatch($request);

        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * A 'user'-role principal in any tenant is denied — role name must be 'admin'.
     */
    public function testNonAdminInActiveContextIsForbidden(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(2);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (2, 'tenant-two')");

        $userId  = 112;
        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);
        $this->registerRoute($handler);
        $this->seedUser($userId, 2, 'user');

        $request = new Request('GET', '/api/migrations', [
            'Authorization' => 'Bearer ' . $this->tokenFor($userId, 2),
        ]);
        $response = $this->dispatch($request);

        self::assertSame(403, $response->getStatusCode());
    }

    // =========================================================================
    // SECTION 3: Response shape for list()
    // =========================================================================

    /**
     * list() with no migration files returns an empty data array.
     */
    public function testListWithNoMigrationFilesReturnsEmptyArray(): void
    {
        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->list(new Request('GET', '/api/migrations'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertSame([], $body['data']);
    }

    /**
     * list() with migrations present returns entries with the required fields.
     */
    public function testListResponseShapeContainsRequiredFields(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_foo');
        $this->writeMigration('002_mah_create_bar');

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->list(new Request('GET', '/api/migrations'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertIsArray($body['data']);
        self::assertCount(2, $body['data']);

        foreach ($body['data'] as $entry) {
            self::assertArrayHasKey('name', $entry);
            self::assertArrayHasKey('executed', $entry);
            self::assertArrayHasKey('executed_at', $entry);
        }
    }

    /**
     * list() marks pending migrations as not executed and executed_at as null.
     */
    public function testListMarksPendingMigrationsAsNotExecuted(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_baz');

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->list(new Request('GET', '/api/migrations'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertCount(1, $body['data']);
        $entry = $body['data'][0];
        self::assertSame('001_mah_create_baz', $entry['name']);
        self::assertFalse($entry['executed']);
        self::assertNull($entry['executed_at']);
    }

    /**
     * list() marks applied migrations as executed with a non-null timestamp.
     */
    public function testListMarksAppliedMigrationsAsExecuted(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_qux');

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO core_schema_migrations
             (migration_name, executed_at, execution_time_ms)
             VALUES (?, NOW(), ?)'
        )->execute(['001_mah_create_qux', 0]);

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->list(new Request('GET', '/api/migrations'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertCount(1, $body['data']);
        $entry = $body['data'][0];
        self::assertSame('001_mah_create_qux', $entry['name']);
        self::assertTrue($entry['executed']);
        self::assertNotNull($entry['executed_at']);
    }

    /**
     * list() returns migrations sorted by filename (ascending).
     */
    public function testListReturnsMigrationsInFilenameOrder(): void
    {
        $this->ensureMigrationsTable();
        // Write them in reverse order to confirm sort.
        $this->writeMigration('003_mah_create_third');
        $this->writeMigration('001_mah_create_first');
        $this->writeMigration('002_mah_create_second');

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->list(new Request('GET', '/api/migrations'));

        self::assertSame(200, $response->getStatusCode());
        $body  = json_decode($response->getBody(), true);
        $names = array_column($body['data'], 'name');
        self::assertSame([
            '001_mah_create_first',
            '002_mah_create_second',
            '003_mah_create_third',
        ], $names);
    }

    // =========================================================================
    // SECTION 4: run() — apply pending migrations
    // =========================================================================

    /**
     * run() with no pending migrations returns count = 0.
     */
    public function testRunWithNoPendingMigrationsReturnsCountZero(): void
    {
        $this->ensureMigrationsTable();

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->run(new Request('POST', '/api/migrations/run'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertSame(0, $body['data']['count']);
    }

    /**
     * run() applies pending migrations and returns the count of applied files.
     */
    public function testRunAppliesPendingMigrationsAndReturnsCount(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_prom');
        $this->writeMigration('002_mah_create_cres');

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->run(new Request('POST', '/api/migrations/run'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertSame(2, $body['data']['count']);
    }

    /**
     * run() skips already-applied migrations (idempotent).
     */
    public function testRunIsIdempotentForAlreadyAppliedMigrations(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_stel');

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO core_schema_migrations
             (migration_name, executed_at, execution_time_ms)
             VALUES (?, NOW(), ?)'
        )->execute(['001_mah_create_stel', 0]);

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->run(new Request('POST', '/api/migrations/run'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertSame(0, $body['data']['count']);
    }

    // =========================================================================
    // SECTION 5: rollback() — roll back the most recent migration
    // =========================================================================

    /**
     * rollback() with no executed migrations returns a 400 error.
     */
    public function testRollbackWithNoExecutedMigrationsReturns400(): void
    {
        $this->ensureMigrationsTable();

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->rollback(new Request('POST', '/api/migrations/rollback'));

        self::assertSame(400, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertArrayHasKey('error', $body);
        self::assertSame('No migrations to rollback', $body['error']);
    }

    /**
     * rollback() removes the record of the most recently executed migration.
     *
     * This exercises the "targeted rollback" semantics introduced in #194:
     * rollback always targets the LAST migration (by executed_at DESC), not a
     * random one, so rolling back leaves exactly the prior state recorded in
     * core_schema_migrations.
     *
     * Records are seeded with explicit timestamps differing by one second so the
     * ORDER BY executed_at DESC ordering is deterministic on both SQLite and
     * PostgreSQL (SQLite's NOW() UDF has second precision; back-to-back run()
     * calls may yield the same timestamp and a non-deterministic LIMIT 1 pick).
     */
    public function testRollbackRemovesLastExecutedMigrationRecord(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_delt');
        $this->writeMigration('002_mah_create_epsi');

        // Seed records with explicit timestamps differing by ≥1 second.
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO core_schema_migrations
             (migration_name, executed_at, execution_time_ms)
             VALUES (?, ?, ?)'
        )->execute(['001_mah_create_delt', '2000-01-01 00:00:01', 0]);
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO core_schema_migrations
             (migration_name, executed_at, execution_time_ms)
             VALUES (?, ?, ?)'
        )->execute(['002_mah_create_epsi', '2000-01-01 00:00:02', 0]);

        $stmt = $this->pdo->query('SELECT migration_name FROM core_schema_migrations ORDER BY migration_name');
        self::assertNotFalse($stmt);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertContains('001_mah_create_delt', $rows);
        self::assertContains('002_mah_create_epsi', $rows);

        // Rollback — must remove 002_mah_create_epsi (most recent by executed_at).
        $handler      = new MigrationsApiHandler($this->db, $this->migrationDir);
        $rollbackResp = $handler->rollback(new Request('POST', '/api/migrations/rollback'));
        self::assertSame(200, $rollbackResp->getStatusCode());

        $body = json_decode($rollbackResp->getBody(), true);
        self::assertArrayHasKey('data', $body);
        self::assertStringContainsString('002_mah_create_epsi', $body['data']['message']);

        // Only migration 001 should remain.
        $stmt = $this->pdo->query('SELECT migration_name FROM core_schema_migrations ORDER BY migration_name');
        self::assertNotFalse($stmt);
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['001_mah_create_delt'], $remaining);
    }

    /**
     * rollback() response shape: contains data.message confirming which
     * migration was rolled back.
     */
    public function testRollbackResponseShapeContainsMessage(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_zeta');

        $this->pdo->prepare(
            'INSERT OR IGNORE INTO core_schema_migrations
             (migration_name, executed_at, execution_time_ms)
             VALUES (?, ?, ?)'
        )->execute(['001_mah_create_zeta', '2000-01-01 00:00:01', 0]);

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->rollback(new Request('POST', '/api/migrations/rollback'));

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertArrayHasKey('data', $body);
        self::assertArrayHasKey('message', $body['data']);
    }

    /**
     * Per-migration targeted rollback ordering (#194): when multiple migrations
     * are applied, successive rollbacks always remove the most recently applied
     * one — the ordering is stable and deterministic.
     *
     * Records are seeded with explicit staggered timestamps (one second apart) to
     * guarantee that ORDER BY executed_at DESC picks a deterministic winner even on
     * SQLite where NOW() has only second-level precision.
     */
    public function testSuccessiveRollbacksRemoveMigrationsInReverseOrder(): void
    {
        $this->ensureMigrationsTable();
        $this->writeMigration('001_mah_create_etam');
        $this->writeMigration('002_mah_create_thet');
        $this->writeMigration('003_mah_create_iota');

        // Seed with distinct timestamps: 001 earliest, 003 latest.
        $timestamps = [
            '001_mah_create_etam' => '2000-01-01 00:00:01',
            '002_mah_create_thet' => '2000-01-01 00:00:02',
            '003_mah_create_iota' => '2000-01-01 00:00:03',
        ];
        foreach ($timestamps as $name => $ts) {
            $this->pdo->prepare(
                'INSERT OR IGNORE INTO core_schema_migrations
                 (migration_name, executed_at, execution_time_ms)
                 VALUES (?, ?, ?)'
            )->execute([$name, $ts, 0]);
        }

        $handler = new MigrationsApiHandler($this->db, $this->migrationDir);

        // First rollback: removes 003_mah_create_iota (latest executed_at).
        $resp1 = $handler->rollback(new Request('POST', '/api/migrations/rollback'));
        self::assertSame(200, $resp1->getStatusCode());
        $body1 = json_decode($resp1->getBody(), true);
        self::assertStringContainsString('003_mah_create_iota', $body1['data']['message']);

        // Second rollback: removes 002_mah_create_thet (next latest).
        $resp2 = $handler->rollback(new Request('POST', '/api/migrations/rollback'));
        self::assertSame(200, $resp2->getStatusCode());
        $body2 = json_decode($resp2->getBody(), true);
        self::assertStringContainsString('002_mah_create_thet', $body2['data']['message']);

        // Only 001_mah_create_etam must remain.
        $stmt = $this->pdo->query('SELECT migration_name FROM core_schema_migrations');
        self::assertNotFalse($stmt);
        $remaining = $stmt->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame(['001_mah_create_etam'], $remaining);
    }

    /**
     * rollback() returns 500 when the migration file no longer exists on disk.
     */
    public function testRollbackReturns500WhenMigrationFileIsMissing(): void
    {
        $this->ensureMigrationsTable();

        // Record a migration that has NO corresponding file on disk.
        $this->pdo->prepare(
            'INSERT OR IGNORE INTO core_schema_migrations
             (migration_name, executed_at, execution_time_ms)
             VALUES (?, ?, ?)'
        )->execute(['999_mah_create_ghost', '2000-01-01 00:00:01', 0]);

        $handler  = new MigrationsApiHandler($this->db, $this->migrationDir);
        $response = $handler->rollback(new Request('POST', '/api/migrations/rollback'));

        self::assertSame(500, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        self::assertArrayHasKey('error', $body);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $files = scandir($dir);
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') {
                continue;
            }
            $child = $dir . '/' . $file;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($dir);
    }
}
