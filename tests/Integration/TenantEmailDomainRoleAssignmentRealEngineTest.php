<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantEmailDomainApiHandler;
use Whity\Core\Identity\AssignableRole;
use Whity\Core\Identity\DnsTxtResolver;
use Whity\Core\Identity\DomainOwnershipVerifier;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * `default_role_id` decides what a stranger arriving on a claimed domain BECOMES,
 * and until now the write side checked only that it was a positive integer.
 *
 * The foreign key is `roles(id)` with no tenant constraint. Roles are either
 * tenant-owned or global (`tenant_id IS NULL`), so the database was satisfied by
 * ANY role in the installation — a tenant could point its domain at a role
 * belonging to somebody else, and the auto-provision path would hand it out.
 *
 * Two things are pinned here:
 *
 *   1. The write side REFUSES a role this tenant may not assign — on create and
 *      on the PATCH that previously did not exist at all, so policy could only
 *      be changed by deleting the domain and re-registering it.
 *
 *   2. The refusal does not leak. "Belongs to another tenant" and "does not
 *      exist" must be indistinguishable, or this validation becomes a
 *      cross-tenant role enumerator.
 *
 * The read-side counterpart (falling back to least privilege for rows written
 * before any of this existed) is covered in the policy-service suites.
 */
final class TenantEmailDomainRoleAssignmentRealEngineTest extends TestCase
{
    private PDO $pdo;
    private TenantEmailDomainsRepository $repo;
    private int $tenantId;
    private int $otherTenantId;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->repo = new TenantEmailDomainsRepository($this->pdo);
        $this->tenantId = $this->seedTenant('Acme');
        $this->otherTenantId = $this->seedTenant('Other');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    private function seedTenant(string $name): int
    {
        $stmt = $this->pdo->prepare('INSERT INTO tenants (name, slug, created_at) VALUES (:n, :s, NOW())');
        $stmt->execute([':n' => $name, ':s' => strtolower($name)]);

        return (int) $this->pdo->lastInsertId();
    }

    private function seedRole(string $name, ?int $tenantId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO roles (name, description, parent_id, tenant_id, created_at)
             VALUES (:n, :d, NULL, :t, NOW())'
        );
        $stmt->execute([':n' => $name, ':d' => $name, ':t' => $tenantId]);

