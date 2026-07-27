<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\EntityTagsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Taxonomy\EntityTagRepository;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see EntityTagsApiHandler} (WC-621): RBAC, idempotent
 * attach + detach, the two-directional filter (an entity's tags / entities
 * carrying a tag), 422 when attaching a foreign-tenant tag, query validation,
 * and cross-tenant isolation of the associations.
 */
final class EntityTagsApiHandlerRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const MANAGER_A = 10;
    private const VIEWER_A  = 11;
    private const MANAGER_B = 20;

    private PDO $pdo;
    private EntityTagsApiHandler $handler;
    private int $tagA;
    private int $tagB;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = TaxonomyTestSeed::make();
        $db = TaxonomyTestSeed::wrap($this->pdo);

        $groups = new TagGroupRepository($this->pdo);
        $tags = new TagRepository($this->pdo);
        $groupA = (int) $groups->create(self::TENANT_A, 'priority', []);
        $groupB = (int) $groups->create(self::TENANT_B, 'priority', []);
        $this->tagA = (int) $tags->create(self::TENANT_A, $groupA, 'high');
        $this->tagB = (int) $tags->create(self::TENANT_B, $groupB, 'high');

        $this->handler = new EntityTagsApiHandler(
            new EntityTagRepository($this->pdo),
            $tags,
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
        $read = $this->handler->list($this->get(self::VIEWER_A, self::TENANT_A, '/api/entity-tags?entity_type=invoice&entity_id=1'));
        self::assertSame(200, $read->getStatusCode());

        $attach = $this->handler->attach($this->body(self::VIEWER_A, self::TENANT_A, 'POST', ['entity_type' => 'invoice', 'entity_id' => 1, 'tag_id' => $this->tagA]));
        self::assertSame(403, $attach->getStatusCode());
    }

    public function testAttachIsIdempotentThenDetach(): void
    {
        $first = $this->handler->attach($this->body(self::MANAGER_A, self::TENANT_A, 'POST', ['entity_type' => 'invoice', 'entity_id' => 42, 'tag_id' => $this->tagA]));
        self::assertSame(201, $first->getStatusCode(), $first->getBody());

        $again = $this->handler->attach($this->body(self::MANAGER_A, self::TENANT_A, 'POST', ['entity_type' => 'invoice', 'entity_id' => 42, 'tag_id' => $this->tagA]));
        self::assertSame(200, $again->getStatusCode(), 'a repeated attach is idempotent (200, not a new 201)');

        $tagsOn = $this->data($this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/entity-tags?entity_type=invoice&entity_id=42')));
        self::assertCount(1, $tagsOn);
        self::assertSame('high', $tagsOn[0]['name']);

        self::assertSame(204, $this->handler->detach($this->body(self::MANAGER_A, self::TENANT_A, 'DELETE', ['entity_type' => 'invoice', 'entity_id' => 42, 'tag_id' => $this->tagA]))->getStatusCode());
        self::assertSame(404, $this->handler->detach($this->body(self::MANAGER_A, self::TENANT_A, 'DELETE', ['entity_type' => 'invoice', 'entity_id' => 42, 'tag_id' => $this->tagA]))->getStatusCode());
    }

    public function testReverseLookupReturnsEntitiesCarryingTheTag(): void
    {
        $this->handler->attach($this->body(self::MANAGER_A, self::TENANT_A, 'POST', ['entity_type' => 'invoice', 'entity_id' => 7, 'tag_id' => $this->tagA]));
        $this->handler->attach($this->body(self::MANAGER_A, self::TENANT_A, 'POST', ['entity_type' => 'invoice', 'entity_id' => 9, 'tag_id' => $this->tagA]));

        $entities = $this->data($this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/entity-tags?entity_type=invoice&tag_id=' . $this->tagA)));
        self::assertSame(
            [['entity_type' => 'invoice', 'entity_id' => 7], ['entity_type' => 'invoice', 'entity_id' => 9]],
            $entities
        );
    }

    public function testAttachingAForeignTenantTagIs422(): void
    {
        // tagB belongs to tenant 2; a tenant-1 caller cannot attach it.
        $res = $this->handler->attach($this->body(self::MANAGER_A, self::TENANT_A, 'POST', ['entity_type' => 'invoice', 'entity_id' => 1, 'tag_id' => $this->tagB]));
        self::assertSame(422, $res->getStatusCode());
    }

    public function testListQueryValidation(): void
    {
        // entity_type missing.
        self::assertSame(422, $this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/entity-tags?entity_id=1'))->getStatusCode());
        // both entity_id and tag_id.
        self::assertSame(422, $this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/entity-tags?entity_type=invoice&entity_id=1&tag_id=2'))->getStatusCode());
        // neither entity_id nor tag_id.
        self::assertSame(422, $this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/entity-tags?entity_type=invoice'))->getStatusCode());
    }

    public function testAssociationsAreTenantIsolated(): void
    {
        // Manager B attaches tag B to an entity in tenant 2.
        self::assertSame(201, $this->handler->attach($this->body(self::MANAGER_B, self::TENANT_B, 'POST', ['entity_type' => 'invoice', 'entity_id' => 42, 'tag_id' => $this->tagB]))->getStatusCode());

        // Tenant-A caller sees nothing for that entity and cannot detach it.
        self::assertSame([], $this->data($this->handler->list($this->get(self::MANAGER_A, self::TENANT_A, '/api/entity-tags?entity_type=invoice&entity_id=42'))));
        self::assertSame(404, $this->handler->detach($this->body(self::MANAGER_A, self::TENANT_A, 'DELETE', ['entity_type' => 'invoice', 'entity_id' => 42, 'tag_id' => $this->tagB]))->getStatusCode());

        // Tenant B's association survives.
        self::assertCount(1, $this->data($this->handler->list($this->get(self::MANAGER_B, self::TENANT_B, '/api/entity-tags?entity_type=invoice&entity_id=42'))));
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function get(int $userId, int $tenantId, string $path): Request
    {
        return $this->build('GET', $userId, $tenantId, $path, '');
    }

    /** @param array<string, mixed> $body */
    private function body(int $userId, int $tenantId, string $method, array $body): Request
    {
        return $this->build($method, $userId, $tenantId, '/api/entity-tags', (string) json_encode($body));
    }

    private function build(string $method, int $userId, int $tenantId, string $path, string $body): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request($method, $path, [], $body);
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];
        return $req;
    }

    /** @return list<array<string, mixed>> */
    private function data(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('data', $decoded);
        return $decoded['data'];
    }
}
