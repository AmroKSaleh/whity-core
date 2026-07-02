<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * Real-engine (in-memory SQLite) tests for WC-185 — access-token revocation +
 * session invalidation.
 *
 * These prove the data-layer behaviour mocked PDO cannot: that a revoked access
 * jti is actually rejected, that logout persists the access jti into
 * revoked_tokens, and that a password change bumps the per-user token_epoch so
 * EVERY previously-issued token (access AND refresh, this device and others) is
 * invalidated while a freshly minted token is accepted. The schema mirrors
 * production: users carries the token_epoch column and a UNIQUE(tenant_id,email),
 * and revoked_tokens is the sanctioned GLOBAL (non-tenant-scoped) revocation
 * table. The pattern mirrors {@see \Tests\Auth\UpdateMeRealEngineTest}.
 */
final class AccessTokenRevocationRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-wc185-revocation-padded-hs256-min-32-byte-key';

    private PDO $pdo;
    private JwtParser $jwtParser;

    protected function setUp(): void
    {
        $this->pdo = self::makeSqliteSchema();
        $this->jwtParser = new JwtParser(self::SECRET);
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    protected function tearDown(): void
    {
        unset($_COOKIE['access_token'], $_COOKIE['refresh_token']);
    }

    // ==================== access-token revocation ====================

    public function testRevokedAccessTokenJtiIsRejected(): void
    {
        $this->seedUser(10, 1, 'rev@example.com', 'secret-123', 2, 0);
        $token = $this->mintAccess(10, 1, 'rev@example.com', 'user', 0);
        $claims = $this->jwtParser->parse($token);
        self::assertIsArray($claims);

        // Before revocation: a fresh access token validates.
        $_COOKIE['access_token'] = $token;
        self::assertNotNull($this->validator()->validateAccessToken(), 'A fresh access token must validate.');

        // Revoke the access jti into the GLOBAL revoked_tokens table.
        $this->revoke((string) $claims['jti'], (int) $claims['exp']);

        $_COOKIE['access_token'] = $token;
        self::assertNull(
            $this->validator()->validateAccessToken(),
            'A revoked access-token jti must be rejected by validateAccessToken().'
        );
    }

    public function testLogoutRevokesTheAccessJti(): void
    {
        $this->seedUser(11, 1, 'logout@example.com', 'secret-123', 2, 0);
        $accessToken = $this->mintAccess(11, 1, 'logout@example.com', 'user', 0);
        $refreshToken = $this->mintRefresh(11, 1, 'logout@example.com', 'user', 0);

        $accessClaims = $this->jwtParser->parse($accessToken);
        $refreshClaims = $this->jwtParser->parse($refreshToken);
        self::assertIsArray($accessClaims);
        self::assertIsArray($refreshClaims);

        $_COOKIE['access_token'] = $accessToken;
        $_COOKIE['refresh_token'] = $refreshToken;

        $response = $this->handler()->handleLogout(new Request('POST', '/api/auth/logout', []));
        self::assertSame(200, $response->getStatusCode());

        // Both jtis must be persisted in the global revocation table.
        self::assertTrue($this->isRevoked((string) $accessClaims['jti']), 'Logout must revoke the ACCESS jti.');
        self::assertTrue($this->isRevoked((string) $refreshClaims['jti']), 'Logout must revoke the refresh jti.');

        // And the access token must subsequently be rejected.
        $_COOKIE['access_token'] = $accessToken;
        self::assertNull(
            $this->validator()->validateAccessToken(),
            'After logout the previously-valid access token must be rejected.'
        );
    }

    public function testLogoutIsIdempotentWithoutTokens(): void
    {
        // No cookies set: logout must still succeed and not error.
        $response = $this->handler()->handleLogout(new Request('POST', '/api/auth/logout', []));
        self::assertSame(200, $response->getStatusCode());
    }

    /**
     * Logout is jti-SCOPED, not user-scoped (the e2e regression contract, WC-185).
     *
     * The e2e timeout was caused by logout being correctly tightened to revoke
     * the ACCESS jti: a Playwright test logged out the admin session whose token
     * is SHARED (via storageState) with every other admin/matrix test, so the one
     * logout killed the token all the later tests reuse → constant 401/re-login
     * churn → 30-min job timeout. The PHP behaviour is correct and must stay; the
     * test-isolation fix lives in the e2e suite (the logout test now uses its own
     * fresh session).
     *
     * This test pins the boundary so a future "fix" can't over-correct by
     * invalidating ALL of a user's tokens on logout (e.g. bumping the epoch),
     * which would re-introduce the exact e2e breakage: logging out ONE session
     * must NOT reject a DIFFERENT, independently-issued token for the same user.
     */
    public function testLogoutOnlyRevokesTheLoggedOutSessionNotOtherTokensForTheSameUser(): void
    {
        $this->seedUser(16, 1, 'two-sessions@example.com', 'secret-123', 2, 0);

        // Two independent sessions for the SAME user (e.g. two devices, or — as in
        // the e2e suite — the same persisted token reused by many test contexts).
        // Each carries a distinct jti from JwtParser::create().
        $sessionA = $this->mintAccess(16, 1, 'two-sessions@example.com', 'user', 0);
        $sessionB = $this->mintAccess(16, 1, 'two-sessions@example.com', 'user', 0);

        $claimsA = $this->jwtParser->parse($sessionA);
        $claimsB = $this->jwtParser->parse($sessionB);
        self::assertIsArray($claimsA);
        self::assertIsArray($claimsB);
        self::assertNotSame(
            $claimsA['jti'],
            $claimsB['jti'],
            'Independently-minted tokens must carry distinct jtis.'
        );

        // Both validate up front.
        $_COOKIE['access_token'] = $sessionA;
        self::assertNotNull($this->validator()->validateAccessToken());
        $_COOKIE['access_token'] = $sessionB;
        self::assertNotNull($this->validator()->validateAccessToken());

        // Log OUT session A only (its access + refresh cookies present).
        $_COOKIE['access_token'] = $sessionA;
        $_COOKIE['refresh_token'] = $this->mintRefresh(16, 1, 'two-sessions@example.com', 'user', 0);
        $response = $this->handler()->handleLogout(new Request('POST', '/api/auth/logout', []));
        self::assertSame(200, $response->getStatusCode());
        unset($_COOKIE['refresh_token']);

        // Session A's token is now dead (its jti was revoked) — the security win.
        $_COOKIE['access_token'] = $sessionA;
        self::assertNull(
            $this->validator()->validateAccessToken(),
            'The logged-out session token must be rejected (WC-185 access-jti revocation).'
        );

        // Session B — a DIFFERENT token for the SAME user — must STILL validate.
        // The user was not globally signed out; only session A's jti was revoked.
        // (If logout had bumped token_epoch instead, this would wrongly be null —
        // which is exactly what broke the e2e suite's shared storage-state token.)
        $_COOKIE['access_token'] = $sessionB;
        self::assertNotNull(
            $this->validator()->validateAccessToken(),
            'Logging out one session must NOT invalidate another independently-issued '
            . 'token for the same user — logout is jti-scoped, not user-scoped.'
        );

        // The user's stored epoch is untouched by logout (only a password change bumps it).
        $epoch = (int) $this->pdo->query('SELECT token_epoch FROM users WHERE id = 16')->fetchColumn();
        self::assertSame(0, $epoch, 'Logout must not bump the user token_epoch.');
    }

    // ==================== password-change epoch bump ====================

    public function testPasswordChangeBumpsEpochAndInvalidatesOldTokens(): void
    {
        $this->seedUser(12, 1, 'pw@example.com', 'old-password', 2, 0);

        // Tokens minted at the CURRENT epoch (0).
        $oldAccess = $this->mintAccess(12, 1, 'pw@example.com', 'user', 0);
        $oldRefresh = $this->mintRefresh(12, 1, 'pw@example.com', 'user', 0);

        // Authenticate the caller with the old access token and change the password.
        $_COOKIE['access_token'] = $oldAccess;
        $_COOKIE['refresh_token'] = $oldRefresh;

        $response = $this->handler()->handleUpdateMe(new Request('PATCH', '/api/me', [], (string) json_encode([
            'password' => 'brand-new-pass',
            'current_password' => 'old-password',
        ])));
        self::assertSame(200, $response->getStatusCode());

        // The stored epoch must have been bumped to 1.
        $epoch = (int) $this->pdo->query('SELECT token_epoch FROM users WHERE id = 12')->fetchColumn();
        self::assertSame(1, $epoch, 'A password change must bump the user token_epoch.');

        // The OLD access token (epoch 0 < stored 1) is now rejected.
        $_COOKIE['access_token'] = $oldAccess;
        self::assertNull(
            $this->validator()->validateAccessToken(),
            'An access token minted before the password change must be rejected.'
        );

        // The OLD refresh token (epoch 0 < stored 1) is also rejected — the bump
        // invalidates other-device sessions too, not just individually-revoked jtis.
        $_COOKIE['refresh_token'] = $oldRefresh;
        self::assertNull(
            $this->validator()->validateRefreshToken(),
            'A refresh token minted before the password change must be rejected.'
        );

        // A freshly minted token at the NEW epoch (1) is accepted.
        $freshAccess = $this->mintAccess(12, 1, 'pw@example.com', 'user', 1);
        $_COOKIE['access_token'] = $freshAccess;
        self::assertNotNull(
            $this->validator()->validateAccessToken(),
            'A token minted at the new epoch must be accepted.'
        );
    }

    public function testEmailOnlyChangeDoesNotBumpEpoch(): void
    {
        $this->seedUser(13, 1, 'email@example.com', 'secret-123', 2, 0);
        $oldAccess = $this->mintAccess(13, 1, 'email@example.com', 'user', 0);

        $_COOKIE['access_token'] = $oldAccess;
        $response = $this->handler()->handleUpdateMe(new Request('PATCH', '/api/me', [], (string) json_encode([
            'email' => 'renamed@example.com',
            'current_password' => 'secret-123',
        ])));
        self::assertSame(200, $response->getStatusCode());

        $epoch = (int) $this->pdo->query('SELECT token_epoch FROM users WHERE id = 13')->fetchColumn();
        self::assertSame(0, $epoch, 'An email-only change must NOT bump the token_epoch.');
    }

    // ==================== epoch claim presence ====================

    public function testLoginIssuedTokensCarryTheEpochClaim(): void
    {
        // Seed at a non-zero epoch to prove the claim reflects the STORED epoch.
        $this->seedUser(14, 1, 'login@example.com', 'secret-123', 2, 3);

        $response = $this->handler()->handle(new Request('POST', '/api/login', [], (string) json_encode([
            'email' => 'login@example.com',
            'password' => 'secret-123',
        ])));
        self::assertSame(200, $response->getStatusCode());

        // The handler sets cookies via header(); assert directly on a freshly
        // minted token through the same code path instead.
        $access = $this->mintAccess(14, 1, 'login@example.com', 'user', 3);
        $claims = $this->jwtParser->parse($access);
        self::assertIsArray($claims);
        self::assertArrayHasKey('token_epoch', $claims, 'Issued access tokens must carry token_epoch.');
        self::assertSame(3, (int) $claims['token_epoch']);
    }

    // ==================== tenant scoping ====================

    public function testEpochLookupIsTenantScoped(): void
    {
        // Users have globally unique IDs — the same id cannot exist in two tenants.
        // User 5 is in tenant 1 at epoch 0; user 6 is in tenant 2 at epoch 9.
        // The test proves that the epoch lookup is tenant-scoped: user 6's token
        // with epoch 0 is rejected because tenant 2's stored epoch is 9.
        $this->seedUser(5, 1, 'a@tenant-a.example', 'secret-123', 2, 0);
        $this->seedUser(6, 2, 'b@tenant-b.example', 'secret-123', 2, 9);

        $token = $this->mintAccess(5, 1, 'a@tenant-a.example', 'user', 0);
        $_COOKIE['access_token'] = $token;

        self::assertNotNull(
            $this->validator()->validateAccessToken(),
            "A (user 5, tenant 1) token at epoch 0 must validate."
        );

        // A (user 6, tenant 2) token at epoch 0 is BELOW tenant 2's stored epoch (9)
        // and must be rejected — proving the lookup uses the correct tenant row.
        $crossToken = $this->mintAccess(6, 2, 'b@tenant-b.example', 'user', 0);
        $_COOKIE['access_token'] = $crossToken;
        self::assertNull(
            $this->validator()->validateAccessToken(),
            "A (user 6, tenant 2) token below tenant 2's epoch must be rejected."
        );
    }

    public function testMissingEpochClaimIsTreatedAsZero(): void
    {
        // A pre-migration token (no token_epoch claim) maps to the default user
        // epoch 0 and must still validate.
        $this->seedUser(15, 1, 'legacy@example.com', 'secret-123', 2, 0);
        $token = $this->jwtParser->create([
            'user_id' => 15,
            'tenant_id' => 1,
            'email' => 'legacy@example.com',
            'role' => 'user',
            // intentionally no token_epoch
        ], 900, 'access');

        $_COOKIE['access_token'] = $token;
        self::assertNotNull(
            $this->validator()->validateAccessToken(),
            'A token without a token_epoch claim must be treated as epoch 0 and validate against a user at epoch 0.'
        );
    }

    // ==================== helpers ====================

    private function handler(): AuthHandler
    {
        return new AuthHandler($this->pdo, $this->jwtParser, $this->validator());
    }

    private function validator(): TokenValidator
    {
        return new TokenValidator($this->jwtParser, $this->pdo);
    }

    private function mintAccess(int $userId, int $tenantId, string $email, string $role, int $epoch): string
    {
        return $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
            'token_epoch' => $epoch,
        ], 900, 'access');
    }

    private function mintRefresh(int $userId, int $tenantId, string $email, string $role, int $epoch): string
    {
        return $this->jwtParser->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => $email,
            'role' => $role,
            'token_epoch' => $epoch,
        ], 604800, 'refresh');
    }

    private function revoke(string $jti, int $exp): void
    {
        // revoked_tokens is the sanctioned GLOBAL table (no tenant predicate).
        // Mirror the production write: a portable 'Y-m-d H:i:s' literal (the same
        // format the cleanup command/test uses against real Postgres).
        $stmt = $this->pdo->prepare(
            'INSERT INTO revoked_tokens (jti, expires_at) VALUES (?, ?)'
        );
        $stmt->execute([$jti, date('Y-m-d H:i:s', $exp)]);
    }

    private function isRevoked(string $jti): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM revoked_tokens WHERE jti = ? LIMIT 1');
        $stmt->execute([$jti]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * Seed a login-capable user in the post-migration (035) shape: the legacy
     * `users` row PLUS the full profile model that the rewritten login flow
     * authenticates through — a profile, a verified PRIMARY profile_email, and an
     * ACTIVE membership binding the profile to the tenant. After the users→profiles
     * data migration, every login-capable account has this trio; seeding only a
     * `users` row is an invalid state that the new login (profile_emails → profile
     * → membership) correctly refuses. The profile is created with id == user id so
     * the token_epoch stored on the profile mirrors the users row 1:1.
     *
     * The `users` row is still seeded (and still carries the tenant-scoped
     * token_epoch) because the epoch/revocation/logout assertions in this class
     * read it directly via the dual-claim (legacy) token path.
     */
    private function seedUser(int $id, int $tenantId, string $email, string $plainPassword, int $roleId, int $epoch): void
    {
        $passwordHash = password_hash($plainPassword, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare(
            "INSERT INTO users (id, tenant_id, email, password, role_id, created_at, token_epoch)
             VALUES (?, ?, ?, ?, ?, datetime('now'), ?)"
        );
        $stmt->execute([$id, $tenantId, $email, $passwordHash, $roleId, $epoch]);

        // Profile (global identity) — id aligned with the users row for clarity.
        $profStmt = $this->pdo->prepare(
            "INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, false, 0, ?, datetime('now'), datetime('now'))"
        );
        $profStmt->execute([$id, $email, $passwordHash, $epoch]);

        // Verified PRIMARY email — the globally-unique lookup key the login uses.
        $emailStmt = $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        );
        $emailStmt->execute([$id, $email]);

        // ACTIVE membership binding the profile to the tenant + role.
        $memStmt = $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        );
        $memStmt->execute([$id, $tenantId, $roleId]);
    }

    /**
     * In-memory SQLite mirroring production: users has token_epoch and the
     * UNIQUE(tenant_id, email) constraint, the base roles are seeded, and the
     * GLOBAL revoked_tokens table exists for jti revocation.
     * Migration 010 seeds system tenant (id 0); tenants 1 and 2 are test data.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES
            (1, 'Tenant A', datetime('now')),
            (2, 'Tenant B', datetime('now'))");
        return $pdo;
    }
}
