<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\FrontendFeaturesApiHandler;
use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * WC-169 / WC-175: GET /api/frontend/features — the host's server-side,
 * permission-filtered listing of plugin frontend feature descriptors, now
 * also exposing the caller's effective per-feature WRITE capabilities (#199).
 *
 * Drives the REAL PluginLoader over an on-disk fixture plugin (so the
 * descriptors AND its routes flow through the same validation/normalization
 * path as in production); RoleChecker is mocked per the AuditLog test pattern so
 * each caller's permission set is precise. The SAME Router is passed to the
 * loader (so the plugin's routes register into it) and the handler (so it reads
 * those routes back). Acceptance focus:
 *
 *  - per-descriptor server-side filtering: a caller sees ONLY the features
 *    whose requiredPermission they hold (never client-trust);
 *  - per-feature capabilities (canCreate/canEdit/canDelete) computed
 *    server-side from the resource's routes' RBAC, mirroring RbacMiddleware:
 *    a read-only caller gets all-false; a manage caller gets all-true; a
 *    feature without a resource gets all-false;
 *  - fail-closed on unresolved tenant context (403);
 *  - fail-closed on missing/invalid authenticated user (403);
 *  - the documented response shape, with resource null for custom screens.
 */
final class FrontendFeaturesApiHandlerRealEngineTest extends TestCase
{
    private static string $pluginDir;

    private PluginLoader $loader;

    private Router $router;

    public static function setUpBeforeClass(): void
    {
        self::$pluginDir = sys_get_temp_dir() . '/whity_featapi_' . uniqid();
        mkdir(self::$pluginDir . '/FeatApi', 0755, true);

        file_put_contents(self::$pluginDir . '/FeatApi/Plugin.php', <<<'PHP'
<?php

declare(strict_types=1);

namespace FeatApi;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface
{
    public function getName(): string { return 'FeatApi'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getRoutes(): array
    {
        $ok = static fn (Request $r): Response => Response::json(['data' => []]);

        return [
            // Read surface: gated on featapi:view.
            [
                'method' => 'GET',
                'path' => '/api/featapi/widgets',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:view',
            ],
            // Write surface: gated on featapi:manage. Create is at the base
            // path; edit/delete are at the item path.
            [
                'method' => 'POST',
                'path' => '/api/featapi/widgets',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:manage',
            ],
            [
                'method' => 'PATCH',
                'path' => '/api/featapi/widgets/{id:\d+}',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:manage',
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/featapi/widgets/{id:\d+}',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:manage',
            ],
            // NESTED sub-resource write routes under the SAME base path, gated
            // on a DIFFERENT permission (featapi:notes). Their item path has an
            // extra segment after the {id}, so a prefix match on the base item
            // path would wrongly attribute them to the widgets resource and
            // over-grant canEdit/canDelete to a caller holding only notes.
            [
                'method' => 'PATCH',
                'path' => '/api/featapi/widgets/{id:\d+}/notes/{noteId:\d+}',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:notes',
            ],
            [
                'method' => 'DELETE',
                'path' => '/api/featapi/widgets/{id:\d+}/notes/{noteId:\d+}',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:notes',
            ],
        ];
    }
    public function getPermissions(): array { return ['featapi:view', 'featapi:manage', 'featapi:notes', 'featapi:admin']; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'featapi-widgets',
                'label' => 'Widgets',
                'screen' => 'crud',
                'requiredPermission' => 'featapi:view',
                'resource' => ['basePath' => '/api/featapi/widgets', 'titleField' => 'name'],
                'icon' => 'box',
                'order' => 7,
            ],
            [
                'id' => 'featapi-console',
                'label' => 'Admin Console',
                'screen' => 'custom',
                'requiredPermission' => 'featapi:admin',
            ],
        ];
    }
}
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        @unlink(self::$pluginDir . '/FeatApi/Plugin.php');
        @rmdir(self::$pluginDir . '/FeatApi');
        @rmdir(self::$pluginDir);
    }

    protected function setUp(): void
    {
        TenantContext::reset();

        $this->router = new Router();
        $this->loader = new PluginLoader(self::$pluginDir, $this->router, new PermissionRegistry(), new HookManager());
        $this->loader->load();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== server-side permission filtering ====================

    public function testCallerSeesOnlyFeaturesWhosePermissionTheyHold(): void
    {
        TenantContext::setTenantId(1);

        // The caller holds featapi:view but NOT featapi:admin.
        $handler = $this->handler(['featapi:view']);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $body = json_decode($response->getBody(), true);

        $this->assertSame(
            ['featapi-widgets'],
            array_column($body['data'], 'id'),
            'Only the descriptor gated on a permission the caller holds may appear'
        );
    }

    public function testCallerWithoutAnyPermissionGetsEmptyListNotAnError(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler([])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['data' => []], json_decode($response->getBody(), true));
    }

    public function testPermissionIsCheckedAgainstTheResolvedTenant(): void
    {
        TenantContext::setTenantId(7);

        $seen = [];
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')
            ->willReturnCallback(function (int $userId, string $permission, int $tenantId) use (&$seen): bool {
                $seen[] = [$userId, $permission, $tenantId];
                return true;
            });

        $handler = new FrontendFeaturesApiHandler($this->loader, $roleChecker, $this->router);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains([42, 'featapi:view', 7], $seen);
        $this->assertContains([42, 'featapi:admin', 7], $seen);
    }

