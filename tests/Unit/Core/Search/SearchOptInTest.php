<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Search;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Api\SearchApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\InvalidDataTypeException;
use Whity\Core\Request;
use Whity\Core\Search\DataTypeSearchSource;
use Whity\Core\Search\SearchContext;
use Whity\Core\Search\SearchService;
use Whity\Core\Tenant\TableOwnershipRegistry;
use Whity\Core\Tenant\TenantContext;

/**
 * Plugin records in `GET /api/search` (WHIT-783): the data-type `search`
 * opt-in, and the handler's refusals.
 */
final class SearchOptInTest extends TestCase
{
    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    public function testATypeThatOptsInIsSearchedOnItsOwnTableAndPermission(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE acme_records (id INTEGER PRIMARY KEY, tenant_id INTEGER, name TEXT, code TEXT, state TEXT)');
        $pdo->exec("INSERT INTO acme_records VALUES (1, 1, 'Example Bakery flour', 'F-1', 'active'),
                                                    (2, 1, 'Example Bakery sugar', 'S-1', 'trashed'),
                                                    (3, 2, 'Example Bakery salt', 'X-1', 'active')");

        $registry = $this->registry([
            'table' => 'acme_records',
            'label' => ['en' => 'Supplies', 'ar' => 'المواد'],
            'lifecycle' => ['column' => 'state', 'states' => ['active', 'trashed'], 'default_state' => 'active', 'trashable' => true],
            'permissions' => ['read' => 'acme:read', 'trash' => 'acme:manage', 'restore' => 'acme:manage'],
            'search' => ['title' => 'name', 'subtitle' => 'code', 'url' => '/acme/records/{id}'],
        ]);
        $sources = DataTypeSearchSource::fromRegistry($pdo, $registry);
        self::assertCount(1, $sources);

        $service = new SearchService(static fn (): array => $sources);
        $granted = new SearchContext(1, 9, static fn (string $p): bool => $p === 'acme:read');
        $denied  = new SearchContext(1, 9, static fn (string $p): bool => false);

        $groups = $service->search($granted, 'example bakery');
        self::assertSame('acme:record', $groups[0]['type']);
        // Not the trashed one, and not the other tenant's.
        self::assertSame([['type' => 'acme:record', 'id' => '1', 'title' => 'Example Bakery flour', 'subtitle' => 'F-1', 'url' => '/acme/records/1']], $groups[0]['results']);
        self::assertSame([], $service->search($denied, 'example bakery'));
    }

    public function testATypeWithoutTheOptInIsNotSearched(): void
    {
        $registry = $this->registry(['table' => 'acme_records', 'permissions' => ['read' => 'acme:read']]);

        self::assertSame([], DataTypeSearchSource::fromRegistry(new PDO('sqlite::memory:'), $registry));
        self::assertNull($registry->get('acme:record')?->search());
    }

    public function testSearchWithoutAReadPermissionIsRefused(): void
    {
        $this->expectException(InvalidDataTypeException::class);
        $this->registry(['table' => 'acme_records', 'search' => ['title' => 'name']]);
    }

    public function testBadSearchColumnsAndUrlsAreRefused(): void
    {
        foreach ([['title' => 'name; DROP TABLE x'], ['title' => 'name', 'url' => 'https://evil.example/{id}'], ['title' => 'name', 'url' => '/no-placeholder']] as $search) {
            try {
                $this->registry(['table' => 'acme_records', 'permissions' => ['read' => 'acme:read'], 'search' => $search]);
                self::fail('accepted ' . json_encode($search));
            } catch (InvalidDataTypeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testTheHandlerNeedsASignedInCallerAndAQuery(): void
    {
        $handler = new SearchApiHandler(new SearchService(static fn (): array => []), $this->createMock(RoleChecker::class));

        self::assertSame(401, $handler->search(new Request('GET', '/api/search?q=example'))->getStatusCode());

        TenantContext::setTenantId(1);
        $request       = new Request('GET', '/api/search');
        $request->user = (object) ['profile_id' => 9];
        self::assertSame(422, $handler->search($request)->getStatusCode());

        $request       = new Request('GET', '/api/search?q=example');
        $request->user = (object) ['profile_id' => 9];
        self::assertSame(200, $handler->search($request)->getStatusCode());
    }

    /** @param array<string, mixed> $declaration */
    private function registry(array $declaration): DataTypeRegistry
    {
        $tables = new TableOwnershipRegistry();
        $tables->register('Acme', ['acme_records' => TableOwnershipRegistry::SCOPE_TENANT]);
        $registry = new DataTypeRegistry($tables);
        $registry->register('Acme', ['record' => $declaration]);

        return $registry;
    }
}
