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
use Whity\Core\Audit\AuditLogger;
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
            new AuditLogger($this->pdo),
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
        $profileId = $this->seedProfile('bob@example.com', 1, null);
        $this->insertPolicy(1, 'tenant', null, 30);

        $response = $this->login('bob@example.com');

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['two_factor_enrollment_required'] ?? false);
        $this->assertIsInt($body['two_factor_enrollment_deadline'] ?? null);
        $this->assertArrayHasKey('user', $body, 'A grace-period login still yields a real session.');

        // Kills a mutant that drops the enrollment_required audit() call: the
        // nag itself must leave an auditable trail, not just a response flag.
        $row = $this->fetchLastAuditRow('auth.two_factor_policy.enrollment_required');
        $this->assertNotNull($row, 'A grace-period nag must be audited.');
        $this->assertSame($profileId, (int) $row['actor_user_id']);
        $this->assertSame(1, (int) $row['tenant_id']);
    }

    public function testLoginRefusedPastDeadlineReturnsEnrollmentGate(): void
    {
        $profileId = $this->seedProfile('carol@example.com', 1, null);
        $this->insertPolicy(1, 'tenant', null, 0);

        $response = $this->login('carol@example.com');

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['requires_2fa_enrollment'] ?? false);
        $this->assertIsString($body['enrollment_token'] ?? null);
        $this->assertArrayNotHasKey('user', $body, 'A refused login must never carry session data.');

        // The enrollment token is a distinct type, not a real access token,
        // and must carry the SAME profile_id as the caller (kills a mutant
        // that drops 'profile_id' from the enrollment claims — a token that
        // silently authenticated no one, or the wrong one).
        $claims = $this->jwtParser->parse($body['enrollment_token']);
        $this->assertSame('two_factor_enrollment', $claims['type'] ?? null);
        $this->assertSame($profileId, $claims['profile_id'] ?? null);

        // Kills a mutant that drops the login_refused audit() call.
        $row = $this->fetchLastAuditRow('auth.two_factor_policy.login_refused');
        $this->assertNotNull($row, 'A past-deadline refusal must be audited.');
        $this->assertSame($profileId, (int) $row['actor_user_id']);
        $this->assertSame(1, (int) $row['tenant_id']);
    }

    /**
     * A profile that already has REAL 2FA enabled (verified TOTP) must never
     * be gated by an admin-enforced enrollment policy — even past its
     * deadline — on a refresh (a path that, unlike the initial login, does
     * not re-check the account-level two_factor_enabled flag before reaching
     * the policy gate). Kills mutants in profileTwoFactorEnabled() that break
     * its DB read (dropping the bound profileId, or the return statement),
     * both of which collapse it to "always false" and would wrongly gate an
     * already-enrolled profile.
     */
    public function testRefreshSkipsTwoFactorPolicyWhenProfileAlreadyHasRealTwoFactorEnabled(): void
    {
        $profileId = $this->seedProfile('already2fa@example.com', 1, null, twoFactorEnabled: true);
        $this->insertPolicy(1, 'tenant', null, 0); // deadline already past

        $refreshToken = $this->mintToken($profileId, 1, 'already2fa@example.com', 'refresh', 604800);
        $request = new Request('POST', '/api/auth/refresh', [
            'X-Auth-Mode'   => 'token',
            'Authorization' => 'Bearer ' . $refreshToken,
        ], '');

        $response = $this->authHandler->handleRefresh($request);

        $this->assertSame(
            200,
            $response->getStatusCode(),
            'A profile with real 2FA already enabled must never be gated by the admin enrollment policy.'
        );
        $body = json_decode($response->getBody(), true);
        $this->assertArrayNotHasKey('requires_2fa_enrollment', $body);
    }

    /**
     * handleLogoutOthers()'s OU-scoped policy gate depends on
     * activeMembershipOuId() correctly binding BOTH profile_id and tenant_id.
     * Kills a mutant that drops the tenant_id parameter from that lookup's
     * execute() call (which would either error on the mismatched placeholder
     * count or silently resolve the wrong/no OU).
     */
    public function testLogoutOthersOuLookupCorrectlyGatesOuScopedPolicy(): void
    {
        $rootOu = $this->seedOu(1, null);
        $profileId = $this->seedProfile('ou-logout@example.com', 1, $rootOu);
        $this->insertPolicy(1, 'ou', $rootOu, 0); // enforced immediately

        $accessToken = $this->mintToken($profileId, 1, 'ou-logout@example.com', 'access', 900);
        $request = new Request('POST', '/api/auth/logout-others', [
            'X-Auth-Mode'   => 'token',
            'Authorization' => 'Bearer ' . $accessToken,
        ], '');

        $response = $this->authHandler->handleLogoutOthers($request);

        $this->assertSame(202, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue(
            $body['requires_2fa_enrollment'] ?? false,
            'The caller\'s OU membership must resolve correctly so the OU-scoped policy actually gates.'
        );
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

    public function testRefreshIsRefusedPastDeadlineEvenForAnExistingSession(): void
    {
        $profileId = $this->seedProfile('henry@example.com', 1, null);
        $this->insertPolicy(1, 'tenant', null, 0);

        // A session token minted BEFORE the policy existed (or during grace) —
        // refresh must still be gated, not just the original login.
        $refreshToken = $this->mintToken($profileId, 1, 'henry@example.com', 'refresh', 604800);
        $request = new Request('POST', '/api/auth/refresh', [
            'X-Auth-Mode'   => 'token',
            'Authorization' => 'Bearer ' . $refreshToken,
        ], '');

        $response = $this->authHandler->handleRefresh($request);

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['requires_2fa_enrollment'] ?? false);
        $this->assertArrayNotHasKey('access_token', $body, 'A refused refresh must never mint a new access token.');
    }

    public function testLogoutOthersRefusalNeverBumpsTheCallersOwnEpoch(): void
    {
        $profileId = $this->seedProfile('iris@example.com', 1, null);
        $this->insertPolicy(1, 'tenant', null, 0);

        $accessToken = $this->mintToken($profileId, 1, 'iris@example.com', 'access', 900);
        $request = new Request('POST', '/api/auth/logout-others', [
            'X-Auth-Mode'   => 'token',
            'Authorization' => 'Bearer ' . $accessToken,
        ], '');

        $response = $this->authHandler->handleLogoutOthers($request);

        $this->assertSame(202, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertTrue($body['requires_2fa_enrollment'] ?? false);

        $epochStmt = $this->pdo->prepare('SELECT token_epoch FROM profiles WHERE id = ?');
        $epochStmt->execute([$profileId]);
        $this->assertSame(
            0,
            (int) $epochStmt->fetchColumn(),
            'A refused logout-others must NEVER bump the epoch — that would invalidate the ' .
            'caller\'s own still-valid session out from under them with nothing minted to replace it.'
        );
    }

    // ==================== Helpers ====================

    private function mintToken(int $profileId, int $tenantId, string $email, string $type, int $ttl): string
    {
        return $this->jwtParser->create([
            'profile_id'       => $profileId,
            'active_tenant_id' => $tenantId,
            'email'            => $email,
            'role'             => 'user',
            'token_epoch'      => 0,
        ], $ttl, $type);
    }

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

    private static function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn(): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }
}
