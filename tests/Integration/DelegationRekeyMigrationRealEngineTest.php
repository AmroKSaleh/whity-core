<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\RekeyDelegationsToProfiles;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Delegation\DelegationRepository;
use Whity\Database\Database;

/**
 * WC-bc07b6de: EXECUTING real-engine test for migration 037 (RekeyDelegationsToProfiles).
 *
 * The documentary MigrationSchemaTest case only regexes the source. This suite
 * actually RUNS the migration against a genuine SQL engine on a seeded
 * permission_delegations dataset and asserts the data/schema outcomes that the
 * regex could never catch:
 *
 *  - pre-existing user-grantee rows end up grantee_type='profile' with
 *    grantee_id = the mapped profile_id AND are resolvable (Blocker 1);
 *  - orphan grantor rows (no 035 mapping) are removed so grantor_profile_id is
 *    NOT NULL afterwards (Major 3);
 *  - the migration-014 hot-path indexes idx_pd_resolution and idx_pd_ou survive
 *    the SQLite rename-recreate (Major 4);
 *  - a grantee_type='profile' INSERT SUCCEEDS post-migration, proving the CHECK
 *    was actually widened (Major 5);
 *  - down() then up() round-trips to a consistent schema (reversibility).
 *
 * Engine strategy: SchemaFromMigrations::make() applies EVERY migration, so 037
 * has already run when we receive the PDO. To exercise 037 against a legacy
 * (pre-037) dataset we first run 037.down() to reconstruct the grantor_user_id /
 * grantee_type='user' shape, seed legacy rows, then run 037.up(). This drives the
 * real up() path identically on SQLite and PostgreSQL (PHPUNIT_PG_DSN).
 */
