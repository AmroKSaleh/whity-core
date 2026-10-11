<?php

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Request;
use Whity\Core\Response;
use PDO;

/**
 * Deactivating a profile has to end the sessions it already has.
 *
 * ── What was wrong ────────────────────────────────────────────────────────
 *
 * `handleLogin()` refuses `profiles.status = 'inactive'`. `handleRefresh()`
 * did not read the column at all. A refresh token lives seven days and rotates
 * indefinitely, so a profile deactivated at 09:00 kept a fully working session
 * for as long as a browser tab stayed open — and the audit log recorded a
 * successful deactivation with nothing recording that the person was still
 * inside.
 *
 * So the button said "Deactivate" and meant "cannot log in again". That gap is
 * invisible from every screen an operator can look at, and it is widest in the
 * case the feature exists for: somebody leaving under bad terms.
 *
 * ── Why the assertions are on the AUDIT ROW, not just the 401 ─────────────
 *
 * Nearly every refusal in `handleRefresh()` returns the same
 * `Unauthorized`/401 — an expired token, a missing claim, a revoked jti. A
 * test asserting only the status code passes when the token merely failed to
 * validate, which is the shape of assertion that let this bug survive next to
 * two adjacent guards. `reason = profile_inactive` can only be written by the
 * branch under test.
 */
class DeactivationEndsSessionsTest extends TestCase
{
    private JwtParser $jwtParser;
    private PDO $pdo;
    private AuditLogger $auditLogger;

    private const TEST_SECRET_KEY = 'test-secret-key-for-integration-tests-padded-min-32-byte-key';
    private const TEST_USER_EMAIL = 'leaver@example.com';
    /** A sentinel id no seeder will reach; see RefreshTokenReuseDetectionTest. */
    private const TEST_USER_ID = 900042;
    private const TEST_TENANT_ID = 1;
    private const TEST_ROLE_ID = 1;

