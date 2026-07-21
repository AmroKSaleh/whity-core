<?php

declare(strict_types=1);

namespace Tests\Auth;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Auth\AuthHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\TwoFactorPolicyResolver;
use Whity\Core\Request;
use Whity\Database\Database;

/**
 * Real-engine tests for the WC-525 PR-2 enforcement gate wired into
 * {@see AuthHandler}'s login flow via the shared
 * {@see AuthHandler::issueSessionForProfile()} chokepoint.
 *
 * Covers the three enforcement states: no applicable policy (normal login),
 * within the grace period (login succeeds with a nag flag), and past the
 * deadline (login refused with an enrollment-gate token instead of a
 * session) — plus tenant isolation, so a tenant-1 policy never blocks a
 * tenant-2 login.
 */
final class AuthHandlerTwoFactorPolicyRealEngineTest extends TestCase
{
    private const PASSWORD = 'correct horse battery staple';

    private PDO $pdo;
    private Database $dbWrapper;
    private JwtParser $jwtParser;
    private AuthHandler $authHandler;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-one')");
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (2, 'tenant-two')");

        $this->jwtParser = new JwtParser('test-secret-key-padded-for-hs256-min-32-byte-key');
        $this->dbWrapper = self::wrapSqlite($this->pdo);

        $resolver = new TwoFactorPolicyResolver($this->dbWrapper, new NullLogger());
        $this->authHandler = new AuthHandler(
            $this->pdo,
            $this->jwtParser,
            null,
            null,
            null,
            new NullLogger(),
            null,
            null,
            $resolver
        );
    }

    public function testLoginSucceedsNormallyWithNoApplicablePolicy(): void
    {
        $this->seedProfile('alice@example.com', 1, null);

        $response = $this->login('alice@example.com');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertArrayNotHasKey('two_factor_enrollment_required', $body);
        $this->assertArrayNotHasKey('requires_2fa_enrollment', $body);
    }

    public function testLoginSucceedsWithNagFlagDuringGracePeriod(): void
    {
        $this->seedProfile('bob@example.com', 1, null);
        $this->insertPolicy(1, 'tenant', null, 30);

        $response = $this->login('bob@example.com');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['two_factor_enrollment_required'] ?? false);
        $this->assertIsInt($body['two_factor_enrollment_deadline'] ?? null);
        $this->assertArrayHasKey('user', $body, 'A grace-period login still yields a real session.');
    }

    public function testLoginRefusedPastDeadlineReturnsEnrollmentGate(): void
    {
        $this->seedProfile('carol@example.com', 1, null);
        $this->insertPolicy(1, 'tenant', null, 0);

        $response = $this->login('carol@example.com');

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['requires_2fa_enrollment'] ?? false);
        $this->assertIsString($body['enrollment_token'] ?? null);
        $this->assertArrayNotHasKey('user', $body, 'A refused login must never carry session data.');

        // The enrollment token is a distinct type, not a real access token.
        $claims = $this->jwtParser->parse($body['enrollment_token']);
        $this->assertSame('two_factor_enrollment', $claims['type'] ?? null);
    }

    public function testAlreadyEnrolledProfileIsNeverGated(): void
    {
        $this->seedProfile('dave@example.com', 1, null, twoFactorEnabled: true);
        $this->insertPolicy(1, 'tenant', null, 0);

        // dave has 2FA enabled, so the login path challenges for a TOTP code
        // (202 requires_2fa) rather than ever reaching the enrollment gate.
        $response = $this->login('dave@example.com');

        $body = json_decode($response->getBody(), true);
        $this->assertArrayNotHasKey('requires_2fa_enrollment', $body);
    }

    public function testOuScopedPolicyEnforcesDescendantMembersOnly(): void
    {
        $rootOu = $this->seedOu(1, null);
        $childOu = $this->seedOu(1, $rootOu);

        $this->seedProfile('erin@example.com', 1, $childOu);
        $this->seedProfile('frank@example.com', 1, null); // no OU — unaffected

        $this->insertPolicy(1, 'ou', $rootOu, 0);

        $erinResponse = $this->login('erin@example.com');
        $this->assertSame(202, $erinResponse->getStatusCode(), 'A member of a descendant OU must be gated.');

        $frankResponse = $this->login('frank@example.com');
        $this->assertSame(200, $frankResponse->getStatusCode(), 'A profile outside the OU scope must be unaffected.');
    }

    public function testTenantWidePolicyNeverLeaksAcrossTenants(): void
    {
        $this->seedProfile('grace@example.com', 2, null);
        $this->insertPolicy(1, 'tenant', null, 0);

        $response = $this->login('grace@example.com');

        $this->assertSame(200, $response->getStatusCode(), 'A tenant-1 policy must never gate a tenant-2 login.');
    }

    // ==================== Helpers ====================

    private function login(string $email): \Whity\Core\Response
    {
        $body = (string) json_encode(['email' => $email, 'password' => self::PASSWORD]);
        $request = new Request('POST', '/api/login', [], $body);

        return $this->authHandler->handle($request);
    }

    private function seedProfile(string $email, int $tenantId, ?int $ouId, bool $twoFactorEnabled = false): int
    {
        $hash = password_hash(self::PASSWORD, PASSWORD_BCRYPT);

        $stmt = $this->pdo->prepare(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                 two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES (?, ?, ?, 0, 0, datetime('now'), datetime('now'))"
        );
        $stmt->execute([explode('@', $email)[0], $hash, $twoFactorEnabled ? 1 : 0]);
        $profileId = (int) $this->pdo->lastInsertId();

        $this->pdo->prepare(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES (?, ?, 1, 1, datetime('now'))"
        )->execute([$profileId, $email]);

        $roleStmt = $this->pdo->query("SELECT id FROM roles WHERE name = 'user'");
        $this->assertNotFalse($roleStmt);
        $roleId = (int) $roleStmt->fetchColumn();

        $this->pdo->prepare(
            'INSERT INTO memberships (profile_id, tenant_id, role_id, ou_id, status, created_at)
             VALUES (?, ?, ?, ?, \'active\', datetime(\'now\'))'
        )->execute([$profileId, $tenantId, $roleId, $ouId]);

        return $profileId;
    }

    private function seedOu(int $tenantId, ?int $parentId): int
    {
        $slug = 'ou-' . uniqid();
        $stmt = $this->pdo->prepare(
            'INSERT INTO organizational_units (tenant_id, name, slug, parent_id, created_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'))'
        );
        $stmt->execute([$tenantId, $slug, $slug, $parentId]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertPolicy(int $tenantId, string $scopeType, ?int $scopeId, int $gracePeriodDays): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO two_factor_policies (tenant_id, scope_type, scope_id, grace_period_days, created_at, updated_at)
             VALUES (?, ?, ?, ?, datetime(\'now\'), datetime(\'now\'))'
        );
        $stmt->execute([$tenantId, $scopeType, $scopeId, $gracePeriodDays]);
    }

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
