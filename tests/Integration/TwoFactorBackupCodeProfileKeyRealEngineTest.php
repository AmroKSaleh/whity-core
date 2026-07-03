<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\BackupCodesService;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Identity\MembershipRepository;
use Whity\Core\Request;

/**
 * BLOCKER-2 (WC-c35c4ce0 review): 2FA backup-code validation must be keyed on
 * the LEGACY users.id, never on profile_id.
 *
 * backup_codes.user_id is an FK to users.id — migration 035 deliberately did NOT
 * re-point it at profiles. The 2FA path used to pass profile_id (the throttle id)
 * to BackupCodesService::validateCode(). That only works while profile_id happens
 * to equal user_id (the early dual window); for any account whose profile_id
 * differs from its users.id, backup-code recovery silently fails (the query keys
 * on the wrong id) and a rotation would hit an FK violation.
 *
 * This test reproduces the divergence: it forces profile_id != user_id, stores a
 * backup code against the users.id, and drives handle2fa() with that backup code.
 * With the fix (resolve the legacy user_id for backup_codes ops) it succeeds; with
 * the bug (profile_id used) it fails.
 *
 * PostgreSQL-only: BackupCodesService::validateCode() marks a code used with a
 * `NOW()` UPDATE that is not valid SQLite, so this is meaningful only against the
 * real PG engine (which the Integration suite uses in CI via PHPUNIT_PG_DSN).
 */
final class TwoFactorBackupCodeProfileKeyRealEngineTest extends TestCase
{
    private const SECRET   = 'wc-c35c4ce0-backup-code-key-test-secret-32b';
    private const PASSWORD = 'backup-code-profile-key-test-pass-123';
    private const TENANT   = 1;
    private const EMAIL    = 'divergent@corp.com';
    private const BACKUP_CODE = 'ZZZZ-YYYY-XXXX';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private AuthHandler $handler;
    private MembershipRepository $memberships;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();

        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            self::markTestSkipped(
                'Backup-code validation uses NOW() (PG-only); run under PHPUNIT_PG_DSN.'
            );
        }

        $this->jwtParser   = new JwtParser(self::SECRET);
        $this->memberships = new MembershipRepository($this->pdo);
        $this->handler     = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
        );

        $this->pdo->exec(
            "INSERT INTO tenants (id, name, created_at) VALUES (1, 'tenant-a', NOW())
             ON CONFLICT (id) DO NOTHING"
        );
        $this->pdo->exec("INSERT INTO roles (id, name) VALUES (1, 'admin') ON CONFLICT (id) DO NOTHING");
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    /**
     * A profile whose id differs from its legacy users.id must still be able to
     * complete 2FA with a backup code — proving backup_codes is keyed on the
     * users.id, not the profile_id.
     */
    public function testBackupCodeValidatesWhenProfileIdDiffersFromUserId(): void
    {
        // Force a wide gap so profile_id and users.id cannot coincide.
        $userId = $this->seedUser(self::EMAIL, self::TENANT);      // small serial id
        $profileId = $this->seedProfileWithHighId(self::EMAIL);    // deliberately large id
        self::assertNotSame($userId, $profileId, 'Test requires profile_id != user_id.');

        $this->memberships->insert($profileId, self::TENANT, 1);

        // Store a backup code (version 1) keyed on the LEGACY users.id.
        $this->pdo->prepare(
            'INSERT INTO backup_codes (user_id, code, used, version, created_at)
             VALUES (?, ?, false, 1, NOW())'
        )->execute([$userId, password_hash(self::BACKUP_CODE, PASSWORD_BCRYPT)]);

        // Mint the temp token the login path produces for a 2FA-enabled profile:
        // new claims (profile_id + active_tenant_id) PLUS the legacy user_id.
        $tempClaims = [
            'profile_id'       => $profileId,
            'active_tenant_id' => self::TENANT,
            'email'            => self::EMAIL,
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
            'A backup code stored against users.id must validate even when profile_id != user_id.'
        );

        // The code must be marked used (single-use) — proving the UPDATE ran
        // against the correct (users.id) row.
        $usedStmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM backup_codes WHERE user_id = ? AND used = true'
        );
        $usedStmt->execute([$userId]);
        self::assertSame(
            1,
            (int) $usedStmt->fetchColumn(),
            'The consumed backup code must be marked used on the users.id row.'
        );
    }

    /**
     * Guard the mirror image: passing profile_id (the bug) must NOT find the code.
     * This asserts that a code stored on the users.id is invisible under the
     * profile_id, i.e. the two ids are genuinely distinct key spaces.
     */
    public function testBackupCodeStoredOnUserIdIsNotFoundUnderProfileId(): void
    {
        $userId = $this->seedUser(self::EMAIL, self::TENANT);
        $profileId = $this->seedProfileWithHighId(self::EMAIL);
        $this->memberships->insert($profileId, self::TENANT, 1);

        $this->pdo->prepare(
            'INSERT INTO backup_codes (user_id, code, used, version, created_at)
             VALUES (?, ?, false, 1, NOW())'
        )->execute([$userId, password_hash(self::BACKUP_CODE, PASSWORD_BCRYPT)]);

        $service = new BackupCodesService(new \Whity\Auth\DatabaseQueryWrapper($this->pdo));

        self::assertFalse(
            $service->validateCode($profileId, self::BACKUP_CODE, 1),
            'A code stored on users.id must NOT validate when queried under profile_id (the bug).'
        );
        self::assertTrue(
            $service->validateCode($userId, self::BACKUP_CODE, 1),
            'The same code must validate under the correct users.id.'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function seedUser(string $email, int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, NOW(), 0) RETURNING id'
        );
        $stmt->execute([$tenantId, $email, password_hash(self::PASSWORD, PASSWORD_BCRYPT), 1]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Seed a profile at a deliberately HIGH id so it cannot collide with the
     * small serial users.id, then a verified primary email for it. 2FA is enabled
     * with a version-1 backup-code set (secret is a throwaway; the test drives the
     * backup-code branch, not TOTP).
     */
    private function seedProfileWithHighId(string $email): int
    {
        $highId = 900000;
        $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_secret,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, true, ?, 1, 0, NOW(), NOW())"
        )->execute([$highId, 'divergent', password_hash(self::PASSWORD, PASSWORD_BCRYPT), 'unused-secret']);

        $this->pdo->prepare(
            'INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, NOW())'
        )->execute([$highId, $email]);

        return $highId;
    }
}
