<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\RekeyMcpTokensToProfiles;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Database\Database;

/**
 * WC-idcut-C: Real-engine migration round-trip test for migration 040
 * (RekeyMcpTokensToProfiles).
 *
 * Verifies:
 *  - up() re-keys mcp_tokens from user_id → profile_id (backfill via migration_035_profile_ids).
 *  - Orphan rows (user_id with no profile mapping) are deleted.
 *  - idx_mcp_tokens_profile_tenant is created; idx_mcp_tokens_user_tenant is gone.
 *  - down() restores the user_id column and idx_mcp_tokens_user_tenant.
 *  - Round-trip (down → up) is idempotent.
 *
 * Exercises both SQLite (default) and PostgreSQL (when PHPUNIT_PG_DSN is set).
 */
final class RekeyMcpTokensMigrationRealEngineTest extends TestCase
{
    private PDO $pdo;
    private Database $db;
    private bool $isPg;

    protected function setUp(): void
    {
        $this->pdo  = SchemaFromMigrations::make(true);
        $this->isPg = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
        $this->db   = Database::withFactory(fn (): PDO => $this->pdo, 86400, 86400);
        $this->db->forceConnect();

        require_once dirname(__DIR__, 2) . '/database/migrations/040_rekey_mcp_tokens_to_profiles.php';

        // Migration 040 has already run (SchemaFromMigrations runs all). Roll it
        // back so we can exercise up() from a legacy state.
        $this->silently(fn () => RekeyMcpTokensToProfiles::down($this->db));

        $this->seedIdentities();
    }

    // ── up(): re-key + orphan removal ────────────────────────────────────────

