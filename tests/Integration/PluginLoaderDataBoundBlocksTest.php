<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * WC-230: when the loader validates a `screen:'blocks'` feature, it must walk
 * the tree and, for each data-bound node (one whose type's contract rule has a
 * `source` prop of kind `apiPath`):
 *  (a) confirm the node's `source` is a GET route the SAME plugin registered
 *      (ownership; fail-closed — foreign source drops the ENTIRE feature); and
 *  (b) rewrite `source` to the versioned `/api/v1/…` URL — mirroring how crud's
 *      `resource.basePath` and action's `path` are already handled.
 *
 * Test-1: a plugin that registers `GET /api/x/rows` exposes a `screen:'blocks'`
 * feature with a `dataTable` whose `source` is `/api/x/rows`; the loader
 * normalises it and the served block's `source` is `/api/v1/x/rows`.
 *
 * Test-2: a `screen:'blocks'` feature whose `dataTable.source` is
 * `/api/other/thing` (NOT a registered GET route of this plugin) is DROPPED —
 * the feature is absent from the loader output; the loader does NOT throw; a
 * sibling valid feature is still served.
 */
final class PluginLoaderDataBoundBlocksTest extends TestCase
{
    // ── fixtures ─────────────────────────────────────────────────────────────

    private static string $ownedSourceDir;
    private static string $foreignSourceDir;

    public static function setUpBeforeClass(): void
    {
        self::$ownedSourceDir  = sys_get_temp_dir() . '/whity_dbb_owned_'  . uniqid();
        self::$foreignSourceDir = sys_get_temp_dir() . '/whity_dbb_foreign_' . uniqid();

        // Plugin that owns GET /api/x/rows and exposes a dataTable bound to it.
        // A second feature (valid, static) is present to prove sibling isolation.
        self::writePlugin(self::$ownedSourceDir, 'DbbOwned', <<<'PHP'
    public function getPermissions(): array { return ['x:view']; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/x/rows',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'x:view',
            ],
            // The write the fieldArray features below submit to. Its permission
            // must EQUAL the block's declared one or the loader drops the
            // feature for a reason unrelated to what these tests are about.
            [
                'method' => 'PUT',
                'path' => '/api/x/rows',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'x:view',
            ],
        ];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // Feature under test: data-bound screen.
            [
                'id' => 'x-data',
                'label' => 'X Data',
                'screen' => 'blocks',
                'requiredPermission' => 'x:view',
                'blocks' => [[
                    'type' => 'dataTable',
                    'source' => '/api/x/rows',
                    'columns' => [
                        ['key' => 'id',   'label' => 'ID'],
                        ['key' => 'name', 'label' => 'Name'],
                    ],
                ]],
            ],
            // WC-240: a `chart` block is data-bound the SAME way (a `source`
            // prop of kind `apiPath`) — it must go through the identical
            // ownership + versioning walk as dataTable/dataStat/dataList,
            // with zero PluginLoader changes required.
            [
                'id' => 'x-chart',
                'label' => 'X Chart',
                'screen' => 'blocks',
                'requiredPermission' => 'x:view',
                'blocks' => [[
                    'type' => 'chart',
                    'source' => '/api/x/rows',
                    'chartType' => 'bar',
                    'xField' => 'name',
                    'series' => [['key' => 'id', 'label' => 'ID', 'color' => 1]],
                ]],
            ],
            // A `fieldArray` with NO source. It is a source-BEARING type now
            // (the contract gives it an optional `source`), and this feature
            // exists to prove that a declaration which simply does not use the
            // prop still survives. Before the loader guarded on the key's
            // PRESENCE rather than on the type's rule, this shape read
            // `$node['source']` off a node that had none, failed the ownership
            // comparison against null, and dropped the whole feature — every
            // source-less fieldArray on the platform, for a prop it never wrote.
            [
                'id' => 'x-array-plain',
                'label' => 'X Array Plain',
                'screen' => 'blocks',
                'requiredPermission' => 'x:view',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PUT', 'endpoint' => '/api/x/rows'],
                    'requiredPermission' => 'x:view',
                    'children' => [
                        [
                            'type' => 'fieldArray',
                            'name' => 'lines',
                            'label' => 'Lines',
                            'children' => [
                                ['type' => 'textInput', 'name' => 'title', 'label' => 'Title'],
                            ],
                        ],
                        ['type' => 'submitButton', 'label' => 'Save'],
                    ],
                ]],
            ],
            // A `fieldArray` WITH an owned source: ownership-checked and
            // version-rewritten by the same generic walk, with no fieldArray
            // knowledge anywhere in PluginLoader.
            [
                'id' => 'x-array-sourced',
                'label' => 'X Array Sourced',
                'screen' => 'blocks',
                'requiredPermission' => 'x:view',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PUT', 'endpoint' => '/api/x/rows'],
                    'requiredPermission' => 'x:view',
                    'children' => [
                        [
                            'type' => 'fieldArray',
                            'name' => 'lines',
                            'label' => 'Lines',
                            'source' => '/api/x/rows',
                            'children' => [
                                ['type' => 'textInput', 'name' => 'title', 'label' => 'Title'],
                            ],
                        ],
                        ['type' => 'submitButton', 'label' => 'Save'],
                    ],
                ]],
            ],
            // A `form` whose PRELOAD names an owned GET: ownership-checked and
            // version-rewritten like every other endpoint a block can name.
            [
                'id' => 'x-form-preload',
                'label' => 'X Form Preload',
                'screen' => 'blocks',
                'requiredPermission' => 'x:view',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'PUT', 'endpoint' => '/api/x/rows'],
                    'dataSource' => ['method' => 'GET', 'path' => '/api/x/rows'],
                    'requiredPermission' => 'x:view',
                    'children' => [
                        ['type' => 'textInput', 'name' => 'title', 'label' => 'Title'],
                        ['type' => 'submitButton', 'label' => 'Save'],
                    ],
                ]],
            ],
            // Sibling static feature: must be unaffected by data-bound logic.
            [
                'id' => 'x-static',
                'label' => 'X Static',
                'screen' => 'custom',
                'requiredPermission' => 'x:view',
            ],
        ];
    }