    protected function setUp(): void
    {
        $this->jwtParser = new JwtParser(self::TEST_SECRET_KEY);
        $this->pdo = $this->makeSchema();
        $this->auditLogger = new AuditLogger($this->pdo);
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    /**
     * THE BASELINE, and it is not decoration: without it, a "fix" that refused
     * EVERY refresh would pass every other test in this file.
     */
    public function testAnActiveProfileCanRefresh(): void
    {
        $this->assertSame(200, $this->refresh()->getStatusCode());
    }

    public function testDeactivatingAProfileRefusesItsNextRefresh(): void
    {
        $this->assertSame(200, $this->refresh()->getStatusCode(), 'precondition: the session works');

        $this->setStatus('inactive');

        $this->assertSame(401, $this->refresh()->getStatusCode());
    }

    /** The refusal has to be attributable, or nobody can answer "when did they lose access?". */
    public function testTheRefusalIsAuditedWithItsReason(): void
    {
        $this->setStatus('inactive');
        $this->pdo->exec('DELETE FROM audit_log');

        $this->refresh();

        $rows = $this->auditRows('auth.refresh.failure');
        $this->assertCount(1, $rows, 'the refusal should write exactly one audit row');

        $metadata = json_decode((string) ($rows[0]['metadata'] ?? '{}'), true);
        $this->assertIsArray($metadata);
        $this->assertSame('profile_inactive', $metadata['reason'] ?? null);
        $this->assertSame(self::TEST_USER_ID, (int) $rows[0]['actor_user_id']);
    }

    /**
     * REACTIVATION MUST RESTORE THE SESSION PATH. A guard that latches is a
     * different bug with the same test coverage — deactivating by mistake would
     * become unrecoverable without a fresh login, and nothing above notices.
     */
    public function testReactivatingRestoresTheAbilityToRefresh(): void
    {
        $this->setStatus('inactive');
        $this->assertSame(401, $this->refresh()->getStatusCode());

        $this->setStatus('active');
        $this->assertSame(200, $this->refresh()->getStatusCode());
    }

    /**
     * A DELETED PROFILE CANNOT REFRESH — and this one PASSED BEFORE THE FIX.
     *
     * It is kept, and labelled, because the draft of this file claimed the
     * opposite: that a token outliving its profile validated cleanly, since
     * `currentProfileTokenEpoch()` returns 0 for a missing row exactly as it
     * does for a real epoch of 0. Running the file against the unfixed code
     * showed three failures and this passing, which is how the claim was
     * caught. The refusal comes from `TokenValidator::isProfileEpochCurrent()`
     * one layer up, which returns false when the row is absent.
     *
     * So this is a regression guard on a guarantee that already holds in a
     * different class, not a demonstration of the bug — and saying so is the
     * point, because a test whose comment overstates what it proves is how the
     * next person concludes the wrong thing about which layer is load-bearing.
     */
    public function testAProfileThatNoLongerExistsCannotRefresh(): void
    {
        $token = $this->mintRefresh();

        $this->pdo->exec('DELETE FROM memberships WHERE profile_id = ' . self::TEST_USER_ID);
        $this->pdo->exec('DELETE FROM profile_emails WHERE profile_id = ' . self::TEST_USER_ID);
        $this->pdo->exec('DELETE FROM profiles WHERE id = ' . self::TEST_USER_ID);

        $this->assertSame(401, $this->refresh($token)->getStatusCode());
    }

    /**
     * AN UNRECOGNISED STATUS IS TREATED AS ACTIVE, matching `handleLogin()`.
     *
     * The tempting shape is an allowlist — refuse anything that is not exactly
     * 'active'. It disagrees with the login gate, so a status somebody adds
     * later would let people log in and then sign them out fifteen minutes on,
     * every time, for every account carrying it. Failing open on an unknown
     * value is the same class of mistake as failing closed; this picks the one
     * that matches the gate it mirrors, so the two can only ever be wrong
     * together.
     */
    public function testAnUnrecognisedStatusIsTreatedTheSameWayLoginTreatsIt(): void
    {
        $this->setStatus('pending_review');

        $this->assertSame(200, $this->refresh()->getStatusCode());
    }

    // ── harness ──────────────────────────────────────────────────────────

    private function handler(): AuthHandler
    {
        return new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            new TokenValidator($this->jwtParser, $this->pdo),
            null,
            null,
            null,
            $this->auditLogger
        );
    }

    /** A refresh revokes the token it consumed, so every call needs a fresh one. */
    private function refresh(?string $token = null): Response
    {
        $_COOKIE['refresh_token'] = $token ?? $this->mintRefresh();

        return $this->handler()->handleRefresh(new Request('POST', '/api/auth/refresh', []));
    }

    private function mintRefresh(): string
    {
        return $this->jwtParser->create([
            'profile_id' => self::TEST_USER_ID,
            'active_tenant_id' => self::TEST_TENANT_ID,
            'email' => self::TEST_USER_EMAIL,
            'role' => 'admin',
            'token_epoch' => 0,
        ], 604800, 'refresh');
    }

    private function setStatus(string $status): void
    {
        $this->pdo->prepare('UPDATE profiles SET status = ? WHERE id = ?')
            ->execute([$status, self::TEST_USER_ID]);
    }

    /** @return list<array<string, mixed>> */
    private function auditRows(string $action): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM audit_log WHERE action = ?');
        $stmt->execute([$action]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Test Tenant', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles   (id, name) VALUES (1, 'admin')");

        $pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        )->execute([self::TEST_USER_ID, 'leaver', password_hash('irrelevant-for-refresh', PASSWORD_BCRYPT)]);

        $pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([self::TEST_USER_ID, self::TEST_USER_EMAIL]);

        $pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([self::TEST_USER_ID, self::TEST_TENANT_ID, self::TEST_ROLE_ID]);

        return $pdo;
    }
}
