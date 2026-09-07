<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * A `rowActionList` prop may carry a `{method, endpoint}` mutation entry, and
 * those entries came through the loader's block walk untouched — the only write
 * endpoint in the contract that was neither ownership-checked nor
 * version-rewritten. `form.submit`, `actionButton.action` and an `inbox`
 * action all are.
 *
 * Two separate defects, one omission:
 *
 *   1. The endpoint reached the browser exactly as declared, so it lacked the
 *      version prefix the Router serves under. The action POSTed to a path
 *      nothing answers and got a 404 on click. Note what that failure does NOT
 *      do: the feature loads, the table renders, the button appears, and it
 *      reports nothing to `dropped`. Every check short of pressing the button
 *      says the screen is fine — which is how five of these shipped.
 *
 *   2. A plugin could aim a row action at a route it does not own. The
 *      dispatcher still gates the request on the route's own permission, so
 *      this was not a way to escalate; it was a way to describe a screen over
 *      somebody else's write route, which the ownership rule exists to refuse.
 *
 * The fix reads the prop kind off {@see \Whity\Sdk\Frontend\Blocks\BlockContract}
 * rather than naming props, so both of today's carriers are covered — a
 * `dataTable`'s `rowActions` and a `flow`'s `nodeActions` — and so is whatever
 * declares one next. Both are asserted below for exactly that reason: a fix
 * that only handled `dataTable` would pass a test that only looked there.
 *
 * The templated case is the one that actually shipped, so it is the primary
 * assertion: a row action declares `{id}` while the route registers
 * `{id:\d+}`, and those must match — as they already do for a form's `submit`.
 * The refusals are asserted alongside, so the change is visibly a
 * normalisation and not a widening of the gate.
 */
final class PluginLoaderRowActionEndpointsTest extends TestCase
{
    private static string $ownedDir;
    private static string $foreignDir;