    public function testUp_rekeysTokensFromUserIdToProfileId(): void
    {
        $this->seedLegacyToken('jti-mapped', 10, 'mapped token');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));

        // After up(): profile_id column must exist and be populated.
        $row = $this->row("jti = 'jti-mapped'");
        self::assertNotNull($row, 'Mapped token must survive up()');
        self::assertSame(100, (int) $row['profile_id'], 'profile_id must be the mapped profile (user 10 → profile 100)');
    }

    public function testUp_deletesOrphanTokens(): void
    {
        $this->seedLegacyToken('jti-orphan', 99, 'orphan token'); // user 99 has no profile mapping
        $this->seedLegacyToken('jti-mapped', 10, 'mapped token');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));

        self::assertNull($this->row("jti = 'jti-orphan'"), 'Orphan token (no profile mapping) must be deleted by up()');
        self::assertNotNull($this->row("jti = 'jti-mapped'"), 'Mapped token must survive');
    }

    public function testUp_createsProfileTenantIndex(): void
    {
        $this->seedLegacyToken('jti-a', 10, 'token a');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));

        self::assertTrue($this->indexExists('idx_mcp_tokens_profile_tenant'));
    }

    public function testUp_dropsUserTenantIndex(): void
    {
        $this->seedLegacyToken('jti-a', 10, 'token a');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));

        self::assertFalse($this->indexExists('idx_mcp_tokens_user_tenant'));
    }

    // ── down(): reverse ───────────────────────────────────────────────────────

    public function testDown_restoresUserIdColumn(): void
    {
        // After setUp() we already ran down(); re-run up() then down() again.
        $this->seedLegacyToken('jti-roundtrip', 10, 'rt token');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));
        $this->silently(fn () => RekeyMcpTokensToProfiles::down($this->db));

        self::assertTrue($this->columnExists('user_id'));
        self::assertFalse($this->columnExists('profile_id'));
    }

    public function testDown_restoresUserTenantIndex(): void
    {
        $this->seedLegacyToken('jti-idx', 10, 'index token');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));
        $this->silently(fn () => RekeyMcpTokensToProfiles::down($this->db));

        self::assertTrue($this->indexExists('idx_mcp_tokens_user_tenant'));
        self::assertFalse($this->indexExists('idx_mcp_tokens_profile_tenant'));
    }

    // ── Idempotency (up → down → up) ─────────────────────────────────────────

    public function testRoundTrip_isIdempotent(): void
    {
        $this->seedLegacyToken('jti-x', 10, 'token x');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));
        $this->silently(fn () => RekeyMcpTokensToProfiles::down($this->db));
        $this->seedLegacyToken('jti-y', 10, 'token y');
        $this->silently(fn () => RekeyMcpTokensToProfiles::up($this->db));

        // After round-trip: profile_id exists, user_id gone, token y re-keyed.
        self::assertTrue($this->columnExists('profile_id'));
        self::assertFalse($this->columnExists('user_id'));
        $row = $this->row("jti = 'jti-y'");
        self::assertNotNull($row);
        self::assertSame(100, (int) $row['profile_id']);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function seedIdentities(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'T1') ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, tenant_id, name) VALUES (1, 1, 'admin') ON CONFLICT DO NOTHING");

        // Seed users 10, 11, and 99 (referenced by legacy mcp_tokens.user_id).
        // User 99 has no entry in migration_035_profile_ids → its tokens are orphans.
        $hash = password_hash('pw', PASSWORD_BCRYPT);
        $this->pdo->prepare("INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (10, 1, 'u10@t.com', ?, 1, 0) ON CONFLICT DO NOTHING")->execute([$hash]);
        $this->pdo->prepare("INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (11, 1, 'u11@t.com', ?, 1, 0) ON CONFLICT DO NOTHING")->execute([$hash]);
        $this->pdo->prepare("INSERT INTO users (id, tenant_id, email, password, role_id, token_epoch)
            VALUES (99, 1, 'u99@t.com', ?, 1, 0) ON CONFLICT DO NOTHING")->execute([$hash]);

        // Seed profiles 100 and 101 (the targets after re-key).
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (100, 'Profile 100', ?, false, 0, 0, NOW(), NOW()) ON CONFLICT DO NOTHING")->execute([$hash]);
        $this->pdo->prepare("INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
            VALUES (101, 'Profile 101', ?, false, 0, 0, NOW(), NOW()) ON CONFLICT DO NOTHING")->execute([$hash]);

        // Seed the migration_035 mapping: user 10 → profile 100; user 11 → profile 101.
        // migration_035 is a real table created by migration 035.
        $this->pdo->exec("INSERT INTO migration_035_profile_ids (user_id, profile_id)
            VALUES (10, 100) ON CONFLICT DO NOTHING");
        $this->pdo->exec("INSERT INTO migration_035_profile_ids (user_id, profile_id)
            VALUES (11, 101) ON CONFLICT DO NOTHING");
    }

    /**
     * Insert a legacy mcp_tokens row with user_id (pre-040 schema).
     * After down() the schema has user_id column; this seeds a row that up() will re-key.
     */
    private function seedLegacyToken(string $jti, int $userId, string $name): void
    {
        $expiresAt = date('Y-m-d H:i:s', strtotime('+90 days'));
        $this->pdo->prepare("INSERT INTO mcp_tokens (jti, user_id, tenant_id, name, principal_kind, scope, expires_at)
            VALUES (?, ?, 1, ?, 'user', '[]', ?) ON CONFLICT DO NOTHING")
            ->execute([$jti, $userId, $name, $expiresAt]);
    }

    /** @return array<string, mixed>|null */
    private function row(string $where): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM mcp_tokens WHERE {$where} LIMIT 1");
        if ($stmt === false) {
            return null;
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $row : null;
    }

    private function columnExists(string $column): bool
    {
        if ($this->isPg) {
            $stmt = $this->pdo->prepare(
                'SELECT 1 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = ? AND column_name = ?'
            );
            $stmt->execute(['mcp_tokens', $column]);
            return $stmt->fetchColumn() !== false;
        }
        $stmt = $this->pdo->query('PRAGMA table_info(mcp_tokens)');
        if ($stmt === false) {
            return false;
        }
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if ((string) $row['name'] === $column) {
                return true;
            }
        }
        return false;
    }

    private function indexExists(string $index): bool
    {
        if ($this->isPg) {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM pg_indexes WHERE schemaname = current_schema() AND indexname = ?"
            );
            $stmt->execute([$index]);
            return $stmt->fetchColumn() !== false;
        }
        $stmt = $this->pdo->query(
            "SELECT name FROM sqlite_master WHERE type='index' AND name=" . $this->pdo->quote($index)
        );
        return $stmt !== false && $stmt->fetch() !== false;
    }

    /** Run a callable while suppressing stdout output (migration echo statements). */
    private function silently(callable $fn): void
    {
        ob_start();
        try {
            $fn();
        } finally {
            ob_end_clean();
        }
    }
}
