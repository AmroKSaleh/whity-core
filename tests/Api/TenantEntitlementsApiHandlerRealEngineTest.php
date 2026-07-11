<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantEntitlementsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Database\Database;
use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Entitlement\TenantEntitlementRepository;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see TenantEntitlementsApiHandler} (WC-ent). Proves the
 * OPERATOR admin surface: a system-tenant caller with entitlements:manage can
 * read/write ANY target tenant's entitlements, but a regular tenant admin — who
 * also holds the permission via the global admin role — is blocked by the
 * system-tenant gate (the cross-tenant escalation guard). Also exercises target
 * isolation, 404 / 409 / 422 / 400 paths, and the JSON-boolean write handling.
 *
 * That the system operator is authorized at all also implicitly verifies
 * migration 052 grants entitlements:manage to the admin role.
 */
final class TenantEntitlementsApiHandlerRealEngineTest extends TestCase
{
    /** Seeded profile ids. */
    private const OPERATOR      = 10; // active member of system tenant 0 (admin role)
    private const TENANT2_ADMIN = 11; // active member of tenant 2 (admin role) — NOT system
    private const SYS_NOPERM    = 12; // active member of system tenant 0 (no-perm role)

    private const SYSTEM_TENANT = 0;
    private const TARGET_TENANT = 1;
    private const OTHER_TENANT  = 2;

