<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Documents\DocumentsPlugin;
use PHPUnit\Framework\TestCase;
use Whity\Sdk\Frontend\Blocks\BlockValidator;
use Whity\Sdk\PluginFrontendInterface;

require_once dirname(__DIR__, 2) . '/plugins/Documents/DocumentsPlugin.php';

/**
 * The Documents block UI. Asserts the plugin declares two valid `screen: 'blocks'`
 * features (Document Templates, Document Blocks) whose trees pass the same
 * {@see BlockValidator} the host runs at registration, source only the plugin`s
 * own routes, and carry the list + create + delete workflow for each (there is no
 * in-UI edit -- see DocumentsPlugin::blocks()).
 */
final class DocumentsPluginFrontendTest extends TestCase
{
    public function testDeclaresThePluginFrontendInterface(): void
    {
        $this->assertInstanceOf(PluginFrontendInterface::class, new DocumentsPlugin());
        $this->assertSame('^1.27', (new DocumentsPlugin())->getSdkConstraint());
    }

    public function testDeclaresTwoBlocksFeaturesGatedOnDocumentsRead(): void
    {
        $features = (new DocumentsPlugin())->getFrontendFeatures();

        $this->assertCount(2, $features);

        $ids = [];
        foreach ($features as $feature) {
            $ids[] = $feature['id'];
            $this->assertSame('blocks', $feature['screen']);
            $this->assertSame('documents:read', $feature['requiredPermission']);
            $this->assertIsArray($feature['blocks']);
        }
        $this->assertSame(['document-templates', 'document-blocks'], $ids);
    }

    public function testEveryFeatureBlocksTreeValidatesAgainstTheContract(): void
    {
        $features = (new DocumentsPlugin())->getFrontendFeatures();

        foreach ($features as $feature) {
            $blocks = $feature['blocks'];
            $this->assertIsArray($blocks);
            $result = BlockValidator::validate($blocks);
            $this->assertSame(
                [],
                $result['errors'],
                "Feature '{$feature['id']}' blocks must pass BlockValidator:\n" . implode("\n", $result['errors'])
            );
            $this->assertTrue($result['ok']);
        }
    }

    public function testDataBoundSourcesAreThePluginsOwnGetRoutes(): void
    {
        $plugin = new DocumentsPlugin();

        $getRoutes = [];
        foreach ($plugin->getRoutes() as $route) {
            if (($route['method'] ?? null) === 'GET' && is_string($route['path'] ?? null)) {
                $getRoutes[] = (string) preg_replace('/\{(\w+):[^}]+\}/', '{$1}', $route['path']);
            }
        }

        foreach ($plugin->getFrontendFeatures() as $feature) {
            $blocks = $feature['blocks'];
            $this->assertIsArray($blocks);
            foreach ($this->collectProp($blocks, 'source') as $source) {
                $this->assertContains(
                    $source,
                    $getRoutes,
                    "Data-bound source '{$source}' must be one of the plugin's own GET routes"
                );
            }
        }
    }

    public function testTemplatesFeatureCarriesManagementWorkflow(): void
    {
        $this->assertFeatureManagement('document-templates', '/api/document-templates', 'create-template');
    }

    public function testBlocksFeatureCarriesManagementWorkflow(): void
    {
        $this->assertFeatureManagement('document-blocks', '/api/document-blocks', 'create-block');
    }

    private function assertFeatureManagement(string $featureId, string $source, string $createId): void
    {
        $blocks = $this->featureBlocks($featureId);

        // The list is present, sourced from the plugin`s own route.
        $this->assertContains($source, $this->collectProp($blocks, 'source'));

        // The create modal exists.
        $this->assertContains($createId, $this->collectProp($blocks, 'id'));

        // There is deliberately NO edit modal -- no `open` row action exists, because
        // editing a populated record would wipe `data` under the full-row sync UPDATE.
        $this->assertSame([], $this->collectProp($blocks, 'open'));

        // Create POSTs the collection; delete templates the row id. No context-
        // templated PATCH endpoint (that was the edit modal) remains.
        $endpoints = $this->collectProp($blocks, 'endpoint');
        $this->assertContains($source, $endpoints);
        $this->assertContains($source . '/{id}', $endpoints);
        foreach ($endpoints as $endpoint) {
            $this->assertStringNotContainsString('.id}', $endpoint, 'No context-templated edit endpoint should remain');
        }

        // The form carries a scope `select`, in a modal.
        $this->assertContains('select', $this->collectTypes($blocks));
        $this->assertContains('modal', $this->collectTypes($blocks));
    }

    /**
     * @return array<mixed>
     */
    private function featureBlocks(string $featureId): array
    {
        foreach ((new DocumentsPlugin())->getFrontendFeatures() as $feature) {
            if (($feature['id'] ?? null) === $featureId) {
                $blocks = $feature['blocks'];
                $this->assertIsArray($blocks);

                return $blocks;
            }
        }

        $this->fail("Feature '{$featureId}' not found");
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