    // ==================== response shape ====================

    public function testResponseCarriesTheDocumentedShape(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['featapi:view', 'featapi:admin', 'featapi:manage'])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $body = json_decode($response->getBody(), true);
        $byId = array_column($body['data'], null, 'id');

        $this->assertSame([
            'id' => 'featapi-widgets',
            'plugin' => 'FeatApi',
            'label' => 'Widgets',
            'icon' => 'box',
            'group' => 'plugins',
            'order' => 7,
            'screen' => 'crud',
            'resource' => ['basePath' => '/api/featapi/widgets', 'titleField' => 'name'],
            'action' => null,
            'requiredPermission' => 'featapi:view',
            'capabilities' => ['canCreate' => true, 'canEdit' => true, 'canDelete' => true],
        ], $byId['featapi-widgets']);

        $this->assertSame([
            'id' => 'featapi-console',
            'plugin' => 'FeatApi',
            'label' => 'Admin Console',
            'icon' => null,
            'group' => 'plugins',
            'order' => 100,
            'screen' => 'custom',
            'resource' => null,
            'action' => null,
            'requiredPermission' => 'featapi:admin',
            'capabilities' => ['canCreate' => false, 'canEdit' => false, 'canDelete' => false],
        ], $byId['featapi-console'], "A custom screen without a resource carries resource: null and all-false capabilities");
    }

    // ==================== per-feature write capabilities (#199) ====================

    public function testReadOnlyCallerGetsAllFalseCapabilitiesForCrudFeature(): void
    {
        TenantContext::setTenantId(1);

        // Holds the view permission (so the feature is visible) but NOT manage
        // (so every write route's RBAC fails).
        $response = $this->handler(['featapi:view'])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column(json_decode($response->getBody(), true)['data'], null, 'id');

        $this->assertSame(
            ['canCreate' => false, 'canEdit' => false, 'canDelete' => false],
            $byId['featapi-widgets']['capabilities'],
            'A caller without the write permission must see no write capabilities'
        );
    }

    public function testManageCallerGetsAllTrueCapabilitiesForCrudFeature(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['featapi:view', 'featapi:manage'])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column(json_decode($response->getBody(), true)['data'], null, 'id');

        $this->assertSame(
            ['canCreate' => true, 'canEdit' => true, 'canDelete' => true],
            $byId['featapi-widgets']['capabilities'],
            'A caller holding the write permission gets every write capability'
        );
    }

    public function testNestedSubResourceWriteRoutesDoNotOverGrantCapabilities(): void
    {
        TenantContext::setTenantId(1);

        // The caller can view widgets and manage their NESTED notes, but holds
        // NO featapi:manage. The genuine item routes (PATCH/DELETE
        // /api/featapi/widgets/{id}) are gated on featapi:manage; only the
        // deeper notes routes are gated on featapi:notes. The renderer only ever
        // submits to ${basePath}/{id}, so canEdit/canDelete must reflect ONLY
        // those genuine item routes — never a nested sub-resource route with a
        // different RBAC. A prefix match would over-grant here.
        $response = $this->handler(['featapi:view', 'featapi:notes'])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column(json_decode($response->getBody(), true)['data'], null, 'id');

        $this->assertSame(
            ['canCreate' => false, 'canEdit' => false, 'canDelete' => false],
            $byId['featapi-widgets']['capabilities'],
            'A nested sub-resource write route gated on a different permission must not grant the resource its own canEdit/canDelete'
        );
    }

    public function testCustomFeatureWithoutResourceGetsAllFalseCapabilities(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['featapi:admin'])->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $byId = array_column(json_decode($response->getBody(), true)['data'], null, 'id');

        $this->assertSame(
            ['canCreate' => false, 'canEdit' => false, 'canDelete' => false],
            $byId['featapi-console']['capabilities'],
            'A feature without a resource has no derivable write routes'
        );
    }

    // ==================== fail-closed ====================

    public function testUnresolvedTenantContextFailsClosed(): void
    {
        // No TenantContext set.
        $response = $this->handler(['featapi:view'])->list($this->authedRequest(42));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMissingAuthenticatedUserFailsClosed(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['featapi:view'])->list(new Request('GET', '/api/frontend/features'));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMalformedUserIdFailsClosed(): void
    {
        TenantContext::setTenantId(1);

        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['user_id' => 'not-an-int'];

        $response = $this->handler(['featapi:view'])->list($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    // ==================== helpers ====================

    /**
     * Build the handler with a RoleChecker stub granting exactly the given permissions.
     *
     * @param array<int, string> $granted The permissions the caller holds.
     */
    private function handler(array $granted): FrontendFeaturesApiHandler
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')
            ->willReturnCallback(
                static fn (int $userId, string $permission, int $tenantId): bool => in_array($permission, $granted, true)
            );

        return new FrontendFeaturesApiHandler($this->loader, $roleChecker, $this->router);
    }

    private function authedRequest(int $userId): Request
    {
        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['user_id' => $userId];

        return $request;
    }
}
