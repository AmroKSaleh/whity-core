<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Router;

/**
 * WC-169: plugin frontend feature descriptors (SDK 1.2) and host-enforced
 * route-array requiredPermission.
 *
 * Plugins MAY implement {@see \Whity\Sdk\PluginFrontendInterface} to describe
 * the admin-UI screens they contribute. The loader validates EVERY descriptor
 * fail-closed (shape, permission ownership, crud resource ownership, id
 * uniqueness) and DROPS invalid ones with a logged warning while the plugin
 * itself keeps loading — descriptors are UI metadata, never a load gate.
 * Disabled / non-active plugins contribute NOTHING.
 *
 * The loader also passes a route's `requiredPermission` through to the router
 * (where RbacMiddleware enforces it) and refuses to register a route whose
 * declared permission is malformed (fail-closed, no unprotected fallback).
 */
final class PluginFrontendFeaturesTest extends TestCase
{
    private static string $mainDir;
    private static string $invalidDir;
    private static string $dupDir;
    private static string $throwingDir;
    private static string $routePermDir;
    private static string $hardeningDir;
    private static string $mismatchDir;
    private static string $shadowDir;
    private static string $actionDir;

    public static function setUpBeforeClass(): void
    {
        self::$mainDir = sys_get_temp_dir() . '/whity_feat_main_' . uniqid();
        self::$invalidDir = sys_get_temp_dir() . '/whity_feat_invalid_' . uniqid();
        self::$dupDir = sys_get_temp_dir() . '/whity_feat_dup_' . uniqid();
        self::$throwingDir = sys_get_temp_dir() . '/whity_feat_throwing_' . uniqid();
        self::$routePermDir = sys_get_temp_dir() . '/whity_feat_routeperm_' . uniqid();
        self::$hardeningDir = sys_get_temp_dir() . '/whity_feat_hardening_' . uniqid();
        self::$mismatchDir = sys_get_temp_dir() . '/whity_feat_mismatch_' . uniqid();
        self::$shadowDir = sys_get_temp_dir() . '/whity_feat_shadow_' . uniqid();
        self::$actionDir = sys_get_temp_dir() . '/whity_feat_action_' . uniqid();

        // ---- mainDir: one healthy plugin with a crud + a custom descriptor ----
        self::writePlugin(self::$mainDir, 'FeatGood', <<<'PHP'
    public function getPermissions(): array { return ['feat:view', 'feat:manage']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/feat/items',
            'handler' => static fn (Request $r): Response => Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'feat:view',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'feat-items',
                'label' => 'Items',
                'screen' => 'crud',
                'requiredPermission' => 'feat:view',
                'resource' => ['basePath' => '/api/feat/items', 'titleField' => 'name'],
                'icon' => 'box',
                'group' => 'inventory',
                'order' => 5,
            ],
            [
                'id' => 'feat-dashboard',
                'label' => 'Feature Dashboard',
                'screen' => 'custom',
                'requiredPermission' => 'feat:manage',
            ],
        ];
    }
PHP);

        // ---- invalidDir: every fail-closed validation rule in one plugin ----
        self::writePlugin(self::$invalidDir, 'FeatInvalid', <<<'PHP'
    public function getPermissions(): array { return ['featinv:view']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/featinv/items',
            'handler' => static fn (Request $r): Response => Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'featinv:view',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // (a) shape violations
            ['label' => 'No id', 'screen' => 'custom', 'requiredPermission' => 'featinv:view'],
            ['id' => 'Bad_Slug', 'label' => 'Bad slug', 'screen' => 'custom', 'requiredPermission' => 'featinv:view'],
            ['id' => 'no-label', 'screen' => 'custom', 'requiredPermission' => 'featinv:view'],
            ['id' => 'bad-screen', 'label' => 'Bad screen', 'screen' => 'wizard', 'requiredPermission' => 'featinv:view'],
            'not-even-an-array',
            // (b) permission violations: malformed, core-owned, foreign
            ['id' => 'bad-perm-format', 'label' => 'X', 'screen' => 'custom', 'requiredPermission' => 'Feat:View'],
            ['id' => 'core-perm', 'label' => 'X', 'screen' => 'custom', 'requiredPermission' => 'users:read'],
            ['id' => 'no-perm', 'label' => 'X', 'screen' => 'custom'],
            // (c) crud resource violations
            ['id' => 'crud-no-resource', 'label' => 'X', 'screen' => 'crud', 'requiredPermission' => 'featinv:view'],
            ['id' => 'crud-foreign-path', 'label' => 'X', 'screen' => 'crud', 'requiredPermission' => 'featinv:view',
             'resource' => ['basePath' => '/api/somebody/elses']],
            ['id' => 'crud-non-api-path', 'label' => 'X', 'screen' => 'crud', 'requiredPermission' => 'featinv:view',
             'resource' => ['basePath' => 'api/featinv/items']],
            // optional-field type violation
            ['id' => 'bad-icon-type', 'label' => 'X', 'screen' => 'custom', 'requiredPermission' => 'featinv:view',
             'icon' => 42],
            // the single VALID survivor
            ['id' => 'featinv-valid', 'label' => 'Valid', 'screen' => 'crud', 'requiredPermission' => 'featinv:view',
             'resource' => ['basePath' => '/api/featinv/items']],
        ];
    }