final class DelegationRekeyMigrationRealEngineTest extends TestCase
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

        require_once dirname(__DIR__, 2) . '/database/migrations/037_rekey_delegations_to_profiles.php';

        // Reconstruct the legacy (pre-037) schema so we can run up() against it.
        $this->silently(fn () => RekeyDelegationsToProfiles::down($this->db));

        $this->seedIdentities();
    }

    /**
     * Full up() on a seeded legacy dataset: grantee re-key, orphan removal,
     * index survival, CHECK widening, resolvability.
     */
    public function testUpRekeysGranteesRemovesOrphansAndKeepsIndexes(): void
    {
        $this->seedLegacyDelegations();

        $this->silently(fn () => RekeyDelegationsToProfiles::up($this->db));

        // Blocker 1: no 'user' grantee rows remain; the mapped user-grantee row is
        // now a 'profile' grantee pointing at the mapped profile id.
        self::assertSame(0, $this->countRows("grantee_type = 'user'"), 'No grantee_type=user rows may remain after up().');

        $userGranteeRow = $this->row('permission=' . $this->q('users:read') . " AND grantee_type = 'profile' AND tenant_id = 1");
        self::assertNotNull($userGranteeRow, 'The migrated user-grantee row must be present as a profile grantee.');
        self::assertSame(101, (int) $userGranteeRow['grantee_id'], 'grantee_id must be re-keyed to the mapped profile_id (100->? ; user 11 -> profile 101).');
        self::assertSame(100, (int) $userGranteeRow['grantor_profile_id'], 'grantor_profile_id must be the mapped profile (user 10 -> profile 100).');

        // The role grantee row is untouched (still a role).
        self::assertSame(1, $this->countRows("grantee_type = 'role'"), 'Role grantee row must survive unchanged.');

        // Major 3: orphan grantor row (user 99, no mapping) removed; no NULLs left.
        self::assertSame(0, $this->countRows('grantor_profile_id IS NULL'), 'No NULL grantor_profile_id rows may remain (orphans deleted).');

        // Major 4: hot-path indexes survive.
        self::assertTrue($this->indexExists('idx_pd_resolution'), 'idx_pd_resolution must exist after up().');
        self::assertTrue($this->indexExists('idx_pd_ou'), 'idx_pd_ou must exist after up().');
        self::assertTrue($this->indexExists('idx_pd_grantor_profile'), 'idx_pd_grantor_profile must exist after up().');

        // Major 5: a 'profile' grantee INSERT succeeds (CHECK actually widened).
        $this->insertDelegation(1, 100, 'profile', 101, 'roles:read');
        self::assertSame(1, $this->countRows("permission = " . $this->q('roles:read') . " AND grantee_type = 'profile'"), 'A grantee_type=profile insert must succeed post-migration.');

        // Resolvability: the re-keyed user-grantee row is found by the resolver.
        $repo  = new DelegationRepository($this->pdo);
        $perms = $repo->livePermissionsForGrantee(1, DelegationRepository::GRANTEE_PROFILE, 101, []);
        self::assertContains('users:read', $perms, 'The migrated user-grantee delegation must be resolvable as a profile grantee.');
    }

    /**
     * NOT NULL is enforced on PostgreSQL (the production engine); the docblock
     * contract is honest.
     */
    public function testGrantorProfileIdIsNotNullOnPostgres(): void
    {
        if (!$this->isPg) {
            self::markTestSkipped('NOT NULL column constraint is enforced on the PostgreSQL path.');
        }

        $this->seedLegacyDelegations();
        $this->silently(fn () => RekeyDelegationsToProfiles::up($this->db));

        // Attempt to insert a row with a NULL grantor_profile_id — must be rejected.
        $threw = false;
        try {
            $this->pdo->exec(
                "INSERT INTO permission_delegations (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
                 VALUES (1, NULL, 'profile', 101, 'users:read', NOW())"
            );
        } catch (\PDOException $e) {
            $threw = true;
        }
        self::assertTrue($threw, 'grantor_profile_id must be NOT NULL on PostgreSQL.');
    }

    /**
     * Reversibility: up() → down() → up() leaves a consistent schema with the
     * correct column set and indexes each way.
     */
    public function testUpDownUpRoundTripsConsistently(): void
    {
        $this->seedLegacyDelegations();

        $this->silently(fn () => RekeyDelegationsToProfiles::up($this->db));
        self::assertTrue($this->columnExists('grantor_profile_id'), 'up(): grantor_profile_id present.');
        self::assertFalse($this->columnExists('grantor_user_id'), 'up(): grantor_user_id gone.');

        $this->silently(fn () => RekeyDelegationsToProfiles::down($this->db));
        self::assertTrue($this->columnExists('grantor_user_id'), 'down(): grantor_user_id restored.');
        self::assertFalse($this->columnExists('grantor_profile_id'), 'down(): grantor_profile_id gone.');
        self::assertSame(0, $this->countRows("grantee_type = 'profile'"), "down(): no 'profile' grantee rows may remain before the narrowed CHECK.");
        self::assertTrue($this->indexExists('idx_pd_resolution'), 'down(): idx_pd_resolution must survive.');
        self::assertTrue($this->indexExists('idx_pd_ou'), 'down(): idx_pd_ou must survive.');
        self::assertTrue($this->indexExists('idx_pd_grantor'), 'down(): legacy idx_pd_grantor restored.');

        $this->silently(fn () => RekeyDelegationsToProfiles::up($this->db));
        self::assertTrue($this->columnExists('grantor_profile_id'), 'up() again: grantor_profile_id present.');
        self::assertFalse($this->columnExists('grantor_user_id'), 'up() again: grantor_user_id gone.');
        self::assertTrue($this->indexExists('idx_pd_resolution'), 'up() again: idx_pd_resolution present.');
        self::assertTrue($this->indexExists('idx_pd_grantor_profile'), 'up() again: idx_pd_grantor_profile present.');
    }

    // ── fixtures ──────────────────────────────────────────────────────────────

    private function seedIdentities(): void
    {
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 't-a') ON CONFLICT (id) DO NOTHING");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (2, 't-b') ON CONFLICT (id) DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT (id) DO NOTHING");
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (2, 'user') ON CONFLICT (id) DO NOTHING");

        // Users 10, 11, 20, 99 ; profiles 100, 101, 200 ; mapping (99 has NONE → orphan).
        $this->pdo->exec(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at) VALUES
                (10, 1, 'a@x', 'x', 1, NOW()),
                (11, 1, 'b@x', 'x', 2, NOW()),
                (20, 2, 'c@x', 'x', 2, NOW()),
                (99, 1, 'orphan@x', 'x', 2, NOW())
             ON CONFLICT (id) DO NOTHING"
        );
        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (100, 'a', '', false, 0, 0, NOW(), NOW()),
                (101, 'b', '', false, 0, 0, NOW(), NOW()),
                (200, 'c', '', false, 0, 0, NOW(), NOW())
             ON CONFLICT (id) DO NOTHING"
        );
        $this->pdo->exec(
            "INSERT INTO migration_035_profile_ids (user_id, profile_id) VALUES
                (10, 100), (11, 101), (20, 200)
             ON CONFLICT (user_id) DO NOTHING"
        );
    }

    /**
     * Seed legacy (pre-037) delegation rows against the reconstructed schema:
     *  1: user-grantee (grantor 10 → grantee user 11) tenant 1  → profile 101
     *  2: role-grantee (grantor 10 → role 2)          tenant 1  → stays role
     *  3: user-grantee (grantor 20 → grantee user 20) tenant 2  → profile 200
     *  4: ORPHAN grantor (user 99, no mapping)        tenant 1  → deleted by up()
     */
    private function seedLegacyDelegations(): void
    {
        $this->pdo->exec(
            "INSERT INTO permission_delegations
                (tenant_id, grantor_user_id, grantee_type, grantee_id, permission, granted_at) VALUES
                (1, 10, 'user', 11, 'users:read', NOW()),
                (1, 10, 'role',  2, 'users:read', NOW()),
                (2, 20, 'user', 20, 'users:read', NOW()),
                (1, 99, 'user', 11, 'users:read', NOW())"
        );
    }

    private function insertDelegation(int $tenantId, int $grantorProfileId, string $granteeType, int $granteeId, string $permission): void
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO permission_delegations
                (tenant_id, grantor_profile_id, grantee_type, grantee_id, permission, granted_at)
             VALUES (?, ?, ?, ?, ?, NOW())"
        );
        $stmt->execute([$tenantId, $grantorProfileId, $granteeType, $granteeId, $permission]);
    }

    // ── introspection ──────────────────────────────────────────────────────────

    private function countRows(string $whereFragment): int
    {
        $stmt = $this->pdo->query("SELECT COUNT(*) FROM permission_delegations WHERE {$whereFragment}");
        self::assertNotFalse($stmt);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function row(string $whereFragment): ?array
    {
        $stmt = $this->pdo->query("SELECT * FROM permission_delegations WHERE {$whereFragment} LIMIT 1");
        self::assertNotFalse($stmt);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function columnExists(string $column): bool
    {
        if ($this->isPg) {
            $stmt = $this->pdo->prepare(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = current_schema() AND table_name = 'permission_delegations' AND column_name = ?"
            );
            $stmt->execute([$column]);
            return $stmt->fetchColumn() !== false;
        }
        $stmt = $this->pdo->query('PRAGMA table_info(permission_delegations)');
        self::assertNotFalse($stmt);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((string) $r['name'] === $column) {
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
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type='index' AND name = ?"
        );
        $stmt->execute([$index]);
        return $stmt->fetchColumn() !== false;
    }

    private function q(string $value): string
    {
        return $this->pdo->quote($value);
    }

    /**
     * Run a migration callable with stdout silenced (migrations print operator notices).
     */
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