        return (int) $this->pdo->lastInsertId();
    }

    /** A global role that ships with the installation. */
    private function globalRoleId(): int
    {
        $stmt = $this->pdo->query('SELECT id FROM roles WHERE tenant_id IS NULL ORDER BY id ASC LIMIT 1');
        self::assertNotFalse($stmt);

        return (int) $stmt->fetchColumn();
    }

    private function handler(): TenantEmailDomainApiHandler
    {
        $resolver = new class implements DnsTxtResolver {
            public function txtRecords(string $host): array
            {
                return [];
            }
        };

        return new TenantEmailDomainApiHandler($this->pdo, new DomainOwnershipVerifier($resolver));
    }

    /** @param array<string, mixed> $body */
    private function request(string $method, array $body): Request
    {
        return new Request($method, '/x', [], json_encode($body, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, mixed> */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $d = json_decode($res->getBody(), true);

        return is_array($d) ? $d : [];
    }

    // ── create ───────────────────────────────────────────────────────────────

    public function testCreateAcceptsARoleOwnedByThisTenant(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $ownRole = $this->seedRole('acme-staff', $this->tenantId);

        $res = $this->handler()->create($this->request('POST', [
            'domain' => 'acme.test',
            'default_role_id' => $ownRole,
        ]));

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
    }

    public function testCreateAcceptsAGlobalRole(): void
    {
        TenantContext::setTenantId($this->tenantId);

        $res = $this->handler()->create($this->request('POST', [
            'domain' => 'acme.test',
            'default_role_id' => $this->globalRoleId(),
        ]));

        self::assertSame(201, $res->getStatusCode(), $res->getBody());
    }

    /** THE DEFECT: another tenant's role satisfied the foreign key and nothing else looked. */
    public function testCreateRefusesARoleBelongingToAnotherTenant(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $foreignRole = $this->seedRole('other-admin', $this->otherTenantId);

        $res = $this->handler()->create($this->request('POST', [
            'domain' => 'acme.test',
            'default_role_id' => $foreignRole,
        ]));

        self::assertSame(422, $res->getStatusCode(), 'A tenant must not point a domain at another tenant\'s role');
        self::assertSame(
            [],
            $this->repo->listForTenant($this->tenantId),
            'The refusal must not leave a row behind'
        );
    }

    /**
     * The refusal must not double as a role enumerator: a caller probing ids
     * should not be able to tell a foreign role from a non-existent one.
     */
    public function testARefusalDoesNotRevealWhetherTheRoleExists(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $foreignRole = $this->seedRole('other-admin', $this->otherTenantId);

        $foreign = $this->handler()->create($this->request('POST', [
            'domain' => 'a.test',
            'default_role_id' => $foreignRole,
        ]));
        $missing = $this->handler()->create($this->request('POST', [
            'domain' => 'b.test',
            'default_role_id' => 987654,
        ]));

        self::assertSame($foreign->getStatusCode(), $missing->getStatusCode());
        self::assertSame(
            $this->decode($foreign)['error'] ?? null,
            $this->decode($missing)['error'] ?? null,
            'A foreign role and an absent one must be indistinguishable'
        );
    }

    // ── update (the route that did not exist) ────────────────────────────────

    public function testUpdateChangesTheDefaultRole(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $id = $this->repo->insert($this->tenantId, 'acme.test', $this->globalRoleId(), true);
        $newRole = $this->seedRole('acme-staff', $this->tenantId);

        $res = $this->handler()->update(
            $this->request('PATCH', ['default_role_id' => $newRole]),
            ['id' => (string) $id]
        );

        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $row = $this->repo->findById($id, $this->tenantId);
        self::assertNotNull($row);
        self::assertSame($newRole, (int) $row['default_role_id']);
    }

    public function testUpdateRefusesAnotherTenantsRole(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $original = $this->globalRoleId();
        $id = $this->repo->insert($this->tenantId, 'acme.test', $original, true);
        $foreignRole = $this->seedRole('other-admin', $this->otherTenantId);

        $res = $this->handler()->update(
            $this->request('PATCH', ['default_role_id' => $foreignRole]),
            ['id' => (string) $id]
        );

        self::assertSame(422, $res->getStatusCode());
        $row = $this->repo->findById($id, $this->tenantId);
        self::assertNotNull($row);
        self::assertSame($original, (int) $row['default_role_id'], 'The stored role must be untouched');
    }

    /**
     * A PATCH that names only `auto_provision` must not require the caller to
     * restate a role it never intended to touch.
     */
    public function testUpdateCanChangeAutoProvisionAlone(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $role = $this->globalRoleId();
        $id = $this->repo->insert($this->tenantId, 'acme.test', $role, true);

        $res = $this->handler()->update(
            $this->request('PATCH', ['auto_provision' => false]),
            ['id' => (string) $id]
        );

        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $row = $this->repo->findById($id, $this->tenantId);
        self::assertNotNull($row);
        self::assertSame($role, (int) $row['default_role_id'], 'The role must be left alone');
    }

    /**
     * Editing policy is not the same act as un-proving ownership. If a PATCH
     * cleared `verified_at`, every policy tweak would silently switch
     * auto-provisioning off until somebody re-ran a DNS challenge.
     */
    public function testUpdateDoesNotDisturbOwnershipVerification(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $id = $this->repo->insert($this->tenantId, 'acme.test', $this->globalRoleId(), true);
        $this->repo->markVerified($id, $this->tenantId);

        $this->handler()->update(
            $this->request('PATCH', ['auto_provision' => false]),
            ['id' => (string) $id]
        );

        $row = $this->repo->findById($id, $this->tenantId);
        self::assertNotNull($row);
        self::assertNotNull($row['verified_at'], 'A policy edit must not discard the ownership proof');
    }

    /** Another tenant's domain must 404, not 403 — the same answer a missing id gets. */
    public function testUpdateCannotReachAnotherTenantsDomain(): void
    {
        $foreignId = $this->repo->insert($this->otherTenantId, 'other.test', $this->globalRoleId(), true);

        TenantContext::setTenantId($this->tenantId);
        $res = $this->handler()->update(
            $this->request('PATCH', ['auto_provision' => false]),
            ['id' => (string) $foreignId]
        );

        self::assertSame(404, $res->getStatusCode());
    }

    public function testUpdateRefusesAnEmptyChange(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $id = $this->repo->insert($this->tenantId, 'acme.test', $this->globalRoleId(), true);

        $res = $this->handler()->update($this->request('PATCH', []), ['id' => (string) $id]);

        self::assertSame(422, $res->getStatusCode());
    }

    // ── the shared resolver ──────────────────────────────────────────────────

    public function testAssignableRoleAgreesWithTheHandler(): void
    {
        $resolver = new AssignableRole($this->pdo);
        $own = $this->seedRole('acme-staff', $this->tenantId);
        $foreign = $this->seedRole('other-admin', $this->otherTenantId);

        self::assertTrue($resolver->isAssignable($own, $this->tenantId));
        self::assertTrue($resolver->isAssignable($this->globalRoleId(), $this->tenantId));
        self::assertFalse($resolver->isAssignable($foreign, $this->tenantId));
        self::assertFalse($resolver->isAssignable(0, $this->tenantId));
    }

    /** A read falls back to least privilege rather than stranding the person. */
    public function testResolveSafeFallsBackToTheGlobalRoleForAForeignClaim(): void
    {
        $resolver = new AssignableRole($this->pdo);
        $foreign = $this->seedRole('other-admin', $this->otherTenantId);

        $resolved = $resolver->resolveSafe($foreign, $this->tenantId);

        self::assertNotNull($resolved);
        self::assertNotSame($foreign, $resolved, 'A foreign role must never be handed back');

        $stmt = $this->pdo->prepare('SELECT name, tenant_id FROM roles WHERE id = :id');
        $stmt->execute([':id' => $resolved]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame(AssignableRole::FALLBACK_ROLE_NAME, $row['name']);
        self::assertNull($row['tenant_id']);
    }

    public function testResolveSafeKeepsAnAssignableClaim(): void
    {
        $resolver = new AssignableRole($this->pdo);
        $own = $this->seedRole('acme-staff', $this->tenantId);

        self::assertSame($own, $resolver->resolveSafe($own, $this->tenantId));
    }
}