PHP);

        // ---- dupDir: two plugins claiming the same descriptor id ----
        self::writePlugin(self::$dupDir, 'AaFirst', <<<'PHP'
    public function getPermissions(): array { return ['aafirst:view']; }
    public function getRoutes(): array { return []; }
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'shared-id',
            'label' => 'First claimant',
            'screen' => 'custom',
            'requiredPermission' => 'aafirst:view',
        ]];
    }
PHP);
        self::writePlugin(self::$dupDir, 'ZzSecond', <<<'PHP'
    public function getPermissions(): array { return ['zzsecond:view']; }
    public function getRoutes(): array { return []; }
    public function getFrontendFeatures(): array
    {
        return [
            [
                'id' => 'shared-id',
                'label' => 'Duplicate claimant',
                'screen' => 'custom',
                'requiredPermission' => 'zzsecond:view',
            ],
            [
                'id' => 'zz-own',
                'label' => 'Own id',
                'screen' => 'custom',
                'requiredPermission' => 'zzsecond:view',
            ],
        ];
    }
PHP);

        // ---- throwingDir: getFrontendFeatures() explodes ----
        self::writePlugin(self::$throwingDir, 'FeatThrows', <<<'PHP'
    public function getPermissions(): array { return ['featthrows:view']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/featthrows/ping',
            'handler' => static fn (Request $r): Response => Response::json(['ok' => true]),
            'requiredRole' => null,
        ]];
    }
    public function getFrontendFeatures(): array
    {
        throw new \RuntimeException('descriptor explosion');
    }
PHP);

        // ---- hardeningDir: permission-ownership hardening (WC-169 review) ----
        // SelfCore SELF-DECLARES the core permission name 'users:read' — owning
        // it in getPermissions() must NOT make it descriptor-eligible.
        self::writePlugin(self::$hardeningDir, 'SelfCore', <<<'PHP'
    public function getPermissions(): array { return ['users:read', 'selfcore:view']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/selfcore/items',
            'handler' => static fn (Request $r): Response => Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'selfcore:view',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            ['id' => 'selfcore-coreperm', 'label' => 'Core-perm screen', 'screen' => 'custom',
             'requiredPermission' => 'users:read'],
            ['id' => 'selfcore-ok', 'label' => 'Own items', 'screen' => 'crud',
             'requiredPermission' => 'selfcore:view',
             'resource' => ['basePath' => '/api/selfcore/items']],
        ];
    }
PHP);
        // AaOwner declares shared:perm FIRST (discovery order: Aa < Zz).
        self::writePlugin(self::$hardeningDir, 'AaOwner', <<<'PHP'
    public function getPermissions(): array { return ['shared:perm']; }
    public function getRoutes(): array { return []; }
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'aa-shared',
            'label' => 'Owner screen',
            'screen' => 'custom',
            'requiredPermission' => 'shared:perm',
        ]];
    }
