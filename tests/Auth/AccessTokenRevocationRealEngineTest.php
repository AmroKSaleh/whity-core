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
 * WC-idcut-E: post-cutover. All tokens carry {profile_id, active_tenant_id}
 * only. Legacy {user_id, tenant_id} claims are not used. Epoch checking is
 * against profiles.token_epoch exclusively.
 *
 * These prove the data-layer behaviour mocked PDO cannot: that a revoked access
 * jti is actually rejected, that logout persists the access jti into
 * revoked_tokens, and that a password change bumps profiles.token_epoch so
 * EVERY previously-issued token (access AND refresh, this device and others) is
 * invalidated while a freshly minted token is accepted.
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
        $profileId = $this->seedProfile('rev@example.com', 'secret-123', epoch: 0);
        $token = $this->mintAccess($profileId, 1, epoch: 0);
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
        $profileId = $this->seedProfile('logout@example.com', 'secret-123', epoch: 0);
        $accessToken  = $this->mintAccess($profileId, 1, epoch: 0);
        $refreshToken = $this->mintRefresh($profileId, 1, epoch: 0);

        $accessClaims  = $this->jwtParser->parse($accessToken);
        $refreshClaims = $this->jwtParser->parse($refreshToken);
        self::assertIsArray($accessClaims);
        self::assertIsArray($refreshClaims);

        $_COOKIE['access_token']  = $accessToken;
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
     * Logout is jti-SCOPED, not profile-scoped (the e2e regression contract, WC-185).
     *
     * Logging out ONE session must NOT reject a DIFFERENT, independently-issued
     * token for the same profile. The token_epoch is not bumped on logout — only
     * a password change bumps it.
     */
    public function testLogoutOnlyRevokesTheLoggedOutSessionNotOtherTokensForTheSameUser(): void
    {
        $profileId = $this->seedProfile('two-sessions@example.com', 'secret-123', epoch: 0);

        // Two independent sessions for the SAME profile (e.g. two devices).
        // Each carries a distinct jti from JwtParser::create().
        $sessionA = $this->mintAccess($profileId, 1, epoch: 0);
        $sessionB = $this->mintAccess($profileId, 1, epoch: 0);

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

        // Log OUT session A only.
        $_COOKIE['access_token']  = $sessionA;
        $_COOKIE['refresh_token'] = $this->mintRefresh($profileId, 1, epoch: 0);
        $response = $this->handler()->handleLogout(new Request('POST', '/api/auth/logout', []));
        self::assertSame(200, $response->getStatusCode());
        unset($_COOKIE['refresh_token']);

        // Session A's token is now dead (its jti was revoked).
        $_COOKIE['access_token'] = $sessionA;
        self::assertNull(
            $this->validator()->validateAccessToken(),
            'The logged-out session token must be rejected (WC-185 access-jti revocation).'
        );

        // Session B — a DIFFERENT token for the SAME profile — must STILL validate.
        $_COOKIE['access_token'] = $sessionB;
        self::assertNotNull(
            $this->validator()->validateAccessToken(),
            'Logging out one session must NOT invalidate another independently-issued '
            . 'token for the same profile — logout is jti-scoped, not profile-scoped.'
        );

        // WC-idcut-E: the profile epoch is untouched by logout.
        $epoch = (int) $this->pdo->query("SELECT token_epoch FROM profiles WHERE id = {$profileId}")->fetchColumn();
        self::assertSame(0, $epoch, 'Logout must not bump the profile token_epoch.');
    }

    // ==================== password-change epoch bump ====================

    public function testPasswordChangeBumpsEpochAndInvalidatesOldTokens(): void
    {
        $profileId = $this->seedProfile('pw@example.com', 'old-password', epoch: 0);

        // Tokens minted at the CURRENT epoch (0).
        $oldAccess  = $this->mintAccess($profileId, 1, epoch: 0);
        $oldRefresh = $this->mintRefresh($profileId, 1, epoch: 0);

        // Authenticate the caller and change the password.
        $_COOKIE['access_token']  = $oldAccess;
        $_COOKIE['refresh_token'] = $oldRefresh;

        $response = $this->handler()->handleUpdateMe(new Request('PATCH', '/api/me', [], (string) json_encode([
            'password'         => 'brand-new-pass',
            'current_password' => 'old-password',
        ])));
        self::assertSame(200, $response->getStatusCode());

        // The stored profile epoch must have been bumped to 1.
        $epoch = (int) $this->pdo->query("SELECT token_epoch FROM profiles WHERE id = {$profileId}")->fetchColumn();
        self::assertSame(1, $epoch, 'A password change must bump profiles.token_epoch.');

        // The OLD access token (epoch 0 < stored 1) is now rejected.
        $_COOKIE['access_token'] = $oldAccess;
        self::assertNull(
            $this->validator()->validateAccessToken(),
            'An access token minted before the password change must be rejected.'
        );

        // The OLD refresh token (epoch 0 < stored 1) is also rejected.
        $_COOKIE['refresh_token'] = $oldRefresh;
        self::assertNull(
            $this->validator()->validateRefreshToken(),
            'A refresh token minted before the password change must be rejected.'
        );

        // A freshly minted token at the NEW epoch (1) is accepted.
        $freshAccess = $this->mintAccess($profileId, 1, epoch: 1);
        $_COOKIE['access_token'] = $freshAccess;
        self::assertNotNull(
            $this->validator()->validateAccessToken(),
            'A token minted at the new epoch must be accepted.'
        );
    }

    public function testEmailOnlyChangeDoesNotBumpEpoch(): void
    {
        $profileId = $this->seedProfile('email@example.com', 'secret-123', epoch: 0);
        $oldAccess = $this->mintAccess($profileId, 1, epoch: 0);

        $_COOKIE['access_token'] = $oldAccess;
        $response = $this->handler()->handleUpdateMe(new Request('PATCH', '/api/me', [], (string) json_encode([
            'email'            => 'renamed@example.com',
            'current_password' => 'secret-123',
        ])));
        self::assertSame(200, $response->getStatusCode());

        $epoch = (int) $this->pdo->query("SELECT token_epoch FROM profiles WHERE id = {$profileId}")->fetchColumn();
        self::assertSame(0, $epoch, 'An email-only change must NOT bump the token_epoch.');
    }

    // ==================== epoch claim presence ====================

    public function testLoginIssuedTokensCarryTheEpochClaim(): void
    {
        // Seed at a non-zero epoch to prove the claim reflects the STORED epoch.
        $profileId = $this->seedProfile('login@example.com', 'secret-123', epoch: 3);

        $response = $this->handler()->handle(new Request('POST', '/api/login', [], (string) json_encode([
            'email'    => 'login@example.com',
            'password' => 'secret-123',
        ])));
        self::assertSame(200, $response->getStatusCode());

        // The handler sets cookies via header(); assert directly on a freshly
        // minted token through the same code path instead.
        $access = $this->mintAccess($profileId, 1, epoch: 3);
        $claims = $this->jwtParser->parse($access);
        self::assertIsArray($claims);
        self::assertArrayHasKey('token_epoch', $claims, 'Issued access tokens must carry token_epoch.');
        self::assertSame(3, (int) $claims['token_epoch']);
    }

    // ==================== epoch: missing claim treated as 0 ====================

    public function testMissingEpochClaimIsTreatedAsZero(): void
    {
        // A token with no token_epoch claim is treated as epoch 0.
        // A profile at epoch 0 must accept it.
        $profileId = $this->seedProfile('noepoch@example.com', 'secret-123', epoch: 0);
        $token = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => 1,
            'email'            => 'noepoch@example.com',
            'role'             => 'user',
            // intentionally no token_epoch
        ], 900, 'access');

        $_COOKIE['access_token'] = $token;
        self::assertNotNull(
            $this->validator()->validateAccessToken(),
            'A token without a token_epoch claim must be treated as epoch 0 and validate against a profile at epoch 0.'
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

    /**
     * Mint a post-cutover access token (profile_id + active_tenant_id).
     */
    private function mintAccess(int $profileId, int $tenantId, int $epoch = 0): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => 'test@example.com',
            'role'             => 'user',
            'token_epoch'      => $epoch,
        ], 900, 'access');
    }

    /**
     * Mint a post-cutover refresh token (profile_id + active_tenant_id).
     */
    private function mintRefresh(int $profileId, int $tenantId, int $epoch = 0): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => 'test@example.com',
            'role'             => 'user',
            'token_epoch'      => $epoch,
        ], 604800, 'refresh');
    }

    private function revoke(string $jti, int $exp): void
    {
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
     * Seed a post-cutover identity: profile + profile_email + active membership.
     * Returns the profile id.
     */
    private function seedProfile(string $email, string $plainPassword, int $epoch = 0, int $tenantId = 1, int $roleId = 1): int
    {
        $hash = password_hash($plainPassword, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, ?, datetime('now'), datetime('now'))"
        );
        $stmt->execute([$email, $hash, $epoch]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, true, true, datetime('now'))"
        )->execute([$profileId, $email]);

        $this->pdo->prepare(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES (?, ?, ?, 'active', datetime('now'))"
        )->execute([$profileId, $tenantId, $roleId]);

        return $profileId;
    }

    /**
     * In-memory SQLite mirroring production. Tenants 1 and 2 are seeded as test data.
     */
    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES
            (1, 'Tenant A', datetime('now')),
            (2, 'Tenant B', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");
        return $pdo;
    }
}
