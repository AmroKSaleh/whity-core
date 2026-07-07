<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\RegistrationsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine integration tests for the pending-registration review API
 * (WC-235 admin-approval activation).
 *
 * Drives the REAL {@see RegistrationsApiHandler} + a REAL {@see RoleChecker}
 * against the full migration-built schema on in-memory SQLite (STRINGIFY on,
 * mirroring PostgreSQL string fetches). The same handler SQL runs on the
 * postgres-integration CI job.
 *
 * Proves the two invariants that make the feature safe:
 *  1. SYSTEM-TENANT ONLY — a regular tenant admin holds registrations:approve
 *     within its OWN tenant, but every endpoint refuses it unless it is acting
 *     in the system tenant (id 0); a caller without the permission is refused
 *     too.
 *  2. PENDING-REGISTRATION SCOPE — only an 'invited' membership in a tenant with
 *     NO active member is a pending registration. An ordinary tenant invitation
 *     (also 'invited', but in a tenant that already has active members) never
 *     appears in the list and can never be approved/rejected here.
 */
final class RegistrationsApiRealEngineTest extends TestCase
{
    private const SYSTEM_TENANT = 0;
    private const TENANT_A = 1; // established tenant with active members
    private const TENANT_B = 2; // freshly registered, still pending (no active member)

    // Profiles (see makeSchema):
    private const SYS_ADMIN = 10;      // system tenant admin — holds registrations:approve in tenant 0
    private const TENANT_A_ADMIN = 11; // tenant-A admin — holds the permission, but NOT in the system tenant
    private const SYS_NOPERM = 14;     // system-tenant user WITHOUT registrations:approve
    // Profiles 12 (pending owner of tenant B) and 13 (ordinary invitee into
    // tenant A) are seeded directly in makeSchema(); their membership ids below
    // are what the approve/reject tests target.

    // Membership ids (seeded explicitly so approve/reject can target them).
    private const MEM_PENDING_B = 1002; // profile 12 → tenant B, 'invited' (the pending registration)
    private const MEM_INVITE_A = 1003;  // profile 13 → tenant A, 'invited' (ordinary invitation)

    private PDO $pdo;
    private RegistrationsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
        $this->pdo = $this->makeSchema();
        $this->handler = $this->makeHandler();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    // ==================== GET /api/registrations/pending ====================

    public function testListPendingReturnsOnlyWholeWorkspacePendingForSystemAdmin(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->listPending(
            $this->req('GET', '/api/registrations/pending', null, self::SYS_ADMIN, self::SYSTEM_TENANT)
        );

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        $items = $this->decode($response)['data'];

        // Exactly the pending tenant-B registration — the ordinary tenant-A
        // invitation is excluded because tenant A already has an active member.
        self::assertCount(1, $items);
        self::assertSame(self::MEM_PENDING_B, $items[0]['membership_id']);
        self::assertSame(self::TENANT_B, $items[0]['tenant_id']);
        self::assertSame('tenant-b', $items[0]['tenant_slug']);
        self::assertSame('owner@tenant-b.test', $items[0]['owner_email']);
    }

    public function testListPendingRejectsNonSystemTenantCallerWithThePermission(): void
    {
        // The tenant-A admin holds registrations:approve within tenant A, yet the
        // system-tenant gate must still refuse it (cross-tenant escalation guard).
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->listPending(
            $this->req('GET', '/api/registrations/pending', null, self::TENANT_A_ADMIN, self::TENANT_A)
        );

        self::assertSame(403, $response->getStatusCode());
    }

    public function testListPendingRejectsSystemCallerWithoutThePermission(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->listPending(
            $this->req('GET', '/api/registrations/pending', null, self::SYS_NOPERM, self::SYSTEM_TENANT)
        );

        self::assertSame(403, $response->getStatusCode());
    }

    // ==================== POST /api/registrations/{id}/approve ====================

    public function testApproveActivatesPendingRegistration(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->approve(
            $this->req('POST', '/api/registrations/x/approve', null, self::SYS_ADMIN, self::SYSTEM_TENANT),
            ['id' => (string) self::MEM_PENDING_B]
        );

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame('active', $this->statusOf(self::MEM_PENDING_B));
    }

    public function testApproveRejectedForNonSystemTenantCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->approve(
            $this->req('POST', '/api/registrations/x/approve', null, self::TENANT_A_ADMIN, self::TENANT_A),
            ['id' => (string) self::MEM_PENDING_B]
        );

