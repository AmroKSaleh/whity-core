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
 * Real-engine (in-memory SQLite) tests for the self-service profile update
 * endpoint {@see AuthHandler::handleUpdateMe()} (PATCH /api/me, WC-64).
 *
 * WC-idcut-E: post-cutover. The endpoint reads/writes `profiles` and
 * `profile_emails` (not `users`). Authentication is via post-cutover access
 * tokens carrying {profile_id, active_tenant_id}. Epoch bumps go to
 * `profiles.token_epoch`.
 *
 * Covered invariants:
 *  - self-update of email persists (profile_emails row);
 *  - self-update of password persists a verifiable bcrypt hash (profiles.password_hash);
 *  - password change bumps profiles.token_epoch;
 *  - the endpoint can only ever edit the authenticated profile;
 *  - a wrong/absent current_password is rejected with no write;
 *  - email uniqueness is enforced globally (profile_emails has a UNIQUE(email)).
 */
final class UpdateMeRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-update-me-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

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

    // ==================== self-update persists ====================

    public function testSelfEmailUpdatePersistsScopedToProfile(): void
    {
        $profileId = $this->seedProfile('old@example.com', 'secret-123');
        $this->authenticateAs($profileId, self::TENANT, 'old@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'new@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(200, $response->getStatusCode());

        // The profile_emails row must carry the new address.
        $email = $this->pdo->prepare('SELECT email FROM profile_emails WHERE profile_id = ? AND is_primary = true');
        $email->execute([$profileId]);
        $this->assertSame('new@example.com', $email->fetchColumn(), 'The new email must be persisted in profile_emails.');

        $data = json_decode($response->getBody(), true)['user'];
        $this->assertSame('new@example.com', $data['email']);
        $this->assertSame($profileId, $data['id'], 'id in response must equal profileId (stable).');
        $this->assertArrayNotHasKey('password', $data, 'The password hash must never leak.');
    }

    public function testSelfPasswordUpdatePersistsVerifiableBcryptHash(): void
    {
        $profileId = $this->seedProfile('pw@example.com', 'old-password');
        $this->authenticateAs($profileId, self::TENANT, 'pw@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest([
                'password'         => 'brand-new-pass',
                'current_password' => 'old-password',
            ])
        );

        $this->assertSame(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT password_hash FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);
        $hash = (string) $stmt->fetchColumn();
        $this->assertTrue(
            password_verify('brand-new-pass', $hash),
            'The new password must be persisted as a verifiable bcrypt hash in profiles.'
        );
        $this->assertFalse(
            password_verify('old-password', $hash),
            'The old password must no longer verify.'
        );
        $this->assertNotSame('brand-new-pass', $hash);
    }

    public function testPasswordChangeEpochBumpsProfilesTokenEpoch(): void
    {
        $profileId = $this->seedProfile('epoch@example.com', 'old-pw');
        $this->authenticateAs($profileId, self::TENANT, 'epoch@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['password' => 'new-password-123', 'current_password' => 'old-pw'])
        );

        $this->assertSame(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT token_epoch FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);
        $this->assertSame(1, (int) $stmt->fetchColumn(), 'A password change must bump profiles.token_epoch to 1.');
    }

    public function testEmailOnlyChangeDoesNotBumpEpoch(): void
    {
        $profileId = $this->seedProfile('emailonly@example.com', 'secret-123');
        $this->authenticateAs($profileId, self::TENANT, 'emailonly@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'newemail@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(200, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT token_epoch FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);
        $this->assertSame(0, (int) $stmt->fetchColumn(), 'An email-only change must NOT bump the token_epoch.');
    }

    // ==================== token-mode password change (#392) ====================

    /**
     * #392: a token-mode client (X-Auth-Mode: token, no cookie jar) that
     * changes its password must get a FRESH token pair in the response body
     * instead of Set-Cookie — its presented bearer token is revoked by the
     * epoch bump the moment the change lands, so without this it would be
     * locked out until a full re-login.
     */
    public function testTokenModePasswordChangeReturnsFreshTokensInBody(): void
    {
        $profileId = $this->seedProfile('tokenmode@example.com', 'old-password');
        $accessToken = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => self::TENANT,
            'email'            => 'tokenmode@example.com',
            'role'             => 'user',
            'token_epoch'      => 0,
        ], 900, 'access');

        $request = new Request(
            'PATCH',
            '/api/me',
            ['Authorization' => 'Bearer ' . $accessToken, 'X-Auth-Mode' => 'token'],
            (string) json_encode([
                'password'         => 'brand-new-pass',
                'current_password' => 'old-password',
            ])
        );

        $response = $this->handler()->handleUpdateMe($request);

        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertArrayHasKey('access_token', $data, 'token mode must return a fresh access_token in the body');
        $this->assertArrayHasKey('refresh_token', $data, 'token mode must return a fresh refresh_token in the body');
        $this->assertSame('Bearer', $data['token_type'] ?? null);
        $this->assertSame(900, $data['expires_in'] ?? null);

        // The fresh access token carries the bumped epoch and authenticates.
        $newClaims = $this->jwtParser->parse($data['access_token']);
        $this->assertNotNull($newClaims);
        $this->assertSame(1, $newClaims['token_epoch'] ?? null, 'fresh token must carry the post-change epoch');

        // The OLD presented bearer token must now be dead (epoch-checked).
        $oldClaims = (new TokenValidator($this->jwtParser, $this->pdo))->validateAccessTokenFromBearer($accessToken);
        $this->assertNull($oldClaims, 'the presented (pre-change) bearer token must be rejected after the epoch bump');
    }

    // ==================== self-only authorization ====================

    public function testCannotUpdateAnotherProfileEvenWhenIdIsSentInBody(): void
    {
        $actorId  = $this->seedProfile('actor@example.com', 'secret-123');
        $victimId = $this->seedProfile('victim@example.com', 'victim-pass');
        $this->authenticateAs($actorId, self::TENANT, 'actor@example.com');

        // The endpoint takes no id from the body; an injected `id`/`user_id` must
        // be ignored and only the authenticated profile may ever change.
        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest([
                'id'               => $victimId,
                'user_id'          => $victimId,
                'email'            => 'hijacked@example.com',
                'current_password' => 'secret-123',
            ])
        );

        $this->assertSame(200, $response->getStatusCode());

        // The victim's email is untouched.
        $stmt = $this->pdo->prepare('SELECT email FROM profile_emails WHERE profile_id = ? AND is_primary = true');
        $stmt->execute([$victimId]);
        $this->assertSame(
            'victim@example.com',
            $stmt->fetchColumn(),
            'A self-service update must never edit another profile.'
        );

        // The actor's email changed (hijacked@example.com).
        $stmt->execute([$actorId]);
        $this->assertSame(
            'hijacked@example.com',
            $stmt->fetchColumn(),
            'Only the authenticated profile is updated.'
        );
    }

    public function testUnauthenticatedRequestIsRejected(): void
    {
        // No access_token cookie set.
        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'x@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== current-password gate ====================

    public function testWrongCurrentPasswordIsRejectedWithNoWrite(): void
    {
        $profileId = $this->seedProfile('guard@example.com', 'correct-pass');
        $this->authenticateAs($profileId, self::TENANT, 'guard@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'changed@example.com', 'current_password' => 'WRONG'])
        );

        $this->assertSame(401, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT email FROM profile_emails WHERE profile_id = ? AND is_primary = true');
        $stmt->execute([$profileId]);
        $this->assertSame(
            'guard@example.com',
            $stmt->fetchColumn(),
            'A wrong current password must not persist any change.'
        );
    }

    public function testMissingCurrentPasswordIsRejected(): void
    {
        $profileId = $this->seedProfile('nocur@example.com', 'correct-pass');
        $this->authenticateAs($profileId, self::TENANT, 'nocur@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'changed@example.com'])
        );

        $this->assertSame(401, $response->getStatusCode());
    }

    // ==================== validation ====================

    public function testEmailUniquenessIsGloballyEnforced(): void
    {
        $profileA = $this->seedProfile('taken@example.com', 'pw1');
        $profileB = $this->seedProfile('me@example.com', 'secret-123');
        $this->authenticateAs($profileB, self::TENANT, 'me@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'taken@example.com', 'current_password' => 'secret-123'])
        );

        $this->assertSame(409, $response->getStatusCode());

        $stmt = $this->pdo->prepare('SELECT email FROM profile_emails WHERE profile_id = ? AND is_primary = true');
        $stmt->execute([$profileB]);
        $this->assertSame(
            'me@example.com',
            $stmt->fetchColumn(),
            'A duplicate email must be rejected without change.'
        );
    }

    public function testInvalidEmailFormatIsRejected(): void
    {
        $profileId = $this->seedProfile('fmt@example.com', 'secret-123');
        $this->authenticateAs($profileId, self::TENANT, 'fmt@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['email' => 'not-an-email', 'current_password' => 'secret-123'])
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    public function testTooShortPasswordIsRejected(): void
    {
        $profileId = $this->seedProfile('short@example.com', 'secret-123');
        $this->authenticateAs($profileId, self::TENANT, 'short@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['password' => 'short', 'current_password' => 'secret-123'])
        );

        $this->assertSame(400, $response->getStatusCode());

        // The original password still verifies.
        $stmt = $this->pdo->prepare('SELECT password_hash FROM profiles WHERE id = ?');
        $stmt->execute([$profileId]);
        $this->assertTrue(password_verify('secret-123', (string) $stmt->fetchColumn()));
    }

    public function testNoFieldsProvidedIsRejected(): void
    {
        $profileId = $this->seedProfile('empty@example.com', 'secret-123');
        $this->authenticateAs($profileId, self::TENANT, 'empty@example.com');

        $response = $this->handler()->handleUpdateMe(
            $this->jsonRequest(['current_password' => 'secret-123'])
        );

        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== Helpers ====================

    private function handler(): AuthHandler
    {
        return new AuthHandler($this->pdo, $this->jwtParser, new TokenValidator($this->jwtParser, $this->pdo));
    }

    /**
     * Place a post-cutover access-token cookie for the given profile.
     */
    private function authenticateAs(int $profileId, int $tenantId, string $email): void
    {
        $_COOKIE['access_token'] = $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => $email,
            'role'             => 'user',
            'token_epoch'      => 0,
        ], 900, 'access');
    }

    /**
     * @param array<string, mixed> $body
     */
    private function jsonRequest(array $body): Request
    {
        return new Request('PATCH', '/api/me', [], (string) json_encode($body));
    }

    /**
     * Seed a post-cutover identity: profile + profile_email + active membership.
     * Returns the profile id.
     */
    private function seedProfile(string $email, string $plainPassword, int $epoch = 0): int
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
             VALUES (?, ?, 1, 'active', datetime('now'))"
        )->execute([$profileId, self::TENANT]);

        return $profileId;
    }

    private static function makeSqliteSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make();
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Tenant A', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");
        return $pdo;
    }
}
