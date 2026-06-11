<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\PluginNavigationBridge;

/**
 * WC-169: descriptor-derived navigation.
 *
 * Plugins declaring a frontend feature (PluginFrontendInterface) get a menu
 * entry automatically — the bridge listens on navigation.register and appends
 * one item per validated descriptor, pointing at the host's dynamic screen
 * route (/admin/x/{id}). navigation.register stays the primitive for custom
 * links; the bridge only ADDS items, never replaces or filters.
 */
final class PluginNavigationBridgeTest extends TestCase
{
    /**
     * A validated, normalized descriptor as PluginLoader::getFrontendFeatures()
     * emits it.
     *
     * @return array<string, mixed>
     */
    private static function feature(): array
    {
        return [
            'id' => 'hello-greetings',
            'plugin' => 'HelloWorld',
            'label' => 'Greetings',
            'icon' => 'message-circle',
            'group' => 'plugins',
            'order' => 10,
            'screen' => 'crud',
            'resource' => ['basePath' => '/api/hello/greetings', 'titleField' => 'message'],
            'requiredPermission' => 'hello:view',
        ];
    }

    public function testMapsADescriptorToANavItemPointingAtTheDynamicScreenRoute(): void
    {
        $item = PluginNavigationBridge::toNavItem(self::feature());

        $this->assertSame([
            'id' => 'plugin-hello-greetings',
            'label' => 'Greetings',
            'href' => '/admin/x/hello-greetings',
            'icon' => 'message-circle',
            'group' => 'plugins',
            'order' => 10,
            'requiredPermission' => 'hello:view',
        ], $item);
    }

    public function testNullIconFallsBackToPuzzle(): void
    {
        $feature = self::feature();
        $feature['icon'] = null;

        $this->assertSame('puzzle', PluginNavigationBridge::toNavItem($feature)['icon']);
    }

    public function testSubscribedBridgeAppendsItemsWithoutTouchingExistingOnes(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('getFrontendFeatures')->willReturn([self::feature()]);

        $hooks = new HookManager();
        // A pre-existing core item, as public/index.php registers them.
        $hooks->listen('navigation.register', static function (array $data): array {
            $items = $data['items'] ?? [];
            $items[] = ['id' => 'dashboard', 'label' => 'Dashboard', 'href' => '/admin', 'icon' => 'dashboard', 'order' => 1];
            return ['items' => $items];
        });

        PluginNavigationBridge::subscribe($hooks, $loader);

        $result = $hooks->dispatch('navigation.register', ['items' => []]);
        $ids = array_column($result['items'], 'id');

        $this->assertContains('dashboard', $ids, 'Core items must survive the bridge');
        $this->assertContains('plugin-hello-greetings', $ids, 'Descriptor items must be appended');
    }

    public function testNoFeaturesMeansNoAddedItems(): void
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('getFrontendFeatures')->willReturn([]);

        $hooks = new HookManager();
        PluginNavigationBridge::subscribe($hooks, $loader);

        $result = $hooks->dispatch('navigation.register', ['items' => []]);

        $this->assertSame([], $result['items']);
    }

    /**
     * The loader is consulted at DISPATCH time, not subscribe time — a plugin
     * disabled after bootstrap must drop out of the menu on the next request.
     */
    public function testFeaturesAreReadLazilyAtDispatchTime(): void
    {
        $features = [self::feature()];
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('getFrontendFeatures')->willReturnCallback(
            static function () use (&$features): array {
                return $features;
            }
        );

        $hooks = new HookManager();
        PluginNavigationBridge::subscribe($hooks, $loader);

        $first = $hooks->dispatch('navigation.register', ['items' => []]);
        $this->assertCount(1, $first['items']);

        $features = []; // plugin disabled between requests
        $second = $hooks->dispatch('navigation.register', ['items' => []]);
        $this->assertSame([], $second['items']);
    }
}