    private PDO $pdo;
    private TenantEntitlementsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = $this->makeSchema();
        $db = $this->wrapSqlite($this->pdo);
        $roleChecker = new RoleChecker($db, new PermissionRegistry());
        $this->handler = new TenantEntitlementsApiHandler(
            $this->pdo,
            new EntitlementService(new TenantEntitlementRepository($this->pdo)),
            $roleChecker
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ── operator happy paths ───────────────────────────────────────────────────

    public function testOperatorGetsTargetTenantDefaults(): void
    {
        $res = $this->get(self::TARGET_TENANT, self::OPERATOR, self::SYSTEM_TENANT);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $data = $this->decode($res)['data'];
        self::assertSame(self::TARGET_TENANT, $data['tenant_id']);
        self::assertFalse($data['effective'][EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertSame([], $data['overridden']);
        // The catalogue is present so the operator UI can render the editor.
        self::assertArrayHasKey(EntitlementRegistry::MEMBERS_MAX, $data['registry']);
        self::assertSame('int', $data['registry'][EntitlementRegistry::MEMBERS_MAX]['type']);
    }

    public function testOperatorSetsEntitlementsForTargetTenant(): void
    {
        $res = $this->patch(self::TARGET_TENANT, [
            EntitlementRegistry::STORAGE_CUSTOM_BACKEND => true,
            EntitlementRegistry::MEMBERS_MAX => 25,
        ], self::OPERATOR, self::SYSTEM_TENANT);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $data = $this->decode($res)['data'];
        self::assertTrue($data['effective'][EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertSame(25, $data['effective'][EntitlementRegistry::MEMBERS_MAX]);
        self::assertContains(EntitlementRegistry::STORAGE_CUSTOM_BACKEND, $data['overridden']);

        // Isolation: the OTHER tenant is untouched (still at defaults).
        $other = $this->decode($this->get(self::OTHER_TENANT, self::OPERATOR, self::SYSTEM_TENANT))['data'];
        self::assertFalse($other['effective'][EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertSame([], $other['overridden']);
    }

    public function testJsonBooleanFalseSetsFalseAndDoesNotClear(): void
    {
        // A JSON `false` must SET false, not clear the override (a raw string cast
        // of false is '' which would look like a clear).
        $res = $this->patch(self::TARGET_TENANT, [
            EntitlementRegistry::SSO_TENANT_IDP => false,
        ], self::OPERATOR, self::SYSTEM_TENANT);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        $data = $this->decode($res)['data'];
        self::assertFalse($data['effective'][EntitlementRegistry::SSO_TENANT_IDP]);
        self::assertContains(EntitlementRegistry::SSO_TENANT_IDP, $data['overridden'], 'false must persist as an explicit override');
    }

    public function testNullClearsOverrideBackToDefault(): void
    {
        $this->patch(self::TARGET_TENANT, [EntitlementRegistry::STORAGE_CUSTOM_BACKEND => true], self::OPERATOR, self::SYSTEM_TENANT);
        $res = $this->patch(self::TARGET_TENANT, [EntitlementRegistry::STORAGE_CUSTOM_BACKEND => null], self::OPERATOR, self::SYSTEM_TENANT);

        $data = $this->decode($res)['data'];
        self::assertFalse($data['effective'][EntitlementRegistry::STORAGE_CUSTOM_BACKEND]);
        self::assertSame([], $data['overridden']);
    }

    // ── the cross-tenant escalation guard ──────────────────────────────────────

    public function testNonSystemTenantAdminIsBlockedByTheSystemTenantGate(): void
    {
        // The tenant-2 admin HOLDS entitlements:manage in tenant 2 (global admin
        // role), so this is NOT a permission failure — it is the system-tenant gate.
        $get = $this->get(self::TARGET_TENANT, self::TENANT2_ADMIN, self::OTHER_TENANT);
        self::assertSame(403, $get->getStatusCode(), 'A non-system admin must never read another tenant\'s entitlements');

        $patch = $this->patch(self::TARGET_TENANT, [
            EntitlementRegistry::MEMBERS_MAX => 999,
        ], self::TENANT2_ADMIN, self::OTHER_TENANT);
        self::assertSame(403, $patch->getStatusCode(), 'A non-system admin must never write another tenant\'s entitlements');

        // And nothing was persisted.
        $data = $this->decode($this->get(self::TARGET_TENANT, self::OPERATOR, self::SYSTEM_TENANT))['data'];
        self::assertSame([], $data['overridden']);
    }

    public function testCallerWithoutPermissionIs403(): void
    {
        $res = $this->get(self::TARGET_TENANT, self::SYS_NOPERM, self::SYSTEM_TENANT);
        self::assertSame(403, $res->getStatusCode());
    }

    // ── error paths ────────────────────────────────────────────────────────────

    public function testUnknownTargetTenantIs404(): void
    {
        self::assertSame(404, $this->get(9999, self::OPERATOR, self::SYSTEM_TENANT)->getStatusCode());
        self::assertSame(404, $this->patch(9999, [EntitlementRegistry::MEMBERS_MAX => 5], self::OPERATOR, self::SYSTEM_TENANT)->getStatusCode());
    }

    public function testSystemTenantTargetPatchIs409(): void
    {
        $res = $this->patch(self::SYSTEM_TENANT, [EntitlementRegistry::MEMBERS_MAX => 5], self::OPERATOR, self::SYSTEM_TENANT);
        self::assertSame(409, $res->getStatusCode(), $res->getBody());
    }

    public function testUnknownKeyIs422(): void
    {
        $res = $this->patch(self::TARGET_TENANT, ['made.up.key' => 'true'], self::OPERATOR, self::SYSTEM_TENANT);
        self::assertSame(422, $res->getStatusCode());
    }

    public function testInvalidValueIs422AndPersistsNothing(): void
    {
        $res = $this->patch(self::TARGET_TENANT, [EntitlementRegistry::MEMBERS_MAX => 'lots'], self::OPERATOR, self::SYSTEM_TENANT);
        self::assertSame(422, $res->getStatusCode());

        $data = $this->decode($this->get(self::TARGET_TENANT, self::OPERATOR, self::SYSTEM_TENANT))['data'];
        self::assertSame([], $data['overridden'], 'A rejected write must not persist anything');
    }

    public function testMissingEntitlementsObjectIs400(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $req = $this->req('PATCH', '/api/tenants/1/entitlements', ['nope' => 1], self::OPERATOR, self::SYSTEM_TENANT);
        self::assertSame(400, $this->handler->patch($req, ['id' => '1'])->getStatusCode());
    }

    // ── helpers ────────────────────────────────────────────────────────────────

    private function get(int $targetTenant, int $userId, int $ctxTenant): \Whity\Sdk\Http\Response
    {
        // Each call re-establishes context; setTenantId locks on first set, so
        // reset() first to allow multiple handler calls within one test.
        TenantContext::reset();
        TenantContext::setTenantId($ctxTenant);
        return $this->handler->get(
            $this->req('GET', "/api/tenants/{$targetTenant}/entitlements", null, $userId, $ctxTenant),
            ['id' => (string) $targetTenant]
        );
    }

    /**
     * @param array<string, mixed> $entitlements
     */
    private function patch(int $targetTenant, array $entitlements, int $userId, int $ctxTenant): \Whity\Sdk\Http\Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($ctxTenant);
        return $this->handler->patch(
            $this->req('PATCH', "/api/tenants/{$targetTenant}/entitlements", ['entitlements' => $entitlements], $userId, $ctxTenant),
            ['id' => (string) $targetTenant]
        );
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body, int $userId, int $tenantId): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded, $res->getBody());

        return $decoded;
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'target', 'target')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'other', 'other')");

        // admin role (1) is seeded by migrations and granted entitlements:manage
        // by migration 052. A no-perm role (101) proves the permission gate.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (101, 'no-perm', '', 0, datetime('now'))");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'operator',      'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'tenant2-admin', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'sys-noperm',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        // Memberships:
        //  - operator (active, tenant 0, admin) → holds entitlements:manage in tenant 0.
        //  - tenant2-admin (active, tenant 2, admin) → holds the permission in tenant 2
        //    but is NOT a system-tenant member → must be blocked by the system gate.
        //  - sys-noperm (active, tenant 0, no-perm role) → in the system tenant but
        //    lacks the permission → 403 on the permission arm.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 0, 1,   'active', datetime('now')),
                (1001, 11, 2, 1,   'active', datetime('now')),
                (1002, 12, 0, 101, 'active', datetime('now'))
        ");

        return $pdo;
    }

    /**
     * Wrap a live SQLite PDO in the production {@see Database} so the real
     * RoleChecker runs unmodified against it (single reused handle).
     */
    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