PHP);
        // ZzPoacher re-declares AaOwner's permission — descriptors gated on the
        // poached name must be dropped; its own-permission descriptor survives.
        self::writePlugin(self::$hardeningDir, 'ZzPoacher', <<<'PHP'
    public function getPermissions(): array { return ['shared:perm', 'zzp:view']; }
    public function getRoutes(): array { return []; }
    public function getFrontendFeatures(): array
    {
        return [
            ['id' => 'zz-poach', 'label' => 'Poached screen', 'screen' => 'custom',
             'requiredPermission' => 'shared:perm'],
            ['id' => 'zz-own2', 'label' => 'Own screen', 'screen' => 'custom',
             'requiredPermission' => 'zzp:view'],
        ];
    }
PHP);

        // ---- mismatchDir: descriptor permission must MATCH the route's ----
        self::writePlugin(self::$mismatchDir, 'PermMismatch', <<<'PHP'
    public function getPermissions(): array { return ['pm:view', 'pm:other']; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/pm/items',
                'handler' => static fn (Request $r): Response => Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'pm:other',
            ],
            [
                'method' => 'GET',
                'path' => '/api/pm/ok',
                'handler' => static fn (Request $r): Response => Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'pm:view',
            ],
        ];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // Menu gated on pm:view but the data route enforces pm:other — a
            // misaligned (or unprotected) data route must not get a screen.
            ['id' => 'pm-mismatch', 'label' => 'Mismatch', 'screen' => 'crud',
             'requiredPermission' => 'pm:view',
             'resource' => ['basePath' => '/api/pm/items']],
            ['id' => 'pm-ok', 'label' => 'Aligned', 'screen' => 'crud',
             'requiredPermission' => 'pm:view',
             'resource' => ['basePath' => '/api/pm/ok']],
        ];
    }
PHP);

        // ---- shadowDir: a plugin route colliding with an existing route ----
        self::writePlugin(self::$shadowDir, 'Shadow', <<<'PHP'
    public function getPermissions(): array { return ['shadow:view']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'GET',
            'path' => '/api/shadow/items',
            'handler' => static fn (Request $r): Response => Response::json(['source' => 'plugin']),
            'requiredRole' => null,
            'requiredPermission' => 'shadow:view',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [[
            'id' => 'shadow-items',
            'label' => 'Shadowed items',
            'screen' => 'crud',
            'requiredPermission' => 'shadow:view',
            'resource' => ['basePath' => '/api/shadow/items'],
        ]];
    }
PHP);

        // ---- routePermDir: malformed route-level requiredPermission ----
        self::writePlugin(self::$routePermDir, 'BadRoutePerm', <<<'PHP'
    public function getPermissions(): array { return ['badrp:view']; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/badrp/protected',
                'handler' => static fn (Request $r): Response => Response::json(['ok' => true]),
                'requiredRole' => null,
                'requiredPermission' => 'NOT A PERMISSION',
            ],
            [
                'method' => 'GET',
                'path' => '/api/badrp/open',
                'handler' => static fn (Request $r): Response => Response::json(['ok' => true]),
                'requiredRole' => null,
            ],
        ];
    }
    public function getFrontendFeatures(): array { return []; }
