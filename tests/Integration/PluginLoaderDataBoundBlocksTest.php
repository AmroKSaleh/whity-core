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
        return [[
            'method' => 'GET',
            'path' => '/api/x/rows',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'x:view',
        ]];
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
        return [[
            'method' => 'GET',
            'path' => '/api/y/own',
            'handler' => static fn ($r) => \Whity\Sdk\Http\Response::json(['data' => []]),
            'requiredRole' => null,
            'requiredPermission' => 'y:view',
        ]];
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
