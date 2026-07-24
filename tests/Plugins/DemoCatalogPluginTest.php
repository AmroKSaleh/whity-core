<?php

declare(strict_types=1);

namespace Tests\Plugins;

use DemoCatalog\DemoCatalogPlugin;
use DemoCatalog\Migrations\CreateDemoCatalogItemsTable;
use DemoCatalog\Migrations\GrantDemoCatalogPermissionsToAdmin;
use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\Http\Request;
use Whity\Sdk\PluginInterface;

require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/DemoCatalogPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/Migrations/CreateDemoCatalogItemsTable.php';
require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/Migrations/GrantDemoCatalogPermissionsToAdmin.php';

/**
 * Tests for the DemoCatalog pilot plugin (multi-client feature-extraction
 * pilot, see packages/features): verifies the plugin satisfies the SDK
 * contract, declares its `screen: 'custom'` feature and colon-notation
 * permissions correctly, and that the PluginLoader discovers it and registers
 * its routes with the right requiredPermission.
 */
final class DemoCatalogPluginTest extends TestCase
{
    public function testImplementsPluginInterface(): void
    {
        $plugin = new DemoCatalogPlugin();

        $this->assertInstanceOf(PluginInterface::class, $plugin);
        $this->assertSame('DemoCatalog', $plugin->getName());
        $this->assertSame('1.0.0', $plugin->getVersion());
    }

    public function testDeclaresTheItemsCrudRoutes(): void
    {
        $routes = (new DemoCatalogPlugin())->getRoutes();

        $this->assertCount(4, $routes);

        $byKey = [];
        foreach ($routes as $route) {
            $byKey[$route['method'] . ' ' . $route['path']] = $route;
        }

        // Reads gated on demo_catalog:view, writes on demo_catalog:manage — a
        // :view permission never gates a write and vice versa.
        $this->assertSame('demo_catalog:view', $byKey['GET /api/demo-catalog/items']['requiredPermission']);
        $this->assertSame('demo_catalog:view', $byKey['GET /api/demo-catalog/items/{id:\d+}']['requiredPermission']);
        $this->assertSame('demo_catalog:manage', $byKey['POST /api/demo-catalog/items']['requiredPermission']);
        $this->assertSame('demo_catalog:manage', $byKey['PATCH /api/demo-catalog/items/{id:\d+}']['requiredPermission']);

        foreach ($byKey as $key => $route) {
            $this->assertIsCallable($route['handler'], "{$key} must have a callable handler");
            $this->assertNull($route['requiredRole'], "{$key} must gate on permission, not role");
            $this->assertArrayHasKey('schema', $route, "{$key} must declare a typed OpenAPI schema");
        }

        $this->assertArrayHasKey(
            'DemoCatalogItem',
            $byKey['GET /api/demo-catalog/items']['schema']['components']
        );
    }

    public function testDeclaresTheCustomScreenFeature(): void
    {
        $features = (new DemoCatalogPlugin())->getFrontendFeatures();

        $this->assertSame([
            [
                'id' => 'demo-catalog',
                'label' => 'Demo Catalog',
                'icon' => 'box',
                'group' => 'plugins',
                'order' => 30,
                'screen' => 'custom',
                'requiredPermission' => 'demo_catalog:view',
            ],
        ], $features);
    }

    public function testDeclaresColonNotationPermissions(): void
    {
        $permissions = (new DemoCatalogPlugin())->getPermissions();

        $this->assertSame(['demo_catalog:view', 'demo_catalog:manage'], $permissions);

        foreach ($permissions as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/',
                $permission
            );
        }
    }

    public function testDeclaresNoHooksAndBothMigrations(): void
    {
        $plugin = new DemoCatalogPlugin();

        $this->assertSame([], $plugin->getHooks());

        $migrations = $plugin->getMigrations();
        $this->assertContains(CreateDemoCatalogItemsTable::class, $migrations);
        $this->assertContains(GrantDemoCatalogPermissionsToAdmin::class, $migrations);
    }

    public function testPluginLoaderDiscoversDemoCatalogAndRegistersRoutes(): void
    {
        // Point the loader at the real plugins/ directory so we exercise the
        // exact discovery path used in production. The directory may contain
        // other plugins; we only assert on DemoCatalog so the test tolerates them.
        $pluginDir = dirname(__DIR__, 2) . '/plugins';

        $router = new Router('');
        $permissionRegistry = new PermissionRegistry();
        $hookManager = new HookManager();

        $loader = new PluginLoader($pluginDir, $router, $permissionRegistry, $hookManager);
        $loader->load();

        $names = array_map(
            static fn(PluginInterface $p): string => $p->getName(),
            $loader->getPlugins()
        );
        $this->assertContains('DemoCatalog', $names);

        $listMatch = $router->match(new Request('GET', '/api/demo-catalog/items'));
        $this->assertNotNull($listMatch);
        $this->assertSame('demo_catalog:view', $listMatch['requiredPermission']);

        $createMatch = $router->match(new Request('POST', '/api/demo-catalog/items'));
        $this->assertNotNull($createMatch);
        $this->assertSame('demo_catalog:manage', $createMatch['requiredPermission']);

        // Permissions were registered for the plugin source.
        $this->assertTrue($permissionRegistry->exists('demo_catalog:view'));
        $this->assertTrue($permissionRegistry->exists('demo_catalog:manage'));

        // The frontend feature descriptor survives loader validation and is
        // exposed with the owning plugin name attached.
        $features = $loader->getFrontendFeatures();
        $byId = array_column($features, null, 'id');
        $this->assertArrayHasKey('demo-catalog', $byId);
        $this->assertSame('DemoCatalog', $byId['demo-catalog']['plugin']);
        $this->assertSame('custom', $byId['demo-catalog']['screen']);
    }
}
