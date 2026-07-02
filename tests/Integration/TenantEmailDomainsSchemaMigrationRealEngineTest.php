<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Tenant\SanctionedGlobalTables;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * WC-9b87: real-engine tests for the tenant_email_domains schema migration (031).
 *
 * `tenant_email_domains` is the tenant-owned table that registers which email
 * domains a tenant claims ownership of (ADR 0005). When a profile verifies an
 * email on a claimed domain the policy service auto-creates or auto-approves the
 * corresponding membership.
 *
 * Being tenant-scoped it MUST be in TenantOwnedTables (so the predicate guard can
 * police it) and must NOT be in SanctionedGlobalTables.
 */
final class TenantEmailDomainsSchemaMigrationRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    // ── table existence & classification ────────────────────────────────────

    public function testTenantEmailDomainsTableExistsAfterMigration(): void
    {
        self::assertTrue(
            $this->tableExists('tenant_email_domains'),
            'tenant_email_domains table must be created by migration 031.'
        );
    }

    public function testTenantEmailDomainsIsClassifiedAsTenantOwned(): void
    {
        self::assertTrue(
            TenantOwnedTables::isTenantOwned('tenant_email_domains'),
            'tenant_email_domains has a tenant_id column and must be in TenantOwnedTables.'
        );
    }

    public function testTenantEmailDomainsIsNotClassifiedAsSanctionedGlobal(): void
    {
        self::assertFalse(
            SanctionedGlobalTables::isGlobal('tenant_email_domains'),
            'tenant_email_domains is tenant-scoped and must NOT be in SanctionedGlobalTables.'
        );
    }

    // ── column contract ──────────────────────────────────────────────────────

    public function testTenantEmailDomainsHasTenantIdColumn(): void
    {
        self::assertContains(
            'tenant_id',
            $this->columns('tenant_email_domains'),
            'tenant_email_domains must carry a tenant_id column — it is a tenant-owned table.'
        );
    }

    public function testTenantEmailDomainsHasAllRequiredColumns(): void
    {
        $columns = $this->columns('tenant_email_domains');

        foreach (['id', 'tenant_id', 'domain', 'default_role_id', 'auto_provision', 'created_at'] as $column) {
            self::assertContains($column, $columns, "tenant_email_domains must have the '{$column}' column.");
        }
    }

    // ── structural invariants ─────────────────────────────────────────────────

    public function testTenantEmailDomainsMigrationIsIdempotent(): void
    {
        $sql = $this->migrationSql('031_create_tenant_email_domains.php');
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS\s+tenant_email_domains\b/i',
            $sql,
            'tenant_email_domains migration must use CREATE TABLE IF NOT EXISTS for idempotency.'
        );
    }

    public function testTenantEmailDomainsAutoProvisionDefaultsToTrue(): void
    {
        $sql = $this->migrationSql('031_create_tenant_email_domains.php');
        self::assertMatchesRegularExpression(
            '/auto_provision\s+BOOLEAN\s+NOT\s+NULL\s+DEFAULT\s+TRUE/i',
            $sql,
            'tenant_email_domains.auto_provision must be BOOLEAN NOT NULL DEFAULT TRUE.'
        );
    }

    public function testTenantEmailDomainsHasUniqueTenantDomainConstraint(): void
    {
        $sql = $this->migrationSql('031_create_tenant_email_domains.php');
        self::assertMatchesRegularExpression(
            '/UNIQUE\s*\(\s*tenant_id\s*,\s*domain\s*\)/i',
            $sql,
            'tenant_email_domains must have UNIQUE(tenant_id, domain) — one registration per domain per tenant.'
        );
    }

    public function testTenantEmailDomainsTenantIdReferencesCascade(): void
    {
        $sql = $this->migrationSql('031_create_tenant_email_domains.php');
        self::assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'tenant_email_domains.tenant_id must reference tenants(id) ON DELETE CASCADE.'
        );
    }

    public function testTenantEmailDomainsDefaultRoleIdReferencesCascade(): void
    {
        $sql = $this->migrationSql('031_create_tenant_email_domains.php');
        self::assertMatchesRegularExpression(
            '/default_role_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+roles\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'tenant_email_domains.default_role_id must reference roles(id) ON DELETE CASCADE.'
        );
    }

    public function testTenantEmailDomainsHasTenantIdIndex(): void
    {
        $sql = $this->migrationSql('031_create_tenant_email_domains.php');
        self::assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+idx_tenant_email_domains_tenant_id\s+ON\s+tenant_email_domains\s*\(\s*tenant_id\s*\)/i',
            $sql,
            'tenant_email_domains must have idx_tenant_email_domains_tenant_id index.'
        );
    }

    // ── constraint smoke ──────────────────────────────────────────────────────

    public function testUniqueConstraintIsEnforced(): void
    {
        $this->seedFixtures();
        $this->pdo->exec(
            "INSERT INTO tenant_email_domains (tenant_id, domain, default_role_id, auto_provision, created_at)
             VALUES (1, 'acme.com', 1, true, datetime('now'))"
        );

        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            "INSERT INTO tenant_email_domains (tenant_id, domain, default_role_id, auto_provision, created_at)
             VALUES (1, 'acme.com', 1, true, datetime('now'))"
        );
    }

    public function testCanInsertAndReadADomain(): void
    {
        $this->seedFixtures();
        $this->pdo->exec(
            "INSERT INTO tenant_email_domains (tenant_id, domain, default_role_id, auto_provision, created_at)
             VALUES (1, 'acme.com', 1, true, datetime('now'))"
        );

        $stmt = $this->pdo->query(
            "SELECT * FROM tenant_email_domains WHERE tenant_id = 1 AND domain = 'acme.com'"
        );
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('acme.com', $row['domain']);
        self::assertSame(1, (int) $row['default_role_id']);
    }

    public function testSameDomainCanBeRegisteredByDifferentTenants(): void
    {
        $this->seedFixtures();
        $this->pdo->exec(
            "INSERT INTO tenant_email_domains (tenant_id, domain, default_role_id, auto_provision, created_at)
             VALUES (1, 'shared.com', 1, true, datetime('now'))"
        );

        // Same domain, different tenant — must not violate UNIQUE(tenant_id, domain).
        $this->pdo->exec(
            "INSERT INTO tenant_email_domains (tenant_id, domain, default_role_id, auto_provision, created_at)
             VALUES (2, 'shared.com', 1, true, datetime('now'))"
        );

        $stmt = $this->pdo->query("SELECT COUNT(*) FROM tenant_email_domains WHERE domain = 'shared.com'");
        self::assertNotFalse($stmt);
        self::assertSame(2, (int) $stmt->fetchColumn());
    }

    // ── reversibility ─────────────────────────────────────────────────────────

    public function testDownDropsTenantEmailDomainsTable(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/031_create_tenant_email_domains.php';
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();

        \Database\Migrations\CreateTenantEmailDomains::down($db);

        self::assertFalse(
            $this->tableExists('tenant_email_domains'),
            'tenant_email_domains table must be dropped by down().'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function seedFixtures(): void
    {
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");
    }

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

    private function migrationSql(string $file): string
    {
        $path = dirname(__DIR__, 2) . '/database/migrations/' . $file;
        self::assertFileExists($path, "Migration file {$file} must exist.");
        $content = file_get_contents($path);
        self::assertIsString($content);
        return $content;
    }
}
