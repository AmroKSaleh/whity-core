<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * WC-234: when the loader validates a `screen:'blocks'` feature, the existing
 * data-bound `$walkNode` closure must ALSO validate each `form.submit.endpoint`
 * and `actionButton.action.endpoint` against the plugin's OWN registered
 * POST/PUT routes (ownership) and require the route's `requiredPermission` to
 * EQUAL the block's declared `requiredPermission` (permission match), then
 * version-rewrite the endpoint exactly as data-bound `source` is rewritten.
 *
 * Test 1 (served + versioned):
 *   A plugin that registers `POST /api/x/save` (perm `x:write`) and exposes a
 *   `screen:'blocks'` feature containing a `form` (submit POST /api/x/save,
 *   requiredPermission x:write) is served; `form.submit.endpoint` becomes
 *   `/api/v1/x/save`. Same for an `actionButton` in a separate feature.
 *
 * Test 2 (foreign endpoint → dropped, sibling served):
 *   A `form.submit.endpoint` of `/api/other/thing` (not a registered POST/PUT
 *   route) causes the feature to be ABSENT, no throw, sibling feature intact.
 *
 * Test 3 (permission mismatch → dropped):
 *   The endpoint IS a registered POST/PUT route but its route requiredPermission
 *   differs from the block's declared `requiredPermission` → feature dropped
 *   fail-closed.
 */
final class PluginLoaderInteractiveBlocksTest extends TestCase
{
    // ── fixtures ─────────────────────────────────────────────────────────────

    private static string $ownedEndpointDir;
    private static string $foreignEndpointDir;
    private static string $permMismatchDir;

    public static function setUpBeforeClass(): void
    {
        self::$ownedEndpointDir  = sys_get_temp_dir() . '/whity_ibb_owned_'    . uniqid();
        self::$foreignEndpointDir = sys_get_temp_dir() . '/whity_ibb_foreign_'  . uniqid();
        self::$permMismatchDir   = sys_get_temp_dir() . '/whity_ibb_permmatch_' . uniqid();

        // ── Plugin 1: registers POST /api/x/save (x:write)
        //    Feature 1a: form block with matching submit endpoint + permission
        //    Feature 1b: actionButton block with matching action endpoint + permission
        //    Feature 1c: valid sibling (custom screen) – always served
        self::writePlugin(self::$ownedEndpointDir, 'IbbOwned', <<<'PHP'
    public function getPermissions(): array { return ['x:write']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'POST',
            'path' => '/api/x/save',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'x:write',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // Feature 1a: form with owned POST endpoint
            [
                'id' => 'x-form',
                'label' => 'X Form',
                'screen' => 'blocks',
                'requiredPermission' => 'x:write',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/x/save'],
                    'requiredPermission' => 'x:write',
                    'children' => [
                        [
                            'type' => 'textInput',
                            'name' => 'title',
                            'label' => 'Title',
                        ],
                        [
                            'type' => 'submitButton',
                            'label' => 'Save',
                        ],
                    ],
                ]],
            ],
            // Feature 1b: standalone actionButton with owned POST endpoint
            [
                'id' => 'x-action',
                'label' => 'X Action',
                'screen' => 'blocks',
                'requiredPermission' => 'x:write',
                'blocks' => [[
                    'type' => 'actionButton',
                    'label' => 'Do It',
                    'action' => ['method' => 'POST', 'endpoint' => '/api/x/save'],
                    'requiredPermission' => 'x:write',
                ]],
            ],
            // Feature 1c: sibling static feature
            [
                'id' => 'x-static',
                'label' => 'X Static',
                'screen' => 'custom',
                'requiredPermission' => 'x:write',
            ],
        ];
    }
PHP);

        // ── Plugin 2: registers POST /api/y/own (y:write)
        //    Feature 2a: form with submit endpoint NOT registered → dropped
        //    Feature 2b: actionButton with action endpoint NOT registered → dropped
        //    Feature 2c: valid sibling → survives
        self::writePlugin(self::$foreignEndpointDir, 'IbbForeign', <<<'PHP'
    public function getPermissions(): array { return ['y:write']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'POST',
            'path' => '/api/y/own',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'y:write',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // INVALID: submit endpoint /api/other/thing is not a registered POST/PUT route
            [
                'id' => 'y-foreign-form',
                'label' => 'Y Foreign Form',
                'screen' => 'blocks',
                'requiredPermission' => 'y:write',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/other/thing'],
                    'requiredPermission' => 'y:write',
                    'children' => [
                        ['type' => 'textInput', 'name' => 'val', 'label' => 'Value'],
                        ['type' => 'submitButton', 'label' => 'Go'],
                    ],
                ]],
            ],
            // INVALID: action endpoint /api/other/action is not registered
            [
                'id' => 'y-foreign-action',
                'label' => 'Y Foreign Action',
                'screen' => 'blocks',
                'requiredPermission' => 'y:write',
                'blocks' => [[
                    'type' => 'actionButton',
                    'label' => 'Do It',
                    'action' => ['method' => 'POST', 'endpoint' => '/api/other/action'],
                    'requiredPermission' => 'y:write',
                ]],
            ],
            // VALID sibling: must survive
            [
                'id' => 'y-static',
                'label' => 'Y Static',
                'screen' => 'custom',
                'requiredPermission' => 'y:write',
            ],
        ];
    }
