<?php

declare(strict_types=1);

namespace Tests\Plugins;

use PHPUnit\Framework\TestCase;
use Taxonomy\TaxonomyPlugin;
use Whity\Sdk\Frontend\Blocks\BlockValidator;
use Whity\Sdk\PluginFrontendInterface;

require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/TaxonomyPlugin.php';

/**
 * SLICE 1c: the Taxonomy block UI. Asserts the plugin declares a valid
 * `screen: 'blocks'` feature whose tree passes the same {@see BlockValidator}
 * the host runs at registration, sources only the plugin's own routes, and
 * carries the tag-group + tag CRUD workflows (list/add/edit/delete for both).
 */
final class TaxonomyPluginFrontendTest extends TestCase
{
    public function testDeclaresThePluginFrontendInterface(): void
    {
        $this->assertInstanceOf(PluginFrontendInterface::class, new TaxonomyPlugin());
        $this->assertSame('^1.27', (new TaxonomyPlugin())->getSdkConstraint());
    }

    public function testDeclaresOneBlocksFeatureGatedOnTagsRead(): void
    {
        $features = (new TaxonomyPlugin())->getFrontendFeatures();

        $this->assertCount(1, $features);
        $feature = $features[0];
        $this->assertSame('taxonomy', $feature['id']);
        $this->assertSame('blocks', $feature['screen']);
        $this->assertSame('tags:read', $feature['requiredPermission']);
        $this->assertIsArray($feature['blocks']);
    }

    public function testTheBlocksTreeValidatesAgainstTheContract(): void
    {
        $blocks = (new TaxonomyPlugin())->getFrontendFeatures()[0]['blocks'];
        $this->assertIsArray($blocks);

        $result = BlockValidator::validate($blocks);
        $this->assertSame(
            [],
            $result['errors'],
            "Taxonomy blocks must pass BlockValidator:\n" . implode("\n", $result['errors'])
        );
        $this->assertTrue($result['ok']);
    }

    public function testDataBoundSourcesAreThePluginsOwnGetRoutes(): void
    {
        $plugin = new TaxonomyPlugin();

        $getRoutes = [];
        foreach ($plugin->getRoutes() as $route) {
            if (($route['method'] ?? null) === 'GET' && is_string($route['path'] ?? null)) {
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

    public function testCarriesTagGroupAndTagCrudWorkflows(): void
    {
        $blocks = (new TaxonomyPlugin())->getFrontendFeatures()[0]['blocks'];
        $this->assertIsArray($blocks);

        // Both lists present, sourced from the plugin's own routes.
        $sources = $this->collectProp($blocks, 'source');
        $this->assertContains('/api/tag-groups', $sources);
        $this->assertContains('/api/tags', $sources);

        // Edit modals exist and are opened from their rows.
        $opens = $this->collectProp($blocks, 'open');
        $this->assertContains('edit-tag-group', $opens);
        $this->assertContains('edit-tag', $opens);
        $ids = $this->collectProp($blocks, 'id');
        $this->assertContains('edit-tag-group', $ids);
        $this->assertContains('edit-tag', $ids);

        // Edit forms PATCH the context-templated per-row endpoints; deletes template the row id.
        $endpoints = $this->collectProp($blocks, 'endpoint');
        $this->assertContains('/api/tag-groups/{edit-tag-group.id}', $endpoints);
        $this->assertContains('/api/tags/{edit-tag.id}', $endpoints);
        $this->assertContains('/api/tag-groups/{id}', $endpoints);
        $this->assertContains('/api/tags/{id}', $endpoints);

        // A tag's owning group is picked via a referenceSelect fed from tag-groups.
        $this->assertContains('referenceSelect', $this->collectTypes($blocks));
    }

    /**
     * @param array<mixed> $blocks
     *
     * @return list<string>
     */
    private function collectTypes(array $blocks): array
    {
        $types = [];
        $walk = function (mixed $node) use (&$walk, &$types): void {
            if (!is_array($node)) {
                return;
            }
            if (isset($node['type']) && is_string($node['type'])) {
                $types[] = $node['type'];
            }
            foreach ($node as $value) {
                if (is_array($value)) {
                    $walk($value);
                }
            }
        };
        $walk($blocks);

        return $types;
    }

    /**
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
