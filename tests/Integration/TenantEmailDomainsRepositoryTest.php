<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Identity\TenantEmailDomainsRepository;

/**
 * WC-9b87: integration tests for TenantEmailDomainsRepository (migration 031).
 *
 * Verifies the full CRUD contract, cross-tenant isolation (a tenant can never
 * read or mutate another tenant's domain rows), and the intentionally unscoped
 * `findTenantsByDomain()` used by the policy service to locate ALL tenants that
 * claim a given domain.
 */
final class TenantEmailDomainsRepositoryTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private TenantEmailDomainsRepository $repo;

    protected function setUp(): void
    {
        $this->pdo  = SchemaFromMigrations::make(true);
        $this->repo = new TenantEmailDomainsRepository($this->pdo);

        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $this->pdo->exec(
            "INSERT OR IGNORE INTO roles (id, name, description, tenant_id, created_at) VALUES
                (1, 'admin', '', NULL, datetime('now')),
                (2, 'user',  '', NULL, datetime('now'))"
        );
    }

    // ── insert + findById ────────────────────────────────────────────────────

    public function testInsertReturnsNewId(): void
    {
        $id = $this->repo->insert(self::TENANT_A, 'acme.com', 1);
        self::assertGreaterThan(0, $id);
    }

    public function testFindByIdReturnsOwnRow(): void
    {
        $id  = $this->repo->insert(self::TENANT_A, 'acme.com', 1);
        $row = $this->repo->findById($id, self::TENANT_A);

        self::assertNotNull($row);
        self::assertSame($id, $row['id']);
        self::assertSame(self::TENANT_A, $row['tenant_id']);
        self::assertSame('acme.com', $row['domain']);
        self::assertSame(1, $row['default_role_id']);
        self::assertTrue($row['auto_provision']);
    }

    public function testFindByIdWithAutoProvisionFalse(): void
    {
        $id  = $this->repo->insert(self::TENANT_A, 'corp.io', 1, false);
        $row = $this->repo->findById($id, self::TENANT_A);

        self::assertNotNull($row);
        self::assertFalse($row['auto_provision']);
    }

    public function testFindByIdReturnNullForMissingRow(): void
    {
        self::assertNull($this->repo->findById(9999, self::TENANT_A));
    }

    // ── findByDomain (tenant-scoped) ──────────────────────────────────────────

    public function testFindByDomainReturnsMatchingRow(): void
    {
        $id  = $this->repo->insert(self::TENANT_A, 'acme.com', 1);
        $row = $this->repo->findByDomain('acme.com', self::TENANT_A);

        self::assertNotNull($row);
        self::assertSame($id, $row['id']);
    }

    public function testFindByDomainReturnsNullForUnregisteredDomain(): void
    {
        self::assertNull($this->repo->findByDomain('unknown.com', self::TENANT_A));
    }

    // ── findTenantsByDomain (cross-tenant, for policy service) ────────────────

    public function testFindTenantsByDomainReturnsAllClaimingTenants(): void
    {
        $this->repo->insert(self::TENANT_A, 'shared.com', 1);
        $this->repo->insert(self::TENANT_B, 'shared.com', 2);

        $rows = $this->repo->findTenantsByDomain('shared.com');

        self::assertCount(2, $rows);
        $tenantIds = array_column($rows, 'tenant_id');
        self::assertContains(self::TENANT_A, $tenantIds);
        self::assertContains(self::TENANT_B, $tenantIds);
    }

    public function testFindTenantsByDomainReturnsEmptyWhenNoneRegistered(): void
    {
        self::assertSame([], $this->repo->findTenantsByDomain('nobody.com'));
    }

    public function testFindTenantsByDomainReturnsDefaultRoleAndProvisionFlag(): void
    {
        $this->repo->insert(self::TENANT_A, 'corp.io', 2, false);

        $rows = $this->repo->findTenantsByDomain('corp.io');

        self::assertCount(1, $rows);
        self::assertSame(2, $rows[0]['default_role_id']);
        self::assertFalse($rows[0]['auto_provision']);
    }

    // ── listForTenant ─────────────────────────────────────────────────────────

    public function testListForTenantReturnsOwnRowsOnly(): void
    {
        $this->repo->insert(self::TENANT_A, 'a-domain.com', 1);
        $this->repo->insert(self::TENANT_A, 'a-other.com', 1);
        $this->repo->insert(self::TENANT_B, 'b-domain.com', 2);

        $rowsA = $this->repo->listForTenant(self::TENANT_A);
        $rowsB = $this->repo->listForTenant(self::TENANT_B);

        self::assertCount(2, $rowsA);
        self::assertCount(1, $rowsB);

        foreach ($rowsA as $row) {
            self::assertSame(self::TENANT_A, $row['tenant_id']);
        }
        foreach ($rowsB as $row) {
            self::assertSame(self::TENANT_B, $row['tenant_id']);
        }
    }

    public function testListForTenantReturnsEmptyWhenNoDomainsRegistered(): void
    {
        self::assertSame([], $this->repo->listForTenant(self::TENANT_A));
    }

    // ── delete ────────────────────────────────────────────────────────────────

    public function testDeleteRemovesOwnRow(): void
    {
        $id = $this->repo->insert(self::TENANT_A, 'acme.com', 1);

        $affected = $this->repo->delete($id, self::TENANT_A);

        self::assertSame(1, $affected);
        self::assertNull($this->repo->findById($id, self::TENANT_A));
    }

    public function testDeleteReturnZeroForMissingRow(): void
    {
        self::assertSame(0, $this->repo->delete(9999, self::TENANT_A));
    }

    // ── cross-tenant isolation ────────────────────────────────────────────────

    public function testTenantCannotReadForeignDomainRow(): void
    {
        $id = $this->repo->insert(self::TENANT_B, 'b-domain.com', 2);

        self::assertNull(
            $this->repo->findById($id, self::TENANT_A),
            "Tenant A must not be able to read Tenant B's domain row."
        );
    }

    public function testTenantCannotDeleteForeignDomainRowAndItSurvives(): void
    {
        $id = $this->repo->insert(self::TENANT_B, 'b-domain.com', 2);

        $affected = $this->repo->delete($id, self::TENANT_A);

        self::assertSame(0, $affected, 'A cross-tenant delete must touch zero rows.');
        self::assertNotNull(
            $this->repo->findById($id, self::TENANT_B),
            "Tenant B's domain row must survive a cross-tenant delete attempt."
        );
    }
}
