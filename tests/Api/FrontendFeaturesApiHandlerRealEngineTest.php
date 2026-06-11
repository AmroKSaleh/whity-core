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
 * WC-169: GET /api/frontend/features — the host's server-side,
 * permission-filtered listing of plugin frontend feature descriptors.
 *
 * Drives the REAL PluginLoader over an on-disk fixture plugin (so the
 * descriptors flow through the same validation/normalization path as in
 * production); RoleChecker is mocked per the AuditLog test pattern so each
 * caller's permission set is precise. Acceptance focus:
 *
 *  - per-descriptor server-side filtering: a caller sees ONLY the features
 *    whose requiredPermission they hold (never client-trust);
 *  - fail-closed on unresolved tenant context (403);
 *  - fail-closed on missing/invalid authenticated user (403);
 *  - the documented response shape, with resource null for custom screens.
 */
final class FrontendFeaturesApiHandlerRealEngineTest extends TestCase
{
    private static string $pluginDir;

    private PluginLoader $loader;

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
        return [[
            'method' => 'GET',
            'path' => '/api/featapi/widgets',
            'handler' => static fn (Request $r): Response => Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'featapi:view',
        ]];
    }
    public function getPermissions(): array { return ['featapi:view', 'featapi:admin']; }
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

        $this->loader = new PluginLoader(self::$pluginDir, new Router(), new PermissionRegistry(), new HookManager());
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

        $handler = new FrontendFeaturesApiHandler($this->loader, $roleChecker);
        $response = $handler->list($this->authedRequest(42));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertContains([42, 'featapi:view', 7], $seen);
        $this->assertContains([42, 'featapi:admin', 7], $seen);
    }

    // ==================== response shape ====================

    public function testResponseCarriesTheDocumentedShape(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['featapi:view', 'featapi:admin'])->list($this->authedRequest(42));

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
            'requiredPermission' => 'featapi:view',
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
            'requiredPermission' => 'featapi:admin',
        ], $byId['featapi-console'], "A custom screen without a resource carries resource: null");
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

        return new FrontendFeaturesApiHandler($this->loader, $roleChecker);
    }

    private function authedRequest(int $userId): Request
    {
        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['user_id' => $userId];

        return $request;
    }
}
