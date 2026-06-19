<?php

declare(strict_types=1);

namespace Tests\Integration;

use PHPUnit\Framework\TestCase;
use UiKitShowcase\Migrations\GrantUiKitViewToAdmin;
use UiKitShowcase\UiKitShowcasePlugin;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Router;
use Whity\Sdk\Frontend\Blocks\BlockContract;
use Whity\Sdk\Frontend\Blocks\BlockValidator;
use Whity\Sdk\PluginFrontendInterface;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\PluginRequirementsInterface;

require_once dirname(__DIR__, 2) . '/plugins/UiKitShowcase/UiKitShowcasePlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/UiKitShowcase/Migrations/GrantUiKitViewToAdmin.php';

/**
 * WC-228: the UiKitShowcase example plugin proves and documents the SP1 block
 * system end-to-end. It is a SANCTIONED example plugin (named for the SDK
 * feature it documents) that contributes ONE `screen: 'blocks'` feature whose
 * tree (a) passes {@see BlockValidator::validate()} and (b) contains a live
 * instance of EVERY block type in {@see BlockContract::types()} beside the PHP
 * snippet that declares it.
 *
 * It declares NO API routes, NO hooks, and NO DB resource — the screen is a
 * purely declarative static block tree — so the only persisted side effect is
 * its one permission (`uikit:view`) and the migration that grants it to admin.
 */
final class UiKitShowcasePluginTest extends TestCase
{
    public function testImplementsTheThreeSdkCapabilityInterfaces(): void
    {
        $plugin = new UiKitShowcasePlugin();

        $this->assertInstanceOf(PluginInterface::class, $plugin);
        $this->assertInstanceOf(PluginRequirementsInterface::class, $plugin);
        $this->assertInstanceOf(PluginFrontendInterface::class, $plugin);

        $this->assertSame('UiKitShowcase', $plugin->getName());
        $this->assertSame('1.0.0', $plugin->getVersion());
    }

    public function testDeclaresTheBlocksSdkConstraintAndNoBackendSurface(): void
    {
        $plugin = new UiKitShowcasePlugin();

        // Data-bound blocks landed in SDK 1.7, so the plugin requires that range.
        $this->assertSame('^1.7', $plugin->getSdkConstraint());
        $this->assertSame('', $plugin->getCoreConstraint());
        $this->assertSame([], $plugin->getPluginDependencies());

        // A purely declarative screen: no API routes, no hooks.
        $this->assertSame([], $plugin->getRoutes());
        $this->assertSame([], $plugin->getHooks());

        // One forward, idempotent permission-grant migration.
        $this->assertSame([GrantUiKitViewToAdmin::class], $plugin->getMigrations());
    }

    public function testDeclaresTheSingleColonNotationPermission(): void
    {
        $permissions = (new UiKitShowcasePlugin())->getPermissions();

        $this->assertContains('uikit:view', $permissions);

        foreach ($permissions as $permission) {
            $this->assertMatchesRegularExpression(
                '/^[a-z][a-z0-9_]*:[a-z][a-z0-9_]*$/',
                $permission
            );
        }
    }

    public function testDeclaresExactlyOneBlocksFeatureGatedOnUikitView(): void
    {
        $features = (new UiKitShowcasePlugin())->getFrontendFeatures();

        $this->assertCount(1, $features, 'The showcase contributes exactly one feature');

        $feature = $features[0];
        $this->assertSame('ui-kit-reference', $feature['id']);
        $this->assertSame('blocks', $feature['screen']);
        $this->assertSame('uikit:view', $feature['requiredPermission']);
        $this->assertSame('plugins', $feature['group']);
        $this->assertIsString($feature['label']);
        $this->assertNotSame('', $feature['label']);
        $this->assertArrayHasKey('blocks', $feature);
        $this->assertIsArray($feature['blocks']);
    }

    public function testTheBlocksTreePassesTheContractValidator(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];
        $result = BlockValidator::validate($blocks);

        $this->assertTrue(
            $result['ok'],
            'The showcase block tree must be valid; errors: ' . implode('; ', $result['errors'])
        );
        $this->assertSame([], $result['errors']);
    }

    public function testTheBlocksTreeCoversEverySp1BlockType(): void
    {
        $feature = (new UiKitShowcasePlugin())->getFrontendFeatures()[0];

        /** @var array<mixed> $blocks */
        $blocks = $feature['blocks'];
        $present = $this->collectTypes($blocks);

        foreach (BlockContract::types() as $type) {
            $this->assertContains(
                $type,
                $present,
                "The showcase must include at least one '{$type}' block"
            );
        }

        // The set present is a SUPERSET of all 18 SP1 types.
        $expected = BlockContract::types();
        $this->assertSame(
            $expected,
            array_values(array_filter($expected, static fn (string $t): bool => in_array($t, $present, true))),
            'Every SP1 block type must be present at least once'
        );
    }

    public function testTheRealLoaderDiscoversTheShowcaseAndExposesTheBlocksFeature(): void
    {
        // Point the loader at the REAL plugins/ directory so we exercise the
        // exact discovery + descriptor-validation path used in production. The
        // directory carries other plugins (HelloWorld, etc.); we only assert on
        // UiKitShowcase so the test tolerates them.
        $pluginDir = dirname(__DIR__, 2) . '/plugins';

        $loader = new PluginLoader(
            $pluginDir,
            new Router(''),
            new PermissionRegistry(),
            new HookManager()
        );
        $loader->load();

        $names = array_map(
            static fn (PluginInterface $p): string => $p->getName(),
            $loader->getPlugins()
        );
        $this->assertContains('UiKitShowcase', $names);

        // The blocks feature survives the loader's fail-closed descriptor
        // validation (screen:'blocks' + a contract-valid tree) and is exposed
        // with the owning plugin name attached and the validated tree intact —
        // proving the SDK-contract -> host-validation pipeline for the REAL
        // plugin, exactly what the frontend-features endpoint then serves.
        $byId = array_column($loader->getFrontendFeatures(), null, 'id');
        $this->assertArrayHasKey('ui-kit-reference', $byId);
        $feature = $byId['ui-kit-reference'];
        $this->assertSame('UiKitShowcase', $feature['plugin']);
        $this->assertSame('blocks', $feature['screen']);
        $this->assertSame('uikit:view', $feature['requiredPermission']);
        $this->assertArrayHasKey('blocks', $feature);
        $this->assertIsArray($feature['blocks']);

        // The exposed tree still covers every SP1 block type and still validates.
        $present = $this->collectTypes($feature['blocks']);
        foreach (BlockContract::types() as $type) {
            $this->assertContains($type, $present, "Loader-exposed tree must include '{$type}'");
        }
        $this->assertTrue(BlockValidator::validate($feature['blocks'])['ok']);
    }

    /**
     * Walk the tree depth-first and collect the distinct `type` of every node.
     *
     * @param array<mixed> $nodes
     *
     * @return list<string>
     */
    private function collectTypes(array $nodes): array
    {
        $types = [];
        foreach ($nodes as $node) {
            if (!is_array($node) || !isset($node['type']) || !is_string($node['type'])) {
                continue;
            }
            $types[] = $node['type'];
            if (isset($node['children']) && is_array($node['children'])) {
                foreach ($this->collectTypes($node['children']) as $childType) {
                    $types[] = $childType;
                }
            }
        }

        return array_values(array_unique($types));
    }
}
