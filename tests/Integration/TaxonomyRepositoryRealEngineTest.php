<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\Taxonomy\EntityTagRepository;
use Whity\Core\Taxonomy\TagGroupRepository;
use Whity\Core\Taxonomy\TagRepository;

/**
 * Real-engine tests for the WC-621 taxonomy repositories: tenant isolation on
 * every operation (a tenant can never read/mutate another tenant's tags), the
 * bilingual display_name JSONB round-trip (incl. Arabic), uniqueness, and the
 * polymorphic attach/detach/reverse-lookup surface. Runs against the real
 * migration-built schema (SQLite locally, Postgres in the postgres-integration
 * CI job) via {@see SchemaFromMigrations}.
 */
final class TaxonomyRepositoryRealEngineTest extends TestCase
{
    private const TENANT_A = 1;
    private const TENANT_B = 2;

    private PDO $pdo;
    private TagGroupRepository $groups;
    private TagRepository $tags;
    private EntityTagRepository $entityTags;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT INTO tenants (id, name, slug) VALUES (1, 'a', 'a'), (2, 'b', 'b')");
        $this->groups = new TagGroupRepository($this->pdo);
        $this->tags = new TagRepository($this->pdo);
        $this->entityTags = new EntityTagRepository($this->pdo);
    }

    // ── tag groups ────────────────────────────────────────────────────────────

    public function testTagGroupCrudAndBilingualDisplayNameRoundTrip(): void
    {
        $id = $this->groups->create(self::TENANT_A, 'priority', ['ar' => 'الأولوية', 'en' => 'Priority']);
        self::assertNotNull($id);

        $row = $this->groups->find(self::TENANT_A, $id);
        self::assertNotNull($row);
        self::assertSame('priority', $row['key']);
        // Value equality (jsonb reorders keys on Postgres) — Arabic must survive.
        self::assertEquals(['ar' => 'الأولوية', 'en' => 'Priority'], $row['display_name']);

        self::assertTrue($this->groups->update(self::TENANT_A, $id, [
            'group_key' => 'urgency',
            'display_name' => ['en' => 'Urgency'],
        ]));
        $updated = $this->groups->find(self::TENANT_A, $id);
        self::assertNotNull($updated);
        self::assertSame('urgency', $updated['key']);
        self::assertEquals(['en' => 'Urgency'], $updated['display_name']);

        self::assertTrue($this->groups->delete(self::TENANT_A, $id));
        self::assertNull($this->groups->find(self::TENANT_A, $id));
    }

    public function testTagGroupKeyIsUniquePerTenantButFreePerOtherTenant(): void
    {
        self::assertNotNull($this->groups->create(self::TENANT_A, 'dept', []));
        // Same key, same tenant → rejected (null).
        self::assertNull($this->groups->create(self::TENANT_A, 'dept', []));
        // Same key, a DIFFERENT tenant → allowed.
        self::assertNotNull($this->groups->create(self::TENANT_B, 'dept', []));
    }

    public function testTagGroupIsTenantIsolated(): void
    {
        $id = (int) $this->groups->create(self::TENANT_A, 'priority', ['en' => 'Priority']);

        // Tenant B cannot read, update, or delete tenant A's group.
        self::assertNull($this->groups->find(self::TENANT_B, $id));
        self::assertFalse($this->groups->update(self::TENANT_B, $id, ['group_key' => 'hijack']));
        self::assertFalse($this->groups->delete(self::TENANT_B, $id));

        // The row is untouched under its real owner, and B's list never sees it.
        self::assertNotNull($this->groups->find(self::TENANT_A, $id));
        self::assertSame([], $this->groups->listForTenant(self::TENANT_B));
        self::assertCount(1, $this->groups->listForTenant(self::TENANT_A));
    }

    // ── tags ──────────────────────────────────────────────────────────────────

    public function testTagCrudAndGroupFilter(): void
    {
        $groupA = (int) $this->groups->create(self::TENANT_A, 'priority', []);
        $groupB = (int) $this->groups->create(self::TENANT_A, 'dept', []);

        $high = (int) $this->tags->create(self::TENANT_A, $groupA, 'high');
        $this->tags->create(self::TENANT_A, $groupA, 'low');
        $this->tags->create(self::TENANT_A, $groupB, 'sales');

        self::assertCount(3, $this->tags->listForTenant(self::TENANT_A));
        self::assertCount(2, $this->tags->listForTenant(self::TENANT_A, $groupA));
        self::assertCount(1, $this->tags->listForTenant(self::TENANT_A, $groupB));

        self::assertTrue($this->tags->rename(self::TENANT_A, $high, 'critical'));
        $row = $this->tags->find(self::TENANT_A, $high);
        self::assertNotNull($row);
        self::assertSame('critical', $row['name']);
        self::assertSame($groupA, $row['group_id']);

        self::assertTrue($this->tags->delete(self::TENANT_A, $high));
        self::assertNull($this->tags->find(self::TENANT_A, $high));
    }

    public function testTagNameIsUniquePerGroupButFreeAcrossGroups(): void
    {
        $groupA = (int) $this->groups->create(self::TENANT_A, 'priority', []);
        $groupB = (int) $this->groups->create(self::TENANT_A, 'dept', []);

        self::assertNotNull($this->tags->create(self::TENANT_A, $groupA, 'high'));
        self::assertNull($this->tags->create(self::TENANT_A, $groupA, 'high'));      // dup in same group
        self::assertNotNull($this->tags->create(self::TENANT_A, $groupB, 'high'));   // same name, other group
    }

    public function testTagIsTenantIsolated(): void
    {
        $group = (int) $this->groups->create(self::TENANT_A, 'priority', []);
        $tag = (int) $this->tags->create(self::TENANT_A, $group, 'high');

        self::assertNull($this->tags->find(self::TENANT_B, $tag));
        self::assertFalse($this->tags->rename(self::TENANT_B, $tag, 'hijack'));
        self::assertFalse($this->tags->delete(self::TENANT_B, $tag));

        self::assertNotNull($this->tags->find(self::TENANT_A, $tag));
        self::assertSame([], $this->tags->listForTenant(self::TENANT_B));
    }

    // ── entity_tags ─────────────────────────────────────────────────────────

    public function testAttachIsIdempotentAndDetachReturnsWhetherPresent(): void
    {
        $group = (int) $this->groups->create(self::TENANT_A, 'priority', []);
        $tag = (int) $this->tags->create(self::TENANT_A, $group, 'high');

        self::assertTrue($this->entityTags->attach(self::TENANT_A, 'invoice', 42, $tag));
        // A repeat attach is a no-op (the composite PK absorbs it).
        self::assertFalse($this->entityTags->attach(self::TENANT_A, 'invoice', 42, $tag));

        $tagsOn = $this->entityTags->tagsForEntity(self::TENANT_A, 'invoice', 42);
        self::assertCount(1, $tagsOn);
        self::assertSame('high', $tagsOn[0]['name']);

        self::assertTrue($this->entityTags->detach(self::TENANT_A, 'invoice', 42, $tag));
        self::assertFalse($this->entityTags->detach(self::TENANT_A, 'invoice', 42, $tag));
        self::assertSame([], $this->entityTags->tagsForEntity(self::TENANT_A, 'invoice', 42));
    }

    public function testReverseLookupReturnsEntitiesCarryingATag(): void
    {
        $group = (int) $this->groups->create(self::TENANT_A, 'priority', []);
        $tag = (int) $this->tags->create(self::TENANT_A, $group, 'high');

        $this->entityTags->attach(self::TENANT_A, 'invoice', 7, $tag);
        $this->entityTags->attach(self::TENANT_A, 'invoice', 9, $tag);
        // A different entity_type carrying the same tag is not returned.
        $this->entityTags->attach(self::TENANT_A, 'order', 7, $tag);

        $entities = $this->entityTags->entitiesForTag(self::TENANT_A, 'invoice', $tag);
        self::assertSame(
            [['entity_type' => 'invoice', 'entity_id' => 7], ['entity_type' => 'invoice', 'entity_id' => 9]],
            $entities
        );
    }

    public function testEntityTagAssociationsAreTenantIsolated(): void
    {
        $group = (int) $this->groups->create(self::TENANT_A, 'priority', []);
        $tag = (int) $this->tags->create(self::TENANT_A, $group, 'high');
        $this->entityTags->attach(self::TENANT_A, 'invoice', 42, $tag);

        // Tenant B sees nothing and cannot detach A's association.
        self::assertSame([], $this->entityTags->tagsForEntity(self::TENANT_B, 'invoice', 42));
        self::assertSame([], $this->entityTags->entitiesForTag(self::TENANT_B, 'invoice', $tag));
        self::assertFalse($this->entityTags->detach(self::TENANT_B, 'invoice', 42, $tag));

        // A's association survives.
        self::assertCount(1, $this->entityTags->tagsForEntity(self::TENANT_A, 'invoice', 42));
    }

    public function testDeletingATagCascadesItsAssociations(): void
    {
        $group = (int) $this->groups->create(self::TENANT_A, 'priority', []);
        $tag = (int) $this->tags->create(self::TENANT_A, $group, 'high');
        $this->entityTags->attach(self::TENANT_A, 'invoice', 42, $tag);

        self::assertTrue($this->tags->delete(self::TENANT_A, $tag));
        self::assertSame([], $this->entityTags->tagsForEntity(self::TENANT_A, 'invoice', 42));
    }
}
