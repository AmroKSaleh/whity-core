<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\TaxonomyTestSeed;
use Whity\Api\EntityTagsApiHandler;
use Whity\Api\TagGroupsApiHandler;
use Whity\Api\TagsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditLoggerInterface;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Taxonomy\EntityTagRepository;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;
use Whity\Core\Tenant\TenantContext;

/**
 * Regression tests for the WC-714 destructive-delete hazard (§5) and the
 * orphaned-association hazard (§6).
 *
 * THE BUG (§5): `DELETE /api/tag-groups/{id}` and `DELETE /api/tags/{id}` were
 * unguarded. The FK cascade runs two levels deep — dropping a group drops its
 * tags, which drops every `entity_tags` row referencing them — so a single
 * request by any holder of `tags:manage` silently destroyed an unbounded number
 * of associations belonging to plugins that had no idea it happened. No
 * warning, no count, no audit record.
 *
 * Every `...survive...` assertion below FAILS against the pre-fix code (the
 * delete returned 204 and the associations were gone) and passes with the
 * guard, which is the point of this file.
 *
 * THE BUG (§6): nothing removed `entity_tags` rows when the tagged plugin
 * record was deleted, so a later record REUSING the same `entity_id` silently
 * inherited the dead record's tags.
 */
final class TaxonomyDestructiveDeleteRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private const MANAGER_A = 10; // tags:read + tags:manage in tenant 1
    private const MANAGER_B = 20; // tags:read + tags:manage in tenant 2

    private PDO $pdo;

    private TagGroupsApiHandler $groupsHandler;
    private TagsApiHandler $tagsHandler;
    private EntityTagsApiHandler $entityTagsHandler;

    /** @var list<array{action: string, options: array<string, mixed>}> */
    private array $audit = [];

    private int $groupA;
    private int $tagHigh;
    private int $tagLow;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        $this->pdo = TaxonomyTestSeed::make();
        $db = TaxonomyTestSeed::wrap($this->pdo);

        $groups = new TagGroupRepository($this->pdo);
        $tags = new TagRepository($this->pdo);
        $entityTags = new EntityTagRepository($this->pdo);
        $roleChecker = new RoleChecker($db, new PermissionRegistry());

        $this->audit = [];
        $recorder = $this->auditRecorder();

        $this->groupsHandler = new TagGroupsApiHandler($groups, $roleChecker, $recorder);
        $this->tagsHandler = new TagsApiHandler($tags, $groups, $roleChecker, $recorder);
        $this->entityTagsHandler = new EntityTagsApiHandler($entityTags, $tags, $roleChecker);

        // Tenant A: one group, two tags.
        $this->groupA = (int) $groups->create(self::TENANT_A, 'priority', ['en' => 'Priority', 'ar' => 'الأولوية']);
        $this->tagHigh = (int) $tags->create(self::TENANT_A, $this->groupA, 'high');
        $this->tagLow = (int) $tags->create(self::TENANT_A, $this->groupA, 'low');
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        RoleChecker::clearCache();
    }

    // ── §5: deleting a tag GROUP ─────────────────────────────────────────────

    public function testDeletingAGroupIsRefusedWhileAssociationsSurviveIntact(): void
    {
        // Two different plugins' records carry tags from this group.
        $this->attach('acme:record', 42, $this->tagHigh);
        $this->attach('other:batch', 7, $this->tagLow);
        self::assertSame(2, $this->associationCount(), 'precondition: two associations exist');

        $res = $this->groupsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tag-groups/' . $this->groupA), ['id' => (string) $this->groupA]);

        self::assertSame(409, $res->getStatusCode(), $res->getBody());

        // THE REGRESSION: the associations must still be there. Pre-fix this
        // returned 204 and both rows were cascaded away.
        self::assertSame(2, $this->associationCount(), 'a refused delete must not destroy any association');
        self::assertNotNull((new TagGroupRepository($this->pdo))->find(self::TENANT_A, $this->groupA), 'the group itself must survive a refused delete');

        // The caller is told the exact blast radius, not just "no".
        $details = $this->details($res);
        self::assertSame(2, $details['associations']);
        self::assertSame(2, $details['tags']);
        self::assertStringContainsString('force=true', (string) $this->errorMessage($res));

        // Nothing destructive happened, so nothing is audited.
        self::assertSame([], $this->audit);
    }

    public function testDeletingAGroupWithNoAssociationsStillSucceeds(): void
    {
        $res = $this->groupsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tag-groups/' . $this->groupA), ['id' => (string) $this->groupA]);

        self::assertSame(204, $res->getStatusCode(), 'the guard must not block an unreferenced group');
        self::assertNull((new TagGroupRepository($this->pdo))->find(self::TENANT_A, $this->groupA));

        self::assertCount(1, $this->audit);
        self::assertSame('taxonomy.tag_group.deleted', $this->audit[0]['action']);
        self::assertFalse($this->audit[0]['options']['metadata']['forced']);
        self::assertSame(0, $this->audit[0]['options']['metadata']['associations_deleted']);
    }

    public function testForcingAGroupDeleteRemovesAssociationsAndRecordsWhatWasDestroyed(): void
    {
        $this->attach('acme:record', 42, $this->tagHigh);
        $this->attach('other:batch', 7, $this->tagLow);

        $res = $this->groupsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tag-groups/' . $this->groupA . '?force=true'), ['id' => (string) $this->groupA]);

        self::assertSame(204, $res->getStatusCode(), $res->getBody());
        self::assertSame(0, $this->associationCount(), 'a forced delete does cascade');
        self::assertNull((new TagGroupRepository($this->pdo))->find(self::TENANT_A, $this->groupA));

        // The destruction is recorded with its full blast radius — the whole
        // point of forcing rather than silently cascading.
        self::assertCount(1, $this->audit);
        self::assertSame('taxonomy.tag_group.deleted', $this->audit[0]['action']);
        $options = $this->audit[0]['options'];
        self::assertSame(self::TENANT_A, $options['tenant_id']);
        self::assertSame(self::MANAGER_A, $options['actor_user_id']);
        self::assertSame('tag_group', $options['target_type']);
        self::assertSame($this->groupA, $options['target_id']);
        self::assertSame('priority', $options['metadata']['group_key']);
        self::assertTrue($options['metadata']['forced']);
        self::assertSame(2, $options['metadata']['tags_deleted']);
        self::assertSame(2, $options['metadata']['associations_deleted']);
    }

    /**
     * `force` is consent, so only an explicit affirmative token counts. A
     * stray/garbled value must fall back to the SAFE branch (refuse), never be
     * read as permission to destroy data.
     *
     * @param string $value
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('nonAffirmativeForceValues')]
    public function testOnlyAnExplicitAffirmativeForceValueUnlocksTheDelete(string $value): void
    {
        $this->attach('acme:record', 42, $this->tagHigh);

        $res = $this->groupsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tag-groups/' . $this->groupA . '?force=' . $value), ['id' => (string) $this->groupA]);

        self::assertSame(409, $res->getStatusCode(), 'force=' . $value . ' must not be read as consent');
        self::assertSame(1, $this->associationCount());
    }

    /** @return list<array{string}> */
    public static function nonAffirmativeForceValues(): array
    {
        return [['false'], ['0'], [''], ['maybe'], ['TRUE'], ['yes']];
    }

    public function testTheGuardCountsOnlyTheCallersOwnTenant(): void
    {
        // Tenant B has its own group + tag + association, on the SAME
        // entity_type/entity_id as tenant A's. Tenant A's guard must neither
        // see nor be blocked by them.
        $groups = new TagGroupRepository($this->pdo);
        $tags = new TagRepository($this->pdo);
        $groupB = (int) $groups->create(self::TENANT_B, 'priority', []);
        $tagB = (int) $tags->create(self::TENANT_B, $groupB, 'high');
        $this->attach('acme:record', 42, $tagB, self::MANAGER_B, self::TENANT_B);

        // Tenant A's group is unreferenced → the guard lets it through.
        $res = $this->groupsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tag-groups/' . $this->groupA), ['id' => (string) $this->groupA]);
        self::assertSame(204, $res->getStatusCode(), $res->getBody());

        // Tenant B's association is untouched by tenant A's delete.
        self::assertSame(1, $this->associationCount(self::TENANT_B));
    }

    // ── §5: deleting a single TAG ────────────────────────────────────────────

    public function testDeletingATagIsRefusedWhileAssociationsSurviveIntact(): void
    {
        $this->attach('acme:record', 42, $this->tagHigh);
        $this->attach('acme:record', 43, $this->tagHigh);
        $this->attach('acme:record', 44, $this->tagLow);

        $res = $this->tagsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tags/' . $this->tagHigh), ['id' => (string) $this->tagHigh]);

        self::assertSame(409, $res->getStatusCode(), $res->getBody());
        self::assertSame(2, $this->details($res)['associations'], 'only this tag\'s associations are counted');

        // THE REGRESSION: all three associations survive the refusal.
        self::assertSame(3, $this->associationCount());
        self::assertNotNull((new TagRepository($this->pdo))->find(self::TENANT_A, $this->tagHigh));
        self::assertSame([], $this->audit);
    }

    public function testForcingATagDeleteRemovesOnlyThatTagsAssociations(): void
    {
        $this->attach('acme:record', 42, $this->tagHigh);
        $this->attach('acme:record', 44, $this->tagLow);

        $res = $this->tagsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tags/' . $this->tagHigh . '?force=true'), ['id' => (string) $this->tagHigh]);

        self::assertSame(204, $res->getStatusCode(), $res->getBody());
        self::assertSame(1, $this->associationCount(), 'the OTHER tag\'s association is untouched');
        self::assertNull((new TagRepository($this->pdo))->find(self::TENANT_A, $this->tagHigh));
        self::assertNotNull((new TagRepository($this->pdo))->find(self::TENANT_A, $this->tagLow));

        self::assertCount(1, $this->audit);
        self::assertSame('taxonomy.tag.deleted', $this->audit[0]['action']);
        self::assertSame('high', $this->audit[0]['options']['metadata']['name']);
        self::assertTrue($this->audit[0]['options']['metadata']['forced']);
        self::assertSame(1, $this->audit[0]['options']['metadata']['associations_deleted']);
    }

    public function testAForeignTenantsTagIsStill404NotAGuardLeak(): void
    {
        $groups = new TagGroupRepository($this->pdo);
        $tags = new TagRepository($this->pdo);
        $groupB = (int) $groups->create(self::TENANT_B, 'priority', []);
        $tagB = (int) $tags->create(self::TENANT_B, $groupB, 'high');
        $this->attach('acme:record', 42, $tagB, self::MANAGER_B, self::TENANT_B);

        // Tenant A must get a plain 404 — never a 409 that would confirm the
        // foreign tag exists AND leak how many records reference it.
        $res = $this->tagsHandler->delete($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/tags/' . $tagB), ['id' => (string) $tagB]);
        self::assertSame(404, $res->getStatusCode());
        self::assertSame(1, $this->associationCount(self::TENANT_B), 'tenant B\'s data is untouched');
    }

    // ── §6: detaching every tag from one entity ──────────────────────────────

    public function testDetachAllRemovesOnlyTheTargetEntitysAssociations(): void
    {
        $this->attach('acme:record', 42, $this->tagHigh);
        $this->attach('acme:record', 42, $this->tagLow);
        $this->attach('acme:record', 99, $this->tagHigh);   // a different record
        $this->attach('other:batch', 42, $this->tagHigh);   // same id, different type

        $res = $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record&entity_id=42'));

        self::assertSame(200, $res->getStatusCode(), $res->getBody());
        self::assertSame(2, $this->payload($res)['removed']);
        self::assertSame(2, $this->associationCount(), 'the other record and the other entity_type are untouched');
    }

    /**
     * The sharper half of §6: an id-reusing successor must not inherit the dead
     * record's tags. Cleaning up on delete is what makes that true.
     */
    public function testAnIdReusingSuccessorDoesNotInheritTheDeadRecordsTags(): void
    {
        $this->attach('acme:record', 42, $this->tagHigh);

        // The plugin deletes record 42 and calls the cleanup hook.
        $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record&entity_id=42'));

        // A brand-new record later reuses id 42.
        $tagsOnSuccessor = $this->data($this->entityTagsHandler->list($this->req('GET', self::MANAGER_A, self::TENANT_A, '/api/entity-tags?entity_type=acme:record&entity_id=42')));
        self::assertSame([], $tagsOnSuccessor, 'a reused entity_id must start with no tags');
    }

    public function testDetachAllIsTenantScoped(): void
    {
        $groups = new TagGroupRepository($this->pdo);
        $tags = new TagRepository($this->pdo);
        $groupB = (int) $groups->create(self::TENANT_B, 'priority', []);
        $tagB = (int) $tags->create(self::TENANT_B, $groupB, 'high');

        $this->attach('acme:record', 42, $this->tagHigh);
        $this->attach('acme:record', 42, $tagB, self::MANAGER_B, self::TENANT_B);

        $res = $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record&entity_id=42'));

        self::assertSame(1, $this->payload($res)['removed']);
        self::assertSame(0, $this->associationCount(self::TENANT_A));
        self::assertSame(1, $this->associationCount(self::TENANT_B), 'the other tenant\'s association on the same (type,id) survives');
    }

    public function testDetachAllOnAnUntaggedEntityIsASuccessfulNoOp(): void
    {
        $res = $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record&entity_id=42'));

        self::assertSame(200, $res->getStatusCode());
        self::assertSame(0, $this->payload($res)['removed']);
    }

    public function testDetachAllValidatesItsQueryAndRequiresManage(): void
    {
        self::assertSame(422, $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_id=42'))->getStatusCode(), 'entity_type is required');
        self::assertSame(422, $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record'))->getStatusCode(), 'entity_id is required');
        self::assertSame(422, $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record&entity_id=0'))->getStatusCode(), 'entity_id must be positive');
        self::assertSame(422, $this->entityTagsHandler->detachAll($this->req('DELETE', self::MANAGER_A, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record&entity_id=abc'))->getStatusCode(), 'entity_id must be numeric');

        // A read-only caller may not mass-detach.
        self::assertSame(403, $this->entityTagsHandler->detachAll($this->req('DELETE', 11, self::TENANT_A, '/api/entity-tags/all?entity_type=acme:record&entity_id=42'))->getStatusCode());
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function attach(string $entityType, int $entityId, int $tagId, int $userId = self::MANAGER_A, int $tenantId = self::TENANT_A): void
    {
        $req = $this->build('POST', $userId, $tenantId, '/api/entity-tags', (string) json_encode([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'tag_id'      => $tagId,
        ]));
        $res = $this->entityTagsHandler->attach($req);
        self::assertSame(201, $res->getStatusCode(), 'attach precondition failed: ' . $res->getBody());
    }

    private function associationCount(int $tenantId = self::TENANT_A): int
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM entity_tags WHERE tenant_id = :tenant_id');
        $stmt->execute([':tenant_id' => $tenantId]);

        return (int) $stmt->fetchColumn();
    }

    private function auditRecorder(): AuditLoggerInterface
    {
        $test = $this;

        return new class ($test) implements AuditLoggerInterface {
            private TaxonomyDestructiveDeleteRealEngineTest $test;

            public function __construct(TaxonomyDestructiveDeleteRealEngineTest $test)
            {
                $this->test = $test;
            }

            /** @param array<string, mixed> $options */
            public function record(string $action, array $options = []): void
            {
                $this->test->recordAudit($action, $options);
            }
        };
    }

    /**
     * @param array<string, mixed> $options
     * @internal Used by the anonymous {@see AuditLoggerInterface} double above.
     */
    public function recordAudit(string $action, array $options): void
    {
        $this->audit[] = ['action' => $action, 'options' => $options];
    }

    private function req(string $method, int $userId, int $tenantId, string $path): Request
    {
        return $this->build($method, $userId, $tenantId, $path, '');
    }

    private function build(string $method, int $userId, int $tenantId, string $path, string $body): Request
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $req = new Request($method, $path, [], $body);
        $req->user = (object) ['profile_id' => $userId, 'active_tenant_id' => $tenantId];

        return $req;
    }

    /** @return array<string, mixed> */
    private function payload(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('data', $decoded);

        return $decoded['data'];
    }

    /** @return list<array<string, mixed>> */
    private function data(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('data', $decoded);

        return $decoded['data'];
    }

    /** @return array<string, mixed> */
    private function details(\Whity\Sdk\Http\Response $res): array
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);
        self::assertArrayHasKey('details', $decoded, 'the 409 must report the blast radius: ' . $res->getBody());

        return $decoded['details'];
    }

    private function errorMessage(\Whity\Sdk\Http\Response $res): string
    {
        $decoded = json_decode($res->getBody(), true);
        self::assertIsArray($decoded);

        return (string) ($decoded['error'] ?? '');
    }
}
