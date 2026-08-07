<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Core\Request;

/**
 * Real-engine tests for the WC-user-status login gate: a profile whose
 * `profiles.status` is 'inactive' must never authenticate, and the failure
 * must be INDISTINGUISHABLE from any other login failure (generic 401
 * "Invalid credentials") — a deactivation-specific error would be a
 * user-enumeration oracle (mirrors the unverified-email guard already in
 * {@see AuthHandler::handle()}).
 *
 * Drives the handler against a genuine SQL engine (in-memory SQLite by
 * default, or real PostgreSQL when PHPUNIT_PG_DSN is set) via
 * {@see SchemaFromMigrations}.
 */
final class AuthHandlerAccountStatusRealEngineTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private JwtParser $jwtParser;
    private AuthHandler $authHandler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-one')");

        $this->jwtParser = new JwtParser('test-secret-key-padded-for-hs256-min-32-byte-key');
        $this->authHandler = new AuthHandler($this->pdo, $this->jwtParser, null, null, null, new NullLogger());
    }

    public function testActiveProfileCanLogIn(): void
    {
        $this->seedProfile('active@example.com', 1, 'active');

        $response = $this->login('active@example.com');

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testInactiveProfileCannotLogInAndGetsGenericFailure(): void
    {
        $this->seedProfile('inactive@example.com', 1, 'inactive');

        $response = $this->login('inactive@example.com');

        $this->assertSame(401, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);

        // Same generic wording as an unknown email / wrong password — a
        // deactivation-specific message would let an attacker enumerate
        // valid-but-deactivated accounts.
        $this->assertSame('Invalid credentials', $body['error'] ?? null);
    }

    /**
     * The inactive-account 401 must be textually IDENTICAL to the
     * wrong-password 401 for a known, active account — proving there is no
     * distinguishing message an attacker could use to tell "wrong password"
     * apart from "correct password, but the account is deactivated".
     */
    public function testInactiveProfileFailureMatchesWrongPasswordFailureVerbatim(): void
    {
        $this->seedProfile('inactive2@example.com', 1, 'inactive');
        $this->seedProfile('active2@example.com', 1, 'active');

        $inactiveResponse = $this->login('inactive2@example.com');
        $wrongPasswordResponse = $this->login('active2@example.com', 'totally-wrong-password');

        $this->assertSame(401, $inactiveResponse->getStatusCode());
        $this->assertSame(401, $wrongPasswordResponse->getStatusCode());

        $inactiveBody = json_decode($inactiveResponse->getBody(), true);
        $wrongPasswordBody = json_decode($wrongPasswordResponse->getBody(), true);

        $this->assertSame($wrongPasswordBody['error'] ?? null, $inactiveBody['error'] ?? null);
    }

    // ==================== Helpers ====================

    private function login(string $email, ?string $password = null): \Whity\Core\Response
    {
        $body = (string) json_encode(['email' => $email, 'password' => $password ?? self::PASSWORD]);
        $request = new Request('POST', '/api/login', [], $body);

        return $this->authHandler->handle($request);
    }

    private function seedProfile(string $email, int $tenantId, string $status): int
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, status, created_at, updated_at)
             VALUES (?, ?, 0, 0, 0, ?, datetime('now'), datetime('now'))"
        );
        $stmt->execute([explode('@', $email)[0], $hash, $status]);
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
        )->execute([$profileId, $tenantId, $roleId]);

        return $profileId;
    }
}
