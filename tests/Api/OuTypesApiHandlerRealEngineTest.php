<?php

declare(strict_types=1);

namespace Tests\Api;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MockRequestFactory;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\OuTypesApiHandler;
use Whity\Core\Ou\OuTypeRegistry;
use Whity\Core\Ou\OuTypeRepository;
use Whity\Core\Request;
use Whity\Core\Tenant\TenantContext;

/**
 * Real-engine tests for {@see OuTypesApiHandler} and {@see OuTypeRepository} (#822).
 *
 * Real engine rather than a mocked PDO because the things worth testing here are
 * database behaviours: the `UNIQUE(tenant_id, type_key)` conflict a duplicate
 * adoption has to become a 409, the tenant predicate that makes another tenant's
 * type read as "not found", and the explicit untyping a forced delete performs
 * (which the ON DELETE SET NULL foreign key does NOT perform on SQLite, since it
 * honours FK actions only under `PRAGMA foreign_keys = ON`).
 *
 * Runs against real PostgreSQL when PHPUNIT_PG_DSN is set, SQLite otherwise —
 * see {@see SchemaFromMigrations}.
 *
 * Seeded (tenant 1): OU 10 Engineering (root), 11 Backend (child of 10).
 * Tenant 2 owns OU 30.
 */
final class OuTypesApiHandlerRealEngineTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = self::makeSchema();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== authoring the vocabulary ====================

    public function testCreatingATenantAuthoredTypeSucceedsAndAppendsToTheEnd(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $first = $handler->create($this->post(['key' => 'campus', 'label' => 'Campus']));
        $this->assertSame(201, $first->getStatusCode());
        $firstData = $this->data($first);
        $this->assertSame('campus', $firstData['key']);
        $this->assertSame('Campus', $firstData['label']);
        $this->assertSame(
            OuTypeRegistry::TENANT_SOURCE,
            $firstData['source'],
            'A key no code declared is provenance `tenant`, so an operator can tell it apart '
            . "from one a plugin's code binds to."
        );

        $second = $handler->create($this->post(['key' => 'faculty', 'label' => 'Faculty']));
        $this->assertSame(201, $second->getStatusCode());
        $this->assertGreaterThan(
            $firstData['sort_order'],
            $this->data($second)['sort_order'],
            'An appended type must rank after the tenant\'s existing ones — a campus outranks a faculty.'
        );
    }

    public function testDuplicateKeyIsA409(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $this->assertSame(201, $handler->create($this->post(['key' => 'campus']))->getStatusCode());
        $this->assertSame(409, $handler->create($this->post(['key' => 'campus']))->getStatusCode());
    }

    public function testLabelFallsBackToTheKeyWhenNoneIsSupplied(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create($this->post(['key' => 'department']));

        $this->assertSame(201, $response->getStatusCode());
        $this->assertSame('department', $this->data($response)['label']);
    }

    /**
     * The reserved sentinel: `?type=none` has to keep meaning "units with no
     * type", so a tenant must not be able to author a real type answering to it.
     */
    public function testTheReservedUntypedKeyCannotBeAuthored(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create($this->post(['key' => OuTypeRegistry::UNTYPED]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('reserved', $this->error($response));
    }

    /**
     * A namespaced key is an ATTRIBUTION. Writing `acme:clinic` by hand claims
     * the Acme plugin said so, so it is refused unless a plugin really declared
     * it.
     */
    public function testANamespacedKeyNoPluginDeclaredIsRefused(): void
    {
        MockRequestFactory::setTestTenant(1);

        $response = $this->handler()->create($this->post(['key' => 'acme:clinic']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->scalar("SELECT COUNT(*) FROM ou_types WHERE type_key = 'acme:clinic'")
        );
    }

    public function testMalformedKeyIsA422(): void
    {
        MockRequestFactory::setTestTenant(1);

        $this->assertSame(422, $this->handler()->create($this->post(['key' => 'Faculty']))->getStatusCode());
        $this->assertSame(422, $this->handler()->create($this->post(['key' => '']))->getStatusCode());
    }

    // ==================== adopting a plugin's declaration ====================

    /**
     * Adoption is a create whose key the registry recognises: the plugin's
     * declared label and rank become the tenant's starting values, and the row
     * records the plugin as its provenance.
     */
    public function testAdoptingADeclaredKeyInheritsTheDeclaredDefaults(): void
    {
        MockRequestFactory::setTestTenant(1);

        $registry = new OuTypeRegistry();
        $registry->register('AcmeClinics', ['clinic' => ['label' => 'Clinic', 'sort_order' => 30]]);

        $response = $this->handler($registry)->create($this->post(['key' => 'acmeclinics:clinic']));

        $this->assertSame(201, $response->getStatusCode());
        $data = $this->data($response);
        $this->assertSame('acmeclinics:clinic', $data['key']);
        $this->assertSame('Clinic', $data['label']);
        $this->assertSame(30, $data['sort_order']);
        $this->assertSame('AcmeClinics', $data['source']);
    }

    /**
     * The resolution chain is request ?? declaration ?? default, so a tenant that
     * calls it a School gets a School.
     */
    public function testAnAdoptingTenantMayOverrideTheDeclaredLabel(): void
    {
        MockRequestFactory::setTestTenant(1);

        $registry = new OuTypeRegistry();
        $registry->register('AcmeClinics', ['clinic' => ['label' => 'Clinic', 'sort_order' => 30]]);

        $response = $this->handler($registry)->create(
            $this->post(['key' => 'acmeclinics:clinic', 'label' => 'عيادة', 'sort_order' => 5])
        );

        $this->assertSame(201, $response->getStatusCode());
        $data = $this->data($response);
        $this->assertSame('عيادة', $data['label']);
        $this->assertSame(5, $data['sort_order']);
    }

    public function testCatalogReportsDeclaredTypesAndPerTenantAdoptionState(): void
    {
        MockRequestFactory::setTestTenant(1);

        $registry = new OuTypeRegistry();
        $registry->register('AcmeClinics', ['clinic' => ['label' => 'Clinic'], 'ward' => []]);
        $handler = $this->handler($registry);

        $handler->create($this->post(['key' => 'acmeclinics:clinic']));

        $entries = $this->data($handler->catalog(new Request('GET', '/api/ou-types/catalog')));
        $byKey = array_column($entries, null, 'key');

        $this->assertTrue($byKey['acmeclinics:clinic']['adopted']);
        $this->assertNotNull($byKey['acmeclinics:clinic']['ou_type_id']);
        $this->assertFalse($byKey['acmeclinics:ward']['adopted']);
        $this->assertNull($byKey['acmeclinics:ward']['ou_type_id']);
    }

    // ==================== updating ====================

    public function testRelabellingAndReRankingSucceed(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $id = $this->data($handler->create($this->post(['key' => 'faculty'])))['id'];

        $response = $handler->update(
            new Request('PATCH', '/api/ou-types/' . $id, [], (string) json_encode([
                'label' => 'Kulliyyah',
                'sort_order' => 20,
            ])),
            ['id' => (string) $id]
        );

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        $this->assertSame('Kulliyyah', $data['label']);
        $this->assertSame(20, $data['sort_order']);
        $this->assertSame('faculty', $data['key'], 'The key is what a routing rule binds to and must not move.');
    }

    /**
     * The key is immutable. Editing it in place would silently repoint every
     * routing rule bound to the old key at a type that no longer exists — the
     * drift this feature was reported to eliminate.
     */
    public function testChangingTheKeyIsRefused(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $id = $this->data($handler->create($this->post(['key' => 'faculty'])))['id'];

        $response = $handler->update(
            new Request('PATCH', '/api/ou-types/' . $id, [], (string) json_encode(['key' => 'school'])),
            ['id' => (string) $id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            'faculty',
            (string) $this->scalar('SELECT type_key FROM ou_types WHERE id = ' . (int) $id)
        );
    }

    public function testNonIntegerSortOrderIsRefusedRatherThanCastToZero(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $id = $this->data($handler->create($this->post(['key' => 'faculty', 'sort_order' => 20])))['id'];

        $response = $handler->update(
            new Request('PATCH', '/api/ou-types/' . $id, [], (string) json_encode(['sort_order' => 'first'])),
            ['id' => (string) $id]
        );

        $this->assertSame(422, $response->getStatusCode());
        $this->assertSame(
            20,
            (int) $this->scalar('SELECT sort_order FROM ou_types WHERE id = ' . (int) $id),
            'A silent cast to 0 would have reordered the tenant\'s whole vocabulary.'
        );
    }

    // ==================== deleting ====================

    public function testDeletingAnUnusedTypeSucceeds(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $id = $this->data($handler->create($this->post(['key' => 'faculty'])))['id'];

        $response = $handler->delete(new Request('DELETE', '/api/ou-types/' . $id), ['id' => (string) $id]);

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM ou_types WHERE id = ' . (int) $id)
        );
    }

    /**
     * Deleting a type in use untypes every unit that carried it, making them
     * invisible to every `?type=` rule that used to match — unbounded and silent.
     * So it is refused, and the exact count is reported.
     */
    public function testDeletingATypeStillInUseIsRefusedWithTheCount(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $id = (int) $this->data($handler->create($this->post(['key' => 'faculty'])))['id'];
        $this->typeOu(10, $id);
        $this->typeOu(11, $id);

        $response = $handler->delete(new Request('DELETE', '/api/ou-types/' . $id), ['id' => (string) $id]);

        $this->assertSame(409, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $this->assertSame(2, $body['details']['units']);
        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM ou_types WHERE id = ' . $id)
        );
    }

    /**
     * The forced path untypes EXPLICITLY. On SQLite the ON DELETE SET NULL
     * foreign key does nothing (FK enforcement is off by default), so without the
     * explicit UPDATE the column would keep pointing at a deleted row.
     */
    public function testForcedDeleteUntypesTheUnitsAndRemovesTheType(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $id = (int) $this->data($handler->create($this->post(['key' => 'faculty'])))['id'];
        $this->typeOu(10, $id);

        $response = $handler->delete(
            new Request('DELETE', '/api/ou-types/' . $id . '?force=true'),
            ['id' => (string) $id]
        );

        $this->assertSame(204, $response->getStatusCode());
        $this->assertSame(
            0,
            (int) $this->scalar('SELECT COUNT(*) FROM ou_types WHERE id = ' . $id)
        );
        $this->assertNull(
            $this->scalar('SELECT ou_type_id FROM organizational_units WHERE id = 10') ?: null,
            'A forced delete must leave no unit pointing at a type that no longer exists.'
        );
    }

    /**
     * An empty `?force=` — what a UI emits for an unchecked box — must leave the
     * guard armed.
     */
    public function testAnEmptyForceValueDoesNotConsentToTheDestructivePath(): void
    {
        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();
        $id = (int) $this->data($handler->create($this->post(['key' => 'faculty'])))['id'];
        $this->typeOu(10, $id);

        $response = $handler->delete(
            new Request('DELETE', '/api/ou-types/' . $id . '?force='),
            ['id' => (string) $id]
        );

        $this->assertSame(409, $response->getStatusCode());
    }

    // ==================== tenant isolation ====================

    /**
     * The vocabulary is tenant data precisely so a university and a company can
     * coexist in one install. Another tenant's type must read as "not found",
     * never as a cross-tenant disclosure.
     */
    public function testAnotherTenantsTypeIsNotVisibleReadableOrDeletable(): void
    {
        MockRequestFactory::setTestTenant(2);
        $foreignId = (int) $this->data($this->handler()->create($this->post(['key' => 'branch'])))['id'];
        TenantContext::reset();

        MockRequestFactory::setTestTenant(1);
        $handler = $this->handler();

        $this->assertSame([], $this->data($handler->list(new Request('GET', '/api/ou-types'))));
        $this->assertSame(
            404,
            $handler->get(new Request('GET', '/api/ou-types/' . $foreignId), ['id' => (string) $foreignId])
                ->getStatusCode()
        );
        $this->assertSame(
            404,
            $handler->delete(new Request('DELETE', '/api/ou-types/' . $foreignId), ['id' => (string) $foreignId])
                ->getStatusCode()
        );
        $this->assertSame(
            1,
            (int) $this->scalar('SELECT COUNT(*) FROM ou_types WHERE id = ' . $foreignId)
        );
    }

    /**
     * Two tenants holding the same key is the NORMAL case — one install's
     * `faculty` renders as School and another's as Kulliyyah — so uniqueness is
     * per tenant, not global.
     */
    public function testTwoTenantsMayEachHoldTheSameKey(): void
    {
        MockRequestFactory::setTestTenant(1);
        $this->assertSame(201, $this->handler()->create($this->post(['key' => 'faculty']))->getStatusCode());
        TenantContext::reset();

        MockRequestFactory::setTestTenant(2);
        $this->assertSame(201, $this->handler()->create($this->post(['key' => 'faculty']))->getStatusCode());

        $this->assertSame(
            2,
            (int) $this->scalar("SELECT COUNT(*) FROM ou_types WHERE type_key = 'faculty'")
        );
    }

    // ==================== helpers ====================

    private function handler(?OuTypeRegistry $registry = null): OuTypesApiHandler
    {
        return new OuTypesApiHandler(
            new OuTypeRepository($this->pdo),
            $registry ?? new OuTypeRegistry()
        );
    }

    /**
     * @param array<string, mixed> $body
     */
    private function post(array $body): Request
    {
        return new Request('POST', '/api/ou-types', [], (string) json_encode($body));
    }

    /**
     * @return array<mixed>
     */
    private function data(\Whity\Core\Response $response): array
    {
        /** @var array{data: array<mixed>} $decoded */
        $decoded = json_decode($response->getBody(), true);

        return $decoded['data'];
    }

    private function error(\Whity\Core\Response $response): string
    {
        return (string) json_decode($response->getBody(), true)['error'];
    }

    /**
     * Fetch one scalar from a literal query.
     *
     * PDO::query() is typed `PDOStatement|false`, so every inline
     * `->query(...)->fetchColumn()` is a static-analysis error. Asserting the
     * statement prepared before reading it is also the better failure: a typo in
     * the SQL reports itself instead of surfacing as a null comparison three
     * lines later.
     */
    private function scalar(string $sql): mixed
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt, 'Query failed: ' . $sql);

        return $stmt->fetchColumn();
    }

    private function typeOu(int $ouId, int $typeId): void
    {
        $this->pdo
            ->prepare('UPDATE organizational_units SET ou_type_id = ? WHERE id = ?')
            ->execute([$typeId, $ouId]);
    }

    private static function makeSchema(): PDO
    {
        $pdo = SchemaFromMigrations::make(true);

        $pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'tenant-a'), (2, 'tenant-b')");
        $pdo->exec("
            INSERT INTO organizational_units (id, tenant_id, parent_id, name, slug, description, created_at) VALUES
                (10, 1, NULL, 'Engineering', 'engineering', '', NOW()),
                (11, 1, 10,   'Backend',     'backend',     '', NOW()),
                (30, 2, NULL, 'Other',       'other',       '', NOW())
        ");
        // PostgreSQL's SERIAL sequences do not advance for explicit ids, so the
        // next id-less INSERT would collide with a seeded row.
        SchemaFromMigrations::syncSequences($pdo);

        return $pdo;
    }
}
