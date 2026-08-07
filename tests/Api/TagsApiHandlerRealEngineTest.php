<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\TagsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see TagsApiHandler} (WC-621): RBAC (tags:read vs
 * tags:manage), CRUD, the optional group_id filter, 422 when the target group
 * is not the caller's tenant's, 409 on a duplicate name, and 404 for another
 * tenant's tag.
 */
final class TagsApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const MANAGER_A = 10;
    private const VIEWER_A  = 11;
    private const MANAGER_B = 20;

    private PDO $pdo;
    private TagsApiHandler $handler;
    private int $groupA;
    private int $groupA2;
    private int $groupB;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = TaxonomyTestSeed::make();
        $db = TaxonomyTestSeed::wrap($this->pdo);

        $groups = new TagGroupRepository($this->pdo);
        $this->groupA = (int) $groups->create(self::TENANT_A, 'priority', []);
        $this->groupA2 = (int) $groups->create(self::TENANT_A, 'dept', []);
        $this->groupB = (int) $groups->create(self::TENANT_B, 'priority', []);

        $this->handler = new TagsApiHandler(
            new TagRepository($this->pdo),
            $groups,
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
        self::assertSame(200, $this->handler->list($this->get(self::VIEWER_A, self::TENANT_A, '/api/tags'))->getStatusCode());
        $res = $this->handler->create($this->post(self::VIEWER_A, self::TENANT_A, ['group_id' => $this->groupA, 'name' => 'high']));
        self::assertSame(403, $res->getStatusCode());
    }

    public function testManagerCrudHappyPath(): void
    {
        $created = $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupA, 'name' => 'high']));
        self::assertSame(201, $created->getStatusCode(), $created->getBody());
        $id = $this->id($created);

        self::assertSame(200, $this->handler->show($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags'), ['id' => (string) $id])->getStatusCode());

        $rename = $this->handler->update($this->post(self::MANAGER_A, self::TENANT_A, ['name' => 'critical']), ['id' => (string) $id]);
        self::assertSame(200, $rename->getStatusCode());
        self::assertSame('critical', $this->data($rename)['name']);

        self::assertSame(204, $this->handler->delete($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags'), ['id' => (string) $id])->getStatusCode());
        self::assertSame(404, $this->handler->show($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags'), ['id' => (string) $id])->getStatusCode());
    }

    public function testGroupFilterNarrowsList(): void
    {
        $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupA, 'name' => 'high']));
        $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupA, 'name' => 'low']));
        $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupA2, 'name' => 'sales']));

        self::assertCount(3, $this->data($this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags'))));
        self::assertCount(2, $this->data($this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags?group_id=' . $this->groupA))));
        self::assertCount(1, $this->data($this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags?group_id=' . $this->groupA2))));
    }

    public function testCreatingInAForeignGroupIs422(): void
    {
        // groupB belongs to tenant 2; a tenant-1 caller must not be able to use it.
        $res = $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupB, 'name' => 'high']));
        self::assertSame(422, $res->getStatusCode());
    }

    public function testDuplicateNameReturns409(): void
    {
        self::assertSame(201, $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupA, 'name' => 'high']))->getStatusCode());
        self::assertSame(409, $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupA, 'name' => 'high']))->getStatusCode());
    }

    public function testValidationErrors(): void
    {
        self::assertSame(422, $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['name' => 'high']))->getStatusCode());
        self::assertSame(422, $this->handler->create($this->post(self::MANAGER_A, self::TENANT_A, ['group_id' => $this->groupA, 'name' => '']))->getStatusCode());
    }

    public function testAnotherTenantsTagIs404NotLeaked(): void
    {
        $created = $this->handler->create($this->post(self::MANAGER_B, self::TENANT_B, ['group_id' => $this->groupB, 'name' => 'high']));
        $foreignId = $this->id($created);

        self::assertSame(404, $this->handler->show($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags'), ['id' => (string) $foreignId])->getStatusCode());
        self::assertSame(404, $this->handler->update($this->post(self::MANAGER_A, self::TENANT_A, ['name' => 'x']), ['id' => (string) $foreignId])->getStatusCode());
        self::assertSame(404, $this->handler->delete($this->get(self::MANAGER_A, self::TENANT_A, '/api/tags'), ['id' => (string) $foreignId])->getStatusCode());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function get(int $userId, int $tenantId, string $path): Request
    {
        return $this->build('GET', $userId, $tenantId, $path, '');
    }

    /** @param array<string, mixed> $body */
    private function post(int $userId, int $tenantId, array $body): Request
    {
        return $this->build('POST', $userId, $tenantId, '/api/tags', (string) json_encode($body));
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
