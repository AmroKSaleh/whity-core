<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\MembershipRepository;

/**
 * WC-101: integration tests for MembershipRepository.
 *
 * Covers all CRUD + lifecycle (invite/accept/suspend/reactivate) methods
 * against in-memory SQLite. Cross-tenant isolation for the repository is
 * proven here through the `tenantId` predicate: a find/suspend/delete scoped
 * to Tenant A must never touch Tenant B's rows.
 */
final class MembershipRepositoryTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private MembershipRepository $repo;

    /** Profile id for "Alice", resolved in setUp via lastInsertId(). */
    private int $aliceId;
    /** Profile id for "Bob", resolved in setUp via lastInsertId(). */
    private int $bobId;

    protected function setUp(): void
    {
        $this->pdo  = SchemaFromMigrations::make();
        $this->repo = new MembershipRepository($this->pdo);

        // Tenants & roles (needed for FK references in SQLite — FK enforcement is
        // off, but inserting them keeps the fixture realistic).
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec("INSERT OR IGNORE INTO roles (id, name) VALUES (1, 'admin'), (2, 'user')");

        // Two profiles (global). migration 036 may have already inserted the
        // system admin (id=1), so we cannot assume Alice/Bob are id=1/2.
        // Capture the actual auto-assigned ids via lastInsertId().
        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Alice', '\$2y\$10\$h1', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->aliceId = (int) $this->pdo->lastInsertId();

        $this->pdo->exec(
            "INSERT INTO profiles (display_name, password_hash, two_factor_enabled,
                two_factor_backup_codes_version, token_epoch, created_at, updated_at)
             VALUES ('Bob', '\$2y\$10\$h2', false, 0, 0, datetime('now'), datetime('now'))"
        );
        $this->bobId = (int) $this->pdo->lastInsertId();
    }

    // ── insert / invite ───────────────────────────────────────────────────────

    public function testInsertReturnsNewIdWithDefaultActiveStatus(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        self::assertGreaterThan(0, $id);

        $row = $this->repo->findById($id, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status']);
    }

    public function testInviteCreatesRowWithInvitedStatus(): void
    {
        $id = $this->repo->invite($this->bobId, self::TENANT_A, 2);
        $row = $this->repo->findById($id, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_INVITED, $row['status']);
    }

    // ── findById ─────────────────────────────────────────────────────────────

    public function testFindByIdReturnsNullForMissing(): void
    {
        self::assertNull($this->repo->findById(99999, self::TENANT_A));
    }

    public function testFindByIdIsTenantScoped(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_B, 1);
        // Tenant A must not find Tenant B's membership.
        self::assertNull($this->repo->findById($id, self::TENANT_A), 'Cross-tenant findById must return null.');
        // Tenant B CAN find it.
        self::assertIsArray($this->repo->findById($id, self::TENANT_B));
    }

    public function testFindByIdReturnsTypedRow(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $row = $this->repo->findById($id, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame($id, $row['id']);
        self::assertSame($this->aliceId, $row['profile_id']);
        self::assertSame(self::TENANT_A, $row['tenant_id']);
        self::assertSame(1, $row['role_id']);
        self::assertNull($row['ou_id']);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status']);
    }

    // ── findByProfile ────────────────────────────────────────────────────────

    public function testFindByProfileReturnsNullWhenNoMatch(): void
    {
        self::assertNull($this->repo->findByProfile($this->aliceId, self::TENANT_A));
    }

    public function testFindByProfileReturnsMembershipForTenant(): void
    {
        $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $row = $this->repo->findByProfile($this->aliceId, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame($this->aliceId, $row['profile_id']);
        self::assertSame(self::TENANT_A, $row['tenant_id']);
    }

    public function testFindByProfileIsTenantScoped(): void
    {
        $this->repo->insert($this->aliceId, self::TENANT_B, 1);
        // Tenant A query must not find Tenant B's row for the same profile.
        self::assertNull($this->repo->findByProfile($this->aliceId, self::TENANT_A));
    }

    // ── findForProfile (cross-tenant, login flow) ────────────────────────────

    public function testFindForProfileReturnsEmptyWhenNone(): void
    {
        self::assertSame([], $this->repo->findForProfile($this->aliceId));
    }

    public function testFindForProfileReturnsAllTenantsForProfile(): void
    {
        $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $this->repo->insert($this->aliceId, self::TENANT_B, 2);

        $rows = $this->repo->findForProfile($this->aliceId);
        self::assertCount(2, $rows);

        $tenants = array_column($rows, 'tenant_id');
        self::assertContains(self::TENANT_A, $tenants);
        self::assertContains(self::TENANT_B, $tenants);
    }

    public function testFindForProfileDoesNotReturnOtherProfilesRows(): void
    {
        $this->repo->insert($this->aliceId, self::TENANT_A, 1); // Alice
        $this->repo->insert($this->bobId, self::TENANT_B, 1);   // Bob

        self::assertCount(1, $this->repo->findForProfile($this->aliceId), 'findForProfile must return only Alice memberships.');
        self::assertCount(1, $this->repo->findForProfile($this->bobId), 'findForProfile must return only Bob memberships.');
    }

    // ── listForTenant / countForTenant ────────────────────────────────────────

    public function testListForTenantReturnsOnlyOwnRows(): void
    {
        $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $this->repo->insert($this->bobId, self::TENANT_B, 1);

        $rows = $this->repo->listForTenant(self::TENANT_A);
        self::assertCount(1, $rows);
        self::assertSame(self::TENANT_A, $rows[0]['tenant_id']);
    }

    public function testListForTenantFiltersOnStatus(): void
    {
        $this->repo->insert($this->aliceId, self::TENANT_A, 1); // active
        $this->repo->invite($this->bobId, self::TENANT_A, 2);   // invited

        self::assertCount(2, $this->repo->listForTenant(self::TENANT_A));
        self::assertCount(1, $this->repo->listForTenant(self::TENANT_A, MembershipRepository::STATUS_ACTIVE));
        self::assertCount(1, $this->repo->listForTenant(self::TENANT_A, MembershipRepository::STATUS_INVITED));
    }

    public function testCountForTenantReturnsCorrectCount(): void
    {
        $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $this->repo->invite($this->bobId, self::TENANT_A, 2);
        $this->repo->insert($this->bobId, self::TENANT_B, 1);

        self::assertSame(2, $this->repo->countForTenant(self::TENANT_A));
        self::assertSame(1, $this->repo->countForTenant(self::TENANT_B));
    }

    // ── lifecycle transitions ─────────────────────────────────────────────────

    public function testAcceptPromotesInvitedToActive(): void
    {
        $id = $this->repo->invite($this->aliceId, self::TENANT_A, 1);
        $affected = $this->repo->accept($id, self::TENANT_A);
        self::assertSame(1, $affected);

        $row = $this->repo->findById($id, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status']);
    }

    public function testSuspendMarksRowAsSuspended(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $affected = $this->repo->suspend($id, self::TENANT_A);
        self::assertSame(1, $affected);

        $row = $this->repo->findById($id, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_SUSPENDED, $row['status']);
    }

    public function testReactivateRestoresSuspendedToActive(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $this->repo->suspend($id, self::TENANT_A);
        $affected = $this->repo->reactivate($id, self::TENANT_A);
        self::assertSame(1, $affected);

        $row = $this->repo->findById($id, self::TENANT_A);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status']);
    }

    public function testAcceptReturnZeroForForeignTenant(): void
    {
        $id = $this->repo->invite($this->aliceId, self::TENANT_B, 1);
        // Tenant A cannot accept Tenant B's invitation.
        self::assertSame(0, $this->repo->accept($id, self::TENANT_A));

        // Tenant B's row remains invited.
        $row = $this->repo->findById($id, self::TENANT_B);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_INVITED, $row['status']);
    }

    public function testSuspendReturnZeroForForeignTenant(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_B, 1);
        self::assertSame(0, $this->repo->suspend($id, self::TENANT_A));

        $row = $this->repo->findById($id, self::TENANT_B);
        self::assertIsArray($row);
        self::assertSame(MembershipRepository::STATUS_ACTIVE, $row['status'], 'Foreign row must stay active.');
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesMembership(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_A, 1);
        $affected = $this->repo->delete($id, self::TENANT_A);
        self::assertSame(1, $affected);
        self::assertNull($this->repo->findById($id, self::TENANT_A));
    }

    public function testDeleteReturnZeroForForeignTenant(): void
    {
        $id = $this->repo->insert($this->aliceId, self::TENANT_B, 1);
        self::assertSame(0, $this->repo->delete($id, self::TENANT_A));

        // Tenant B's row survives.
        self::assertIsArray($this->repo->findById($id, self::TENANT_B));
    }
}
