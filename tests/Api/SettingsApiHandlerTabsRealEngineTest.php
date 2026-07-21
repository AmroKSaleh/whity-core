<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SettingsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Settings\TenantSettingsRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine tests for {@see SettingsApiHandler::tabs()} (WC-tabs-nav-be):
 * the settings console's tab bar is now RBAC-filtered SERVER-SIDE per tab
 * (mirrors {@see \Whity\Api\NavigationApiHandler}) rather than each settings
 * page independently re-deriving `show*` visibility booleans client-side.
 */
final class SettingsApiHandlerTabsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SettingsApiHandler $handler;
    private int $tenantId;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = SchemaFromMigrations::make();

        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        self::assertNotFalse($stmt);
        $stmt->execute([':n' => 'Acme', ':s' => 'acme']);
        $this->tenantId = (int) $this->pdo->lastInsertId();

        $settings = new SettingsService(
            new GlobalSettingsRepository($this->pdo),
            new TenantSettingsRepository($this->pdo)
        );
        $roleChecker = new RoleChecker(self::wrapSqlite($this->pdo), new PermissionRegistry());
        $this->handler = new SettingsApiHandler($settings, $roleChecker);
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    /**
     * Seed a profile with an active membership holding exactly the given
     * permissions (via a fresh throwaway role), in the given tenant.
     *
     * @param list<string> $permissions
     */
    private function seedProfileWithPermissions(int $tenantId, array $permissions): int
    {
        static $roleSuffix = 0;
        $roleSuffix++;

        $roleStmt = $this->pdo->prepare(
            "INSERT INTO roles (name, created_at) VALUES (?, datetime('now'))"
        );
        $roleStmt->execute(["tabs-test-role-{$roleSuffix}"]);
        $roleId = (int) $this->pdo->lastInsertId();

        foreach ($permissions as $permission) {
            $this->pdo->prepare(
                "INSERT OR IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, datetime('now'))"
            )->execute([$permission, null]);
            $permissionStmt = $this->pdo->query("SELECT id FROM permissions WHERE name = '{$permission}'");
            self::assertNotFalse($permissionStmt);
            $permissionId = (int) $permissionStmt->fetchColumn();
            $this->pdo->prepare(
                'INSERT INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, datetime(\'now\'))'
            )->execute([$roleId, $permissionId]);
        }

        $profileStmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('tabs-test', 'x', 0, 0, 0, datetime('now'), datetime('now'))"
        );
        $profileStmt->execute();
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, NULL, \'active\', datetime(\'now\'))'
        )->execute([$profileId, $tenantId, $roleId]);

        return $profileId;
    }

    private function requestAs(int $profileId, int $tenantId): Request
    {
        TenantContext::setTenantId($tenantId);
        $request = new Request('GET', '/api/settings/tabs', [], '');
        $request->user = (object) ['profile_id' => $profileId];

        return $request;
    }

    /**
     * @return list<string>
     */
    private function tabIds(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return array_column($decoded['data'], 'id');
    }

    public function testNoPermissionsSeesNothing(): void
    {
        $profileId = $this->seedProfileWithPermissions($this->tenantId, []);

        $response = $this->handler->tabs($this->requestAs($profileId, $this->tenantId));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame([], $this->tabIds($response));
    }

    public function testSettingsReadSeesGeneralAndBrandingOnlyInATenant(): void
    {
        $profileId = $this->seedProfileWithPermissions($this->tenantId, [CorePermissions::SETTINGS_READ]);

        $tabs = $this->tabIds($this->handler->tabs($this->requestAs($profileId, $this->tenantId)));

        $this->assertSame(['general', 'branding'], $tabs);
    }

    public function testAuthProvidersManageAloneShowsOnlySsoEvenWithoutSettingsRead(): void
    {
        $profileId = $this->seedProfileWithPermissions($this->tenantId, [CorePermissions::AUTH_PROVIDERS_MANAGE]);

        $tabs = $this->tabIds($this->handler->tabs($this->requestAs($profileId, $this->tenantId)));

        $this->assertSame(['sso'], $tabs, 'A caller with ONLY auth_providers:manage must still see the SSO tab.');
    }

    public function testSettingsManageInARegularTenantDoesNotUnlockSystemTenantOnlyTabs(): void
    {
        $profileId = $this->seedProfileWithPermissions(
            $this->tenantId,
            [CorePermissions::SETTINGS_READ, CorePermissions::SETTINGS_MANAGE]
        );

        $tabs = $this->tabIds($this->handler->tabs($this->requestAs($profileId, $this->tenantId)));

        $this->assertSame(
            ['general', 'branding'],
            $tabs,
            'settings:manage in a non-system tenant must not reveal Sign-up/Email/Storage.'
        );
    }

    public function testSettingsManageInTheSystemTenantUnlocksSystemTenantOnlyTabs(): void
    {
        $profileId = $this->seedProfileWithPermissions(
            0,
            [CorePermissions::SETTINGS_READ, CorePermissions::SETTINGS_MANAGE]
        );

        $tabs = $this->tabIds($this->handler->tabs($this->requestAs($profileId, 0)));

        $this->assertSame(['general', 'branding', 'signup', 'email', 'storage'], $tabs);
    }

    public function testSecurityManageShowsOnlySecurityTab(): void
    {
        $profileId = $this->seedProfileWithPermissions($this->tenantId, [CorePermissions::SECURITY_MANAGE]);

        $tabs = $this->tabIds($this->handler->tabs($this->requestAs($profileId, $this->tenantId)));

        $this->assertSame(['security'], $tabs);
    }

    public function testUnresolvedTenantContextRefusesWith403(): void
    {
        TenantContext::reset();
        $request = new Request('GET', '/api/settings/tabs', [], '');
        $request->user = (object) ['profile_id' => 1];

        $response = $this->handler->tabs($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUnauthenticatedCallerRefusesWith403(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $request = new Request('GET', '/api/settings/tabs', [], '');

        $response = $this->handler->tabs($request);

        $this->assertSame(403, $response->getStatusCode());
    }
}