PHP);

        // ---- actionDir: screen='action' descriptors (valid + every fail) ----
        self::writePlugin(self::$actionDir, 'FeatAction', <<<'PHP'
    public function getPermissions(): array { return ['featact:run', 'featact:other']; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'POST',
                'path' => '/api/featact/run',
                'handler' => static fn (Request $r): Response => Response::json(['ok' => true]),
                'requiredRole' => null,
                'requiredPermission' => 'featact:run',
            ],
            [
                'method' => 'POST',
                'path' => '/api/featact/other',
                'handler' => static fn (Request $r): Response => Response::json(['ok' => true]),
                'requiredRole' => null,
                'requiredPermission' => 'featact:other',
            ],
        ];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // missing action object
            ['id' => 'act-no-action', 'label' => 'X', 'screen' => 'action', 'requiredPermission' => 'featact:run'],
            // GET is not allowed for an action route
            ['id' => 'act-bad-method', 'label' => 'X', 'screen' => 'action', 'requiredPermission' => 'featact:run',
             'action' => ['method' => 'GET', 'path' => '/api/featact/run']],
            // path is registered but as POST, not PUT — method mismatch
            ['id' => 'act-wrong-method', 'label' => 'X', 'screen' => 'action', 'requiredPermission' => 'featact:run',
             'action' => ['method' => 'PUT', 'path' => '/api/featact/run']],
            // path the plugin does not serve as POST/PUT
            ['id' => 'act-foreign', 'label' => 'X', 'screen' => 'action', 'requiredPermission' => 'featact:run',
             'action' => ['method' => 'POST', 'path' => '/api/somebody/else']],
            // descriptor permission != the route's permission
            ['id' => 'act-mismatch', 'label' => 'X', 'screen' => 'action', 'requiredPermission' => 'featact:run',
             'action' => ['method' => 'POST', 'path' => '/api/featact/other']],
            // malformed field
            ['id' => 'act-bad-field', 'label' => 'X', 'screen' => 'action', 'requiredPermission' => 'featact:run',
             'action' => ['method' => 'POST', 'path' => '/api/featact/run',
                          'fields' => [['label' => 'No name', 'kind' => 'text']]]],
            // the single VALID survivor
            ['id' => 'act-valid', 'label' => 'Run It', 'screen' => 'action', 'requiredPermission' => 'featact:run',
             'icon' => 'bolt', 'order' => 7,
             'action' => ['method' => 'POST', 'path' => '/api/featact/run', 'submitLabel' => 'Go',
                          'fields' => [
                              ['name' => 'csv', 'label' => 'CSV', 'kind' => 'file', 'accept' => '.csv', 'required' => true],
                              ['name' => 'note', 'kind' => 'text'],
                          ]]],
        ];
    }
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        $dirs = [
            self::$mainDir, self::$invalidDir, self::$dupDir, self::$throwingDir,
            self::$routePermDir, self::$hardeningDir, self::$mismatchDir, self::$shadowDir,
            self::$actionDir,
        ];
        foreach ($dirs as $dir) {
            self::removeDirectory($dir);
        }
    }

    // ==================== route requiredPermission passthrough ====================

    public function testRouteRequiredPermissionReachesTheRouter(): void
    {
        [, $router] = $this->loadDir(self::$mainDir);

        $match = $router->match(new Request('GET', '/api/feat/items'));
        $this->assertNotNull($match, 'The plugin route must be registered');
        $this->assertSame(
            'feat:view',
            $match['requiredPermission'],
            'The declared requiredPermission must reach the router so RbacMiddleware enforces it'
        );
    }

    public function testRouteWithMalformedRequiredPermissionIsNotRegistered(): void
    {
        [$loader, $router] = $this->loadDir(self::$routePermDir);

        $this->assertNull(
            $router->match(new Request('GET', '/api/badrp/protected')),
            'A route declaring a malformed requiredPermission must fail CLOSED (not registered), never serve unprotected'
        );
        $this->assertNotNull(
            $router->match(new Request('GET', '/api/badrp/open')),
            'The plugin\'s other, well-declared routes still register'
        );

        $states = $this->statesByName($loader);
        $this->assertSame('active', $states['BadRoutePerm']['state'] ?? null, 'The plugin itself still loads');
    }

    // ==================== descriptor collection + normalization ====================

    public function testValidDescriptorsAreExposedWithPluginNameAndDefaults(): void
    {
        [$loader] = $this->loadDir(self::$mainDir);

        $features = $loader->getFrontendFeatures();
        $byId = array_column($features, null, 'id');

        $this->assertArrayHasKey('feat-items', $byId);
        $this->assertSame([
            'id' => 'feat-items',
            'plugin' => 'FeatGood',
            'label' => 'Items',
            'icon' => 'box',
            'group' => 'inventory',
            'order' => 5,
            'screen' => 'crud',
            'resource' => ['basePath' => '/api/feat/items', 'titleField' => 'name'],
            'action' => null,
            'requiredPermission' => 'feat:view',
        ], $byId['feat-items']);

        // The custom descriptor gets the documented defaults filled.
        $this->assertArrayHasKey('feat-dashboard', $byId);
        $this->assertSame([
            'id' => 'feat-dashboard',
            'plugin' => 'FeatGood',
            'label' => 'Feature Dashboard',
            'icon' => null,
            'group' => 'plugins',
            'order' => 100,
            'screen' => 'custom',
            'resource' => null,
            'action' => null,
            'requiredPermission' => 'feat:manage',
        ], $byId['feat-dashboard']);
    }

    // ==================== fail-closed validation (each rule drops) ====================

    public function testInvalidDescriptorsAreDroppedButThePluginStillLoads(): void
    {
        [$loader, $router] = $this->loadDir(self::$invalidDir);

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertSame(['featinv-valid'], $ids, 'Only the single valid descriptor survives validation');

        // The plugin itself still loads and serves — descriptors are UI metadata only.
        $states = $this->statesByName($loader);
        $this->assertSame('active', $states['FeatInvalid']['state'] ?? null);
        $this->assertNotNull($router->match(new Request('GET', '/api/featinv/items')));
    }

    /**
     * THE ACCEPTANCE-CRITERION TEST: a descriptor gated on a permission the
     * plugin does NOT declare (here the core 'users:read') is rejected — an
     * over-broad descriptor cannot expose a screen over someone else's
     * resource.
     */
    public function testDescriptorWithForeignPermissionIsRejected(): void
    {
        [$loader] = $this->loadDir(self::$invalidDir);

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains('core-perm', $ids, "A descriptor gated on 'users:read' (not the plugin's own permission) must be dropped");
        $this->assertNotContains('bad-perm-format', $ids, 'A descriptor with a malformed permission must be dropped');
        $this->assertNotContains('no-perm', $ids, 'A descriptor without requiredPermission must be dropped (no permissionless screens)');
    }

    public function testCrudDescriptorOverAPathThePluginDoesNotServeIsRejected(): void
    {
        [$loader] = $this->loadDir(self::$invalidDir);

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains('crud-no-resource', $ids, 'crud without resource.basePath must be dropped');
        $this->assertNotContains('crud-foreign-path', $ids, 'crud over a path the plugin does not own must be dropped');
        $this->assertNotContains('crud-non-api-path', $ids, 'basePath must start with /api/');
        $this->assertContains('featinv-valid', $ids, 'crud over the plugin\'s OWN GET route is accepted');
    }

    // ==================== permission-ownership hardening (review round) ====================

    /**
     * Self-declaring a CORE permission name in getPermissions() must not make
     * it descriptor-eligible: ownership is not self-asserted. A screen gated
     * on 'users:read' would otherwise surface for every core admin.
     */
    public function testDescriptorGatedOnASelfDeclaredCorePermissionIsRejected(): void
    {
        [$loader] = $this->loadDir(self::$hardeningDir);

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains('selfcore-coreperm', $ids, "Self-declaring 'users:read' must not make it descriptor-eligible");
        $this->assertContains('selfcore-ok', $ids, "The plugin's genuinely own permission still works");
    }

    /**
     * Cross-plugin permission poaching: the FIRST plugin (discovery order) to
     * declare a permission owns it; a later plugin re-declaring the same name
     * cannot gate descriptors on it.
     */
    public function testDescriptorGatedOnAnotherPluginsPermissionIsRejected(): void
    {
        [$loader] = $this->loadDir(self::$hardeningDir);

        $features = $loader->getFrontendFeatures();
        $ids = array_column($features, 'id');

        $this->assertContains('aa-shared', $ids, 'The first declarant keeps its descriptor');
        $this->assertNotContains('zz-poach', $ids, 'A poached permission cannot gate a descriptor');
        $this->assertContains('zz-own2', $ids, "The poacher's own-permission descriptor is unaffected");
    }

    /**
     * The descriptor's permission must MATCH the registered basePath GET
     * route's requiredPermission — a menu gated on X over a data route gated
     * on Y (or unprotected) is a misalignment that must fail closed.
     */
    public function testCrudDescriptorPermissionMustMatchTheRoutePermission(): void
    {
        [$loader] = $this->loadDir(self::$mismatchDir);

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains('pm-mismatch', $ids, 'Descriptor permission != route permission must be dropped');
        $this->assertContains('pm-ok', $ids, 'An aligned descriptor survives');
    }

    // ==================== screen='action' (declarative action screens) ====================

    /**
     * An `action` descriptor must point at a POST/PUT route the plugin actually
     * registered, gated on the SAME permission, and declare well-typed fields.
     * Every violation drops fail-closed; the one valid descriptor normalizes
     * (defaults filled, file/text fields, resource null, action populated).
     */
    public function testActionDescriptorValidationAndNormalization(): void
    {
        [$loader] = $this->loadDir(self::$actionDir);

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $ids = array_keys($byId);

        $this->assertSame(['act-valid'], $ids, 'Only the single valid action descriptor survives');
        $this->assertNotContains('act-no-action', $ids, "screen='action' without an action object must drop");
        $this->assertNotContains('act-bad-method', $ids, 'action.method must be POST or PUT');
        $this->assertNotContains('act-wrong-method', $ids, 'action.method PUT must not match a POST-only route');
        $this->assertNotContains('act-foreign', $ids, 'action.path must be a POST/PUT route this plugin registered');
        $this->assertNotContains('act-mismatch', $ids, "action route permission must equal the descriptor's");
        $this->assertNotContains('act-bad-field', $ids, 'a malformed action.fields entry fails closed');

        $this->assertSame([
            'id' => 'act-valid',
            'plugin' => 'FeatAction',
            'label' => 'Run It',
            'icon' => 'bolt',
            'group' => 'plugins',
            'order' => 7,
            'screen' => 'action',
            'resource' => null,
            'action' => [
                'method' => 'POST',
                'path' => '/api/featact/run',
                'submitLabel' => 'Go',
                'fields' => [
                    ['name' => 'csv', 'label' => 'CSV', 'kind' => 'file', 'accept' => '.csv', 'required' => true],
                    ['name' => 'note', 'label' => 'note', 'kind' => 'text', 'accept' => null, 'required' => false],
                ],
            ],
            'requiredPermission' => 'featact:run',
        ], $byId['act-valid']);
    }

    /**
     * Route shadowing: a plugin route colliding with an ALREADY-REGISTERED
     * route (e.g. a core route) is skipped by the router (first registration
     * wins), and a descriptor over that path is dropped — ownership is judged
     * by what actually REGISTERED, not by what the plugin declared.
     */
    public function testPluginRouteCollidingWithAnExistingRouteIsSkippedAndItsDescriptorDropped(): void
    {
        $router = new Router('');
        // Simulates a core route registered before plugins load.
        $router->register(
            'GET',
            '/api/shadow/items',
            static fn (): string => 'core-handler'
        );

        $loader = new PluginLoader(self::$shadowDir, $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        $match = $router->match(new Request('GET', '/api/shadow/items'));
        $this->assertNotNull($match);
        $this->assertSame('core-handler', ($match['handler'])(), 'The FIRST registration (core) must keep serving the path');

        $this->assertSame(
            [],
            $loader->getFrontendFeatures(),
            'A descriptor over a path the plugin failed to register must be dropped'
        );
    }

    public function testDuplicateIdsAcrossPluginsFirstWinsLaterDropped(): void
    {
        [$loader] = $this->loadDir(self::$dupDir);

        $features = $loader->getFrontendFeatures();
        $shared = array_values(array_filter($features, static fn (array $f): bool => $f['id'] === 'shared-id'));

        $this->assertCount(1, $shared, 'Exactly one plugin may hold a descriptor id');
        $this->assertSame('AaFirst', $shared[0]['plugin'], 'The FIRST claimant (discovery order) wins');

        // The duplicate claimant's other descriptors are unaffected.
        $this->assertContains('zz-own', array_column($features, 'id'));
    }

    public function testThrowingGetFrontendFeaturesDoesNotBreakThePlugin(): void
    {
        [$loader, $router] = $this->loadDir(self::$throwingDir);

        $this->assertSame([], $loader->getFrontendFeatures(), 'A throwing declaration contributes nothing');

        // The plugin's other capabilities still registered and it still serves.
        $states = $this->statesByName($loader);
        $this->assertSame('active', $states['FeatThrows']['state'] ?? null);
        $this->assertNotNull($router->match(new Request('GET', '/api/featthrows/ping')));
    }

    // ==================== lifecycle filtering ====================

    public function testDisabledPluginContributesNothingAndReEnableRestores(): void
    {
        [$loader] = $this->loadDir(self::$mainDir);

        $this->assertCount(2, $loader->getFrontendFeatures(), 'Baseline: both descriptors exposed');

        $this->assertTrue($loader->disablePlugin('FeatGood\\Plugin'));
        $this->assertSame([], $loader->getFrontendFeatures(), 'A disabled plugin contributes NO frontend features');

        $this->assertTrue($loader->reEnablePlugin('FeatGood\\Plugin'));
        $restored = $loader->getFrontendFeatures();
        $this->assertCount(2, $restored, 'Re-enabling restores the features');
        $this->assertEqualsCanonicalizing(['feat-items', 'feat-dashboard'], array_column($restored, 'id'));
    }

    // ==================== helpers ====================

    /**
     * @return array{0: PluginLoader, 1: Router}
     */
    private function loadDir(string $dir): array
    {
        $router = new Router('');
        $loader = new PluginLoader($dir, $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        return [$loader, $router];
    }

    /**
     * @return array<string, array{id: string, name: string, state: string, consecutive_errors: int, last_error: array{message: string, type: string, trace: string, at: int}|null}>
     */
    private function statesByName(PluginLoader $loader): array
    {
        $byName = [];
        foreach ($loader->getPluginStatuses() as $status) {
            $byName[$status['name']] = $status;
        }

        return $byName;
    }

    /**
     * Write a fixture plugin implementing PluginInterface + PluginFrontendInterface,
     * mirroring the PluginVersionGateTest fixture mechanism.
     */
    private static function writePlugin(string $baseDir, string $name, string $body): void
    {
        mkdir($baseDir . '/' . $name, 0755, true);

        file_put_contents($baseDir . '/' . $name . '/Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\Http\\Request;
use Whity\\Sdk\\Http\\Response;
use Whity\\Sdk\\PluginFrontendInterface;
use Whity\\Sdk\\PluginInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
{$body}
}
PHP);
    }

    private static function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
