<?php

declare(strict_types=1);

namespace Tests\Core\Plugin\Tenant;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\ManifestValidatorTest;
use Tests\Support\SchemaFromMigrations;
use Whity\Api\FrontendFeaturesApiHandler;
use Whity\Api\PermissionsApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Plugin\Tenant\TenantManifestHost;
use Whity\Core\Plugin\Tenant\TenantManifestPublisher;
use Whity\Core\Plugin\Tenant\TenantPluginNavigation;
use Whity\Core\Plugin\Tenant\TenantPluginRepository;
use Whity\Core\PluginLoader;
use Whity\Core\PluginRoleSeeder;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Manifest\InvalidManifestException;

/**
 * WHIT-760: a workspace's own plugins, published as data and served per
 * request, on the real schema.
 */
final class TenantPluginsRealEngineTest extends TestCase
{
    private const A = 1;
    private const B = 2;

    private PDO $pdo;
    private TenantPluginRepository $repository;
    private PermissionRegistry $registry;
    private TenantManifestHost $host;
    private TenantManifestPublisher $publisher;

    protected function setUp(): void
    {
        TenantContext::reset();
        $this->pdo = SchemaFromMigrations::make(true);
        $this->pdo->exec("INSERT OR IGNORE INTO tenants (id, name) VALUES (0, 'system')");
        $this->pdo->exec("INSERT INTO tenants (id, name) VALUES (1, 'example-bakery'), (2, 'example-cafe')");

        $this->repository = new TenantPluginRepository($this->pdo);
        $this->registry = new PermissionRegistry();
        $this->host = new TenantManifestHost($this->repository, $this->registry);
        $this->publisher = new TenantManifestPublisher(
            $this->pdo,
            $this->repository,
            new PluginRoleSeeder($this->pdo),
            ['HelloWorld'],
        );
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * Example Bakery's cake orders, as a workspace plugin: no tables, data
     * types or limits, and a screen that reads only its own record routes.
     *
     * @return array<string, mixed>
     */
    public static function workspaceManifest(string $screenTitle = 'Today'): array
    {
        $m = ManifestValidatorTest::bakeryManifest();
        unset($m['tables'], $m['dataTypes'], $m['limits']);
        $m['screens'][0]['blocks'] = [
            ['type' => 'heading', 'level' => 2, 'text' => $screenTitle],
            ['type' => 'dataTable', 'source' => '/api/x/cakeorders/order', 'columns' => [['key' => 'customer', 'label' => 'Customer']]],
        ];
        $m['permissions'] = [
            ['slug' => 'cakeorders:read', 'label' => ['en' => 'See cake orders', 'ar' => 'عرض طلبات الكيك']],
            ['slug' => 'cakeorders:manage', 'label' => ['en' => 'Manage cake orders', 'ar' => 'إدارة طلبات الكيك']],
        ];
        $m['roles']['baker']['permissions'] = ['cakeorders:read'];
        $m['roles']['shop_manager']['permissions'] = ['cakeorders:read', 'cakeorders:manage'];
        $m['screens'][0]['permission'] = 'cakeorders:read';

        return $m;
    }

    public function testEachWorkspaceIsServedOnlyItsOwnPlugins(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest('Bakery orders'), 7);

        self::assertSame(['CakeOrders'], array_map(fn ($m) => $m->name(), $this->host->manifestsFor(self::A)));
        self::assertSame([], $this->host->manifestsFor(self::B));

        $features = $this->host->frontendFeatures(self::A);
        self::assertCount(1, $features);
        self::assertSame('cake-orders-today', $features[0]['id']);
        self::assertTrue($features[0]['tenantPlugin']);
        self::assertSame('Bakery orders', $features[0]['blocks'][0]['text']);
    }

    public function testAPublishIsServedOnTheNextRequestWithoutAReload(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest('First'), 7);
        self::assertSame('First', $this->host->frontendFeatures(self::A)[0]['blocks'][0]['text']);

        $second = self::workspaceManifest('Second');
        $second['plugin']['version'] = '1.1.0';
        $this->publisher->publish(self::A, $second, 7);

        // Same request: the memo still answers.
        self::assertSame('First', $this->host->frontendFeatures(self::A)[0]['blocks'][0]['text']);

