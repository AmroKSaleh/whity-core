<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SettingsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine integration tests for the Website Settings API (Website Settings).
 *
 * Drives the REAL {@see SettingsApiHandler} + {@see SettingsService} +
 * repositories + a REAL {@see RoleChecker} against the full migration-built
 * schema on in-memory SQLite (PDO::ATTR_STRINGIFY_FETCHES on, mirroring
 * PostgreSQL string fetches). The same handler SQL runs on the
 * postgres-integration CI job.
 *
 * Proves, per endpoint: (1) it rejects a caller lacking ITS specific permission
 * with 403 and accepts the caller that holds it; (2) PATCH validation returns
 * 422 with field details on a bad timezone/email/unknown key; (3) clearing an
 * override falls back to global → default.
 */
final class SettingsApiRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const SYSTEM_TENANT = 0;

    // Seeded users (see makeSchema): user 10 holds ALL three settings perms;
    // 11 holds only settings:read; 12 holds NO settings perms; 13 holds
    // settings:read + settings:write but NOT settings:manage (a "write-only"
    // editor who must still be locked out of the GLOBAL defaults surface).
    private const USER_FULL = 10;
    private const USER_READ_ONLY = 11;
    private const USER_NONE = 12;
    private const USER_WRITE_NO_MANAGE = 13;

    private PDO $pdo;
    private SettingsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        $this->handler = $this->makeHandler();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== GET /api/settings (settings:read) ====================

    public function testGetSettingsRejectsCallerWithoutSettingsRead(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->get($this->req('GET', '/api/settings', null, self::USER_NONE));

        self::assertSame(403, $response->getStatusCode());
    }

    public function testGetSettingsReturnsEffectiveRegistryAndOverriddenForAuthorizedCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->get($this->req('GET', '/api/settings', null, self::USER_READ_ONLY));

        self::assertSame(200, $response->getStatusCode());
        $data = $this->decode($response)['data'];
        self::assertSame('Whity', $data['effective']['site_name']);
        self::assertSame('UTC', $data['effective']['timezone']);
        self::assertCount(4, $data['registry']);
        self::assertSame([], $data['overridden']);
    }

    public function testGetSettingsReportsTenantOverridableTrueForRegularTenant(): void
    {
        // WC-224: a regular tenant HAS a per-tenant override layer, so the UI may
        // present the editable tenant form. The flag is server-computed.
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->get($this->req('GET', '/api/settings', null, self::USER_READ_ONLY));

        self::assertSame(200, $response->getStatusCode());
        self::assertTrue($this->decode($response)['data']['tenant_overridable']);
    }

    public function testGetSettingsReportsTenantOverridableFalseForSystemTenant(): void
    {
        // WC-224: the system tenant (0) has globals only — no per-tenant override
        // layer — so the editable tenant form must be hidden client-side. The
        // server signals this with tenant_overridable=false to prevent the 422.
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->get(
            $this->req('GET', '/api/settings', null, self::USER_FULL, self::SYSTEM_TENANT)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertFalse($this->decode($response)['data']['tenant_overridable']);
    }

    // ==================== PATCH /api/settings (settings:write) ====================

    public function testPatchSettingsRejectsReadOnlyCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->patch(
            $this->req('PATCH', '/api/settings', ['settings' => ['site_name' => 'Acme']], self::USER_READ_ONLY)
        );

        self::assertSame(403, $response->getStatusCode());
        // Nothing persisted.
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenant_settings')->fetchColumn()
        );
    }

    public function testPatchSettingsUpsertsOverrideForAuthorizedCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->patch(
            $this->req('PATCH', '/api/settings', ['settings' => ['site_name' => 'Acme Co']], self::USER_FULL)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Acme Co', $this->decode($response)['data']['site_name']);
        self::assertSame(
            'Acme Co',
            $this->pdo->query(
                "SELECT value FROM tenant_settings WHERE tenant_id = 1 AND setting_key = 'site_name'"
            )->fetchColumn()
        );
    }

    public function testPatchSettingsRejectsInvalidTimezoneWith422AndFieldDetail(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->patch(
            $this->req('PATCH', '/api/settings', ['settings' => ['timezone' => 'Mars/Phobos']], self::USER_FULL)
        );

        self::assertSame(422, $response->getStatusCode());
        $body = $this->decode($response);
        self::assertSame('Validation failed', $body['error']);
        self::assertArrayHasKey('timezone', $body['details']);
        // Nothing persisted on a rejected write.
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenant_settings')->fetchColumn()
        );
    }

    public function testPatchSettingsRejectsInvalidEmailWith422(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->patch(
            $this->req('PATCH', '/api/settings', ['settings' => ['support_email' => 'nope']], self::USER_FULL)
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('support_email', $this->decode($response)['details']);
    }

    public function testPatchSettingsRejectsUnknownKeyWith422(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->patch(
            $this->req('PATCH', '/api/settings', ['settings' => ['bogus_key' => 'x']], self::USER_FULL)
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('bogus_key', $this->decode($response)['details']);
    }

    public function testPatchSettingsClearingOverrideFallsBackToGlobalThenDefault(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        // A global default exists; the tenant overrides it, then clears it.
        $this->seedGlobal('site_name', 'Platform Default');
        $overridden = $this->handler->patch(
            $this->req('PATCH', '/api/settings', ['settings' => ['site_name' => 'Tenant Co']], self::USER_FULL)
        );
        self::assertSame('Tenant Co', $this->decode($overridden)['data']['site_name']);

        // Clearing with empty string removes the override → falls back to global.
        $cleared = $this->handler->patch(
            $this->req('PATCH', '/api/settings', ['settings' => ['site_name' => '']], self::USER_FULL)
        );
        self::assertSame(200, $cleared->getStatusCode());
        self::assertSame('Platform Default', $this->decode($cleared)['data']['site_name']);
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM tenant_settings')->fetchColumn(),
            'Clearing an override must remove the row, not store an empty value'
        );
    }

    // ==================== /api/settings/global (settings:manage) ====================

    public function testGetGlobalRejectsReadOnlyCaller(): void
    {
        // USER_READ_ONLY holds settings:read but NOT settings:manage.
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->getGlobal($this->req('GET', '/api/settings/global', null, self::USER_READ_ONLY));

        self::assertSame(403, $response->getStatusCode());
    }

    /**
     * The headline security invariant: holding settings:write (the per-tenant
     * editor permission) does NOT grant access to the GLOBAL defaults surface —
     * that requires settings:manage. A write-no-manage caller must be denied on
     * BOTH the global read and the global write, and nothing must persist.
     *
     * RED-first reasoning: if getGlobal/patchGlobal were mis-gated on
     * SETTINGS_WRITE (instead of SETTINGS_MANAGE), USER_WRITE_NO_MANAGE would
     * sail through with 200/upsert and these 403 + zero-row assertions would
     * fail. They pass against the real handler precisely because both global
     * endpoints re-check settings:manage in authorize().
     */
    public function testGlobalEndpointsRejectWriteOnlyCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);

        // GET /api/settings/global — write-only holder is denied (403).
        $get = $this->handler->getGlobal(
            $this->req('GET', '/api/settings/global', null, self::USER_WRITE_NO_MANAGE)
        );
        self::assertSame(403, $get->getStatusCode());
        self::assertSame(
            'settings:manage',
            $this->decode($get)['details']['required'] ?? null,
            'The 403 must name settings:manage as the missing permission.'
        );

        // PATCH /api/settings/global — write-only holder is denied (403) and the
        // global default is NOT written.
        $patch = $this->handler->patchGlobal(
            $this->req('PATCH', '/api/settings/global', ['settings' => ['timezone' => 'Europe/Berlin']], self::USER_WRITE_NO_MANAGE)
        );
        self::assertSame(403, $patch->getStatusCode());
        self::assertSame(
            'settings:manage',
            $this->decode($patch)['details']['required'] ?? null,
            'The 403 must name settings:manage as the missing permission.'
        );
        self::assertSame(
            0,
            (int) $this->pdo->query('SELECT COUNT(*) FROM app_settings')->fetchColumn(),
            'A write-only caller must not be able to write a global default.'
        );
    }

    public function testPatchGlobalUpsertsGlobalDefaultForManageCaller(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->patchGlobal(
            $this->req('PATCH', '/api/settings/global', ['settings' => ['timezone' => 'Europe/Berlin']], self::USER_FULL, self::SYSTEM_TENANT)
        );

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('Europe/Berlin', $this->decode($response)['data']['timezone']);
        self::assertSame(
            'Europe/Berlin',
            $this->pdo->query("SELECT value FROM app_settings WHERE setting_key = 'timezone'")->fetchColumn()
        );
    }

    public function testPatchGlobalRejectsManageCallerWithBadValue(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->patchGlobal(
            $this->req('PATCH', '/api/settings/global', ['settings' => ['locale' => 'english']], self::USER_FULL, self::SYSTEM_TENANT)
        );

        self::assertSame(422, $response->getStatusCode());
        self::assertArrayHasKey('locale', $this->decode($response)['details']);
    }

    // ==================== helpers ====================

    private function makeHandler(): SettingsApiHandler
    {
        $service = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );

        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();

        $roleChecker = new RoleChecker($this->databaseFor($this->pdo), $registry);

        return new SettingsApiHandler($service, $roleChecker);
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    private function seedGlobal(string $key, string $value): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO app_settings (setting_key, value, updated_at) VALUES (:k, :v, datetime('now'))"
        );
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body = null, int $userId = self::USER_FULL, int $tenantId = self::TENANT_A): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
        $request->user = (object) ['user_id' => $userId, 'tenant_id' => $tenantId];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * In-memory SQLite with the full production schema (migrations) seeded with
     * three users in Tenant A holding different settings-permission subsets so
     * each endpoint's RBAC can be proven both ways.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a')");

        // admin role (1) is seeded by migrations and granted all three settings
        // perms by migration 026. Two tenant-private roles carry partial grants.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("
            INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES
                (100, 'settings-reader', '', 1, datetime('now')),
                (101, 'no-settings',     '', 1, datetime('now')),
                (102, 'settings-writer', '', 1, datetime('now'))
        ");

        // Resolve the seeded permission ids, then grant precise subsets:
        //  - role 100 (reader): ONLY settings:read.
        //  - role 102 (writer): settings:read + settings:write, but NOT
        //    settings:manage (a per-tenant editor with no global-defaults reach).
        // (admin role 1 already holds all three via migration 026.)
        $readPermId = (int) $pdo->query("SELECT id FROM permissions WHERE name = 'settings:read'")->fetchColumn();
        $writePermId = (int) $pdo->query("SELECT id FROM permissions WHERE name = 'settings:write'")->fetchColumn();
        $pdo->exec("
            INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES
                (100, {$readPermId},  datetime('now')),
                (102, {$readPermId},  datetime('now')),
                (102, {$writePermId}, datetime('now'))
        ");

        // user 10 = admin (all three settings perms), 11 = reader, 12 = none,
        // 13 = write-no-manage (settings:read + settings:write only).
        $pdo->exec("
            INSERT INTO users (id, tenant_id, email, password, role_id, created_at) VALUES
                (10, 1, 'admin@t1.example',  'x', 1,   datetime('now')),
                (11, 1, 'reader@t1.example', 'x', 100, datetime('now')),
                (12, 1, 'none@t1.example',   'x', 101, datetime('now')),
                (13, 1, 'writer@t1.example', 'x', 102, datetime('now'))
        ");

        return $pdo;
    }
}