    public static function setUpBeforeClass(): void
    {
        self::$ownedDir   = sys_get_temp_dir() . '/whity_rae_owned_'   . uniqid();
        self::$foreignDir = sys_get_temp_dir() . '/whity_rae_foreign_' . uniqid();

        // Registers the GET a data-bound `source` needs, plus the POST the row
        // actions target — spelling its parameter `{id:\d+}` while the block
        // declares `{id}`, which is the live case this test exists for.
        self::writePlugin(self::$ownedDir, 'RaeOwned', <<<'PHP'
    public function getPermissions(): array { return ['ral:read', 'ral:approve']; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/ral/items',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'ral:read',
            ],
            [
                'method' => 'POST',
                'path' => '/api/ral/items/{id:\d+}/approve',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'ral:approve',
            ],
        ];
    }
    public function getFrontendFeatures(): array
    {
        $columns = [['key' => 'id', 'label' => 'ID'], ['key' => 'name', 'label' => 'Name']];

        return [
            [
                'id' => 'ral-table',
                'label' => 'Ral Table',
                'screen' => 'blocks',
                'requiredPermission' => 'ral:read',
                'blocks' => [[
                    'type' => 'dataTable',
                    'source' => '/api/ral/items',
                    'columns' => $columns,
                    'rowActions' => [
                        [
                            'label' => 'Approve',
                            'method' => 'POST',
                            'endpoint' => '/api/ral/items/{id}/approve',
                        ],
                        // An internal-nav entry. Not a route, and must come out
                        // byte-for-byte as declared: versioning a page path
                        // would send the browser to a page that does not exist.
                        [
                            'label' => 'Open',
                            'href' => '/admin/x/ral-record?id={id}',
                        ],
                    ],
                ]],
            ],
            // The SECOND carrier of the same prop kind. A fix that named
            // `rowActions` would leave this one broken.
            [
                'id' => 'ral-flow',
                'label' => 'Ral Flow',
                'screen' => 'blocks',
                'requiredPermission' => 'ral:read',
                'blocks' => [[
                    'type' => 'flow',
                    'source' => '/api/ral/items',
                    'nodeIdField' => 'id',
                    'nodeLabelField' => 'name',
                    'nodeActions' => [
                        [
                            'label' => 'Approve',
                            'method' => 'POST',
                            'endpoint' => '/api/ral/items/{id}/approve',
                        ],
                    ],
                ]],
            ],
            [
                'id' => 'ral-static',
                'label' => 'Ral Static',
                'screen' => 'custom',
                'requiredPermission' => 'ral:read',
            ],
        ];
    }
PHP);

        // Three refusals that must keep working, and a sibling that must
        // survive all of them.
        self::writePlugin(self::$foreignDir, 'RaeForeign', <<<'PHP'
    public function getPermissions(): array { return ['rf:read', 'rf:approve']; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/rf/items',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'rf:read',
            ],
            [
                'method' => 'POST',
                'path' => '/api/rf/items/{id:\d+}/approve',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'rf:approve',
            ],
        ];
    }
    public function getFrontendFeatures(): array
    {
        $columns = [['key' => 'id', 'label' => 'ID']];

        return [
            // A path this plugin never registered.
            [
                'id' => 'rf-foreign',
                'label' => 'Rf Foreign',
                'screen' => 'blocks',
                'requiredPermission' => 'rf:read',
                'blocks' => [[
                    'type' => 'dataTable',
                    'source' => '/api/rf/items',
                    'columns' => $columns,
                    'rowActions' => [[
                        'label' => 'Approve',
                        'method' => 'POST',
                        'endpoint' => '/api/elsewhere/{id}/approve',
                    ]],
                ]],
            ],
            // The right path, the wrong VERB: no DELETE is registered here.
            // Normalising parameter names must not make the gate verb-blind.
            [
                'id' => 'rf-wrong-verb',
                'label' => 'Rf Wrong Verb',
                'screen' => 'blocks',
                'requiredPermission' => 'rf:read',
                'blocks' => [[
                    'type' => 'dataTable',
                    'source' => '/api/rf/items',
                    'columns' => $columns,
                    'rowActions' => [[
                        'label' => 'Delete',
                        'method' => 'DELETE',
                        'endpoint' => '/api/rf/items/{id}/approve',
                    ]],
                ]],
            ],
            // The right prefix and verb, one segment more than any registered
            // route has.
            [
                'id' => 'rf-wrong-shape',
                'label' => 'Rf Wrong Shape',
                'screen' => 'blocks',
                'requiredPermission' => 'rf:read',
                'blocks' => [[
                    'type' => 'dataTable',
                    'source' => '/api/rf/items',
                    'columns' => $columns,
                    'rowActions' => [[
                        'label' => 'Approve',
                        'method' => 'POST',
                        'endpoint' => '/api/rf/items/{id}/approve/extra',
                    ]],
                ]],
            ],
            [
                'id' => 'rf-static',
                'label' => 'Rf Static',
                'screen' => 'custom',
                'requiredPermission' => 'rf:read',
            ],
        ];
    }
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$ownedDir, self::$foreignDir] as $dir) {
            self::removeDirectory($dir);
        }
    }

    // ── served + versioned ───────────────────────────────────────────────────

    /**
     * The defect as it shipped: the row action's endpoint must be rewritten to
     * the versioned URL the Router actually serves, with the declared `{id}`
     * matching the route's `{id:\d+}`.
     */
    public function testAnOwnedRowActionEndpointIsVersioned(): void
    {
        $byId = $this->featuresIn(self::$ownedDir, new Router('/v1'));

        $this->assertArrayHasKey(
            'ral-table',
            $byId,
            'A dataTable whose row action targets an owned POST route must be served'
        );

        $actions = $byId['ral-table']['blocks'][0]['rowActions'];
        $this->assertSame(
            '/api/v1/ral/items/{id}/approve',
            $actions[0]['endpoint'],
            'The row action endpoint must carry the version prefix, or it 404s on click'
        );
        $this->assertSame('POST', $actions[0]['method']);
        $this->assertSame('Approve', $actions[0]['label'], 'The rest of the entry must survive the rewrite');
    }

    /**
     * An `href` entry is a page path, not a route. Versioning it would send the
     * browser somewhere that does not exist — a second bug wearing the first
     * one's fix.
     */
    public function testAnHrefRowActionIsLeftAlone(): void
    {
        $byId = $this->featuresIn(self::$ownedDir, new Router('/v1'));

        $actions = $byId['ral-table']['blocks'][0]['rowActions'];
        $this->assertSame(
            '/admin/x/ral-record?id={id}',
            $actions[1]['href'],
            'An internal-nav href must come through byte-for-byte'
        );
        $this->assertArrayNotHasKey('endpoint', $actions[1]);
    }

    /**
     * The same prop kind under a different name. This is the assertion that
     * fails if the fix names props instead of reading their kind.
     */
    public function testAFlowNodeActionEndpointIsVersionedToo(): void
    {
        $byId = $this->featuresIn(self::$ownedDir, new Router('/v1'));

        $this->assertArrayHasKey(
            'ral-flow',
            $byId,
            "A flow whose nodeActions target an owned POST route must be served"
        );

        $this->assertSame(
            '/api/v1/ral/items/{id}/approve',
            $byId['ral-flow']['blocks'][0]['nodeActions'][0]['endpoint'],
            'flow.nodeActions is the same rowActionList kind and must be rewritten identically'
        );
    }

    public function testTheSiblingStaticFeatureIsUnaffected(): void
    {
        $byId = $this->featuresIn(self::$ownedDir, new Router('/v1'));

        $this->assertArrayHasKey('ral-static', $byId);
        $this->assertSame('custom', $byId['ral-static']['screen']);
    }

    /**
     * With no version prefix there is nothing to insert, and the endpoint must
     * come through unchanged rather than mangled.
     */
    public function testAnEmptyVersionPrefixLeavesTheEndpointUnchanged(): void
    {
        $byId = $this->featuresIn(self::$ownedDir, new Router(''));

        $this->assertSame(
            '/api/ral/items/{id}/approve',
            $byId['ral-table']['blocks'][0]['rowActions'][0]['endpoint']
        );
    }

    // ── refusals ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedFeatures(): array
    {
        return [
            'a path this plugin never registered' => ['rf-foreign'],
            'the right path, the wrong verb'      => ['rf-wrong-verb'],
            'one segment too many'                => ['rf-wrong-shape'],
        ];
    }

    /**
     * @dataProvider refusedFeatures
     */
    public function testAnUnownedRowActionEndpointDropsTheFeature(string $featureId): void
    {
        $byId = $this->featuresIn(self::$foreignDir, new Router('/v1'));

        $this->assertArrayNotHasKey(
            $featureId,
            $byId,
            'A row action may only target a write route this plugin registered'
        );
    }

    /**
     * Fail-closed, not fail-loud: dropping one feature must not take the
     * plugin's other features with it, and must not throw.
     */
    public function testARefusalDoesNotKillSiblingFeatures(): void
    {
        $byId = $this->featuresIn(self::$foreignDir, new Router('/v1'));

        $this->assertArrayHasKey('rf-static', $byId, 'The sibling must survive all three refusals');
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    /**
     * @return array<string, array<string, mixed>>
     */
    private function featuresIn(string $dir, Router $router): array
    {
        $loader = new PluginLoader($dir, $router, new PermissionRegistry(), new HookManager());
        $loader->load();

        return array_column($loader->getFrontendFeatures(), null, 'id');
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
