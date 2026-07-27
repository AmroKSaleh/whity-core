<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\TagGroupsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;

/**
 * Real-engine tests for {@see TagGroupsApiHandler} (WC-621): route-level RBAC
 * (tags:read vs tags:manage), CRUD happy path, 409 on a duplicate key, 422 on
 * validation, and 404 (never a cross-tenant leak) for another tenant's group.
 */
final class TagGroupsApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const MANAGER_A = 10; // tags:read + tags:manage in tenant 1
    private const VIEWER_A  = 11; // tags:read only in tenant 1
    private const MANAGER_B = 20; // tags:read + tags:manage in tenant 2

    private PDO $pdo;
    private TagGroupsApiHandler $handler;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = TaxonomyTestSeed::make();
        $db = TaxonomyTestSeed::wrap($this->pdo);
        $this->handler = new TagGroupsApiHandler(
            new TagGroupRepository($this->pdo),
            new RoleChecker($db, new PermissionRegistry())
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    public function testViewerMayReadButNotWrite(): void
    {
        self::assertSame(200, $this->handler->list($this->act(self::VIEWER_A, self::TENANT_A))->getStatusCode());

        $res = $this->handler->create($this->req(self::VIEWER_A, self::TENANT_A, ['key' => 'priority']));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testManagerCrudHappyPath(): void
    {
        $created = $this->handler->create($this->req(self::MANAGER_A, self::TENANT_A, [
            'key' => 'priority',
            'display_name' => ['ar' => 'الأولوية', 'en' => 'Priority'],
        ]));
        self::assertSame(201, $created->getStatusCode(), $created->getBody());
        $id = $this->id($created);

        $show = $this->handler->show($this->act(self::MANAGER_A, self::TENANT_A), ['id' => (string) $id]);
        self::assertSame(200, $show->getStatusCode());
        self::assertSame('priority', $this->data($show)['key']);

        $update = $this->handler->update(
            $this->req(self::MANAGER_A, self::TENANT_A, ['display_name' => ['en' => 'Urgency']]),
            ['id' => (string) $id]
        );
        self::assertSame(200, $update->getStatusCode());
        self::assertEquals(['en' => 'Urgency'], $this->data($update)['display_name']);

        $delete = $this->handler->delete($this->act(self::MANAGER_A, self::TENANT_A), ['id' => (string) $id]);
        self::assertSame(204, $delete->getStatusCode());
        self::assertSame(404, $this->handler->show($this->act(self::MANAGER_A, self::TENANT_A), ['id' => (string) $id])->getStatusCode());
    }

    public function testDuplicateKeyReturns409(): void
    {
        self::assertSame(201, $this->handler->create($this->req(self::MANAGER_A, self::TENANT_A, ['key' => 'dept']))->getStatusCode());
        self::assertSame(409, $this->handler->create($this->req(self::MANAGER_A, self::TENANT_A, ['key' => 'dept']))->getStatusCode());
    }

    public function testValidationErrors(): void
    {
        self::assertSame(422, $this->handler->create($this->req(self::MANAGER_A, self::TENANT_A, ['key' => '']))->getStatusCode());
        self::assertSame(422, $this->handler->create($this->req(self::MANAGER_A, self::TENANT_A, ['key' => 'has spaces']))->getStatusCode());
        self::assertSame(422, $this->handler->create($this->req(self::MANAGER_A, self::TENANT_A, [
            'key' => 'ok',
            'display_name' => 'not-an-object',
        ]))->getStatusCode());
    }

    public function testAnotherTenantsGroupIs404NotLeaked(): void
    {
        $created = $this->handler->create($this->req(self::MANAGER_B, self::TENANT_B, ['key' => 'priority']));
        $foreignId = $this->id($created);

        // Tenant-A manager may not see, update, or delete tenant-B's group.
        self::assertSame(404, $this->handler->show($this->act(self::MANAGER_A, self::TENANT_A), ['id' => (string) $foreignId])->getStatusCode());
        self::assertSame(404, $this->handler->update($this->req(self::MANAGER_A, self::TENANT_A, ['key' => 'x']), ['id' => (string) $foreignId])->getStatusCode());
        self::assertSame(404, $this->handler->delete($this->act(self::MANAGER_A, self::TENANT_A), ['id' => (string) $foreignId])->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function act(int $userId, int $tenantId): Request
    {
        return $this->build('GET', $userId, $tenantId, '/api/tag-groups', '');
    }

    /** @param array<string, mixed> $body */
    private function req(int $userId, int $tenantId, array $body): Request
    {
        return $this->build('POST', $userId, $tenantId, '/api/tag-groups', (string) json_encode($body));
    }

    private function build(string $method, int $userId, int $tenantId, string $path, string $body): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request($method, $path, [], $body);
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];
        return $req;
    }

    private function id(\Whity\Sdk\Http\Response $res): int
    {
        self::assertSame(201, $res->getStatusCode(), $res->getBody());
        return (int) $this->data($res)['id'];
    }

    /** @return array<string, mixed> */
    private function data(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('data', $decoded);
        return $decoded['data'];
    }
}
