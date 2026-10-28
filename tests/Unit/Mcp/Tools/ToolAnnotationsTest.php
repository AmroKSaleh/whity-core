<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Tools;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\ToolAnnotations;
use Whity\Mcp\Tools\ToolDeriver;

/**
 * MCP tool annotations (WHIT-706). The expected hints are written out per
 * method from the spec's definitions, not recomputed from the class.
 */
final class ToolAnnotationsTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    /** @return array<string, array{string, array<string, bool>}> */
    public static function methods(): array
    {
        return [
            'GET reads'                          => ['GET', ['readOnlyHint' => true, 'openWorldHint' => false]],
            'PUT replaces, so it is destructive' => ['PUT', ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],
            'DELETE is destructive, idempotent'  => ['DELETE', ['readOnlyHint' => false, 'destructiveHint' => true, 'idempotentHint' => true, 'openWorldHint' => false]],
            'POST leaves destructive unset'      => ['POST', ['readOnlyHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],
            'PATCH leaves destructive unset'     => ['PATCH', ['readOnlyHint' => false, 'idempotentHint' => false, 'openWorldHint' => false]],
        ];
    }

    /** @param array<string, bool> $hints */
    #[DataProvider('methods')]
    public function testHintsFollowTheMethod(string $method, array $hints): void
    {
        $annotations = ToolAnnotations::forRoute($method, [], 'A title');

        self::assertSame('A title', $annotations['title']);
        unset($annotations['title']);
        self::assertEqualsCanonicalizing($hints, $annotations);
    }

    public function testPostNeverClaimsToBeHarmless(): void
    {
        // The spec's default for an unset destructiveHint is true. Leaving it
        // unset on POST is what keeps "trash" or "disable 2FA" behind a prompt.
        self::assertArrayNotHasKey('destructiveHint', ToolAnnotations::forRoute('POST', [], 't'));
    }

    public function testARouteMayOverrideTheDerivedHints(): void
    {
        $annotations = ToolAnnotations::forRoute('POST', [
            'mcp_annotations' => ['destructiveHint' => false, 'idempotentHint' => true, 'title' => 'Create a widget'],
        ], 'Derived');

        self::assertFalse($annotations['destructiveHint']);
        self::assertTrue($annotations['idempotentHint']);
        self::assertSame('Create a widget', $annotations['title']);
    }

    public function testMalformedDeclarationsAreDroppedNotPassedOn(): void
    {
        self::assertNull(ToolAnnotations::sanitize('readOnly'));
        self::assertNull(ToolAnnotations::sanitize([]));
        self::assertSame(
            ['readOnlyHint' => true],
            ToolAnnotations::sanitize(['readOnlyHint' => true, 'destructiveHint' => 'no', 'secret' => true, 'title' => ' ']),
        );

        $annotations = ToolAnnotations::forRoute('DELETE', ['mcp_annotations' => ['destructiveHint' => 'false']], 't');
        self::assertTrue($annotations['destructiveHint'], 'a string "false" must not switch off the delete prompt');
    }

    public function testDerivedToolsCarryAnnotationsAndATitle(): void
    {
        $router = new Router('');
        $router->registerUnversioned('GET', '/api/widgets', fn () => null, null, null, 'widgets:read', ['summary' => 'List widgets']);
        $router->registerUnversioned('DELETE', '/api/widgets/{id}', fn () => null, null, null, 'widgets:manage', ['summary' => 'Delete a widget']);

        $tools = [];
        foreach ((new ToolDeriver([], [], $router))->deriveTools() as $tool) {
            $tools[$tool['name']] = $tool;
        }

        self::assertSame('List widgets', $tools['get_api_widgets']['annotations']['title']);
        self::assertTrue($tools['get_api_widgets']['annotations']['readOnlyHint']);
        self::assertTrue($tools['delete_api_widgets_id']['annotations']['destructiveHint']);
    }

    public function testAuthoredToolsCarryOnlyWhatTheyDeclare(): void
    {
        $registry = new AuthoredToolRegistry();
        $base = ['description' => 'd', 'inputSchema' => ['type' => 'object'], 'handler' => fn () => null, 'open' => true];
        self::assertNull($registry->register(['name' => 'declared', 'annotations' => ['readOnlyHint' => true, 'bogus' => 1]] + $base, 'p'));
        self::assertNull($registry->register(['name' => 'silent'] + $base, 'p'));

        $tools = [];
        foreach ($registry->toolObjects() as $tool) {
            $tools[$tool['name']] = $tool;
        }

        self::assertSame(['readOnlyHint' => true], $tools['declared']['annotations']);
        self::assertArrayNotHasKey('annotations', $tools['silent'], 'nothing to derive from, so nothing is guessed');
    }
}
