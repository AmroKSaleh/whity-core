<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\CreateAppSettings;
use Database\Migrations\CreateTenantSettings;
use Database\Migrations\GrantSettingsPermissionsToAdmin;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RBAC\CorePermissions;
use Whity\Database\Database;

/**
 * Real-engine tests for the Website Settings migrations and permission seed.
 *
 * Runs the full migration set (forward) on in-memory SQLite, asserts the two
 * settings tables exist and the admin role holds the three new permissions, then
 * exercises each new migration's down() and asserts a clean teardown — proving
 * the migrations are non-destructive and reversible. The same migration SQL runs
 * against PostgreSQL on the postgres-integration CI job.
 */
final class SettingsMigrationsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->db = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $this->db->forceConnect();
    }

    public function testForwardMigrationsCreateBothSettingsTables(): void
    {
        self::assertTrue($this->tableExists('app_settings'), 'app_settings must exist after migrate.');
        self::assertTrue($this->tableExists('tenant_settings'), 'tenant_settings must exist after migrate.');
    }

    public function testAppSettingsHasNoTenantIdColumn(): void
    {
        $columns = $this->columns('app_settings');
        self::assertContains('setting_key', $columns);
        self::assertContains('value', $columns);
        self::assertNotContains('tenant_id', $columns, 'app_settings is a sanctioned global table — no tenant_id.');
    }

    public function testTenantSettingsCarriesTenantId(): void
    {
        self::assertContains('tenant_id', $this->columns('tenant_settings'));
    }

    public function testAdminHoldsTheThreeSettingsPermissionsAfterSeed(): void
    {
        foreach ([CorePermissions::SETTINGS_READ, CorePermissions::SETTINGS_WRITE, CorePermissions::SETTINGS_MANAGE] as $perm) {
            self::assertTrue($this->adminHasPermission($perm), "admin must hold {$perm} after the seeding migration.");
        }
    }

    public function testAppSettingsUniqueKeyIsEnforced(): void
    {
        $this->pdo->exec("INSERT INTO app_settings (setting_key, value, updated_at) VALUES ('site_name', 'A', datetime('now'))");
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO app_settings (setting_key, value, updated_at) VALUES ('site_name', 'B', datetime('now'))");
    }

    public function testTenantSettingsUniquePerTenantKeyIsEnforced(): void
    {
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (1, 'locale', 'en', datetime('now'))");
        // Same (tenant_id, key) → conflict.
        $this->expectException(\PDOException::class);
        $this->pdo->exec("INSERT INTO tenant_settings (tenant_id, setting_key, value, updated_at) VALUES (1, 'locale', 'de', datetime('now'))");
    }

    public function testDownReversesTablesAndGrants(): void
    {
        GrantSettingsPermissionsToAdmin::down($this->db);
        CreateTenantSettings::down($this->db);
        CreateAppSettings::down($this->db);

        self::assertFalse($this->tableExists('app_settings'), 'app_settings must be dropped by down().');
        self::assertFalse($this->tableExists('tenant_settings'), 'tenant_settings must be dropped by down().');

        foreach ([CorePermissions::SETTINGS_READ, CorePermissions::SETTINGS_WRITE, CorePermissions::SETTINGS_MANAGE] as $perm) {
            self::assertFalse($this->adminHasPermission($perm), "{$perm} grant must be removed by down().");
            self::assertSame(
                0,
                (int) $this->pdo->query("SELECT COUNT(*) FROM permissions WHERE name = '{$perm}'")->fetchColumn(),
                "the {$perm} catalogue row must be removed by down() (no other grant references it)."
            );
        }
    }

    // ---- helpers ----

    private function tableExists(string $table): bool
    {
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='table' AND name = " . $this->pdo->quote($table)
        );

        return $stmt !== false && $stmt->fetchColumn() !== false;
    }

    /**
     * @return list<string>
     */
    private function columns(string $table): array
    {
        $stmt = $this->pdo->query('PRAGMA table_info(' . $table . ')');
        self::assertNotFalse($stmt);
        /** @var array<int, array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map(static fn (array $r): string => (string) $r['name'], $rows);
    }

    private function adminHasPermission(string $permission): bool
    {
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*)
             FROM role_permissions rp
             JOIN roles r ON r.id = rp.role_id
             JOIN permissions p ON p.id = rp.permission_id
             WHERE r.name = 'admin' AND p.name = " . $this->pdo->quote($permission)
        )->fetchColumn();

        return $count > 0;
    }
}
