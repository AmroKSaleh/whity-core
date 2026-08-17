<?php

declare(strict_types=1);

namespace Tests\Plugins;

use PHPUnit\Framework\TestCase;
use Relations\RelationsPlugin;
use Whity\Sdk\Frontend\Blocks\BlockValidator;
use Whity\Sdk\PluginFrontendInterface;

require_once dirname(__DIR__, 2) . '/plugins/Relations/RelationsPlugin.php';

/**
 * SLICE 2a: the Relations persons block UI. Asserts the plugin declares a valid
 * `screen: 'blocks'` feature whose tree passes the same {@see BlockValidator}
 * the host runs at registration, sources only the plugin's own routes, and
 * carries the list + add + delete persons workflow.
 */
final class RelationsPluginFrontendTest extends TestCase
{
    public function testDeclaresThePluginFrontendInterface(): void
    {
        $this->assertInstanceOf(PluginFrontendInterface::class, new RelationsPlugin());
        // The block UI uses the modal overlay type — SDK 1.27+.
        $this->assertSame('^1.27', (new RelationsPlugin())->getSdkConstraint());
    }

    public function testDeclaresOneBlocksFeatureGatedOnRelationsRead(): void
    {
        $features = (new RelationsPlugin())->getFrontendFeatures();

        $this->assertCount(1, $features, 'Relations contributes exactly one feature');
        $feature = $features[0];
        $this->assertSame('relations', $feature['id']);
        $this->assertSame('blocks', $feature['screen']);
        $this->assertSame('relations:read', $feature['requiredPermission']);
        $this->assertIsArray($feature['blocks']);
        $this->assertNotSame([], $feature['blocks']);
    }

    public function testTheBlocksTreeValidatesAgainstTheContract(): void
    {
        $blocks = (new RelationsPlugin())->getFrontendFeatures()[0]['blocks'];
        $this->assertIsArray($blocks);

        $result = BlockValidator::validate($blocks);
        $this->assertSame(
            [],
            $result['errors'],
            "Relations blocks must pass BlockValidator:\n" . implode("\n", $result['errors'])
        );
        $this->assertTrue($result['ok']);
    }

    public function testDataBoundSourcesAreThePluginsOwnGetRoutes(): void
    {
        $plugin = new RelationsPlugin();

        $getRoutes = [];
        foreach ($plugin->getRoutes() as $route) {
            if (($route['method'] ?? null) === 'GET' && is_string($route['path'] ?? null)) {
                // Normalise the router's inline constraint ({id:\d+} → {id}).
                $getRoutes[] = (string) preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $route['path']);
            }
        }

        $blocks = $plugin->getFrontendFeatures()[0]['blocks'];
        $this->assertIsArray($blocks);
        foreach ($this->collectProp($blocks, 'source') as $source) {
            $this->assertContains(
                $source,
                $getRoutes,
                "Data-bound source '{$source}' must be one of the plugin's own GET routes"
            );
        }
    }

    public function testCarriesTheListAddDeleteWorkflow(): void
    {
        $blocks = (new RelationsPlugin())->getFrontendFeatures()[0]['blocks'];
        $this->assertIsArray($blocks);

        $types = $this->collectTypes($blocks);
        $this->assertContains('dataTable', $types, 'the persons list');
        $this->assertContains('modal', $types, 'the add-person modal');
        $this->assertContains('form', $types, 'the add-person form');

        // The list sources the persons route; a per-row soft-delete tombstones it.
        $this->assertContains('/api/persons', $this->collectProp($blocks, 'source'));
        $endpoints = $this->collectProp($blocks, 'endpoint');
        $this->assertContains('/api/persons', $endpoints, 'create POSTs /api/persons');
        $this->assertContains('/api/persons/{id}', $endpoints, 'delete templates the row id');
    }

    /**
     * @param array<mixed> $blocks
     *
     * @return list<string>
     */
    private function collectTypes(array $blocks): array
    {
        $types = [];
        foreach ($blocks as $node) {
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['type']) && is_string($node['type'])) {
                $types[] = $node['type'];
            }
            foreach (['children'] as $childKey) {
                if (isset($node[$childKey]) && is_array($node[$childKey])) {
                    $types = array_merge($types, $this->collectTypes($node[$childKey]));
                }
            }
        }

        return $types;
    }

    /**
     * Recursively collect every value of a named prop (including inside
     * `rowActions`/`submit`), so source/endpoint assertions see the whole tree.
     *
     * @param array<mixed> $blocks
     *
     * @return list<string>
     */
    private function collectProp(array $blocks, string $key): array
    {
        $found = [];
        $walk = function (mixed $node) use (&$walk, &$found, $key): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node[$key]) && is_string($node[$key])) {
                $found[] = $node[$key];
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($blocks);

        return $found;
    }
}