PHP);

        // Plugin that does NOT register /api/other/thing but references it as a
        // data-bound source — the feature must be dropped fail-closed.
        // A sibling valid (static) feature is present to prove isolation.
        self::writePlugin(self::$foreignSourceDir, 'DbbForeign', <<<'PHP'
    public function getPermissions(): array { return ['y:view']; }
    public function getRoutes(): array
    {
        return [
            [
                'method' => 'GET',
                'path' => '/api/y/own',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'y:view',
            ],
            // A LEGITIMATE write, so the fieldArray feature below is dropped for
            // its source and nothing else. Without this the form's own submit
            // was the violation, the feature was refused before its source was
            // ever looked at, and the test passed while proving nothing.
            [
                'method' => 'POST',
                'path' => '/api/y/write',
                'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
                'requiredRole' => null,
                'requiredPermission' => 'y:view',
            ],
        ];
    }
    public function getFrontendFeatures(): array
    {
        return [
            // INVALID: source is not a GET route this plugin registered.
            [
                'id' => 'y-foreign-data',
                'label' => 'Y Foreign',
                'screen' => 'blocks',
                'requiredPermission' => 'y:view',
                'blocks' => [[
                    'type' => 'dataTable',
                    'source' => '/api/other/thing',
                    'columns' => [['key' => 'id', 'label' => 'ID']],
                ]],
            ],
            // WC-240: same ownership check, but for a `chart` block.
            [
                'id' => 'y-foreign-chart',
                'label' => 'Y Foreign Chart',
                'screen' => 'blocks',
                'requiredPermission' => 'y:view',
                'blocks' => [[
                    'type' => 'chart',
                    'source' => '/api/other/thing',
                    'chartType' => 'bar',
                    'series' => [['key' => 'id', 'label' => 'ID', 'color' => 1]],
                ]],
            ],
            // Same ownership check, reached through a `fieldArray.source`.
            [
                'id' => 'y-foreign-preload',
                'label' => 'Y Foreign Preload',
                'screen' => 'blocks',
                'requiredPermission' => 'y:view',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/y/write'],
                    // The gap this fixture exists for: a GET at a route this
                    // plugin never registered, reaching the browser with the
                    // user's session and landing in the form's own values.
                    'dataSource' => ['method' => 'GET', 'path' => '/api/other/thing'],
                    'requiredPermission' => 'y:view',
                    'children' => [
                        ['type' => 'textInput', 'name' => 'title', 'label' => 'Title'],
                        ['type' => 'submitButton', 'label' => 'Save'],
                    ],
                ]],
            ],
            [
                'id' => 'y-foreign-array',
                'label' => 'Y Foreign Array',
                'screen' => 'blocks',
                'requiredPermission' => 'y:view',
                'blocks' => [[
                    'type' => 'form',
                    'submit' => ['method' => 'POST', 'endpoint' => '/api/y/write'],
                    'requiredPermission' => 'y:view',
                    'children' => [
                        [
                            'type' => 'fieldArray',
                            'name' => 'lines',
                            'label' => 'Lines',
                            'source' => '/api/other/thing',
                            'children' => [
                                ['type' => 'textInput', 'name' => 'title', 'label' => 'Title'],
                            ],
                        ],
                        ['type' => 'submitButton', 'label' => 'Save'],
                    ],
                ]],
            ],
            // VALID sibling: must survive even though the above is dropped.
            [
                'id' => 'y-static',
                'label' => 'Y Static',
                'screen' => 'custom',
                'requiredPermission' => 'y:view',
            ],
        ];
    }
