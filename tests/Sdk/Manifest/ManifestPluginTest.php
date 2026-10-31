<?php

declare(strict_types=1);

namespace Tests\Sdk\Manifest;

use HelloWorld\HelloWorldPlugin;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Manifest\InvalidManifestException;
use Whity\Sdk\Manifest\ManifestPlugin;
use Whity\Sdk\Manifest\PluginManifest;

require_once dirname(__DIR__, 3) . '/plugins/HelloWorld/HelloWorldPlugin.php';

/**
 * WHIT-758: a plugin declared in `plugin.json` answers every capability in the
 * shape the PHP interfaces define, so the host can't tell the two apart.
 */
final class ManifestPluginTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/whity_manifest_' . uniqid();
        mkdir($this->dir, 0755, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $file) {
            unlink($file);
        }
        rmdir($this->dir);
    }

    /**
     * HelloWorld's declarative half (identity, requirements, permissions,
     * roles), written as JSON, yields exactly what the PHP class returns.
     */
    public function testHelloWorldsDeclarationsRoundTripThroughJson(): void
    {
        $php = new HelloWorldPlugin();
        $json = $this->pluginFrom([
            'manifest' => 'whity.plugin/1',
            'plugin' => [
                'name' => 'HelloWorld',
                'version' => $php->getVersion(),
                'label' => ['en' => 'Hello World', 'ar' => 'مرحبا بالعالم'],
            ],
            'requires' => ['sdk' => '^1.29'],
            'permissions' => [
                ['slug' => 'hello:view', 'label' => ['en' => 'View greetings', 'ar' => 'عرض التحيات']],
                ['slug' => 'hello:manage', 'label' => ['en' => 'Manage greetings', 'ar' => 'إدارة التحيات']],
            ],
            'roles' => [
                'hello_viewer' => [
                    'label' => ['en' => 'Can view Hello World content', 'ar' => 'يمكنه عرض محتوى مرحبا بالعالم'],
                    'permissions' => ['hello:view'],
                ],
            ],
        ]);

        self::assertSame($php->getName(), $json->getName());
        self::assertSame($php->getVersion(), $json->getVersion());
        self::assertSame($php->getSdkConstraint(), $json->getSdkConstraint());
        self::assertSame($php->getCoreConstraint(), $json->getCoreConstraint());
        self::assertSame($php->getPluginDependencies(), $json->getPluginDependencies());
        self::assertSame($php->getPermissions(), $json->getPermissions());
        self::assertSame($php->getRoles(), $json->getRoles());
        self::assertSame($php->getRolePermissions(), $json->getRolePermissions());
    }

    public function testScreensBecomeBlocksFeaturesAndLimitsBecomeEntitlements(): void
    {
        $plugin = $this->pluginFrom(ManifestValidatorTest::bakeryManifest());

        self::assertSame([[
            'id' => 'cake-orders-today',
            'label' => 'Today\'s orders',
            'labelAr' => 'طلبات اليوم',
            'screen' => 'blocks',
            'requiredPermission' => 'cake_orders:read',
            'blocks' => [['type' => 'heading', 'text' => 'Today', 'level' => 2]],
            'group' => 'plugins',
            'order' => 10,
            'icon' => 'cake',
        ]], $plugin->getFrontendFeatures());

        self::assertSame([[
            'key' => 'cakeorders.orders.max',
            'type' => 'int',
            'default' => '-1',
            'description' => 'Open cake orders a workspace may hold.',
        ]], $plugin->getEntitlements());

        self::assertSame(['cake_orders' => 'tenant', 'cake_order_lines' => 'tenant'], $plugin->getOwnedTables());
        self::assertSame('cake_orders', $plugin->getDataTypes()['order']['table']);
        self::assertSame(['description' => 'Shop manager', 'parent' => 'baker'], $plugin->getRoles()['shop_manager']);
    }

    public function testJsonDeclaresNoBehaviour(): void
    {
        $plugin = $this->pluginFrom(ManifestValidatorTest::bakeryManifest());

        self::assertSame([], $plugin->getRoutes());
        self::assertSame([], $plugin->getHooks());
        self::assertSame([], $plugin->getMigrations());
    }

    public function testAnInvalidManifestRefusesTheWholePluginWithEveryError(): void
    {
        $manifest = ManifestValidatorTest::bakeryManifest();
        $manifest['plugin']['version'] = 'one';
        $manifest['roles']['baker']['permissions'] = ['users:manage'];

        try {
            $this->pluginFrom($manifest);
            self::fail('An invalid manifest must not construct a plugin.');
        } catch (InvalidManifestException $e) {
            self::assertCount(2, $e->getErrors());
            self::assertStringEndsWith('plugin.json', $e->getSource());
        }
    }

    public function testAMissingOrMalformedFileIsRefused(): void
    {
        $this->expectException(InvalidManifestException::class);
        PluginManifest::fromFile($this->dir . '/absent.json');
    }

    public function testATopLevelListIsRefused(): void
    {
        file_put_contents($this->dir . '/plugin.json', '[1, 2]');

        $this->expectException(InvalidManifestException::class);
        $this->expectExceptionMessage('the top level must be a JSON object');
        PluginManifest::fromFile($this->dir . '/plugin.json');
    }

    public function testASubclassAddsToTheDeclaredPart(): void
    {
        file_put_contents($this->dir . '/plugin.json', json_encode(ManifestValidatorTest::bakeryManifest(), JSON_THROW_ON_ERROR));
        $dir = $this->dir;
        $plugin = new class ($dir) extends ManifestPlugin {
            public function __construct(private readonly string $base)
            {
                parent::__construct();
            }

            protected function manifestPath(): string
            {
                return $this->base . '/plugin.json';
            }

            public function getPermissions(): array
            {
                return [...parent::getPermissions(), 'cake_orders:export'];
            }
        };

        self::assertSame(['cake_orders:read', 'cake_orders:manage', 'cake_orders:export'], $plugin->getPermissions());
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function pluginFrom(array $manifest): ManifestPlugin
    {
        file_put_contents($this->dir . '/plugin.json', json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
        $dir = $this->dir;

        return new class ($dir) extends ManifestPlugin {
            public function __construct(private readonly string $base)
            {
                parent::__construct();
            }

            protected function manifestPath(): string
            {
                return $this->base . '/plugin.json';
            }
        };
    }
}
