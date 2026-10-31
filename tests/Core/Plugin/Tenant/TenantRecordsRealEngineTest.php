<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Tenant;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\RecordTypeValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FrontendFeaturesApiHandler;
use Whity\Api\TenantRecordsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\Plugin\Tenant\TenantRecordRepository;
use Whity\Core\PluginLoader;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Core\Tenant\TenantContext;

/**
 * WHIT-761: the shared record store behind workspace plugins, through its
 * routes, on the real schema.
 */
final class TenantRecordsRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;
    private const READ = 'cakeorders:read';
    private const MANAGE = 'cakeorders:manage';

    private PDO $pdo;
    private TenantManifestHost $host;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-cafe')");
        $repository = new TenantPluginRepository($this->pdo);
        $this->host = new TenantManifestHost($repository, new PermissionRegistry());
        (new TenantManifestPublisher($this->pdo, $repository, new PluginRoleSeeder($this->pdo), []))
            ->publish(self::A, self::manifest(), 7);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /** @return array<string, mixed> */
    private static function manifest(): array
    {
        $m = RecordTypeValidatorTest::manifest();
        $m['screens'] = [[
            'id' => 'cake-orders',
            'label' => ['en' => 'Cake orders', 'ar' => 'طلبات الكيك'],
            'permission' => self::READ,
            'blocks' => [['type' => 'dataTable', 'source' => '/api/x/cakeorders/order', 'columns' => [['key' => 'customer', 'label' => 'Customer']]]],
        ]];

        return $m;
    }

    /** @return array<string, mixed> */
    private static function order(): array
    {
        return ['customer' => 'Example Bakery', 'cake_type' => 'wedding', 'price' => 50000, 'pickup_on' => '2026-11-03'];
    }

    public function testCreateReadAndListARecord(): void
    {
        $created = $this->call('create', self::A, [self::READ, self::MANAGE], body: self::order());
        self::assertSame(201, $created->getStatusCode(), $created->getBody());
        $record = $this->json($created)['data'];
        self::assertSame('draft', $record['state'], 'a new record starts in the lifecycle\'s initial state');
        self::assertSame(50000, $record['price']);

        $got = $this->json($this->call('get', self::A, [self::READ], ['id' => (string) $record['id']]))['data'];
        self::assertSame('Example Bakery', $got['customer']);

        $list = $this->json($this->call('list', self::A, [self::READ]))['data'];
        self::assertSame([$record['id']], array_column($list, 'id'));
    }

    public function testInvalidValuesAreRefusedWithAnIssueForEachField(): void
    {
        $response = $this->call('create', self::A, [self::READ, self::MANAGE], body: [
            'cake_type' => 'funeral',
            'price' => 12.5,
            'colour' => 'blue',
            'pickup_on' => '2026-02-30',
        ]);

        self::assertSame(422, $response->getStatusCode());
        $columns = array_column($this->json($response)['issues'], 'column');
        sort($columns);
        self::assertSame(['cake_type', 'colour', 'customer', 'pickup_on', 'price'], $columns);
        self::assertSame([], $this->rows(), 'nothing is stored');
    }

    public function testALinkMustPointAtARecordOfThisWorkspace(): void
    {
        $response = $this->call('create', self::A, [self::READ, self::MANAGE], body: self::order() + ['parent' => 999]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame(['parent'], array_column($this->json($response)['issues'], 'column'));
    }

    public function testUpdateChangesOnlyTheGivenFieldsAndCantClearARequiredOne(): void
    {
        $id = $this->createOrder();

        $updated = $this->call('update', self::A, [self::READ, self::MANAGE], ['id' => $id], ['price' => 55000]);
        self::assertSame(200, $updated->getStatusCode(), $updated->getBody());
        self::assertSame(['Example Bakery', 55000], [$this->json($updated)['data']['customer'], $this->json($updated)['data']['price']]);

        $cleared = $this->call('update', self::A, [self::READ, self::MANAGE], ['id' => $id], ['customer' => null]);
        self::assertSame(422, $cleared->getStatusCode());
    }

    public function testMovesFollowTheDeclaredLifecycleAndPermission(): void
    {
        $id = $this->createOrder();

        self::assertSame(403, $this->call('move', self::A, [self::READ], ['id' => $id, 'move' => 'confirm'])->getStatusCode());
        self::assertSame(404, $this->call('move', self::A, [self::READ, self::MANAGE], ['id' => $id, 'move' => 'ship'])->getStatusCode());

        $moved = $this->call('move', self::A, [self::READ, self::MANAGE], ['id' => $id, 'move' => 'confirm']);
        self::assertSame('confirmed', $this->json($moved)['data']['state']);

        $again = $this->call('move', self::A, [self::READ, self::MANAGE], ['id' => $id, 'move' => 'confirm']);
        self::assertSame(409, $again->getStatusCode(), 'confirm is only available from draft');
    }

    public function testDeleteRemovesTheRecord(): void
    {
        $id = $this->createOrder();

        self::assertSame(204, $this->call('delete', self::A, [self::READ, self::MANAGE], ['id' => $id])->getStatusCode());
        self::assertSame(404, $this->call('get', self::A, [self::READ], ['id' => $id])->getStatusCode());
    }

    public function testPermissionsGateEachAction(): void
    {
        $id = $this->createOrder();

        self::assertSame(404, $this->call('list', self::A, [])->getStatusCode(), 'no read: the type is not even there');
        self::assertSame(404, $this->call('get', self::A, [], ['id' => $id])->getStatusCode());
        self::assertSame(403, $this->call('create', self::A, [self::READ], body: self::order())->getStatusCode(), 'read but not create');
        self::assertSame(403, $this->call('delete', self::A, [self::READ], ['id' => $id])->getStatusCode());
    }

    public function testAnotherWorkspaceSeesNothing(): void
    {
        $id = $this->createOrder();
        $all = [self::READ, self::MANAGE];

        self::assertSame(404, $this->call('list', self::B, $all)->getStatusCode(), 'workspace B has no such plugin');
        self::assertSame(404, $this->call('get', self::B, $all, ['id' => $id])->getStatusCode());
        self::assertSame(404, $this->call('delete', self::B, $all, ['id' => $id])->getStatusCode());
        self::assertCount(1, $this->rows(), "A's record is untouched");
    }

    public function testTheRecordRepositoryIsTenantScoped(): void
    {
        $id = (int) $this->createOrder();
        $records = new TenantRecordRepository($this->pdo);

        self::assertNull($records->find(self::B, 'CakeOrders', 'order', $id));
        self::assertFalse($records->update(self::B, 'CakeOrders', 'order', $id, ['customer' => 'x'], null, 9));
        self::assertFalse($records->delete(self::B, 'CakeOrders', 'order', $id));
        self::assertSame([], $records->list(self::B, 'CakeOrders', 'order', null, null, 50));
        self::assertSame('Example Bakery', $records->find(self::A, 'CakeOrders', 'order', $id)['data']['customer'] ?? null);
    }

    public function testWorkspaceScreensFetchTheVersionedRecordRoutes(): void
    {
        TenantContext::setTenantId(self::A);
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')->willReturn(true);
        $sqlite = new PDO('sqlite::memory:');
        $labels = new ServerLabels(new LanguageRegistry(new LanguageRepository($sqlite), new TranslationRepository($sqlite), new StaticTenantContextAdapter()));
        $loader = new PluginLoader(sys_get_temp_dir() . '/whity_no_plugins_' . uniqid(), new Router('/v1'), new PermissionRegistry(), new HookManager());
        $handler = new FrontendFeaturesApiHandler($loader, $roleChecker, new Router('/v1'), $labels, null, [], $this->host);
        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['profile_id' => 7];

        $features = array_column(json_decode($handler->list($request)->getBody(), true)['data'], null, 'id');
        self::assertSame('/api/v1/x/cakeorders/order', $features['cake-orders']['blocks'][0]['source']);
    }

    private function createOrder(): string
    {
        $response = $this->call('create', self::A, [self::READ, self::MANAGE], body: self::order());
        self::assertSame(201, $response->getStatusCode(), $response->getBody());

        return (string) $this->json($response)['data']['id'];
    }

    /**
     * @param list<string> $granted
     * @param array<string, string> $params
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, int $tenantId, array $granted, array $params = [], ?array $body = null): Response
    {
        TenantContext::reset();
        TenantContext::setTenantId($tenantId);
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static fn (int $u, string $p, int $t): bool => in_array($p, $granted, true));
        $this->host->resetRequest();
        $handler = new TenantRecordsApiHandler($this->host, new TenantRecordRepository($this->pdo), $roleChecker);
        $request = new Request($method === 'list' || $method === 'get' ? 'GET' : 'POST', '/api/x/cakeorders/order', [], $body === null ? '' : (string) json_encode($body));
        $request->user = (object) ['profile_id' => 7];

        return $handler->{$method}($request, ['plugin' => 'cakeorders', 'type' => 'order'] + $params);
    }

    /** @return array<string, mixed> */
    private function json(Response $response): array
    {
        /** @var array<string, mixed> */
        return json_decode($response->getBody(), true) ?? [];
    }

    /** @return list<mixed> */
    private function rows(): array
    {
        $stmt = $this->pdo->query('SELECT id FROM plugin_records');
        self::assertNotFalse($stmt);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
