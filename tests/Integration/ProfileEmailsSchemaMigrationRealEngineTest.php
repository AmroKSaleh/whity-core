<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Tenant\SanctionedGlobalTables;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * WC-99: real-engine tests for the profile_emails schema migration (029).
 *
 * `profile_emails` stores verified, globally-unique email addresses linked to
 * a profile. The UNIQUE(email) constraint is the structural fix for issue #181:
 * the same email address cannot belong to two profiles, so login-by-email is
 * always unambiguous regardless of the number of tenants.
 *
 * Like `profiles`, this table carries no `tenant_id` — it is a sanctioned
 * global table that joins only to the global `profiles` table.
 */
final class ProfileEmailsSchemaMigrationRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    // ── table existence & classification ────────────────────────────────────

    public function testProfileEmailsTableExistsAfterMigration(): void
    {
        self::assertTrue(
            $this->tableExists('profile_emails'),
            'profile_emails table must be created by migration 029.'
        );
    }

    public function testProfileEmailsIsClassifiedAsSanctionedGlobal(): void
    {
        self::assertTrue(
            SanctionedGlobalTables::isGlobal('profile_emails'),
            'profile_emails has no tenant_id and must be a sanctioned global table.'
        );
    }

    public function testProfileEmailsIsNotClassifiedAsTenantOwned(): void
    {
        self::assertFalse(
            TenantOwnedTables::isTenantOwned('profile_emails'),
            'profile_emails must NOT be in TenantOwnedTables — it carries no tenant_id.'
        );
    }

    // ── column contract ──────────────────────────────────────────────────────

    public function testProfileEmailsHasNoTenantIdColumn(): void
    {
        self::assertNotContains(
            'tenant_id',
            $this->columns('profile_emails'),
            'profile_emails must NOT have a tenant_id column — it is a global table.'
        );
    }

    public function testProfileEmailsHasAllRequiredColumns(): void
    {
        $columns = $this->columns('profile_emails');

        foreach (['id', 'profile_id', 'email', 'verified', 'is_primary', 'created_at'] as $column) {
            self::assertContains($column, $columns, "profile_emails must have the '{$column}' column.");
        }
    }

    // ── structural invariants ─────────────────────────────────────────────────

    public function testProfileEmailsIdIsAutoIncrement(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/id\s+(SERIAL|INTEGER)\b/i',
            $sql,
            'profile_emails.id must be a SERIAL/INTEGER primary key.'
        );
    }

    public function testProfileEmailsEmailIsNotNull(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/email\s+VARCHAR\(\d+\)\s+NOT\s+NULL/i',
            $sql,
            'profile_emails.email must be VARCHAR NOT NULL.'
        );
    }

    public function testProfileEmailsEmailHasUniqueConstraint(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/UNIQUE\s*\(\s*email\s*\)/i',
            $sql,
            'profile_emails must have a UNIQUE(email) constraint — structural fix for #181.'
        );
    }

    public function testProfileEmailsVerifiedDefaultsFalse(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/verified\s+BOOLEAN\s+NOT\s+NULL\s+DEFAULT\s+FALSE/i',
            $sql,
            'profile_emails.verified must be BOOLEAN NOT NULL DEFAULT FALSE.'
        );
    }

    public function testProfileEmailsIsPrimaryDefaultsFalse(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/is_primary\s+BOOLEAN\s+NOT\s+NULL\s+DEFAULT\s+FALSE/i',
            $sql,
            'profile_emails.is_primary must be BOOLEAN NOT NULL DEFAULT FALSE.'
        );
    }

    public function testProfileEmailsProfileIdReferencesCascade(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/profile_id\s+INTEGER\s+NOT\s+NULL\s+REFERENCES\s+profiles\s*\(\s*id\s*\)\s+ON DELETE CASCADE/i',
            $sql,
            'profile_emails.profile_id must reference profiles(id) ON DELETE CASCADE.'
        );
    }

    public function testProfileEmailsHasProfileIdIndex(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+idx_profile_emails_profile_id\s+ON\s+profile_emails\s*\(\s*profile_id\s*\)/i',
            $sql,
            'profile_emails must have idx_profile_emails_profile_id index.'
        );
    }

    public function testProfileEmailsHasEmailIndex(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/CREATE INDEX IF NOT EXISTS\s+idx_profile_emails_email\s+ON\s+profile_emails\s*\(\s*email\s*\)/i',
            $sql,
            'profile_emails must have idx_profile_emails_email index.'
        );
    }

    public function testProfileEmailsMigrationIsIdempotent(): void
    {
        $sql = $this->migrationSql('029_create_profile_emails.php');
        self::assertMatchesRegularExpression(
            '/CREATE TABLE IF NOT EXISTS\s+profile_emails\b/i',
            $sql,
            'profile_emails migration must use CREATE TABLE IF NOT EXISTS for idempotency.'
        );
    }

    // ── constraint smoke ──────────────────────────────────────────────────────

    public function testUniqueEmailConstraintIsEnforced(): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Alice', '\$2y\$10\$hash1', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Bob', '\$2y\$10\$hash2', false, 0, 0, datetime('now'), datetime('now'))"
        );

        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (1, 'alice@corp.com', true, true, datetime('now'))"
        );

        $this->expectException(\PDOException::class);
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (2, 'alice@corp.com', false, false, datetime('now'))"
        );
    }

    public function testMultipleEmailsPerProfileAreAllowed(): void
    {
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Alice', '\$2y\$10\$hash1', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (1, 'alice@work.com', true, true, datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (1, 'alice@home.com', false, false, datetime('now'))"
        );

        $stmt = $this->pdo->query('SELECT COUNT(*) FROM profile_emails WHERE profile_id = 1');
        self::assertNotFalse($stmt);
        self::assertSame('2', (string) $stmt->fetchColumn());
    }

    // ── reversibility ─────────────────────────────────────────────────────────

    public function testDownDropsProfileEmailsTable(): void
    {
        require_once dirname(__DIR__, 2) . '/database/migrations/029_create_profile_emails.php';
        $db = \Whity\Database\Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $db->forceConnect();

        \Database\Migrations\CreateProfileEmails::down($db);

        self::assertFalse(
            $this->tableExists('profile_emails'),
            'profile_emails table must be dropped by down().'
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