        self::assertSame(403, $response->getStatusCode());
        // The pending registration must be untouched.
        self::assertSame('invited', $this->statusOf(self::MEM_PENDING_B));
    }

    public function testApproveReturns404ForOrdinaryInvitation(): void
    {
        // The ordinary tenant-A invitation is 'invited' but tenant A has active
        // members, so it is NOT a pending registration — this endpoint must
        // refuse it (404) rather than silently activating a tenant invite.
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->approve(
            $this->req('POST', '/api/registrations/x/approve', null, self::SYS_ADMIN, self::SYSTEM_TENANT),
            ['id' => (string) self::MEM_INVITE_A]
        );

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('invited', $this->statusOf(self::MEM_INVITE_A));
    }

    public function testApproveReturns404ForUnknownId(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->approve(
            $this->req('POST', '/api/registrations/x/approve', null, self::SYS_ADMIN, self::SYSTEM_TENANT),
            ['id' => '99999']
        );

        self::assertSame(404, $response->getStatusCode());
    }

    public function testApproveReturns422ForMissingId(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->approve(
            $this->req('POST', '/api/registrations/x/approve', null, self::SYS_ADMIN, self::SYSTEM_TENANT),
            []
        );

        self::assertSame(422, $response->getStatusCode());
    }

    // ==================== POST /api/registrations/{id}/reject ====================

    public function testRejectSuspendsPendingRegistration(): void
    {
        TenantContext::setTenantId(self::SYSTEM_TENANT);
        $response = $this->handler->reject(
            $this->req('POST', '/api/registrations/x/reject', null, self::SYS_ADMIN, self::SYSTEM_TENANT),
            ['id' => (string) self::MEM_PENDING_B]
        );

        self::assertSame(200, $response->getStatusCode(), $response->getBody());
        self::assertSame('suspended', $this->statusOf(self::MEM_PENDING_B));
    }

    public function testRejectRejectedForNonSystemTenantCaller(): void
    {
        TenantContext::setTenantId(self::TENANT_A);
        $response = $this->handler->reject(
            $this->req('POST', '/api/registrations/x/reject', null, self::TENANT_A_ADMIN, self::TENANT_A),
            ['id' => (string) self::MEM_PENDING_B]
        );

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('invited', $this->statusOf(self::MEM_PENDING_B));
    }

    // ==================== helpers ====================

    private function statusOf(int $membershipId): string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM memberships WHERE id = :id');
        $stmt->execute([':id' => $membershipId]);

        return (string) $stmt->fetchColumn();
    }

    private function makeHandler(): RegistrationsApiHandler
    {
        $registry = new PermissionRegistry();
        $registry->registerCorePermissions();

        $roleChecker = new RoleChecker($this->databaseFor($this->pdo), $registry);

        return new RegistrationsApiHandler($this->pdo, $roleChecker);
    }

    private function databaseFor(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo, 86400, 86400);
        $db->forceConnect();

        return $db;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function req(string $method, string $path, ?array $body, int $userId, int $tenantId): Request
    {
        $request = new Request($method, $path, [], $body !== null ? (string) json_encode($body) : '');
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
     * In-memory SQLite with the full production schema. Seeds a system tenant, an
     * established tenant A (with active members + an ordinary pending invite), and
     * a freshly-registered tenant B whose only member is its invited owner.
     */
    private function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT OR IGNORE INTO tenants (id, name, slug) VALUES (0, 'system', 'system')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'tenant-a', 'tenant-a')");
        $pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (2, 'tenant-b', 'tenant-b')");

        // admin role (1) is seeded by migrations and granted registrations:approve
        // by migration 043. A no-perm role (101) proves the permission gate.
        $pdo->exec("INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES (1, 'admin', '', NULL, datetime('now'))");
        $pdo->exec("INSERT INTO roles (id, name, description, tenant_id, created_at) VALUES (101, 'no-perm', '', 0, datetime('now'))");

        $pdo->exec("
            INSERT INTO profiles (id, display_name, password_hash, two_factor_enabled, two_factor_backup_codes_version, token_epoch, created_at, updated_at) VALUES
                (10, 'sys-admin',     'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (11, 'tenant-a-admin','x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (12, 'pending-owner', 'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (13, 'invitee-a',     'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP),
                (14, 'sys-noperm',    'x', false, 0, 0, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
        ");

        // Primary email for the pending owner so owner_email is populated.
        $pdo->exec("
            INSERT INTO profile_emails (profile_id, email, verified, is_primary, created_at) VALUES
                (12, 'owner@tenant-b.test', true, true, datetime('now'))
        ");

        // Memberships:
        //  - system admin (active, tenant 0) holds registrations:approve.
        //  - tenant-A admin (active, tenant 1): tenant A therefore HAS an active member.
        //  - pending owner (invited, tenant 2): tenant B has NO active member → a pending registration.
        //  - invitee-a (invited, tenant 1): ordinary invitation into an active tenant → NOT a registration.
        //  - sys-noperm (active, tenant 0) with the no-perm role.
        $pdo->exec("
            INSERT INTO memberships (id, profile_id, tenant_id, role_id, status, created_at) VALUES
                (1000, 10, 0, 1,   'active',  datetime('now')),
                (1001, 11, 1, 1,   'active',  datetime('now')),
                (1002, 12, 2, 1,   'invited', datetime('now')),
                (1003, 13, 1, 1,   'invited', datetime('now')),
                (1004, 14, 0, 101, 'active',  datetime('now'))
        ");

        return $pdo;
    }
}
