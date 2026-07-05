<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\RelationsSchema;
use Whity\Api\PersonsApiHandler;
use Whity\Api\RelationsApiHandler;
use Whity\Core\Relations\PersonRepository;
use Whity\Core\Relations\RelationRepository;
use Whity\Core\Relations\RelationResolver;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine (in-memory SQLite, STRINGIFY_FETCHES on) tests for the WC-65 API
 * handlers — {@see PersonsApiHandler} and {@see RelationsApiHandler} — exercising
 * the HTTP behaviour the kernel sees: status codes, the typed-exception → 4xx
 * translations, the person delete guard, and auto-provision through the API.
 *
 * Tenant context is set directly (the RbacMiddleware/Router path is covered by
 * the integration test); these focus on handler semantics.
 */
final class RelationsApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;
    private PersonsApiHandler $persons;
    private RelationsApiHandler $relations;
    private PersonRepository $personRepo;
    private RelationRepository $relationRepo;

    protected function setUp(): void
    {
        $this->pdo = RelationsSchema::make();
        $this->personRepo = new PersonRepository($this->pdo);
        $this->relationRepo = new RelationRepository($this->pdo);
        $resolver = new RelationResolver($this->pdo, $this->personRepo, $this->relationRepo);

        $this->persons = new PersonsApiHandler($this->personRepo, $this->relationRepo);
        $this->relations = new RelationsApiHandler($this->personRepo, $this->relationRepo, $resolver);

        TenantContext::reset();
        TenantContext::setTenantId(1);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== Persons CRUD ====================

    public function testCreateAndListPerson(): void
    {
        $create = $this->persons->create(
            new Request('POST', '/api/persons', [], (string) json_encode(['displayName' => 'Grandpa Joe', 'deceased' => true]))
        );
        $this->assertSame(201, $create->getStatusCode());
        $created = json_decode($create->getBody(), true)['data'];
        $this->assertSame('Grandpa Joe', $created['displayName']);
        $this->assertFalse($created['hasAccount']);
        $this->assertTrue($created['deceased']);

        $list = $this->persons->list(new Request('GET', '/api/persons'));
        $this->assertSame(200, $list->getStatusCode());
        $this->assertCount(1, json_decode($list->getBody(), true)['data']);
    }

    public function testCreateRequiresDisplayName(): void
    {
        $response = $this->persons->create(
            new Request('POST', '/api/persons', [], (string) json_encode(['displayName' => '   ']))
        );
        $this->assertSame(400, $response->getStatusCode());
    }

    public function testListIncludesRelationCount(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');
        $this->relationRepo->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);

        $data = json_decode($this->persons->list(new Request('GET', '/api/persons'))->getBody(), true)['data'];
        $byName = [];
        foreach ($data as $row) {
            $byName[$row['displayName']] = $row;
        }
        $this->assertSame(1, $byName['Alice']['relationCount']);
        $this->assertSame(1, $byName['Bob']['relationCount']);
    }

    // ==================== Person delete guard ====================

    public function testDeletingANonUserPersonSucceeds(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');

        $response = $this->persons->delete(new Request('DELETE', "/api/persons/{$alice}"), ['id' => (string) $alice]);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->personRepo->findById($alice, 1));
    }

    public function testDeletingAProfileLinkedPersonIsGuarded(): void
    {
        $profileId = RelationsSchema::seedProfile($this->pdo, 1, 'dave@example.com');
        // A profile-linked shadow person (as a write path would provision).
        $shadowId = $this->personRepo->insert(1, 'dave', $profileId);
        $shadow = $this->personRepo->findById($shadowId, 1);
        $this->assertNotNull($shadow);

        $response = $this->persons->delete(
            new Request('DELETE', '/api/persons/' . $shadow['id']),
            ['id' => (string) $shadow['id']]
        );
        $this->assertSame(409, $response->getStatusCode(), 'A profile-linked person must not be deletable here.');
        $this->assertNotNull($this->personRepo->findById((int) $shadow['id'], 1), 'The shadow person must survive.');
    }

    public function testUpdatingAProfileLinkedPersonIsGuarded(): void
    {
        $profileId = RelationsSchema::seedProfile($this->pdo, 1, 'dave@example.com');
        $shadowId = $this->personRepo->insert(1, 'dave', $profileId);
        $shadow = $this->personRepo->findById($shadowId, 1);
        $this->assertNotNull($shadow);

        $response = $this->persons->update(
            new Request('PATCH', '/api/persons/' . $shadow['id'], [], (string) json_encode(['displayName' => 'Hacked'])),
            ['id' => (string) $shadow['id']]
        );
        $this->assertSame(409, $response->getStatusCode());
    }

    public function testUpdateNonUserPersonSucceeds(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $response = $this->persons->update(
            new Request('PATCH', "/api/persons/{$alice}", [], (string) json_encode(['displayName' => 'Alice B', 'notes' => 'note'])),
            ['id' => (string) $alice]
        );
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('Alice B', $this->personRepo->findById($alice, 1)['display_name']);
    }

    // ==================== Relation create: typed errors ====================

    public function testCreateSelfRelationReturns422(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $response = $this->relations->create(new Request('POST', '/api/relations', [], (string) json_encode([
            'from' => ['kind' => 'person', 'id' => $alice],
            'to' => ['kind' => 'person', 'id' => $alice],
            'relationshipTypeId' => RelationsSchema::TYPE_SIBLING,
        ])));
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateDuplicateRelationReturns422(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');
        $this->relationRepo->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);

        $response = $this->relations->create(new Request('POST', '/api/relations', [], (string) json_encode([
            'from' => ['kind' => 'person', 'id' => $alice],
            'to' => ['kind' => 'person', 'id' => $bob],
            'relationshipTypeId' => RelationsSchema::TYPE_PARENT,
        ])));
        $this->assertSame(422, $response->getStatusCode());
    }

    public function testCreateCrossTenantReferenceReturns404(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $carol = RelationsSchema::seedPerson($this->pdo, 2, 'Carol');

        $response = $this->relations->create(new Request('POST', '/api/relations', [], (string) json_encode([
            'from' => ['kind' => 'person', 'id' => $alice],
            'to' => ['kind' => 'person', 'id' => $carol],
            'relationshipTypeId' => RelationsSchema::TYPE_SIBLING,
        ])));
        $this->assertSame(404, $response->getStatusCode(), 'Cross-tenant ref must be 404, not disclosed.');
        $this->assertSame('Person not found', json_decode($response->getBody(), true)['error']);
    }

    public function testSystemTenantCannotCreateCrossTenantEdge(): void
    {
        // The system tenant (0) may reference any tenant's persons, but BOTH ends of
        // an edge must still live in the same tenant — otherwise a forged cross-tenant
        // edge would surface a foreign person in a tenant's reads (WC-65 review).
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $carol = RelationsSchema::seedPerson($this->pdo, 2, 'Carol');

        TenantContext::reset();
        TenantContext::setTenantId(0);

        $response = $this->relations->create(new Request('POST', '/api/relations', [], (string) json_encode([
            'from' => ['kind' => 'person', 'id' => $alice],
            'to' => ['kind' => 'person', 'id' => $carol],
            'relationshipTypeId' => RelationsSchema::TYPE_SIBLING,
        ])));

        $this->assertSame(404, $response->getStatusCode(), 'System tenant must not forge a cross-tenant edge.');
        $this->assertSame([], $this->relationRepo->listEdges(1), 'No edge created in tenant 1.');
        $this->assertSame([], $this->relationRepo->listEdges(2), 'No edge created in tenant 2.');
    }

    public function testCreateWithUnknownTypeReturns404(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');

        $response = $this->relations->create(new Request('POST', '/api/relations', [], (string) json_encode([
            'from' => ['kind' => 'person', 'id' => $alice],
            'to' => ['kind' => 'person', 'id' => $bob],
            'relationshipTypeId' => 999,
        ])));
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateMalformedRefReturns400(): void
    {
        $response = $this->relations->create(new Request('POST', '/api/relations', [], (string) json_encode([
            'from' => ['kind' => 'banana', 'id' => 1],
            'to' => ['kind' => 'person', 'id' => 2],
            'relationshipTypeId' => RelationsSchema::TYPE_SIBLING,
        ])));
        $this->assertSame(400, $response->getStatusCode());
    }

    // ==================== Relation create + auto-provision via API ====================

    public function testCreateProfileToPersonRelationAutoProvisionsShadow(): void
    {
        $profileId = RelationsSchema::seedProfile($this->pdo, 1, 'mum@example.com');
        $kid = RelationsSchema::seedPerson($this->pdo, 1, 'Kid');

        $response = $this->relations->create(new Request('POST', '/api/relations', [], (string) json_encode([
            'from' => ['kind' => 'profile', 'id' => $profileId],
            'to' => ['kind' => 'person', 'id' => $kid],
            'relationshipTypeId' => RelationsSchema::TYPE_PARENT,
        ])));
        $this->assertSame(201, $response->getStatusCode());

        // The profile's shadow person now exists and the kid reads "Child of mum".
        $shadow = $this->personRepo->findByProfileId($profileId, 1);
        $this->assertNotNull($shadow);
        $kidRels = $this->relationRepo->listForPerson($kid, 1);
        $this->assertSame('Child', $kidRels[0]['typeName']);
    }

    public function testDeleteRelationRemovesTheEdge(): void
    {
        $alice = RelationsSchema::seedPerson($this->pdo, 1, 'Alice');
        $bob = RelationsSchema::seedPerson($this->pdo, 1, 'Bob');
        $id = $this->relationRepo->insert(1, $alice, $bob, RelationsSchema::TYPE_PARENT);

        $response = $this->relations->delete(new Request('DELETE', "/api/relations/{$id}"), ['id' => (string) $id]);
        $this->assertSame(204, $response->getStatusCode());
        $this->assertNull($this->relationRepo->findById($id, 1));
    }

    public function testDeleteRelationFromAnotherTenantIs404(): void
    {
        $a2 = RelationsSchema::seedPerson($this->pdo, 2, 'A2');
        $b2 = RelationsSchema::seedPerson($this->pdo, 2, 'B2');
        $id = $this->relationRepo->insert(2, $a2, $b2, RelationsSchema::TYPE_PARENT);

        // Acting as tenant 1.
        $response = $this->relations->delete(new Request('DELETE', "/api/relations/{$id}"), ['id' => (string) $id]);
        $this->assertSame(404, $response->getStatusCode());
        $this->assertNotNull($this->relationRepo->findById($id, 2), 'The edge must remain for its owner tenant.');
    }

    // ==================== profiles/{id}/relations sugar ====================

    public function testProfileRelationsForProfileWithoutShadowDoesNotProvision(): void
    {
        // GET is a relations:read path and must NOT write. A profile with no shadow
        // person yet has no relations, and none is created as a side effect.
        $profileId = RelationsSchema::seedProfile($this->pdo, 1, 'dave@example.com');

        $response = $this->relations->profileRelations(
            new Request('GET', "/api/profiles/{$profileId}/relations"),
            ['id' => (string) $profileId]
        );
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertNull($data['personId']);
        $this->assertSame([], $data['relations']);
        $this->assertNull(
            $this->personRepo->findByProfileId($profileId, 1),
            'A relations:read GET must not auto-provision a shadow person (no write on read).'
        );
    }

    public function testProfileRelationsForCrossTenantProfileIs404(): void
    {
        $profileId = RelationsSchema::seedProfile($this->pdo, 2, 'foreign@example.com');

        $response = $this->relations->profileRelations(
            new Request('GET', "/api/profiles/{$profileId}/relations"),
            ['id' => (string) $profileId]
        );
        $this->assertSame(404, $response->getStatusCode());
    }

    public function testProfileRelationsResponseContainsOtherPersonHasAccount(): void
    {
        // Arrange: two profiles with a relation between their shadows.
        $profileId1 = RelationsSchema::seedProfile($this->pdo, 1, 'alice@example.com');
        $profileId2 = RelationsSchema::seedProfile($this->pdo, 1, 'bob@example.com');
        // Provision shadows via the write path.
        $shadow1 = $this->personRepo->insert(1, 'Alice', $profileId1);
        $shadow2 = $this->personRepo->insert(1, 'Bob', $profileId2);
        $this->relationRepo->insert(1, $shadow1, $shadow2, RelationsSchema::TYPE_PARENT);

        $response = $this->relations->profileRelations(
            new Request('GET', "/api/profiles/{$profileId1}/relations"),
            ['id' => (string) $profileId1]
        );
        $this->assertSame(200, $response->getStatusCode());
        $data = json_decode($response->getBody(), true)['data'];
        $this->assertSame($shadow1, $data['personId']);
        $this->assertCount(1, $data['relations']);
        $rel = $data['relations'][0];
        // Field shape must match PersonsApiHandler.toPublicRelation() contract.
        $this->assertArrayHasKey('otherPersonHasAccount', $rel);
        $this->assertArrayNotHasKey('otherPersonProfileId', $rel);
        $this->assertTrue($rel['otherPersonHasAccount']); // Bob has a profile account
        $this->assertSame($shadow2, $rel['otherPersonId']);
        $this->assertSame('Bob', $rel['otherPersonName']);
    }

    // ==================== relationship-types ====================

    public function testListTypesReturnsSeededVocabulary(): void
    {
        $response = $this->relations->listTypes(new Request('GET', '/api/relationship-types'));
        $this->assertSame(200, $response->getStatusCode());
        $names = array_map(static fn (array $t): string => $t['name'], json_decode($response->getBody(), true)['data']);
        sort($names);
        $this->assertSame(['Child', 'Parent', 'Sibling', 'Spouse'], $names);
    }
}