        // Next request: the new hash is read and served.
        $this->host->resetRequest();
        self::assertSame('Second', $this->host->frontendFeatures(self::A)[0]['blocks'][0]['text']);
    }

    public function testASecondWorkerSeesThePublishOnItsNextRequest(): void
    {
        $otherWorker = new TenantManifestHost(new TenantPluginRepository($this->pdo), new PermissionRegistry());
        $this->publisher->publish(self::A, self::workspaceManifest('First'), 7);
        self::assertSame('First', $otherWorker->frontendFeatures(self::A)[0]['blocks'][0]['text']);

        $second = self::workspaceManifest('Second');
        $second['plugin']['version'] = '1.1.0';
        $this->publisher->publish(self::A, $second, 7);
        $otherWorker->resetRequest();

        self::assertSame('Second', $otherWorker->frontendFeatures(self::A)[0]['blocks'][0]['text']);
    }

    public function testASwitchedOffPluginIsNotServed(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest(), 7);
        self::assertTrue($this->repository->setEnabled(self::A, 'CakeOrders', false));
        $this->host->resetRequest();

        self::assertSame([], $this->host->manifestsFor(self::A));
    }

    public function testPublishingMakesItsPermissionsGrantableAndSeedsRolesInThatWorkspaceOnly(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest(), 7);

        $catalogue = $this->column("SELECT name FROM permissions WHERE name LIKE 'cakeorders:%' ORDER BY name");
        self::assertSame(['cakeorders:manage', 'cakeorders:read'], $catalogue);

        // tenant_id is a string on SQLite and an int on PostgreSQL; compare as ints.
        $roles = array_map(
            static fn (array $r): array => ['name' => (string) $r['name'], 'tenant_id' => (int) $r['tenant_id']],
            $this->rows("SELECT name, tenant_id FROM roles WHERE name IN ('baker', 'shop_manager') ORDER BY name"),
        );
        self::assertSame([['name' => 'baker', 'tenant_id' => 1], ['name' => 'shop_manager', 'tenant_id' => 1]], $roles);

        $grants = $this->column(
            "SELECT p.name FROM role_permissions rp JOIN roles r ON r.id = rp.role_id JOIN permissions p ON p.id = rp.permission_id
              WHERE r.name = 'baker' AND r.tenant_id = 1"
        );
        self::assertSame(['cakeorders:read'], $grants);

        $this->host->manifestsFor(self::A);
        self::assertTrue($this->registry->exists('cakeorders:read'), 'a role check needs the slug in the worker registry');
    }

    public function testOtherWorkspacesDoNotSeeItsPermissionNames(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest(), 7);

        self::assertSame(['cakeorders:manage', 'cakeorders:read'], $this->sorted($this->repository->permissionsOfOtherTenants(self::B)));
        self::assertSame([], $this->repository->permissionsOfOtherTenants(self::A));

        TenantContext::reset();
        TenantContext::setTenantId(self::B);
        $names = array_column(
            json_decode((new PermissionsApiHandler($this->pdo, null, $this->repository))->list(new Request('GET', '/api/permissions?per_page=500'))->getBody(), true)['data'],
            'name',
        );
        self::assertNotContains('cakeorders:read', $names);

        TenantContext::reset();
        TenantContext::setTenantId(self::A);
        $names = array_column(
            json_decode((new PermissionsApiHandler($this->pdo, null, $this->repository))->list(new Request('GET', '/api/permissions?per_page=500'))->getBody(), true)['data'],
            'name',
        );
        self::assertContains('cakeorders:read', $names);
    }

    public function testTheFeaturesEndpointServesTheWorkspacesScreensThroughThePermissionFilter(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest(), 7);
        $loader = new PluginLoader(sys_get_temp_dir() . '/whity_no_plugins_' . uniqid(), new Router(''), new PermissionRegistry(), new HookManager());

        TenantContext::reset();
        TenantContext::setTenantId(self::A);
        $ids = $this->featureIds($loader, ['cakeorders:read']);
        self::assertContains('cake-orders-today', $ids);
        self::assertNotContains('cake-orders-today', $this->featureIds($loader, []), 'gated by its own permission');

        TenantContext::reset();
        TenantContext::setTenantId(self::B);
        $this->host->resetRequest();
        self::assertNotContains('cake-orders-today', $this->featureIds($loader, ['cakeorders:read']), 'never in another workspace');
    }

    public function testTheMenuCarriesTheWorkspacesScreens(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest(), 7);
        $hooks = new HookManager();
        TenantPluginNavigation::subscribe($hooks, $this->host);

        TenantContext::reset();
        TenantContext::setTenantId(self::A);
        $items = $hooks->dispatch('navigation.register', ['items' => []])['items'];
        self::assertSame(['plugin-cake-orders-today'], array_column($items, 'id'));
        self::assertSame('/admin/x/cake-orders-today', $items[0]['href']);

        TenantContext::reset();
        TenantContext::setTenantId(self::B);
        self::assertSame([], $hooks->dispatch('navigation.register', ['items' => []])['items']);
    }

    /**
     * @return iterable<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
     */
    public static function refusals(): iterable
    {
        yield 'tables' => [static function (array $m): array {
            $m['tables'] = ['cake_orders' => 'tenant'];
            return $m;
        }, 'tables: a workspace plugin can\'t declare tables'];
        yield 'limits' => [static function (array $m): array {
            $m['limits'] = [['key' => 'cakeorders.x', 'type' => 'bool', 'default' => true, 'description' => ['en' => 'x', 'ar' => 'س']]];
            return $m;
        }, 'limits: a workspace plugin can\'t declare limits'];
        yield 'a name an installed plugin uses' => [static function (array $m): array {
            $m['plugin']['name'] = 'helloworld';
            $m['permissions'] = [['slug' => 'helloworld:read', 'label' => ['en' => 'r', 'ar' => 'ق']]];
            $m['roles'] = [];
            $m['screens'] = [];
            return $m;
        }, 'is taken by a plugin installed for every workspace'];
        yield 'a permission outside its name' => [static function (array $m): array {
            $m['permissions'][] = ['slug' => 'users:delete', 'label' => ['en' => 'x', 'ar' => 'س']];
            return $m;
        }, '"users:delete" must start with "cakeorders:"'];
        yield 'a role inheriting from an admin role' => [static function (array $m): array {
            $m['roles']['baker']['parent'] = 'admin';
            return $m;
        }, 'roles.baker.parent: must be another role this plugin declares'];
        yield 'a screen posting to a core route' => [static function (array $m): array {
            $m['screens'][0]['blocks'][] = ['type' => 'actionButton', 'label' => 'Go', 'action' => ['method' => 'POST', 'endpoint' => '/api/users']];
            return $m;
        }, '"/api/users" is outside this plugin\'s own routes'];
    }

    /**
     * @param callable(array<string, mixed>): array<string, mixed> $mutate
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('refusals')]
    public function testRefusesWhatAWorkspacePluginMayNotDeclare(callable $mutate, string $expected): void
    {
        try {
            $this->publisher->publish(self::A, $mutate(self::workspaceManifest()), 7);
            self::fail('Expected the publish to be refused');
        } catch (InvalidManifestException $e) {
            self::assertNotEmpty(
                array_filter($e->getErrors(), static fn (string $err): bool => str_contains($err, $expected)),
                'Got: ' . implode(' | ', $e->getErrors()),
            );
        }
        self::assertSame(0, count($this->column('SELECT id FROM plugin_manifest_versions')), 'nothing is stored');
    }

    public function testTheSystemTenantCannotPublishAWorkspacePlugin(): void
    {
        $this->expectException(InvalidManifestException::class);
        $this->publisher->publish(0, self::workspaceManifest(), 7);
    }

    public function testAStoredManifestThatNoLongerValidatesIsSkippedNotHalfServed(): void
    {
        $this->publisher->publish(self::A, self::workspaceManifest(), 7);
        $this->pdo->exec("UPDATE plugin_manifest_versions SET manifest = '{\"manifest\":\"whity.plugin/1\"}', hash = 'changed'");
        $this->host->resetRequest();

        self::assertSame([], $this->host->manifestsFor(self::A));
    }

    /**
     * @param list<string> $granted
     * @return list<string>
     */
    private function featureIds(PluginLoader $loader, array $granted): array
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static fn (int $u, string $p, int $t): bool => in_array($p, $granted, true));
        $sqlite = new PDO('sqlite::memory:');
        $labels = new ServerLabels(new LanguageRegistry(new LanguageRepository($sqlite), new TranslationRepository($sqlite), new StaticTenantContextAdapter()));
        $handler = new FrontendFeaturesApiHandler($loader, $roleChecker, new Router(''), $labels, null, [], $this->host);
        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['profile_id' => 7];
        $body = json_decode($handler->list($request)->getBody(), true);

        return array_column($body['data'] ?? [], 'id');
    }

    /** @return list<mixed> */
    private function column(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }

    /** @return list<array<string, mixed>> */
    private function rows(string $sql): array
    {
        $stmt = $this->pdo->query($sql);
        self::assertNotFalse($stmt);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sorted(array $values): array
    {
        sort($values);

        return $values;
    }
}
