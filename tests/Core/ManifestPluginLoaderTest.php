<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Tests\Sdk\Manifest\ManifestValidatorTest;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;

/**
 * WHIT-758: the host loads a plugin declared in `plugin.json` through the same
 * loader and registries as a PHP plugin, and refuses an invalid one whole.
 */
final class ManifestPluginLoaderTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity_manifest_loader_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->dir);
    }

    public function testAManifestPluginRegistersItsPermissionsAndScreens(): void
    {
        $name = $this->writeManifestPlugin(ManifestValidatorTest::bakeryManifest());

        $permissions = new PermissionRegistry();
        $loader = new PluginLoader($this->dir, new Router(''), $permissions, new HookManager());
        $loader->load();

        $all = array_keys($permissions->getAll());
        self::assertContains('cake_orders:read', $all);
        self::assertContains('cake_orders:manage', $all);

        $features = array_column($loader->getFrontendFeatures(), null, 'id');
        self::assertArrayHasKey('cake-orders-today', $features);
        self::assertSame('blocks', $features['cake-orders-today']['screen']);
        self::assertSame($name, $features['cake-orders-today']['plugin']);
        self::assertSame('cake_orders:read', $features['cake-orders-today']['requiredPermission']);
    }

    public function testAnInvalidManifestIsNotLoadedAndOtherPluginsAre(): void
    {
        $bad = ManifestValidatorTest::bakeryManifest();
        $bad['roles']['baker']['permissions'] = ['users:manage'];
        $this->writeManifestPlugin($bad);

        $good = ManifestValidatorTest::bakeryManifest();
        $good['plugin']['name'] = 'OtherPlugin';
        $good['permissions'] = [['slug' => 'other:read', 'label' => ['en' => 'Read', 'ar' => 'قراءة']]];
        unset($good['roles'], $good['screens'], $good['dataTypes'], $good['tables'], $good['limits']);
        $this->writeManifestPlugin($good);

        $permissions = new PermissionRegistry();
        $loader = new PluginLoader($this->dir, new Router(''), $permissions, new HookManager());
        $loader->load();

        $all = array_keys($permissions->getAll());
        self::assertNotContains('cake_orders:read', $all, 'nothing from an invalid manifest may register');
        self::assertContains('other:read', $all);
        self::assertSame([], $loader->getFrontendFeatures());
    }

    public function testEditingPluginJsonChangesTheReloadFingerprint(): void
    {
        $this->writeManifestPlugin(ManifestValidatorTest::bakeryManifest());
        $loader = new PluginLoader($this->dir, new Router(''), new PermissionRegistry(), new HookManager());
        $loader->load();

        $fingerprint = new \ReflectionMethod(PluginLoader::class, 'computeFingerprint');
        $before = $fingerprint->invoke($loader);

        $manifestFiles = glob($this->dir . '/*/plugin.json') ?: [];
        self::assertCount(1, $manifestFiles);
        $edited = ManifestValidatorTest::bakeryManifest();
        $edited['permissions'][] = ['slug' => 'cake_orders:export', 'label' => ['en' => 'Export', 'ar' => 'تصدير']];
        file_put_contents($manifestFiles[0], json_encode($edited, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        clearstatcache();

        self::assertNotSame($before, $fingerprint->invoke($loader));
    }

    /**
     * Writes `<Name>/<Name>Plugin.php` (an empty ManifestPlugin subclass) and
     * its `plugin.json`. The class name is made unique, because a class can't
     * be declared twice in one test process.
     *
     * @param array<string, mixed> $manifest
     */
    private function writeManifestPlugin(array $manifest): string
    {
        $ns = 'Mf' . str_replace('.', '', uniqid('', true));
        $pluginDir = $this->dir . '/' . $ns;
        mkdir($pluginDir, 0755, true);
        file_put_contents($pluginDir . '/' . $ns . 'Plugin.php', <<<PHP
<?php

declare(strict_types=1);

namespace {$ns};

final class {$ns}Plugin extends \\Whity\\Sdk\\Manifest\\ManifestPlugin
{
}
PHP);
        file_put_contents(
            $pluginDir . '/plugin.json',
            json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );

        return (string) $manifest['plugin']['name'];
    }

    private function removeDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($dir);
    }
}
