<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Tenant\StaticTenantContextAdapter;
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
            // #951's reported defect, reproduced: a resource whose item route
            // is registered as PUT, which is a perfectly valid route the
            // capability derivation simply does not read (it derives edit from
            // PATCH). Nothing is registered for create or delete either, so
            // this resource exercises every no-route denial at once.
            [
                'method' => 'GET',
                'path' => '/api/featapi/gadgets',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:gadgets',
            ],
            [
                'method' => 'PUT',
                'path' => '/api/featapi/gadgets/{id:\d+}',
                'handler' => $ok,
                'requiredRole' => null,
                'requiredPermission' => 'featapi:gadgets',
            ],
        ];
    }
    public function getPermissions(): array { return ['featapi:view', 'featapi:manage', 'featapi:notes', 'featapi:admin', 'featapi:gadgets']; }
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
            [
                'id' => 'featapi-gadgets',
                'label' => 'Gadgets',
                'screen' => 'crud',
                'requiredPermission' => 'featapi:gadgets',
                'resource' => ['basePath' => '/api/featapi/gadgets', 'titleField' => 'name'],
            ],
            // #953: a descriptor the LOADER refuses — its resource names a GET
            // route this plugin never registered. It never reaches the served
            // list; the point is that it no longer vanishes silently either.
            [
                'id' => 'featapi-ghost',
                'label' => 'Ghost',
                'screen' => 'crud',
                'requiredPermission' => 'featapi:view',
                'resource' => ['basePath' => '/api/featapi/ghosts', 'titleField' => 'name'],
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

        $this->router = new Router('');
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
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(function (int $userId, string $permission, int $tenantId) use (&$seen): bool {
                $seen[] = [$userId, $permission, $tenantId];
                return true;
            });

        $handler = new FrontendFeaturesApiHandler($this->loader, $roleChecker, $this->router, $this->serverLabels());
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
            'embed' => null,
            'requiredPermission' => 'featapi:view',
            'capabilities' => ['canCreate' => true, 'canEdit' => true, 'canDelete' => true],
            // #951: a GRANTED capability has nothing to explain, so the map is
            // empty rather than carrying three null-ish entries.
            'capabilityReasons' => [],
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
            'embed' => null,
            'requiredPermission' => 'featapi:admin',
            'capabilities' => ['canCreate' => false, 'canEdit' => false, 'canDelete' => false],
            // #951: every capability is false and each says so in its own right.
            // This caller does not hold plugins:read, so `detail` is withheld.
            'capabilityReasons' => [
                'canCreate' => [
                    'code' => 'no-resource',
                    'reason' => 'Creating records is not available on this screen.',
                    'detail' => null,
                ],
                'canEdit' => [
                    'code' => 'no-resource',
                    'reason' => 'Editing records is not available on this screen.',
                    'detail' => null,
                ],
                'canDelete' => [
                    'code' => 'no-resource',
                    'reason' => 'Deleting records is not available on this screen.',
                    'detail' => null,
                ],
            ],
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

    // ============ why a capability came back false (#951) ============

    public function testDeniedCapabilityIsExplainedAsForbiddenWhenTheRouteExists(): void
    {
        TenantContext::setTenantId(1);

        // Holds view but not manage: the PATCH/DELETE item routes ARE
        // registered, and it is the caller's RBAC that denies them.
        $response = $this->handler(['featapi:view'])->list($this->authedRequest(42));

        $reasons = $this->reasonsFor($response, 'featapi-widgets');

        $this->assertSame('forbidden', $reasons['canEdit']['code']);
        $this->assertSame(
            'You do not have permission to edit records here.',
            $reasons['canEdit']['reason'],
            'The user-facing half names no identifier — only the fact, about the reader'
        );
    }

    public function testWrongMethodRegistrationIsExplainedAsAMissingRouteNotAsPermission(): void
    {
        TenantContext::setTenantId(1);

        // The gadgets resource registers PUT at the item path and the caller
        // holds its permission outright, so nothing here is an RBAC failure.
        // This is #951's reported case: the screen lists records and offers no
        // way to edit them, and until now said nothing about why.
        $response = $this->handler(['featapi:gadgets', 'plugins:read'])->list($this->authedRequest(42));

        $reasons = $this->reasonsFor($response, 'featapi-gadgets');

        $this->assertSame(
            'no-route',
            $reasons['canEdit']['code'],
            'A permitted caller facing a wrong-method registration must not be told it is a permission problem'
        );

        $detail = $reasons['canEdit']['detail'];
        $this->assertIsString($detail, 'A plugins:read caller must be given the operator detail');
        $this->assertStringContainsString(
            "no PATCH route is registered at '/api/featapi/gadgets/{id}'",
            $detail,
            'The author is told the exact method and path the platform looked for'
        );
        $this->assertStringContainsString(
            'never PUT',
            $detail,
            'And the one mistake that most often produces it is named outright'
        );
    }

    public function testOperatorDetailIsWithheldFromACallerWhoCannotActOnIt(): void
    {
        TenantContext::setTenantId(1);

        // Same screen, same denial — but a caller with no plugins:read.
        $response = $this->handler(['featapi:gadgets'])->list($this->authedRequest(42));

        $reasons = $this->reasonsFor($response, 'featapi-gadgets');

        $this->assertNull(
            $reasons['canEdit']['detail'],
            'Route diagnostics are meaningless to a user and are not theirs to read'
        );
        $this->assertSame(
            'Editing records is not available on this screen.',
            $reasons['canEdit']['reason'],
            'They still get an answer — just the one written for them'
        );
    }

    public function testForbiddenDetailNamesTheRouteRbacOnlyForAPluginReader(): void
    {
        TenantContext::setTenantId(1);

        $withRead = $this->reasonsFor(
            $this->handler(['featapi:view', 'plugins:read'])->list($this->authedRequest(42)),
            'featapi-widgets'
        );
        $withoutRead = $this->reasonsFor(
            $this->handler(['featapi:view'])->list($this->authedRequest(42)),
            'featapi-widgets'
        );

        $this->assertSame(
            "PATCH /api/featapi/widgets/{id} requires permission 'featapi:manage'",
            $withRead['canEdit']['detail']
        );
        $this->assertNull(
            $withoutRead['canEdit']['detail'],
            'The permission slug is RBAC surface: naming it to someone who does not hold it is the one real leak here'
        );
    }

    public function testGrantedCapabilitiesCarryNoReasonAtAll(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['featapi:view', 'featapi:manage'])->list($this->authedRequest(42));

        $this->assertSame(
            [],
            $this->reasonsFor($response, 'featapi-widgets'),
            'Nothing is denied, so there is nothing to explain'
        );
    }

    public function testReasonsDoNotChangeTheCapabilitiesThemselves(): void
    {
        TenantContext::setTenantId(1);

        // The whole point of #951 is that the ANSWER was already right and only
        // the explanation was discarded. A plugins:read caller must not be
        // granted anything a plain caller is not.
        $withRead = $this->handler(['featapi:view', 'plugins:read'])->list($this->authedRequest(42));
        $withoutRead = $this->handler(['featapi:view'])->list($this->authedRequest(42));

        $this->assertSame(
            $this->featureById($withoutRead, 'featapi-widgets')['capabilities'],
            $this->featureById($withRead, 'featapi-widgets')['capabilities']
        );
    }

    // ============ refusals reach an administrator (#953) ============

    public function testALoaderRefusedDescriptorReachesTheAdministratorWithItsReason(): void
    {
        TenantContext::setTenantId(1);

        $response = $this->handler(['featapi:view', 'plugins:read'])->list($this->authedRequest(42));
        $body = json_decode($response->getBody(), true);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertNotContains(
            'featapi-ghost',
            array_column($body['data'], 'id'),
            'Surfacing the refusal must not serve the refused screen'
        );

        $byId = array_column($body['dropped'], null, 'featureId');
        $this->assertArrayHasKey('featapi-ghost', $byId);
        $this->assertSame('FeatApi', $byId['featapi-ghost']['plugin']);
        $this->assertStringContainsString(
            "resource.basePath '/api/featapi/ghosts' is not a GET route this plugin registered",
            $byId['featapi-ghost']['reason']
        );
    }

    public function testARefusedDescriptorIsNotReportedToAnOrdinaryCaller(): void
    {
        TenantContext::setTenantId(1);

        $body = json_decode(
            $this->handler(['featapi:view'])->list($this->authedRequest(42))->getBody(),
            true
        );

        $this->assertArrayNotHasKey(
            'dropped',
            $body,
            'The reasons quote route paths, and every authenticated caller fetches this endpoint'
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
        $request->user = (object) ['profile_id' => 'not-an-int'];

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
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(
                static fn (int $userId, string $permission, int $tenantId): bool => in_array($permission, $granted, true)
            );

        return new FrontendFeaturesApiHandler($this->loader, $roleChecker, $this->router, $this->serverLabels());
    }

    /**
     * A translator over an empty schema, so declarations serve as written.
     *
     * `ServerLabels` is `final` and required here on purpose (#1044): an
     * optional one silently served English from `public/index.php` for an hour.
     * Given a registry with no tables its lookup throws and the helper answers
     * with the declared English, which is the behaviour these assertions expect.
     */
    private function serverLabels(): ServerLabels
    {
        $pdo = new \PDO('sqlite::memory:');

        return new ServerLabels(new LanguageRegistry(
            new LanguageRepository($pdo),
            new TranslationRepository($pdo),
            new StaticTenantContextAdapter(),
        ));
    }

    /**
     * The decoded feature entry with the given id.
     *
     * @return array<string, mixed>
     */
    private function featureById(\Whity\Core\Response $response, string $id): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());
        $byId = array_column(json_decode($response->getBody(), true)['data'], null, 'id');
        $this->assertArrayHasKey($id, $byId, "Feature '{$id}' is missing from the response");

        return $byId[$id];
    }

    /**
     * The `capabilityReasons` map of the feature with the given id (#951).
     *
     * @return array<string, array{code: string, reason: string, detail: string|null}>
     */
    private function reasonsFor(\Whity\Core\Response $response, string $id): array
    {
        return $this->featureById($response, $id)['capabilityReasons'];
    }

    private function authedRequest(int $userId): Request
    {
        $request = new Request('GET', '/api/frontend/features');
        $request->user = (object) ['profile_id' => $userId];

        return $request;
    }
}
