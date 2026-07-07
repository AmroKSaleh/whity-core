<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\SessionsApiHandler;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\SessionService;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;

/**
 * Real-engine (in-memory SQLite) tests for the interactive session registry
 * (WC-f-sessions-table). Drives the REAL AuthHandler (login/refresh) +
 * SessionService + SessionsApiHandler against the migration-built schema; the
 * same SQL runs on the postgres-integration CI job.
 *
 * Proves the lifecycle and the list/revoke contract:
 *   - login records ONE session row (family), exposed via GET /me/sessions;
 *   - refresh ROTATES that row in place (still one row, new current jtis);
 *   - revoking a session blacklists its current jtis so the live access token
 *     is rejected immediately;
 *   - "revoke all others" keeps the caller's current session and kills the rest;
 *   - list/revoke are ownership-scoped and require authentication.
 */
final class SessionsRealEngineTest extends TestCase
{
    private const SECRET = 'test-secret-key-for-sessions-table-padded-hs256-min-32-byte-key';

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

    public function testLoginRecordsOneSessionExposedInTheList(): void
    {
        $this->seedProfile('a@example.com');
        [$access] = $this->login('a@example.com');

        $res = $this->sessionsHandler()->list($this->bearer('GET', '/api/me/sessions', $access));
        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $sessions = json_decode($res->getBody(), true)['sessions'];
        self::assertCount(1, $sessions);
        self::assertTrue($sessions[0]['current'], 'The caller\'s own session must be flagged current.');
    }

    public function testRefreshRotatesTheSameSessionRowInPlace(): void
    {
        $this->seedProfile('a@example.com');
        [, $refresh] = $this->login('a@example.com');

        $refreshRes = $this->authHandler()->handleRefresh(
            new Request('POST', '/api/auth/refresh', ['Authorization' => 'Bearer ' . $refresh, 'X-Auth-Mode' => 'token'])
        );
        self::assertSame(200, $refreshRes->getStatusCode(), $refreshRes->getBody());
        $newAccess = (string) json_decode($refreshRes->getBody(), true)['access_token'];

        // Still ONE session row (rotated in place, not a new family).
        self::assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());
        // And the rotated session is the caller's current one under the new token.
        $sessions = json_decode($this->sessionsHandler()->list($this->bearer('GET', '/api/me/sessions', $newAccess))->getBody(), true)['sessions'];
        self::assertCount(1, $sessions);
        self::assertTrue($sessions[0]['current']);
    }

    public function testRevokingASessionRejectsItsLiveAccessToken(): void
    {
        $this->seedProfile('a@example.com');
        [$access] = $this->login('a@example.com');
        self::assertNotNull($this->validator()->validateAccessTokenFromBearer($access), 'Valid before revoke.');

        $id = json_decode($this->sessionsHandler()->list($this->bearer('GET', '/api/me/sessions', $access))->getBody(), true)['sessions'][0]['id'];
        $revoke = $this->sessionsHandler()->revoke($this->bearer('DELETE', "/api/me/sessions/{$id}", $access), ['id' => (string) $id]);
        self::assertSame(204, $revoke->getStatusCode());

        // The live access token is now rejected (its jti was blacklisted).
        self::assertNull(
            $this->validator()->validateAccessTokenFromBearer($access),
            'Revoking a session must reject its live access token immediately.'
        );
    }

    public function testRevokeOthersKeepsCurrentAndKillsTheRest(): void
    {
        $this->seedProfile('a@example.com');
        [$accessA] = $this->login('a@example.com'); // session A
        [$accessB] = $this->login('a@example.com'); // session B (another browser)

        self::assertSame(2, (int) $this->pdo->query('SELECT COUNT(*) FROM sessions')->fetchColumn());

        // From session A, revoke all OTHERS.
        $res = $this->sessionsHandler()->revokeOthers($this->bearer('DELETE', '/api/me/sessions', $accessA));
        self::assertSame(200, $res->getStatusCode());
        self::assertSame(1, json_decode($res->getBody(), true)['revoked']);

        // A survives; B is dead.
        self::assertNotNull($this->validator()->validateAccessTokenFromBearer($accessA), 'Current session must survive.');
        self::assertNull($this->validator()->validateAccessTokenFromBearer($accessB), 'Other sessions must be revoked.');
    }

    public function testSessionEndpointsRequireAuthentication(): void
    {
        self::assertSame(401, $this->sessionsHandler()->list(new Request('GET', '/api/me/sessions'))->getStatusCode());
        self::assertSame(401, $this->sessionsHandler()->revokeOthers(new Request('DELETE', '/api/me/sessions'))->getStatusCode());
        self::assertSame(
            401,
            $this->sessionsHandler()->revoke(new Request('DELETE', '/api/me/sessions/1'), ['id' => '1'])->getStatusCode()
        );
    }

    // ==================== helpers ====================

    /**
     * Log in (token mode) and return [accessToken, refreshToken].
     *
     * @return array{0: string, 1: string}
     */
    private function login(string $email, string $password = 'secret-123'): array
    {
        $res = $this->authHandler()->handle(new Request(
            'POST',
            '/api/login',
            ['X-Auth-Mode' => 'token'],
            (string) json_encode(['email' => $email, 'password' => $password])
        ));
        self::assertSame(200, $res->getStatusCode(), 'login failed: ' . $res->getBody());
        $body = json_decode($res->getBody(), true);
        self::assertIsArray($body);
        return [(string) $body['access_token'], (string) $body['refresh_token']];
    }

    private function authHandler(): AuthHandler
    {
        return new AuthHandler($this->pdo, $this->jwtParser, $this->validator());
    }

    private function validator(): TokenValidator
    {
        return new TokenValidator($this->jwtParser, $this->pdo);
    }

    private function sessionsHandler(): SessionsApiHandler
    {
        return new SessionsApiHandler($this->validator(), new SessionService($this->pdo));
    }

    /** @param array<string, mixed>|null $body */
    private function bearer(string $method, string $path, string $token, ?array $body = null): Request
    {
        return new Request($method, $path, ['Authorization' => 'Bearer ' . $token], $body !== null ? (string) json_encode($body) : '');
    }

    private function seedProfile(string $email, int $tenantId = 1, int $roleId = 2): int
    {
        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, false, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([$email, password_hash('secret-123', PASSWORD_BCRYPT)]);
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
        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, created_at) VALUES (1, 'Tenant A', datetime('now'))");
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");
        return $pdo;
    }
}
