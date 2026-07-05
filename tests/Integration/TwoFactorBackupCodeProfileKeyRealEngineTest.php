<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\DatabaseQueryWrapper;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Request;

/**
 * WC-idcut-A: backup_codes is now keyed on profiles.id (migration 038).
 *
 * This test suite verifies the post-038 invariants:
 *
 * 1. A backup code stored for profile A cannot be validated for profile B
 *    (profile isolation — the column is profiles.id, not users.id).
 * 2. Backup codes cascade-delete when the owning profile is deleted
 *    (ON DELETE CASCADE on the profiles.id FK).
 * 3. A full 2FA-with-backup-code login flow completes successfully when
 *    a backup code is stored and validated against the profile_id from the
 *    temp token's `profile_id` claim.
 *
 * PostgreSQL-only: BackupCodesService::validateCode() marks a code used with a
 * `NOW()` UPDATE that is not valid SQLite syntax, so the validate + mark-used
 * tests are meaningful only against the real PG engine
 * (run via PHPUNIT_PG_DSN environment variable).
 */
final class TwoFactorBackupCodeProfileKeyRealEngineTest extends TestCase
{
    private const SECRET    = 'wc-idcut-A-backup-code-profile-key-test-32b';
    private const PASSWORD  = 'backup-code-profile-key-test-pass-123';
    private const TENANT    = 1;
    private const EMAIL_A   = 'profile-a@corp.com';
    private const EMAIL_B   = 'profile-b@corp.com';
    private const BACKUP_CODE = 'ZZZZ-YYYY-XXXX';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private AuthHandler $handler;
    private MembershipRepository $memberships;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();