PHP);
    }

    public static function tearDownAfterClass(): void
    {
        foreach ([self::$ownedSourceDir, self::$foreignSourceDir] as $dir) {
            self::removeDirectory($dir);
        }
    }

    // ── TEST 1: owned source → served + versioned ─────────────────────────

    /**
     * A plugin that registers GET /api/x/rows and exposes a `screen:'blocks'`
     * feature containing a `dataTable` with `source:'/api/x/rows'`:
     *  - the feature IS included in getFrontendFeatures();
     *  - the served block's `source` is the VERSIONED form `/api/v1/x/rows`.
     */
    public function testOwnedSourceIsServedAndVersioned(): void
    {
        // Use a versioned router (default /v1) to prove the rewrite happens.
        [$loader] = $this->loadDir(self::$ownedSourceDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey(
            'x-data',
            $byId,
            'A data-bound feature whose source is a plugin-owned GET route must be served'
        );

        $feature = $byId['x-data'];
        $this->assertSame('blocks', $feature['screen']);
        $this->assertIsArray($feature['blocks']);
        $this->assertCount(1, $feature['blocks']);

        $node = $feature['blocks'][0];
        $this->assertSame('dataTable', $node['type']);
        $this->assertSame(
            '/api/v1/x/rows',
            $node['source'],
            'The block source must be rewritten to the versioned URL (/api/v1/x/rows)'
        );

        // Columns must be untouched.
        $this->assertSame([
            ['key' => 'id',   'label' => 'ID'],
            ['key' => 'name', 'label' => 'Name'],
        ], $node['columns']);
    }

    /**
     * The sibling static feature is unaffected by the data-bound source check.
     */
    public function testSiblingStaticFeatureIsNotAffectedByDataBoundLogic(): void
    {
        [$loader] = $this->loadDir(self::$ownedSourceDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('x-static', $byId, 'The sibling static feature must still be served');
        $this->assertSame('custom', $byId['x-static']['screen']);
    }

    /**
     * WC-240: a `chart` block's `source` is verified and versioned through the
     * SAME generic walk as `dataTable` — no chart-specific code exists in
     * PluginLoader, so this proves the reuse rather than a parallel path.
     */
    public function testChartOwnedSourceIsServedAndVersioned(): void
    {
        [$loader] = $this->loadDir(self::$ownedSourceDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('x-chart', $byId);

        $node = $byId['x-chart']['blocks'][0];
        $this->assertSame('chart', $node['type']);
        $this->assertSame('/api/v1/x/rows', $node['source']);
        $this->assertSame('bar', $node['chartType']);
        $this->assertSame([['key' => 'id', 'label' => 'ID', 'color' => 1]], $node['series']);
    }

    /**
     * A source-BEARING type that declares NO source is served untouched.
     *
     * `fieldArray` is the first type whose `source` is optional, and optional
     * changed a rule the loader had been able to take for granted: that a type
     * whose contract mentions `source` always has one. It did not guard on the
     * key, so a source-less `fieldArray` read `null`, failed the ownership
     * comparison, and dropped the ENTIRE feature — silently, fail-closed, for a
     * prop the author never wrote. Every plugin already shipping a plain
     * repeatable field-group would have lost its screen the moment the contract
     * gained the prop.
     *
     * The block is asserted to still carry no `source` afterwards: "survived"
     * is not enough if the loader invented one on the way through.
     */
    public function testASourceBearingBlockWithNoSourceIsServedUnchanged(): void
    {
        [$loader] = $this->loadDir(self::$ownedSourceDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey(
            'x-array-plain',
            $byId,
            'A fieldArray that declares no source must not be treated as a foreign source'
        );

        $array = $byId['x-array-plain']['blocks'][0]['children'][0];
        $this->assertSame('fieldArray', $array['type']);
        $this->assertArrayNotHasKey(
            'source',
            $array,
            'The loader must not invent a source for a block that declared none'
        );
    }

    /**
     * The same generic walk ownership-checks and versions a `fieldArray.source`,
     * with no fieldArray-specific code anywhere in the loader — the reuse
     * `chart` and `dataRecord` already demonstrate, extended to an OPTIONAL
     * source.
     */
    public function testFieldArrayOwnedSourceIsServedAndVersioned(): void
    {
        [$loader] = $this->loadDir(self::$ownedSourceDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('x-array-sourced', $byId);

        $array = $byId['x-array-sourced']['blocks'][0]['children'][0];
        $this->assertSame('fieldArray', $array['type']);
        $this->assertSame(
            '/api/v1/x/rows',
            $array['source'],
            'A fieldArray source must be rewritten to the versioned URL like any other'
        );
    }

    /**
     * A `form`'s PRELOAD endpoint is ownership-checked and version-rewritten.
     *
     * `dataSource` was absent from BlockContract, and neither of the two things
     * that could have caught that does: `BlockValidator::validateProps()`
     * iterates the DECLARED prop rules rather than the node's own keys, and the
     * loader's walk returns the node it was handed. An undeclared prop is
     * therefore neither refused nor stripped — it reaches the renderer exactly
     * as written.
     *
     * So this was the only endpoint a block could name that nothing checked,
     * while `submit`, every `source`, `inbox.actions` and every `rowActionList`
     * were all compared against the routes the plugin registered.
     */
    public function testFormPreloadOwnedSourceIsServedAndVersioned(): void
    {
        [$loader] = $this->loadDir(self::$ownedSourceDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('x-form-preload', $byId);

        $form = $byId['x-form-preload']['blocks'][0];
        $this->assertSame('form', $form['type']);
        $this->assertSame(
            '/api/v1/x/rows',
            $form['dataSource']['path'],
            'a form preload must be rewritten to the versioned URL like every other endpoint — '
            . 'an unversioned path matches no route, and a preload that fails hands the author '
            . 'an enabled, EMPTY form that overwrites the record on save (#957)'
        );
        $this->assertSame('GET', $form['dataSource']['method']);
    }

    /**
     * And a `form` preloading somebody else's route drops the feature
     * fail-closed.
     *
     * This is the case that made the missing gate matter. The response body of
     * whatever it names is spread into the form's own values, and the form then
     * submits those values to an endpoint the plugin DOES own — so an unchecked
     * preload is a read of arbitrary data followed by a write of it to the
     * plugin, through two props that each look ordinary. Bounded by the viewing
     * user's own permissions, and still exactly the ownership rule every other
     * endpoint obeys.
     */
    public function testForeignFormPreloadDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');
        $this->assertNotContains(
            'y-foreign-preload',
            $ids,
            'a form preloading a route the plugin does not own must drop the feature'
        );
        $this->assertContains('y-static', $ids, 'and must not take its siblings with it');

        // The REASON, for what the neighbouring test learned the hard way: a
        // feature refused for an unrelated defect in its own fixture would make
        // this pass without the preload ever being reached.
        $dropped = array_column($loader->getDroppedFrontendFeatures(), null, 'featureId');
        $this->assertArrayHasKey('y-foreign-preload', $dropped);
        $this->assertStringContainsString(
            "form.dataSource path '/api/other/thing' is not a GET route this plugin registered",
            $dropped['y-foreign-preload']['reason']
        );
    }

    /**
     * And a `fieldArray` aimed at somebody else's route drops the feature
     * fail-closed.
     *
     * This one matters more than the `dataTable` case it mirrors. A foreign
     * source on a table shows a plugin data it does not own; a foreign source
     * on a `fieldArray` SEEDS AN EDITOR from another plugin's rows, and the
     * form around it then submits them somewhere. Read and write in one gesture,
     * through a prop that looks like presentation.
     */
    public function testForeignFieldArraySourceDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');
        $this->assertNotContains(
            'y-foreign-array',
            $ids,
            'A fieldArray bound to a route the plugin does not own must drop the feature'
        );
        $this->assertContains('y-static', $ids, 'and must not take its siblings with it');

        // The REASON, not just the absence. The first cut of this test passed
        // while the feature was being refused for an unrelated defect in its own
        // fixture (a submit endpoint the plugin had not registered), so the
        // source it was written to exercise was never reached. Asserting the
        // recorded reason is what makes "dropped" mean "dropped for this".
        $dropped = array_column($loader->getDroppedFrontendFeatures(), null, 'featureId');
        $this->assertArrayHasKey('y-foreign-array', $dropped);
        $this->assertStringContainsString(
            "data-bound block source '/api/other/thing' is not a GET route this plugin registered",
            $dropped['y-foreign-array']['reason']
        );
    }

    // ── TEST 2: foreign source → dropped fail-closed ──────────────────────

    /**
     * A `screen:'blocks'` feature whose `dataTable.source` points at a route
     * the plugin did NOT register is DROPPED (fail-closed):
     *  - the feature is ABSENT from getFrontendFeatures();
     *  - the loader does not throw or return a 500-style error;
     *  - a sibling valid (non-data-bound) feature is still present.
     */
    public function testForeignSourceDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');

        $this->assertNotContains(
            'y-foreign-data',
            $ids,
            'A data-bound feature with a foreign source must be DROPPED (fail-closed)'
        );
    }

    /**
     * WC-240: a `chart` feature whose `source` is a foreign (non-owned) route
     * is dropped fail-closed exactly like the `dataTable` case above.
     */
    public function testForeignChartSourceDropsTheFeatureFailClosed(): void
    {
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));

        $ids = array_column($loader->getFrontendFeatures(), 'id');
        $this->assertNotContains('y-foreign-chart', $ids);
    }

    /**
     * #953: dropping the feature is right; dropping the REASON is not.
     *
     * From the operator's side a refused screen is simply absent from the
     * navigation, which looks the same as a permission problem, a caching
     * problem, or a typo in the screen id — and until the loader kept this,
     * the only way to tell was to read container logs.
     */
    public function testAForeignSourceDropIsRecordedWithItsReason(): void
    {
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));

        $byId = array_column($loader->getDroppedFrontendFeatures(), null, 'featureId');

        $this->assertArrayHasKey(
            'y-foreign-data',
            $byId,
            'A refused feature must be reportable, not merely absent'
        );
        $this->assertSame('DbbForeign', $byId['y-foreign-data']['plugin']);
        $this->assertStringContainsString(
            'is not a GET route this plugin registered',
            $byId['y-foreign-data']['reason'],
            'The recorded reason is the same exact one the log carries'
        );
    }

    /**
     * The mirror of {@see testForeignSourceDropDoesNotKillSiblingFeatures()}:
     * a feature that was SERVED must never be reported as refused.
     */
    public function testASurvivingFeatureIsNotReportedAsDropped(): void
    {
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));

        $this->assertNotContains(
            'y-static',
            array_column($loader->getDroppedFrontendFeatures(), 'featureId')
        );
    }

    /**
     * When the foreign-source feature is dropped, the sibling static feature
     * (`y-static`) is still served — the drop is per-feature, not per-plugin.
     */
    public function testForeignSourceDropDoesNotKillSiblingFeatures(): void
    {
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey(
            'y-static',
            $byId,
            'A valid sibling feature must survive even when the data-bound one is dropped'
        );
    }

    /**
     * No exception is thrown when a foreign-source feature is encountered.
     * The loader returns an array (possibly empty) — never null / exception.
     */
    public function testForeignSourceNeverThrows(): void
    {
        $this->expectNotToPerformAssertions();

        // If this throws, the test fails automatically.
        [$loader] = $this->loadDir(self::$foreignSourceDir, new Router('/v1'));
        $loader->getFrontendFeatures();
    }

    // ── version-prefix edge case: empty prefix → no rewrite ─────────────

    /**
     * When the router has an empty version prefix (e.g. test/dev harness),
     * the data-bound source must NOT be altered — ownership passes and the
     * unversioned form is preserved.
     */
    public function testEmptyVersionPrefixLeavesSourceUnchanged(): void
    {
        // Router('') → getVersionPrefix() === '' → rewrite skipped.
        [$loader] = $this->loadDir(self::$ownedSourceDir, new Router(''));

        $byId = array_column($loader->getFrontendFeatures(), null, 'id');

        $this->assertArrayHasKey('x-data', $byId);
        $node = $byId['x-data']['blocks'][0];
        $this->assertSame(
            '/api/x/rows',
            $node['source'],
            'With an empty version prefix the source must remain as declared'
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
    public function getSdkConstraint(): string { return '^1.7'; }
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
