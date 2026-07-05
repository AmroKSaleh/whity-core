<?php

declare(strict_types=1);

namespace Tests\Core\Relations;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RelationsSchema;
use Whity\Api\Exception\CrossTenantReferenceException;
use Whity\Api\Exception\DuplicateRelationException;
use Whity\Api\Exception\PersonNotFoundException;
use Whity\Api\Exception\SelfRelationException;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Relations\RelationResolver;

/**
 * Real-engine (in-memory SQLite, STRINGIFY_FETCHES on for Postgres parity) tests
 * for the WC-65 data layer: {@see PersonRepository}, {@see RelationRepository},
 * and {@see RelationResolver}.
 *
 * Covers the ADR's testing checklist: reciprocal derivation (Parent reads as
 * Child from the other end; Spouse/Sibling symmetric), self/duplicate/cross-
 * tenant integrity, auto-provision of a profile's shadow person (unique profile_id),
 * person delete cascade, and full tenant isolation (A vs B vs system).
 */
final class RelationsRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PersonRepository $persons;
    private RelationRepository $relations;
    private RelationResolver $resolver;

    protected function setUp(): void
    {
        $this->pdo = RelationsSchema::make();
        $this->persons = new PersonRepository($this->pdo);
        $this->relations = new RelationRepository($this->pdo);
        $this->resolver = new RelationResolver($this->pdo, $this->persons, $this->relations);
    }

    // ==================== Reciprocal derivation ====================

    public function testParentEdgeReadsAsChildFromTheOtherEnd(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');

        // Store ONE edge: Alice Parent-of Bob.
        $this->relations->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);

        // From Alice's side: she reads as Parent of Bob (outgoing).
        $aliceRels = $this->relations->listForPerson($alice, 1);
        $this->assertCount(1, $aliceRels);
        $this->assertSame('Parent', $aliceRels[0]['typeName']);
        $this->assertSame($bob, $aliceRels[0]['otherPersonId']);
        $this->assertSame('outgoing', $aliceRels[0]['direction']);

        // From Bob's side: the SAME single row reads as Child of Alice (incoming,
        // reciprocal-derived via inverse_type_id) — no second row was stored.
        $bobRels = $this->relations->listForPerson($bob, 1);
        $this->assertCount(1, $bobRels);
        $this->assertSame('Child', $bobRels[0]['typeName']);
        $this->assertSame($alice, $bobRels[0]['otherPersonId']);
        $this->assertSame('incoming', $bobRels[0]['direction']);

        // Exactly one physical edge exists.
        $this->assertSame(1, (int) $this->pdo->query('SELECT COUNT(*) FROM relations')->fetchColumn());
    }

    public function testSpouseAndSiblingReadSymmetricallyFromEitherEnd(): void
    {
        $a = RelationsSchema::seedPerson($this->pdo, 1, 'Ann');
        $b = RelationsSchema::seedPerson($this->pdo, 1, 'Ben');
        $this->relations->insert(1, $a, $b, RelationsSchema::TYPE_SPOUSE);

        $c = RelationsSchema::seedPerson($this->pdo, 1, 'Cara');
        $d = RelationsSchema::seedPerson($this->pdo, 1, 'Dan');
        $this->relations->insert(1, $c, $d, RelationsSchema::TYPE_SIBLING);

        // Spouse is its own inverse: reads "Spouse" from both ends.
        $this->assertSame('Spouse', $this->relations->listForPerson($a, 1)[0]['typeName']);
        $this->assertSame('Spouse', $this->relations->listForPerson($b, 1)[0]['typeName']);
        // Sibling likewise.
        $this->assertSame('Sibling', $this->relations->listForPerson($c, 1)[0]['typeName']);
        $this->assertSame('Sibling', $this->relations->listForPerson($d, 1)[0]['typeName']);
    }

    public function testRelationshipTypeVocabularyAndInversesAreCorrect(): void
    {
        $types = $this->relations->listTypes();
        $byName = [];
        foreach ($types as $t) {
            $byName[$t['name']] = $t;
        }

        $this->assertSame($byName['Child']['id'], $byName['Parent']['inverseTypeId']);
        $this->assertSame($byName['Parent']['id'], $byName['Child']['inverseTypeId']);
        $this->assertFalse($byName['Parent']['symmetric']);
        $this->assertTrue($byName['Spouse']['symmetric']);
        $this->assertSame($byName['Spouse']['id'], $byName['Spouse']['inverseTypeId']);
        $this->assertTrue($byName['Sibling']['symmetric']);
    }

    // ==================== Integrity (via the resolver) ====================

    public function testSelfRelationIsRejected(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');

        $this->expectException(SelfRelationException::class);
        $this->resolver->resolveRelation(
            ['kind' => 'person', 'id' => $alice],
            ['kind' => 'person', 'id' => $alice],
            RelationsSchema::TYPE_SIBLING,
            1
        );
    }

    public function testDuplicateRelationIsRejected(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');
        $this->relations->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);

        $this->expectException(DuplicateRelationException::class);
        $this->resolver->resolveRelation(
            ['kind' => 'person', 'id' => $alice],
            ['kind' => 'person', 'id' => $bob],
            RelationsSchema::TYPE_PARENT,
            1
        );
    }

    public function testUniqueConstraintBacksTheNoDuplicateRule(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');
        $this->relations->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);

        $this->expectException(\PDOException::class);
        // Bypass the resolver's pre-check to prove the DB UNIQUE constraint is the
        // backstop against a duplicate directed edge.
        $this->relations->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);
    }

    public function testCrossTenantReferenceIsTreatedAsNotFound(): void
    {
        // Alice in tenant 1, Carol in tenant 2.
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $carol = RelationsSchema::seedPerson($this->pdo, 2, 'Carol');

        $this->expectException(CrossTenantReferenceException::class);
        $this->resolver->resolveRelation(
            ['kind' => 'person', 'id' => $alice],
            ['kind' => 'person', 'id' => $carol],
            RelationsSchema::TYPE_SIBLING,
            1
        );
    }

    public function testMissingPersonReferenceIsNotFound(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');

        $this->expectException(PersonNotFoundException::class);
        $this->resolver->resolveRelation(
            ['kind' => 'person', 'id' => $alice],
            ['kind' => 'person', 'id' => 9999],
            RelationsSchema::TYPE_SIBLING,
            1
        );
    }

    public function testMissingRelationshipTypeIsNotFound(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');

        $this->expectException(PersonNotFoundException::class);
        $this->resolver->resolveRelation(
            ['kind' => 'person', 'id' => $alice],
            ['kind' => 'person', 'id' => $bob],
            777,
            1
        );
    }

    // ==================== Auto-provision of a profile's shadow person ====================

    public function testResolvingAProfileAutoProvisionsAShadowPersonOnce(): void
    {
        $profileId = RelationsSchema::seedProfile($this->pdo, 1, 'dave@example.com');

        // No person row for the profile yet.
        $this->assertNull($this->persons->findByProfileId($profileId, 1));

        $personId = $this->resolver->resolveRef(RelationResolver::KIND_PROFILE, $profileId, 1);

        $shadow = $this->persons->findById($personId, 1);
        $this->assertNotNull($shadow);
        $this->assertSame($profileId, $shadow['profile_id']);
        // Display name seeded from the email local part.
        $this->assertSame('dave', $shadow['display_name']);

        // Resolving again returns the SAME shadow (profile_id stays unique).
        $again = $this->resolver->resolveRef(RelationResolver::KIND_PROFILE, $profileId, 1);
        $this->assertSame($personId, $again);
        $this->assertSame(
            1,
            (int) $this->pdo->query('SELECT COUNT(*) FROM persons WHERE profile_id = ' . $profileId)->fetchColumn(),
            'A profile must have at most one shadow person.'
        );
    }

    public function testProfileToPersonRelationAutoProvisionsBothShadows(): void
    {
        $p1 = RelationsSchema::seedProfile($this->pdo, 1, 'parent@example.com');
        $p2 = RelationsSchema::seedProfile($this->pdo, 1, 'kid@example.com');

        $resolved = $this->resolver->resolveRelation(
            ['kind' => 'profile', 'id' => $p1],
            ['kind' => 'profile', 'id' => $p2],
            RelationsSchema::TYPE_PARENT,
            1
        );
        $this->relations->insert(1, $resolved['fromPersonId'], $resolved['toPersonId'], $resolved['relationshipTypeId']);

        // Both profiles now have a shadow; the edge reads Parent/Child reciprocally.
        $parentPerson = $this->persons->findByProfileId($p1, 1);
        $kidPerson    = $this->persons->findByProfileId($p2, 1);
        $this->assertNotNull($parentPerson);
        $this->assertNotNull($kidPerson);

        $this->assertSame('Parent', $this->relations->listForPerson((int) $parentPerson['id'], 1)[0]['typeName']);
        $this->assertSame('Child', $this->relations->listForPerson((int) $kidPerson['id'], 1)[0]['typeName']);
    }

    // ==================== ON DELETE SET NULL on profile delete ====================

    public function testDeletingAProfileSetsPersonProfileIdToNull(): void
    {
        $profileId = RelationsSchema::seedProfile($this->pdo, 1, 'test@example.com');
        $personId  = $this->persons->insert(1, 'test', $profileId);

        // Confirm the link.
        $before = $this->persons->findById($personId, 1);
        $this->assertNotNull($before, 'Person row must exist before profile deletion.');
        $this->assertSame($profileId, $before['profile_id']);

        // Delete the profile — ON DELETE SET NULL must fire.
        $this->pdo->exec('DELETE FROM profiles WHERE id = ' . $profileId);

        // Person survives but loses its profile link.
        $after = $this->persons->findById($personId, 1);
        $this->assertNotNull($after, 'Person row must survive profile deletion.');
        $this->assertNull($after['profile_id'], 'profile_id must be NULL after profile is deleted (ON DELETE SET NULL).');
    }

    // ==================== Person delete cascade ====================

    public function testDeletingANonUserPersonCascadesItsEdges(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');
        $this->relations->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);

        $this->assertSame(1, $this->persons->relationCount($alice, 1));

        $this->persons->delete($alice, 1);

        // The edge is gone (FK cascade) and Bob no longer reports the relation.
        $this->assertSame(0, (int) $this->pdo->query('SELECT COUNT(*) FROM relations')->fetchColumn());
        $this->assertCount(0, $this->relations->listForPerson($bob, 1));
    }

    // ==================== Tenant isolation ====================

    public function testTenantsSeeOnlyTheirOwnPersonsAndSystemSeesAll(): void
    {
        RelationsSchema::seedPerson($this->pdo, 1, 'Alice-A');
        RelationsSchema::seedPerson($this->pdo, 1, 'Bob-A');
        RelationsSchema::seedPerson($this->pdo, 2, 'Carol-B');

        $this->assertCount(2, $this->persons->list(1), 'Tenant 1 sees only its own persons.');
        $this->assertCount(1, $this->persons->list(2), 'Tenant 2 sees only its own persons.');
        $this->assertCount(3, $this->persons->list(0), 'System tenant sees all persons.');
    }

    public function testTenantCannotReadAnotherTenantsPersonById(): void
    {
        $carol = RelationsSchema::seedPerson($this->pdo, 2, 'Carol-B');

        $this->assertNull($this->persons->findById($carol, 1), 'Tenant 1 must not read tenant 2 person.');
        $this->assertNotNull($this->persons->findById($carol, 2));
        $this->assertNotNull($this->persons->findById($carol, 0), 'System tenant may read it.');
    }

    public function testRelationsListingIsTenantScoped(): void
    {
        // Identical edge ids could collide across tenants; ensure scope filters.
        $a1 = RelationsSchema::seedPerson($this->pdo, 1, 'A1');
        $b1 = RelationsSchema::seedPerson($this->pdo, 1, 'B1');
        $this->relations->insert(1, $a1, $b1, RelationsSchema::TYPE_PARENT);

        $a2 = RelationsSchema::seedPerson($this->pdo, 2, 'A2');
        $b2 = RelationsSchema::seedPerson($this->pdo, 2, 'B2');
        $this->relations->insert(2, $a2, $b2, RelationsSchema::TYPE_PARENT);

        $this->assertCount(1, $this->relations->listEdges(1));
        $this->assertCount(1, $this->relations->listEdges(2));
        $this->assertCount(2, $this->relations->listEdges(0), 'System tenant sees all edges.');
    }

    public function testSearchFiltersPersonsByDisplayNameCaseInsensitively(): void
    {
        RelationsSchema::seedPerson($this->pdo, 1, 'Alice Smith');
        RelationsSchema::seedPerson($this->pdo, 1, 'Bob Jones');

        $hits = $this->persons->list(1, 'smith');
        $this->assertCount(1, $hits);
        $this->assertSame('Alice Smith', $hits[0]['display_name']);
    }
}