        // SQLite does not enforce FK constraints by default; enable them so that
        // ON DELETE CASCADE on backup_codes.profile_id is exercised in SQLite tests.
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        }

        $this->jwtParser   = new JwtParser(self::SECRET);
        $this->memberships = new MembershipRepository($this->pdo);
        $this->handler     = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
        );

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            $this->pdo->exec(
                "INSERT INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', NOW())
                 ON CONFLICT (id) DO NOTHING"
            );
            $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT (id) DO NOTHING");
        } else {
            $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', datetime('now'))");
            $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin')");
        }
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    /**
     * Profile isolation: a backup code stored for profile A cannot be validated
     * for profile B. After migration 038, backup_codes.profile_id is the key.
     */
    public function testBackupCodeStoredForProfileACannotBeValidatedForProfileB(): void
    {
        $profileIdA = $this->seedProfile(self::EMAIL_A, id: 80001);
        $profileIdB = $this->seedProfile(self::EMAIL_B, id: 80002);

        $service = new BackupCodesService(new DatabaseQueryWrapper($this->pdo));
        $hash    = $service->hashCode(self::BACKUP_CODE);

        // Store the code for profile A only.
        $this->insertBackupCode($profileIdA, $hash, version: 1);

        // Profile A must validate successfully.
        self::assertTrue(
            $service->validateCode($profileIdA, self::BACKUP_CODE, 1),
            'Profile A must be able to validate its own backup code.'
        );

        // Profile B must not find the code (different profile_id key).
        self::assertFalse(
            $service->validateCode($profileIdB, self::BACKUP_CODE, 1),
            'A backup code stored for profile A must not be usable by profile B.'
        );
    }

    /**
     * Cascade-delete: deleting a profile must delete all its backup codes.
     */
    public function testBackupCodesCascadeDeleteOnProfileDelete(): void
    {
        $profileId = $this->seedProfile(self::EMAIL_A, id: 80010);

        $service = new BackupCodesService(new DatabaseQueryWrapper($this->pdo));
        $hash    = $service->hashCode(self::BACKUP_CODE);
        $this->insertBackupCode($profileId, $hash, version: 1);

        // Verify the code exists before deletion.
        $beforeStmt = $this->pdo->prepare('SELECT COUNT(*) FROM backup_codes WHERE profile_id = ?');
        $beforeStmt->execute([$profileId]);
        self::assertGreaterThan(0, (int) $beforeStmt->fetchColumn(), 'Code must exist before profile deletion.');

        // Delete the profile — backup_codes should cascade.
        $delStmt = $this->pdo->prepare('DELETE FROM profiles WHERE id = ?');
        $delStmt->execute([$profileId]);

        $afterStmt = $this->pdo->prepare('SELECT COUNT(*) FROM backup_codes WHERE profile_id = ?');
        $afterStmt->execute([$profileId]);
        self::assertSame(
            0,
            (int) $afterStmt->fetchColumn(),
            'All backup codes must be cascade-deleted when their owning profile is deleted.'
        );
    }

    /**
     * Full 2FA-with-backup-code login flow: a backup code stored against
     * profile_id must allow handle2fa() to complete login.
     *
     * PostgreSQL-only (NOW() in BackupCodesService UPDATE is PG syntax).
     */
    public function testBackupCodeLoginFlowSucceedsWithProfileIdKey(): void
    {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped('Backup-code mark-used uses NOW() (PG-only); run under PHPUNIT_PG_DSN.');
        }

        $profileId = $this->seedProfile(self::EMAIL_A, id: 80020, twoFactorEnabled: true);
        $userId    = $this->seedUserForProfile(self::EMAIL_A, self::TENANT);
        $this->memberships->insert($profileId, self::TENANT, 1);

        // Store a backup code keyed on profile_id (migration 038).
        $service = new BackupCodesService(new DatabaseQueryWrapper($this->pdo));
        $hash    = $service->hashCode(self::BACKUP_CODE);
        $this->insertBackupCode($profileId, $hash, version: 1);

        // Mint the temp token with profile_id (new-claims shape from handle()).
        $tempClaims = [
            'profile_id'       => $profileId,
            'active_tenant_id' => self::TENANT,
            'email'            => self::EMAIL_A,
            'user_id'          => $userId,
            'tenant_id'        => self::TENANT,
        ];
        $_COOKIE['temp_auth_token'] = $this->jwtParser->create($tempClaims, 300, 'temp');

        // An invalid TOTP code forces fall-through to backup-code validation.
        $response = $this->handler->handle2fa(
            new Request('POST', '/api/login/2fa', [], (string) json_encode(['code' => self::BACKUP_CODE]))
        );

        self::assertSame(
            200,
            $response->getStatusCode(),
            'A backup code stored against profile_id must complete 2FA login.'
        );

        // The code must be marked used (single-use).
        $usedStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM backup_codes WHERE profile_id = ? AND used = true'
        );
        $usedStmt->execute([$profileId]);
        self::assertSame(
            1,
            (int) $usedStmt->fetchColumn(),
            'The consumed backup code must be marked used on the profile_id row.'
        );
    }

    /**
     * Guard: a code stored for profile A does not validate under profile B even
     * when their integer ids are numerically adjacent (no off-by-one leakage).
     */
    public function testAdjacentProfileIdsDoNotLeakCodes(): void
    {
        $profileIdA = $this->seedProfile(self::EMAIL_A, id: 80030);
        $profileIdB = $this->seedProfile(self::EMAIL_B, id: 80031);

        $service = new BackupCodesService(new DatabaseQueryWrapper($this->pdo));
        $hash    = $service->hashCode(self::BACKUP_CODE);

        $this->insertBackupCode($profileIdA, $hash, version: 1);

        self::assertFalse(
            $service->validateCode($profileIdB, self::BACKUP_CODE, 1),
            'Adjacent profile ids must not leak backup codes across profiles.'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * Seed a minimal profile row (with optional 2FA enabled) and a verified
     * primary profile_email for it.
     */
    private function seedProfile(string $email, int $id, bool $twoFactorEnabled = false): int
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";
        $secret = $twoFactorEnabled ? "'unused-secret'" : 'NULL';

        $this->pdo->exec(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_secret, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ({$id}, 'test-profile', '" . password_hash(self::PASSWORD, PASSWORD_BCRYPT) . "',
                " . ($twoFactorEnabled ? 'true' : 'false') . ", {$secret}, 1, 0, {$now}, {$now})"
        );

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, {$now})"
        )->execute([$id, $email]);

        return $id;
    }

    /**
     * Seed a legacy users row for the given email/tenant (for dual-window tests).
     */
    private function seedUserForProfile(string $email, int $tenantId): int
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";
        $stmt = $this->pdo->prepare(
            "INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, 1, {$now}, 0)"
            . ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? ' RETURNING id' : '')
        );
        $stmt->execute([$tenantId, $email, password_hash(self::PASSWORD, PASSWORD_BCRYPT)]);
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            return (int) $stmt->fetchColumn();
        }
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Insert a backup_codes row keyed on profile_id (migration 038).
     */
    private function insertBackupCode(int $profileId, string $hashedCode, int $version): void
    {
        $now = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql' ? 'NOW()' : "datetime('now')";
        $this->pdo->prepare(
            "INSERT INTO backup_codes (profile_id, code, used, version, created_at)
             VALUES (?, ?, false, ?, {$now})"
        )->execute([$profileId, $hashedCode, $version]);
    }
}
