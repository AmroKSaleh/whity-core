<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\DeviceApiHandler;
use Whity\Auth\AuthHandler;
use Whity\Auth\DeviceCredentialService;
use Whity\Auth\JwtParser;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * Real-engine (in-memory SQLite) tests for device/client registration + the
 * long-lived per-device credential model with per-device revocation
 * (WC-b-device-tokens). The same handler/service/validator SQL runs on the
 * postgres-integration CI job.
 *
 * Proves the end-to-end contract KeyHub codes against:
 *   - enroll a device (session-authenticated) → get a long-lived credential;
 *   - the credential is type='device'/aud='device', registered in `devices`;
 *   - exchange the credential → a fresh, epoch-current access session;
 *   - revoke the device → the credential no longer validates or exchanges;
 *   - revocation + listing are ownership-scoped to the caller's profile;
 *   - the device credential is NOT usable as an access token, and a session
 *     access token is NOT usable at the exchange endpoint (type isolation);
 *   - a validly-signed but unregistered device token is rejected.
 */
final class DeviceTokensRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-device-tokens-padded-hs256-min-32-byte-key';

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

    // ==================== registration ====================

    public function testRegisterEnrollsDeviceAndReturnsDeviceTypeCredential(): void
    {
        $profileId = $this->seedProfile('owner@example.com');
        $res = $this->deviceHandler()->register(
            $this->bearerReq('POST', '/api/devices', $this->mintAccess($profileId, 1), [
                'name' => 'Amro KiCad', 'platform' => 'windows', 'fingerprint' => 'ab:cd',
            ])
        );

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('credential', $data);
        self::assertGreaterThan(0, (int) $data['id']);

        // The credential is a type='device'/aud='device' JWT, registered in `devices`.
        $claims = $this->jwtParser->parse((string) $data['credential']);
        self::assertIsArray($claims);
        self::assertSame('device', $claims['type']);
        self::assertSame('device', $claims['aud']);
        self::assertSame($profileId, $claims['profile_id']);

        self::assertSame(
            1,
            (int) $this->pdo->query("SELECT COUNT(*) FROM devices WHERE jti = " . $this->pdo->quote((string) $claims['jti']))->fetchColumn()
        );

        // And it validates as a device credential.
        self::assertNotNull($this->validator()->validateDeviceToken((string) $data['credential']));
    }

    public function testRegisterRejectsUnknownPlatformAndOverLongName(): void
    {
        $profileId = $this->seedProfile('owner@example.com');
        $access = $this->mintAccess($profileId, 1);

        $badPlatform = $this->deviceHandler()->register(
            $this->bearerReq('POST', '/api/devices', $access, ['name' => 'x', 'platform' => 'toaster'])
        );
        self::assertSame(422, $badPlatform->getStatusCode());

        $longName = $this->deviceHandler()->register(
            $this->bearerReq('POST', '/api/devices', $access, ['name' => str_repeat('a', 256), 'platform' => 'linux'])
        );
        self::assertSame(422, $longName->getStatusCode());
    }

    public function testRegisterRequiresAuthentication(): void
    {
        $res = $this->deviceHandler()->register(
            new Request('POST', '/api/devices', [], (string) json_encode(['name' => 'x', 'platform' => 'linux']))
        );
        self::assertSame(401, $res->getStatusCode());
    }

    // ==================== exchange ====================

    public function testExchangeMintsEpochCurrentAccessSession(): void
    {
        $profileId = $this->seedProfile('owner@example.com', epoch: 2);
        $credential = $this->enroll($profileId);

        $res = $this->authHandler()->handleDeviceTokenExchange(
            new Request('POST', '/api/devices/token', ['Authorization' => 'Bearer ' . $credential])
        );
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $data = json_decode($res->getBody(), true);
        self::assertIsArray($data);
        self::assertArrayHasKey('access_token', $data);

        // The minted access token is a REAL, epoch-current session token.
        $accessClaims = $this->validator()->validateAccessTokenFromBearer((string) $data['access_token']);
        self::assertNotNull($accessClaims, 'The exchanged access token must validate as a session.');
        self::assertSame($profileId, $accessClaims['profile_id']);
        self::assertSame(2, (int) $accessClaims['token_epoch'], 'Access token must carry the CURRENT profile epoch.');

        // last_seen_at was recorded.
        $seen = $this->pdo->query("SELECT last_seen_at FROM devices WHERE profile_id = {$profileId}")->fetchColumn();
        self::assertNotNull($seen);
    }

    public function testExchangeRejectsASessionAccessToken(): void
    {
        // A normal access token (type='access') must NOT be exchangeable — only
        // a type='device' credential is accepted at the device-token endpoint.
        $profileId = $this->seedProfile('owner@example.com');
        $access = $this->mintAccess($profileId, 1);

        $res = $this->authHandler()->handleDeviceTokenExchange(
            new Request('POST', '/api/devices/token', ['Authorization' => 'Bearer ' . $access])
        );
        self::assertSame(401, $res->getStatusCode());
    }

    public function testDeviceCredentialIsNotUsableAsAnAccessToken(): void
    {
        $profileId = $this->seedProfile('owner@example.com');
        $credential = $this->enroll($profileId);

        // type='device' must be rejected everywhere an access token is expected.
        self::assertNull($this->validator()->validateAccessTokenFromBearer($credential));
    }

    public function testValidlySignedButUnregisteredDeviceTokenIsRejected(): void
    {
        $profileId = $this->seedProfile('owner@example.com');
        // Correct signature + type/aud, but never inserted into `devices`.
        $forged = $this->jwtParser->create([
            'profile_id' => $profileId, 'active_tenant_id' => 1, 'aud' => 'device', 'email' => 'owner@example.com',
        ], 3600, 'device');

        self::assertNull($this->validator()->validateDeviceToken($forged));
    }

    public function testPasswordChangeEpochBumpInvalidatesDeviceCredential(): void
    {
        // Device credentials are epoch-bound: bumping the profile epoch (which a
        // password change does) invalidates the credential and blocks exchange —
        // closing the "stolen access token → 90-day persistence that survives a
        // password change" laundering vector.
        $profileId = $this->seedProfile('owner@example.com', epoch: 0);
        $credential = $this->enroll($profileId);
        self::assertNotNull($this->validator()->validateDeviceToken($credential), 'Fresh credential validates.');

        // Simulate the password-change epoch bump.
        $this->pdo->exec("UPDATE profiles SET token_epoch = 1 WHERE id = {$profileId}");

        self::assertNull(
            $this->validator()->validateDeviceToken($credential),
            'A device credential minted before the epoch bump must be rejected.'
        );
        $exchange = $this->authHandler()->handleDeviceTokenExchange(
            new Request('POST', '/api/devices/token', ['Authorization' => 'Bearer ' . $credential])
        );
        self::assertSame(401, $exchange->getStatusCode(), 'Exchange must fail after the epoch bump.');
    }

    // ==================== list + revoke ====================

    public function testListShowsEnrolledDevice(): void
    {
        $profileId = $this->seedProfile('owner@example.com');
        $this->enroll($profileId, name: 'Studio Desktop', platform: 'macos');

        $res = $this->deviceHandler()->list($this->bearerReq('GET', '/api/devices', $this->mintAccess($profileId, 1)));
        self::assertSame(200, $res->getStatusCode());
        $devices = json_decode($res->getBody(), true)['devices'];
        self::assertCount(1, $devices);
        self::assertSame('Studio Desktop', $devices[0]['name']);
        self::assertSame('macos', $devices[0]['platform']);
    }

    public function testRevokeInvalidatesCredentialAndExchange(): void
    {
        $profileId = $this->seedProfile('owner@example.com');
        $credential = $this->enroll($profileId);
        $deviceId = (int) $this->pdo->query("SELECT id FROM devices WHERE profile_id = {$profileId}")->fetchColumn();

        $revoke = $this->deviceHandler()->revoke(
            $this->bearerReq('DELETE', "/api/devices/{$deviceId}", $this->mintAccess($profileId, 1)),
            ['id' => (string) $deviceId]
        );
        self::assertSame(204, $revoke->getStatusCode());

        // The credential no longer validates, and can no longer be exchanged.
        self::assertNull($this->validator()->validateDeviceToken($credential));
        $exchange = $this->authHandler()->handleDeviceTokenExchange(
            new Request('POST', '/api/devices/token', ['Authorization' => 'Bearer ' . $credential])
        );
        self::assertSame(401, $exchange->getStatusCode());

        // And it drops out of the active list.
        $list = $this->deviceHandler()->list($this->bearerReq('GET', '/api/devices', $this->mintAccess($profileId, 1)));
        self::assertCount(0, json_decode($list->getBody(), true)['devices']);
    }

    public function testRevokeIsOwnershipScopedToTheCallersProfile(): void
    {
        $ownerA = $this->seedProfile('a@example.com');
        $ownerB = $this->seedProfile('b@example.com');
        $credentialA = $this->enroll($ownerA);
        $deviceIdA = (int) $this->pdo->query("SELECT id FROM devices WHERE profile_id = {$ownerA}")->fetchColumn();

        // B tries to revoke A's device → 404 (not found for B).
        $res = $this->deviceHandler()->revoke(
            $this->bearerReq('DELETE', "/api/devices/{$deviceIdA}", $this->mintAccess($ownerB, 1)),
            ['id' => (string) $deviceIdA]
        );
        self::assertSame(404, $res->getStatusCode());

        // A's credential is untouched.
        self::assertNotNull($this->validator()->validateDeviceToken($credentialA));
    }

    // ==================== helpers ====================

    private function deviceHandler(): DeviceApiHandler
    {
        return new DeviceApiHandler($this->validator(), new DeviceCredentialService($this->pdo, $this->jwtParser));
    }

    private function authHandler(): AuthHandler
    {
        return new AuthHandler($this->pdo, $this->jwtParser, $this->validator());
    }

    private function validator(): TokenValidator
    {
        return new TokenValidator($this->jwtParser, $this->pdo);
    }

    /** Enroll a device for a profile and return its raw credential. */
    private function enroll(int $profileId, string $name = 'Device', string $platform = 'windows'): string
    {
        $issued = (new DeviceCredentialService($this->pdo, $this->jwtParser))
            ->issue($profileId, 1, 'owner@example.com', $name, $platform, null);
        return $issued['token'];
    }

    private function mintAccess(int $profileId, int $tenantId, int $epoch = 0): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => 'owner@example.com',
            'role'             => 'user',
            'token_epoch'      => $epoch,
        ], 900, 'access');
    }

    /** @param array<string, mixed>|null $body */
    private function bearerReq(string $method, string $path, string $bearer, ?array $body = null): Request
    {
        return new Request(
            $method,
            $path,
            ['Authorization' => 'Bearer ' . $bearer],
            $body !== null ? (string) json_encode($body) : ''
        );
    }

    private function seedProfile(string $email, int $epoch = 0, int $tenantId = 1, int $roleId = 2): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, ?, datetime('now'), datetime('now'))"
        );
        $stmt->execute([$email, password_hash('secret-123', PASSWORD_BCRYPT), $epoch]);
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
