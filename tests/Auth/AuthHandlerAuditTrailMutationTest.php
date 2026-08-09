<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\LoginThrottleService;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Request;
use Whity\Core\Store\ArraySharedStore;

/**
 * Mutation-hardening tests for {@see AuthHandler}'s security-relevant SIDE
 * EFFECTS: the exact audit-log entries written on login failure/success and
 * 2FA challenge, the per-user login-throttle counter lifecycle, session-table
 * bookkeeping, and token revocation on a self-service password change.
 *
 * These deliberately assert on precise OUTCOMES (audit_log rows, throttle
 * counters, sessions rows, revoked_tokens rows) rather than just the HTTP
 * response shape: several of these code paths are exercised by OTHER
 * real-engine tests for their response status/body, but nothing previously
 * asserted on the side effect itself — which is exactly the gap a mutant that
 * deletes the side-effecting call (or a field within it) can hide behind.
 *
 * Runs against the real production schema (SchemaFromMigrations) on SQLite,
 * matching every other *RealEngineTest in this directory.
 */
final class AuthHandlerAuditTrailMutationTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';
    private const TENANT = 1;

    private PDO $pdo;
    private JwtParser $jwtParser;
    private ArraySharedStore $throttleStore;
    private LoginThrottleService $throttle;
    private AuthHandler $authHandler;

    protected function setUp(): void
    {
        $_COOKIE = [];
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-one')");

        $this->jwtParser = new JwtParser('test-secret-key-padded-for-hs256-min-32-byte-key-aa');
        $this->throttleStore = new ArraySharedStore();
        $this->throttle = new LoginThrottleService($this->throttleStore);

        $this->authHandler = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            null,
            null,
            null,
            new NullLogger(),
            new AuditLogger($this->pdo),
            $this->throttle
        );
    }

    protected function tearDown(): void
    {
        $_COOKIE = [];
    }

    // =========================================================================
    // Login-failure audit trail (the inactive-account / wrong-password paths)
    // =========================================================================

    /**
     * The inactive-account login rejection must audit the exact email + reason
     * AND still count toward the per-user brute-force throttle. Kills mutants
     * that drop the audit() call entirely, drop the 'email' field from its
     * metadata, or drop the loginThrottle->recordFailure() call.
     */
    public function testInactiveAccountLoginFailureAuditsExactMetadataAndThrottlesFailure(): void
    {
        $profileId = $this->seedProfile('inactive-audit@example.com', 'inactive');

        $response = $this->login('inactive-audit@example.com');

        $this->assertSame(401, $response->getStatusCode());

        $row = $this->fetchLastAuditRow('auth.login.failure');
        $this->assertNotNull($row, 'An inactive-account login failure must be audited.');
        $this->assertSame($profileId, (int) $row['actor_user_id']);

        $metadata = json_decode((string) $row['metadata'], true);
        $this->assertSame('inactive-audit@example.com', $metadata['email'] ?? null);
        $this->assertSame('profile_inactive', $metadata['reason'] ?? null);

        $this->assertSame(
            1,
            $this->throttleStore->count('login:fail:user:' . $profileId),
            'An inactive-account failure must still count toward the per-user throttle.'
        );
    }

    /**
     * A wrong-password login rejection (active account) must audit the exact
     * email + reason AND throttle the failure. Kills mutants that drop the
     * audit() call, drop the 'email' field, or drop recordFailure().
     */
    public function testInvalidPasswordLoginFailureAuditsExactMetadataAndThrottlesFailure(): void
    {
        $profileId = $this->seedProfile('wrongpass-audit@example.com', 'active');

        $response = $this->login('wrongpass-audit@example.com', 'totally-wrong-password');

        $this->assertSame(401, $response->getStatusCode());

        $row = $this->fetchLastAuditRow('auth.login.failure');
        $this->assertNotNull($row, 'A wrong-password login failure must be audited.');
        $this->assertSame($profileId, (int) $row['actor_user_id']);

        $metadata = json_decode((string) $row['metadata'], true);
        $this->assertSame('wrongpass-audit@example.com', $metadata['email'] ?? null);
        $this->assertSame('invalid_password', $metadata['reason'] ?? null);

        $this->assertSame(
            1,
            $this->throttleStore->count('login:fail:user:' . $profileId),
            'A wrong-password failure must count toward the per-user throttle.'
        );
    }

    // =========================================================================
    // 2FA-required audit (single-membership login)
    // =========================================================================

    /**
     * A single-membership login for a 2FA-enabled profile must audit the
     * 2fa_required event against the resolved tenant. Kills a mutant that
     * drops that audit() call entirely.
     */
    public function testSingleMembershipTwoFactorRequiredAuditsTenantAndActor(): void
    {
        $profileId = $this->seedProfile('twofa-required@example.com', 'active', twoFactorEnabled: true);

        $response = $this->login('twofa-required@example.com');

        $this->assertSame(202, $response->getStatusCode());

        $row = $this->fetchLastAuditRow('auth.login.2fa_required');
        $this->assertNotNull($row, 'A 2FA challenge for a single-membership login must be audited.');
        $this->assertSame(self::TENANT, (int) $row['tenant_id'], 'The single resolved membership tenant must be recorded.');
        $this->assertSame($profileId, (int) $row['actor_user_id']);
    }

    // =========================================================================
    // Successful login: throttle clearing, audit, and session recording
    // =========================================================================

    /**
     * A successful login must clear any prior per-user throttle counter (so a
     * legitimate user is not left flagged after recovering from a few failed
     * attempts) and must audit auth.login.success. Kills a mutant that drops
     * the loginThrottle->clearUser() call, and one that drops the success
     * audit() call.
     */
    public function testSuccessfulLoginClearsThrottleAndAuditsLoginSuccess(): void
    {
        $profileId = $this->seedProfile('clears-throttle@example.com', 'active');

        // Prime a prior failure so there is something to clear.
        $this->throttle->recordFailure($profileId, null);
        $this->assertSame(1, $this->throttleStore->count('login:fail:user:' . $profileId));

        $response = $this->login('clears-throttle@example.com');

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(
            0,
            $this->throttleStore->count('login:fail:user:' . $profileId),
            'A successful login must clear the per-user throttle counter.'
        );

        $row = $this->fetchLastAuditRow('auth.login.success');
        $this->assertNotNull($row, 'A successful login must be audited.');
        $this->assertSame($profileId, (int) $row['actor_user_id']);
        $this->assertSame(self::TENANT, (int) $row['tenant_id']);
    }

    /**
     * A direct single-membership login must record a new interactive session
     * row (WC-f-sessions-table). Kills a mutant that drops the recordSession()
     * call inside issueSessionForProfile().
     */
    public function testSuccessfulLoginRecordsNewSessionRow(): void
    {
        $profileId = $this->seedProfile('records-session@example.com', 'active');

        $response = $this->login('records-session@example.com');

        $this->assertSame(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sessions WHERE profile_id = ? AND tenant_id = ?');
        $stmt->execute([$profileId, self::TENANT]);
        $this->assertSame(
            1,
            (int) $stmt->fetchColumn(),
            'A direct single-membership login must record a new session row.'
        );
    }

    // =========================================================================
    // resolveTempToken(): cookie takes precedence over the Authorization header
    // =========================================================================

    /**
     * handle2fa() must resolve the temp token from the cookie FIRST, even when
     * a validly-signed alternative is also present in the Authorization
     * header. Kills a mutant that removes the cookie branch's early return,
     * which would silently fall through to the header instead.
     */
    public function testResolveTempTokenPrefersCookieOverAuthorizationHeader(): void
    {
        $profileId = $this->seedProfile('temp-precedence@example.com', 'active', twoFactorEnabled: true);
        $validHeaderTempToken = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => self::TENANT,
            'email'            => 'temp-precedence@example.com',
        ], 300, 'temp');

        // A syntactically invalid cookie token — if cookie precedence holds,
        // this is the one actually used (and rejected), regardless of the
        // valid header alternative.
        $_COOKIE['temp_auth_token'] = 'not-a-valid-jwt-at-all';

        $request = new Request(
            'POST',
            '/api/login/2fa',
            ['Authorization' => 'Bearer ' . $validHeaderTempToken],
            (string) json_encode(['code' => '123456'])
        );
        $response = $this->authHandler->handle2fa($request);

        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(
            'Invalid or expired temporary token',
            json_decode($response->getBody(), true)['error'] ?? null,
            'The invalid COOKIE temp token must be the one resolved (and rejected) even though a ' .
            'validly-signed header alternative exists — cookie takes precedence.'
        );
    }

    // =========================================================================
    // Self-service password change: token revocation (both auth modes)
    // =========================================================================

    /**
     * A COOKIE-mode password change must revoke BOTH the old access and
     * refresh jtis into revoked_tokens — not merely rely on the epoch bump
     * (which independently also rejects them, masking a dropped revocation
     * call in a test that only checks TokenValidator's verdict). Kills
     * mutants that drop the access-token entry from revokeCurrentSessionTokens()'s
     * array, or that remove its call from handleUpdateMe() entirely.
     */
    public function testCookieModePasswordChangeRevokesBothAccessAndRefreshJtis(): void
    {
        $profileId = $this->seedProfile('cookierevoke@example.com', 'active');
        $oldAccess = $this->jwtParser->create([
            'profile_id' => $profileId, 'active_tenant_id' => self::TENANT,
            'email' => 'cookierevoke@example.com', 'role' => 'user', 'token_epoch' => 0,
        ], 900, 'access');
        $oldRefresh = $this->jwtParser->create([
            'profile_id' => $profileId, 'active_tenant_id' => self::TENANT,
            'email' => 'cookierevoke@example.com', 'role' => 'user', 'token_epoch' => 0,
        ], 604800, 'refresh');
        $accessClaims = $this->jwtParser->parse($oldAccess);
        $refreshClaims = $this->jwtParser->parse($oldRefresh);
        $this->assertIsArray($accessClaims);
        $this->assertIsArray($refreshClaims);

        $_COOKIE['access_token'] = $oldAccess;
        $_COOKIE['refresh_token'] = $oldRefresh;

        $response = $this->authHandler->handleUpdateMe(new Request('PATCH', '/api/me', [], (string) json_encode([
            'password'         => 'brand-new-pass',
            'current_password' => self::PASSWORD,
        ])));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertTrue(
            $this->isRevoked((string) $accessClaims['jti']),
            'A cookie-mode password change must revoke the OLD access jti (not rely solely on the epoch bump).'
        );
        $this->assertTrue(
            $this->isRevoked((string) $refreshClaims['jti']),
            'A cookie-mode password change must revoke the OLD refresh jti.'
        );
    }

    /**
     * A TOKEN-mode password change must revoke the presented Bearer access
     * jti AND the body `refresh_token` field's jti. Kills a mutant that drops
     * the bearerToken() entry from the revokeTokens() array, or removes the
     * call entirely.
     */
    public function testTokenModePasswordChangeRevokesBearerAndBodyRefreshJtis(): void
    {
        $profileId = $this->seedProfile('tokenrevoke@example.com', 'active');
        $accessToken = $this->jwtParser->create([
            'profile_id' => $profileId, 'active_tenant_id' => self::TENANT,
            'email' => 'tokenrevoke@example.com', 'role' => 'user', 'token_epoch' => 0,
        ], 900, 'access');
        $refreshToken = $this->jwtParser->create([
            'profile_id' => $profileId, 'active_tenant_id' => self::TENANT,
            'email' => 'tokenrevoke@example.com', 'role' => 'user', 'token_epoch' => 0,
        ], 604800, 'refresh');
        $accessClaims = $this->jwtParser->parse($accessToken);
        $refreshClaims = $this->jwtParser->parse($refreshToken);
        $this->assertIsArray($accessClaims);
        $this->assertIsArray($refreshClaims);

        $request = new Request(
            'PATCH',
            '/api/me',
            ['Authorization' => 'Bearer ' . $accessToken, 'X-Auth-Mode' => 'token'],
            (string) json_encode([
                'password'         => 'brand-new-pass',
                'current_password' => self::PASSWORD,
                'refresh_token'    => $refreshToken,
            ])
        );
        $response = $this->authHandler->handleUpdateMe($request);

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $this->assertTrue(
            $this->isRevoked((string) $accessClaims['jti']),
            'A token-mode password change must revoke the presented BEARER access jti.'
        );
        $this->assertTrue(
            $this->isRevoked((string) $refreshClaims['jti']),
            'A token-mode password change must revoke the body refresh_token jti.'
        );
    }

    // =========================================================================
    // Refresh-token reuse detection audit
    // =========================================================================

    /**
     * Reusing an already-consumed refresh token must be logged as a distinct
     * security event (auth.refresh_token_reuse_detected). Kills a mutant that
     * drops that audit() call.
     */
    public function testRefreshTokenReuseIsAudited(): void
    {
        $profileId = $this->seedProfile('reuse-audit@example.com', 'active');
        $refreshToken = $this->jwtParser->create([
            'profile_id' => $profileId, 'active_tenant_id' => self::TENANT,
            'email' => 'reuse-audit@example.com', 'role' => 'user', 'token_epoch' => 0,
        ], 604800, 'refresh');

        // First use: succeeds and revokes this refresh jti.
        $_COOKIE['refresh_token'] = $refreshToken;
        $first = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        $this->assertSame(200, $first->getStatusCode());

        // Second use of the SAME (now-revoked) token: a reuse attempt.
        $_COOKIE['refresh_token'] = $refreshToken;
        $second = $this->authHandler->handleRefresh(new Request('POST', '/api/auth/refresh', []));
        $this->assertSame(401, $second->getStatusCode());

        $row = $this->fetchLastAuditRow('auth.refresh_token_reuse_detected');
        $this->assertNotNull($row, 'Reusing a consumed refresh token must be audited as a security event.');
        $this->assertSame($profileId, (int) $row['actor_user_id']);
        $this->assertSame(self::TENANT, (int) $row['tenant_id']);
    }

    // =========================================================================
    // Helpers
    // =========================================================================

    private function login(string $email, ?string $password = null): \Whity\Core\Response
    {
        $body = (string) json_encode(['email' => $email, 'password' => $password ?? self::PASSWORD]);
        $request = new Request('POST', '/api/login', [], $body);

        return $this->authHandler->handle($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchLastAuditRow(string $action): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM audit_log WHERE action = ? ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([$action]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    private function isRevoked(string $jti): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
        $stmt->execute([$jti]);

        return (bool) $stmt->fetchColumn();
    }

    private function seedProfile(string $email, string $status, bool $twoFactorEnabled = false): int
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, ?, ?, 0, 0, ?, datetime('now'), datetime('now'))"
        );
        $stmt->execute([explode('@', $email)[0], $hash, $twoFactorEnabled ? 1 : 0, $status]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, 1, 1, datetime('now'))"
        )->execute([$profileId, $email]);

        $roleStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'user'");
        $this->assertNotFalse($roleStmt);
        $roleId = (int) $roleStmt->fetchColumn();

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, self::TENANT, $roleId]);

        return $profileId;
    }
}
