<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\TenantEmailDomainApiHandler;
use Whity\Core\Identity\DnsTxtResolver;
use Whity\Core\Identity\DomainOwnershipVerifier;
use Whity\Core\Identity\TenantEmailDomainsRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for the domain-ownership verify() flow (WC-628738f5): the
 * DNS TXT challenge, tenant-scoping, the 422-with-instructions path, and that a
 * verified domain becomes eligible for auto-provisioning.
 */
final class TenantEmailDomainVerifyRealEngineTest extends TestCase
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

    private function baseRoleId(): int
    {
        $stmt = $this->pdo->query('SELECT id FROM roles ORDER BY id ASC LIMIT 1');
        self::assertNotFalse($stmt);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Build a handler whose verifier's DNS resolver returns $txt for ANY host —
     * lets us simulate "the TXT record is (not) published".
     *
     * @param list<string> $txt
     */
    private function handlerWithDns(array $txt): TenantEmailDomainApiHandler
    {
        $resolver = new class ($txt) implements DnsTxtResolver {
            /** @param list<string> $txt */
            public function __construct(private array $txt)
            {
            }

            public function txtRecords(string $host): array
            {
                return $this->txt;
            }
        };

        return new TenantEmailDomainApiHandler($this->pdo, new DomainOwnershipVerifier($resolver));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(\Whity\Sdk\Http\Response $res): array
    {
        $d = json_decode($res->getBody(), true);
        return is_array($d) ? $d : [];
    }

    public function testVerifyFailsWithChallengeWhenTxtAbsent(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $id = $this->repo->insert($this->tenantId, 'acme.test', $this->baseRoleId(), true);

        $res = $this->handlerWithDns([])->verify(new Request('POST', '/x', [], ''), ['id' => (string) $id]);

        self::assertSame(422, $res->getStatusCode());
        $body = $this->decode($res);
        self::assertArrayHasKey('verification', $body);
        self::assertSame('_whity-verify.acme.test', $body['verification']['record_name']);
        self::assertStringStartsWith('whity-verify=', $body['verification']['record_value']);
        // Still unverified.
        $row = $this->repo->findById($id, $this->tenantId);
        self::assertNotNull($row);
        self::assertNull($row['verified_at']);
    }

    public function testVerifySucceedsWhenTxtMatches(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $id = $this->repo->insert($this->tenantId, 'acme.test', $this->baseRoleId(), true);
        $row = $this->repo->findById($id, $this->tenantId);
        self::assertNotNull($row);
        $token = (string) $row['verification_token'];

        $res = $this->handlerWithDns(['whity-verify=' . $token])
            ->verify(new Request('POST', '/x', [], ''), ['id' => (string) $id]);

        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        $row = $this->repo->findById($id, $this->tenantId);
        self::assertNotNull($row);
        self::assertNotNull($row['verified_at'], 'the domain is marked verified');
        self::assertTrue($row['is_verified']);
    }

    public function testVerifyCannotTargetAnotherTenantsDomain(): void
    {
        // A domain registered by Other; the caller is Acme. Acme must not be able
        // to verify (or even see) it — tenant-scoped 404.
        $foreignId = $this->repo->insert($this->otherTenantId, 'foreign.test', $this->baseRoleId(), true);
        $foreignRow = $this->repo->findById($foreignId, $this->otherTenantId);
        self::assertNotNull($foreignRow);
        $foreignToken = (string) $foreignRow['verification_token'];

        TenantContext::setTenantId($this->tenantId);
        $res = $this->handlerWithDns(['whity-verify=' . $foreignToken])
            ->verify(new Request('POST', '/x', [], ''), ['id' => (string) $foreignId]);

        self::assertSame(404, $res->getStatusCode());
        // The foreign domain stays unverified.
        $row = $this->repo->findById($foreignId, $this->otherTenantId);
        self::assertNotNull($row);
        self::assertNull($row['verified_at'], "another tenant's domain must not be verifiable across the boundary");
    }

    public function testVerifyIsIdempotentOnceVerified(): void
    {
        TenantContext::setTenantId($this->tenantId);
        $id = $this->repo->insert($this->tenantId, 'acme.test', $this->baseRoleId(), true);
        $this->repo->markVerified($id, $this->tenantId);

        // Even with NO matching TXT record now, an already-verified domain returns 200.
        $res = $this->handlerWithDns([])->verify(new Request('POST', '/x', [], ''), ['id' => (string) $id]);
        self::assertSame(200, $res->getStatusCode());
    }
}
