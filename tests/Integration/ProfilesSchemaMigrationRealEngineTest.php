<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Tenant\SanctionedGlobalTables;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * WC-96: real-engine tests for the profiles schema migration (028).
 *
 * `profiles` is the global identity anchor introduced by ADR 0005: it holds
 * credentials and 2FA state for a person, decoupled from any single tenant.
 * Being tenant-agnostic it carries no `tenant_id` column and is therefore a
 * sanctioned global table, not a tenant-owned one.
 *
 * Tests run against in-memory SQLite (via SchemaFromMigrations::make()) so the
 * full migration stack, including 028, is exercised every time. The same SQL
 * runs against PostgreSQL on the postgres-integration CI job.
 */
final class ProfilesSchemaMigrationRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    // ── table existence & global classification ──────────────────────────────

    public function testProfilesTableExistsAfterMigration(): void
    {
        self::assertTrue(
            $this->tableExists('profiles'),
            'profiles table must be created by migration 028.'
        );
    }

    public function testProfilesIsClassifiedAsSanctionedGlobal(): void
    {
        self::assertTrue(
            SanctionedGlobalTables::isGlobal('profiles'),
            'profiles has no tenant_id column and must be a sanctioned global table.'
        );
    }

    public function testProfilesIsNotClassifiedAsTenantOwned(): void
    {
        self::assertFalse(
            TenantOwnedTables::isTenantOwned('profiles'),
            'profiles carries no tenant_id column and must NOT be in TenantOwnedTables.'
        );
    }

    // ── column contract ──────────────────────────────────────────────────────

    public function testProfilesHasNoTenantIdColumn(): void
    {
        self::assertNotContains(
            'tenant_id',
            $this->columns('profiles'),
            'profiles must NOT have a tenant_id column — it is a global identity anchor.'
        );
    }

    public function testProfilesHasAllRequiredColumns(): void
    {
        $columns = $this->columns('profiles');

        foreach ([
            'id',
            'display_name',
            'password_hash',
            'two_factor_enabled',
            'two_factor_secret',
            'two_factor_backup_codes_version',
            'token_epoch',
            'created_at',
            'updated_at',
        ] as $column) {
            self::assertContains($column, $columns, "profiles must have the '{$column}' column.");
        }
    }

    // ── structural invariants ─────────────────────────────────────────────────

    public function testProfilesIdIsAutoIncrement(): void
    {
        $sql = $this->migrationSql('028_create_profiles.php');
        self::assertMatchesRegularExpression(
            '/id\s+(SERIAL|INTEGER)\b/i',
            $sql,
            'profiles.id must be a SERIAL/INTEGER primary key.'
        );
        self::assertMatchesRegularExpression(
            '/PRIMARY KEY/i',
            $sql,
            'profiles.id must be a PRIMARY KEY.'
        );
    }

    public function testProfilesDisplayNameHasDefault(): void
    {
        $sql = $this->migrationSql('028_create_profiles.php');
        self::assertMatchesRegularExpression(
            "/display_name\s+VARCHAR\(\d+\)\s+NOT\s+NULL\s+DEFAULT\s+''/i",
            $sql,
            "profiles.display_name must be NOT NULL with a DEFAULT '' (empty string)."
        );
    }

    public function testProfilesPasswordHashIsNotNull(): void
    {
        $sql = $this->migrationSql('028_create_profiles.php');
        self::assertMatchesRegularExpression(
            '/password_hash\s+VARCHAR\(\d+\)\s+NOT\s+NULL/i',
            $sql,
            'profiles.password_hash must be NOT NULL.'
        );
    }

    public function testProfilesTwoFactorEnabledDefaultsFalse(): void
    {
        $sql = $this->migrationSql('028_create_profiles.php');
        self::assertMatchesRegularExpression(
            '/two_factor_enabled\s+BOOLEAN\s+NOT\s+NULL\s+DEFAULT\s+FALSE/i',
            $sql,
            'profiles.two_factor_enabled must be BOOLEAN NOT NULL DEFAULT FALSE.'
        );
    }

    public function testProfilesTokenEpochDefaultsZero(): void
    {
        $sql = $this->migrationSql('028_create_profiles.php');
        self::assertMatchesRegularExpression(
            '/token_epoch\s+INTEGER\s+NOT\s+NULL\s+DEFAULT\s+0/i',
            $sql,
            'profiles.token_epoch must be INTEGER NOT NULL DEFAULT 0.'
        );
    }

    public function testProfilesMigrationIsIdempotent(): void
    {
        $sql = $this->migrationSql('028_create_profiles.php');
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS\s+profiles\b/i',
            $sql,
            'profiles migration must use CREATE TABLE IF NOT EXISTS for idempotency.'
        );
    }

    // ── insert / constraint smoke ─────────────────────────────────────────────

    public function testCanInsertAndReadAProfile(): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Alice', '\$2y\$10\$fakehash', false, 0, 0, datetime('now'), datetime('now'))"
        );

        $stmt = $this->pdo->query('SELECT * FROM profiles WHERE id = 1');
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('Alice', $row['display_name']);
        self::assertNull($row['two_factor_secret']);
    }

    // ── reversibility ─────────────────────────────────────────────────────────

    public function testDownDropsProfilesTable(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/028_create_profiles.php';
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();

        \Database\Migrations\CreateProfiles::down($db);

        self::assertFalse(
            $this->tableExists('profiles'),
            'profiles table must be dropped by down().'
        );
    }

    // ── helpers ───────────────────────────────────────────────────────────────

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