PHP);

        // ── Plugin 3: registers POST /api/z/save (z:write)
        //    Feature 3a: form declares requiredPermission='z:read' but route needs 'z:write' → dropped
        //    Feature 3b: actionButton declares requiredPermission='z:read' but route needs 'z:write' → dropped
        //    Feature 3c: valid sibling → survives
        self::writePlugin(self::$permMismatchDir, 'IbbPermMismatch', <<<'PHP'
    public function getPermissions(): array { return ['z:write', 'z:read']; }
    public function getRoutes(): array
    {
        return [[
            'method' => 'POST',
            'path' => '/api/z/save',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'z:write',
        ]];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // INVALID: form declares z:read but route requires z:write → mismatch → dropped
            [
                'id' => 'z-form-mismatch',
                'label' => 'Z Form Mismatch',
                'screen' => 'blocks',
                'requiredPermission' => 'z:read',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/z/save'],
                    'requiredPermission' => 'z:read',
                    'children' => [
                        ['type' => 'textInput', 'name' => 'val', 'label' => 'Value'],
                        ['type' => 'submitButton', 'label' => 'Go'],
                    ],
                ]],
            ],
            // INVALID: actionButton declares z:read but route requires z:write → dropped
            [
                'id' => 'z-action-mismatch',
                'label' => 'Z Action Mismatch',
                'screen' => 'blocks',
                'requiredPermission' => 'z:read',
                'blocks' => [[
                    'type' => 'actionButton',
                    'label' => 'Do It',
                    'action' => ['method' => 'POST', 'endpoint' => '/api/z/save'],
                    'requiredPermission' => 'z:read',
                ]],
            ],
            // VALID sibling: must survive
            [
                'id' => 'z-static',
                'label' => 'Z Static',
                'screen' => 'custom',
                'requiredPermission' => 'z:read',
            ],
        ];
    }
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$ownedEndpointDir, self::$foreignEndpointDir, self::$permMismatchDir] as $dir) {
            self::removeDirectory($dir);
        }
    }

    // ── TEST 1: owned endpoint → served + versioned ───────────────────────

    /**
     * A plugin registering POST /api/x/save (perm x:write) and a form block
     * with submit:{method:POST, endpoint:/api/x/save, requiredPermission:x:write}
     * must be SERVED, with form.submit.endpoint rewritten to /api/v1/x/save.
     */
    public function testOwnedFormEndpointIsServedAndVersioned(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'x-form',
            $byId,
            'A form feature whose submit endpoint is a plugin-owned POST route must be served'
        );

        $feature = $byId['x-form'];
        $this->assertSame('blocks', $feature['screen']);
        $this->assertIsArray($feature['blocks']);
        $this->assertCount(1, $feature['blocks']);

        $formNode = $feature['blocks'][0];
        $this->assertSame('form', $formNode['type']);
        $this->assertIsArray($formNode['submit']);
        $this->assertSame(
            '/api/v1/x/save',
            $formNode['submit']['endpoint'],
            'The form.submit.endpoint must be rewritten to the versioned URL (/api/v1/x/save)'
        );
        $this->assertSame('POST', $formNode['submit']['method']);
    }

    /**
     * A plugin with an actionButton whose action.endpoint is an owned POST route
     * must be SERVED, with action.endpoint rewritten to /api/v1/x/save.
     */
    public function testOwnedActionButtonEndpointIsServedAndVersioned(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'x-action',
            $byId,
            'An actionButton feature whose action endpoint is a plugin-owned POST route must be served'
        );

        $feature = $byId['x-action'];
        $this->assertSame('blocks', $feature['screen']);
        $this->assertIsArray($feature['blocks']);
        $this->assertCount(1, $feature['blocks']);

        $btnNode = $feature['blocks'][0];
        $this->assertSame('actionButton', $btnNode['type']);
        $this->assertIsArray($btnNode['action']);
        $this->assertSame(
            '/api/v1/x/save',
            $btnNode['action']['endpoint'],
            'The actionButton.action.endpoint must be rewritten to the versioned URL (/api/v1/x/save)'
        );
        $this->assertSame('POST', $btnNode['action']['method']);
    }

    /**
     * The sibling static feature is unaffected by the interactive endpoint check.
     */
    public function testSiblingStaticFeatureIsNotAffectedByInteractiveBlockLogic(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('x-static', $byId, 'The sibling static feature must still be served');
        $this->assertSame('custom', $byId['x-static']['screen']);
    }

    /**
     * With an empty version prefix, endpoints must NOT be altered (no rewrite).
     */
    public function testEmptyVersionPrefixLeavesEndpointsUnchanged(): void
    {
        [$loader] = $this->loadDir(self::$ownedEndpointDir, new Router(''));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey('x-form', $byId);
        $this->assertSame(
            '/api/x/save',
            $byId['x-form']['blocks'][0]['submit']['endpoint'],
            'With empty version prefix, submit.endpoint must remain as declared'
        );

        $this->assertArrayHasKey('x-action', $byId);
        $this->assertSame(
            '/api/x/save',
            $byId['x-action']['blocks'][0]['action']['endpoint'],
            'With empty version prefix, action.endpoint must remain as declared'
        );
    }

    // ── TEST 2: foreign endpoint → dropped fail-closed ───────────────────

    /**
     * A form whose submit.endpoint is NOT a registered POST/PUT route is
     * DROPPED fail-closed. No exception thrown.
     */
    public function testForeignFormEndpointDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'y-foreign-form',
            $ids,
            'A form feature with a foreign submit endpoint must be DROPPED (fail-closed)'
        );
    }

    /**
     * An actionButton whose action.endpoint is NOT a registered POST/PUT route
     * is DROPPED fail-closed. No exception thrown.
     */
    public function testForeignActionButtonEndpointDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'y-foreign-action',
            $ids,
            'An actionButton feature with a foreign action endpoint must be DROPPED (fail-closed)'
        );
    }

    /**
     * When foreign-endpoint features are dropped, the sibling static feature
     * (y-static) is still served — the drop is per-feature, not per-plugin.
     */
    public function testForeignEndpointDropDoesNotKillSiblingFeatures(): void
    {
        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey(
            'y-static',
            $byId,
            'A valid sibling feature must survive even when the interactive endpoint feature is dropped'
        );
    }

    /**
     * No exception is thrown when a foreign-endpoint feature is encountered.
     */
    public function testForeignEndpointNeverThrows(): void
    {
        $this->expectNotToPerformAssertions();

        [$loader] = $this->loadDir(self::$foreignEndpointDir, new Router('/v1'));
        $loader->getFrontendFeatures();
    }

    // ── TEST 3: permission mismatch → dropped ────────────────────────────

    /**
     * A form whose block-level requiredPermission does NOT match the registered
     * route's requiredPermission is DROPPED fail-closed.
     */
    public function testPermissionMismatchOnFormDropsTheFeature(): void
    {
        [$loader] = $this->loadDir(self::$permMismatchDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'z-form-mismatch',
            $ids,
            'A form feature where block requiredPermission != route requiredPermission must be DROPPED'
        );
    }

    /**
     * An actionButton whose block-level requiredPermission does NOT match the
     * registered route's requiredPermission is DROPPED fail-closed.
     */
    public function testPermissionMismatchOnActionButtonDropsTheFeature(): void
    {
        [$loader] = $this->loadDir(self::$permMismatchDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'z-action-mismatch',
            $ids,
            'An actionButton feature where block requiredPermission != route requiredPermission must be DROPPED'
        );
    }

    /**
     * When permission-mismatch features are dropped, the sibling static feature
     * (z-static) is still served.
     */
    public function testPermissionMismatchDropDoesNotKillSiblingFeatures(): void
    {
        [$loader] = $this->loadDir(self::$permMismatchDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey(
            'z-static',
            $byId,
            'A valid sibling feature must survive even when the permission-mismatch feature is dropped'
        );
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array{0: PluginLoader, 1: Router}
     */
    private function loadDir(string $dir, ?Router $router = null): array
    {
        $router ??= new Router('');
        $loader = new PluginLoader($dir, $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        return [$loader, $router];
    }

    private static function writePlugin(string $baseDir, string $name, string $body): void
    {
        mkdir($baseDir . '/' . $name, 0755, true);

        file_put_contents($baseDir . '/' . $name . '/Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$name};

use Whity\\Sdk\\Http\\Response;
use Whity\\Sdk\\PluginFrontendInterface;
use Whity\\Sdk\\PluginInterface;

final class Plugin implements PluginInterface, PluginFrontendInterface
{
    public function getName(): string { return '{$name}'; }
    public function getVersion(): string { return '1.0.0'; }
    public function getHooks(): array { return []; }
    public function getMigrations(): array { return []; }
    public function getSdkConstraint(): string { return '^1.8'; }
    public function getCoreConstraint(): string { return ''; }
    public function getPluginDependencies(): array { return []; }
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
