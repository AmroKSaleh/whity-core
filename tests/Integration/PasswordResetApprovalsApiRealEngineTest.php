<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\PasswordResetApprovalsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLogger;
use Whity\Core\Identity\PasswordResetService;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine integration tests for the tenant-scoped password-reset
 * approval queue (WC-password-reset-2fa-recovery).
 *
 * Drives the REAL {@see PasswordResetApprovalsApiHandler} + a REAL
 * {@see RoleChecker} against the full migration-built schema (migrations
 * 076/078 create the table and grant `password_resets:approve` to the seeded
 * `admin` role). Proves:
 *  1. TENANT SCOPING (unlike RegistrationsApiHandler's system-tenant-only
 *     model) — a tenant's admin sees and can act ONLY on requests from
 *     profiles holding an active membership in THAT tenant; another tenant's
 *     admin cannot see or approve/reject it, even holding the same permission.
 *  2. PERMISSION GATING — a caller without password_resets:approve is refused.
 *  3. Approve applies the staged password + bumps token_epoch; reject leaves
 *     the profile completely untouched.
 */
final class PasswordResetApprovalsApiRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const ADMIN_A = 10; // tenant-A admin (holds password_resets:approve via the seeded admin role)
    private const ADMIN_B = 11; // tenant-B admin
    private const NOPERM_A = 12; // tenant-A member without the admin role

    private PDO $pdo;
    private PasswordResetService $service;
    private PasswordResetApprovalsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        $this->service = new PasswordResetService($this->pdo);
        $this->handler = $this->makeHandler();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== list ====================

    public function testListPendingIsScopedToTheCallersOwnTenant(): void
    {
        $requesterA = $this->seedProfile('reqA@acme.test', self::TENANT_A);
        $requesterB = $this->seedProfile('reqB@acme.test', self::TENANT_B);
        $this->stageRequest($requesterA);
        $this->stageRequest($requesterB);

        TenantContext::setTenantId(self::TENANT_A);
        $resA = $this->handler->listPending($this->req(self::ADMIN_A, self::TENANT_A));
        self::assertSame(200, $resA->getStatusCode(), $resA->getBody());
        $itemsA = $this->decode($resA)['data'];
        self::assertCount(1, $itemsA);
        self::assertSame('reqA@acme.test', $itemsA[0]['email']);

        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $resB = $this->handler->listPending($this->req(self::ADMIN_B, self::TENANT_B));
        $itemsB = $this->decode($resB)['data'];
        self::assertCount(1, $itemsB);
        self::assertSame('reqB@acme.test', $itemsB[0]['email']);
    }

    public function testListPendingRejectsCallerWithoutThePermission(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->listPending($this->req(self::NOPERM_A, self::TENANT_A));
        self::assertSame(403, $res->getStatusCode());
    }

    // ==================== approve ====================

    public function testApproveAppliesTheStagedPasswordForOwnTenantRequester(): void
    {
        $requester = $this->seedProfile('own@acme.test', self::TENANT_A);
        $requestId = $this->stageRequest($requester, 'staged-approved-password');

        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->approve($this->req(self::ADMIN_A, self::TENANT_A), ['id' => (string) $requestId]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        self::assertTrue(password_verify(
            'staged-approved-password',
            (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$requester}")
        ));
        self::assertSame(1, $this->epochOf($requester));
    }

    public function testApproveRejectsAnotherTenantsAdmin(): void
    {
        $requester = $this->seedProfile('protected@acme.test', self::TENANT_A);
        $requestId = $this->stageRequest($requester, 'must-not-apply');

        TenantContext::setTenantId(self::TENANT_B);
        $res = $this->handler->approve($this->req(self::ADMIN_B, self::TENANT_B), ['id' => (string) $requestId]);
        self::assertSame(404, $res->getStatusCode());

        self::assertFalse(password_verify(
            'must-not-apply',
            (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$requester}")
        ));
        self::assertSame(0, $this->epochOf($requester));
    }

    public function testApproveRejectsCallerWithoutThePermission(): void
    {
        $requester = $this->seedProfile('gated@acme.test', self::TENANT_A);
        $requestId = $this->stageRequest($requester);

        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->approve($this->req(self::NOPERM_A, self::TENANT_A), ['id' => (string) $requestId]);
        self::assertSame(403, $res->getStatusCode());
    }

    // ==================== reject ====================

    public function testRejectLeavesTheProfileCompletelyUntouched(): void
    {
        $requester = $this->seedProfile('rejectme@acme.test', self::TENANT_A);
        $originalHash = (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$requester}");
        $requestId = $this->stageRequest($requester, 'never-applied');

        TenantContext::setTenantId(self::TENANT_A);
        $res = $this->handler->reject($this->req(self::ADMIN_A, self::TENANT_A), ['id' => (string) $requestId]);
        self::assertSame(200, $res->getStatusCode(), $res->getBody());

        self::assertSame($originalHash, (string) $this->col("SELECT password_hash FROM profiles WHERE id = {$requester}"));
        self::assertSame(0, $this->epochOf($requester));
        self::assertSame('rejected', (string) $this->col(
            "SELECT status FROM password_resets WHERE id = {$requestId}"
        ));
    }

    // ==================== helpers ====================

    private function stageRequest(int $profileId, string $password = 'a-staged-password-1'): int
    {
        $token = $this->service->issue($profileId);
        $result = $this->service->confirm($token, $password, true);
        self::assertNotNull($result);

        return $result['request_id'];
    }

    private function epochOf(int $profileId): int
    {
        return (int) $this->col("SELECT token_epoch FROM profiles WHERE id = {$profileId}");
    }

    private function col(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        if ($stmt === false) {
            self::fail("query failed: {$sql}");
        }

        return $stmt->fetchColumn();
    }

    private function seedProfile(string $email, int $tenantId): int
    {
        $this->pdo->exec(
            "INSERT INTO profiles
                (display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Requester', 'x', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $profileId = (int) $this->pdo->lastInsertId();
        $this->pdo->exec(
            "INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at)
             VALUES ({$profileId}, '{$email}', true, true, datetime('now'))"
        );
        $this->pdo->exec(
            "INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at)
             VALUES ({$profileId}, {$tenantId}, 1, 'active', datetime('now'))"
        );

        return $profileId;
    }

    private function makeHandler(): PasswordResetApprovalsApiHandler
    {
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();
        $roleChecker = new RoleChecker($this->databaseFor($this->pdo), $registry);

        return new PasswordResetApprovalsApiHandler($this->service, $roleChecker, new AuditLogger($this->pdo, new NullLogger()));
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    private function req(int $userId, int $tenantId): Request
    {
        $request = new Request('GET', '/api/password-resets/pending', [], '');
        $request->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }

    /**
     * In-memory SQLite with the full production schema (migrations 076/078
     * create the table + grant password_resets:approve to the seeded admin
     * role). Seeds two tenants, each with an admin-role member and a
     * no-permission member.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_A . ", 'tenant-a', 'tenant-a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (" . self::TENANT_B . ", 'tenant-b', 'tenant-b')");

        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (101, 'no-perm', '', 0, datetime('now'))");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (" . self::ADMIN_A . ", 'admin-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::ADMIN_B . ", 'admin-b', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (" . self::NOPERM_A . ", 'noperm-a', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        $pdo->exec("
            INSERT INTO memberships (profile_id, tenant_id, role_id, status, created_at) VALUES
                (" . self::ADMIN_A . ", " . self::TENANT_A . ", 1,   'active', datetime('now')),
                (" . self::ADMIN_B . ", " . self::TENANT_B . ", 1,   'active', datetime('now')),
                (" . self::NOPERM_A . ", " . self::TENANT_A . ", 101, 'active', datetime('now'))
        ");

        return $pdo;
    }
}
