<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Tenant\SanctionedGlobalTables;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * WC-101: real-engine tests for the memberships schema migration (030).
 *
 * `memberships` is the tenant-scoped table that links a global profile to a
 * tenant (ADR 0005). It replaces the implicit `users.tenant_id` relationship
 * with an explicit, lifecycle-managed row:
 *   active   — full access
 *   invited  — account pending; cannot log in until accepted
 *   suspended — access revoked without deletion; re-activatable
 *
 * Being tenant-scoped it MUST be in TenantOwnedTables (so the predicate guard
 * can police it) and must NOT be in SanctionedGlobalTables.
 */
final class MembershipsSchemaMigrationRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    // ── table existence & classification ────────────────────────────────────

    public function testMembershipsTableExistsAfterMigration(): void
    {
        self::assertTrue(
            $this->tableExists('memberships'),
            'memberships table must be created by migration 030.'
        );
    }

    public function testMembershipsIsClassifiedAsTenantOwned(): void
    {
        self::assertTrue(
            TenantOwnedTables::isTenantOwned('memberships'),
            'memberships has a tenant_id column and must be in TenantOwnedTables.'
        );
    }

    public function testMembershipsIsNotClassifiedAsSanctionedGlobal(): void
    {
        self::assertFalse(
            SanctionedGlobalTables::isGlobal('memberships'),
            'memberships is tenant-scoped and must NOT be in SanctionedGlobalTables.'
        );
    }

    // ── column contract ──────────────────────────────────────────────────────

    public function testMembershipsHasTenantIdColumn(): void
    {
        self::assertContains(
            'tenant_id',
            $this->columns('memberships'),
            'memberships must carry a tenant_id column — it is a tenant-owned table.'
        );
    }

    public function testMembershipsHasAllRequiredColumns(): void
    {
        $columns = $this->columns('memberships');

        foreach (['id', 'profile_id', 'tenant_id', 'role_id', 'ou_id', 'status', 'created_at'] as $column) {
            self::assertContains($column, $columns, "memberships must have the '{$column}' column.");
        }
    }

    // ── structural invariants ─────────────────────────────────────────────────

    public function testMembershipsMigrationIsIdempotent(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS\s+memberships\b/i',
            $sql,
            'memberships migration must use CREATE TABLE IF NOT EXISTS for idempotency.'
        );
    }

    public function testMembershipsStatusDefaultsToActive(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            "/status\s+VARCHAR\(\d+\)\s+NOT\s+NULL\s+DEFAULT\s+'active'/i",
            $sql,
            "memberships.status must be VARCHAR NOT NULL DEFAULT 'active'."
        );
    }

    public function testMembershipsHasUniqueProfileTenantConstraint(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/UNIQUE\s*\(\s*profile_id\s*,\s*tenant_id\s*\)/i',
            $sql,
            'memberships must have UNIQUE(profile_id, tenant_id) — one membership per profile per tenant.'
        );
    }

    public function testMembershipsProfileIdReferencesCascade(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/profile_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+profiles\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'memberships.profile_id must reference profiles(id) ON DELETE CASCADE.'
        );
    }

    public function testMembershipsTenantIdReferencesCascade(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/tenant_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+tenants\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'memberships.tenant_id must reference tenants(id) ON DELETE CASCADE.'
        );
    }

    public function testMembershipsRoleIdReferencesCascade(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/role_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+roles\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'memberships.role_id must reference roles(id) ON DELETE CASCADE.'
        );
    }

    public function testMembershipsOuIdIsNullableSetNull(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/ou_id\s+INTEGER\s+REFERENCES\s+organizational_units\s*\(\s*id\s*\)\s+ON DELETE SET NULL/i',
            $sql,
            'memberships.ou_id must be a nullable FK to organizational_units(id) ON DELETE SET NULL.'
        );
    }

    public function testMembershipsHasProfileIdIndex(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+idx_memberships_profile_id\s+ON\s+memberships\s*\(\s*profile_id\s*\)/i',
            $sql,
            'memberships must have idx_memberships_profile_id index.'
        );
    }

    public function testMembershipsHasTenantIdIndex(): void
    {
        $sql = $this->migrationSql('030_create_memberships.php');
        self::assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+idx_memberships_tenant_id\s+ON\s+memberships\s*\(\s*tenant_id\s*\)/i',
            $sql,
            'memberships must have idx_memberships_tenant_id index.'
        );
    }

    // ── constraint smoke ──────────────────────────────────────────────────────

    public function testUniqueProfileTenantConstraintIsEnforced(): void
    {
        $this->seedFixtures();
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (1, 1, 1, 'active', datetime('now'))"
        );

        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (1, 1, 1, 'invited', datetime('now'))"
        );
    }

    public function testCanInsertAndReadAMembership(): void
    {
        $this->seedFixtures();
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (1, 1, 1, NULL, 'active', datetime('now'))"
        );

        $stmt = $this->pdo->query('SELECT * FROM memberships WHERE profile_id = 1 AND tenant_id = 1');
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('active', $row['status']);
        self::assertNull($row['ou_id']);
    }

    // ── reversibility ─────────────────────────────────────────────────────────

    public function testDownDropsMembershipsTable(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/030_create_memberships.php';
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();

        \Database\Migrations\CreateMemberships::down($db);

        self::assertFalse(
            $this->tableExists('memberships'),
            'memberships table must be dropped by down().'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function seedFixtures(): void
    {
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Alice', '\$2y\$10\$hash', false, 0, 0, datetime('now'), datetime('now'))"
        );
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
