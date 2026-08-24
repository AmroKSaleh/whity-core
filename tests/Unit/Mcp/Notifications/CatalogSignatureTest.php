<?php

declare(strict_types=1);

namespace Tests\Unit\Mcp\Notifications;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Mcp\Notifications\CatalogSignature;
use Whity\Mcp\Prompts\Prompt;
use Whity\Mcp\Prompts\PromptRegistry;
use Whity\Mcp\Resources\ResourceDeriver;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Sdk\Http\Response as SdkResponse;

/**
 * Content signatures for the MCP discovery lists (#952).
 *
 * These assert the property the whole notification scheme rests on: the
 * signature tracks what a worker WOULD SERVE. A worker whose catalogue has not
 * moved must report the same signature no matter how many times it is asked —
 * that is what stops a notification storm — and a worker whose catalogue HAS
 * moved must report a different one, including when only a field inside a
 * route's schema changed, which is the exact shape of the corruption in #952.
 */
final class CatalogSignatureTest extends TestCase
{
    protected function setUp(): void
    {
        ToolDeriver::clearCache();
    }

    protected function tearDown(): void
    {
        ToolDeriver::clearCache();
    }

    public function testSignatureIsStableWhenNothingChanges(): void
    {
        $signature = $this->signatureOver(new Router(''), new PromptRegistry());

        self::assertSame($signature->current(), $signature->current());
    }

    public function testEveryListHasASignature(): void
    {
        $current = $this->signatureOver(new Router(''), new PromptRegistry())->current();

        self::assertArrayHasKey(CatalogSignature::TOOLS, $current);
        self::assertArrayHasKey(CatalogSignature::RESOURCES, $current);
        self::assertArrayHasKey(CatalogSignature::PROMPTS, $current);
    }

    public function testAddingAPluginToolChangesTheToolsSignature(): void
    {
        $router    = new Router('');
        $signature = $this->signatureOver($router, new PromptRegistry());
        $before    = $signature->current();

        $this->registerRoute($router, 'POST', '/api/plugin/things', 'plugin_create_thing');
        $this->refresh($signature);

        self::assertNotSame(
            $before[CatalogSignature::TOOLS],
            $signature->current()[CatalogSignature::TOOLS],
        );
    }

    /**
     * The #952 corruption: nothing about the tool's name or path moved, only the
     * declared type of one field. A signature that missed this would leave the
     * stale client sending a JSON string against a schema expecting an object.
     */
    public function testCorrectingAFieldTypeInsideARouteSchemaChangesTheToolsSignature(): void
    {
        $router    = new Router('');
        $signature = $this->signatureOver($router, new PromptRegistry());

        $this->registerRoute($router, 'POST', '/api/plugin/things', 'plugin_create_thing', [
            'operationId' => 'plugin_create_thing',
            'summary'     => 'Create thing',
            'request'     => ['type' => 'object', 'properties' => ['payload' => []]],
        ]);
        $this->refresh($signature);
        $untyped = $signature->current()[CatalogSignature::TOOLS];

        $corrected = new Router('');
        $this->registerRoute($corrected, 'POST', '/api/plugin/things', 'plugin_create_thing', [
            'operationId' => 'plugin_create_thing',
            'summary'     => 'Create thing',
            'request'     => ['type' => 'object', 'properties' => ['payload' => ['type' => 'object']]],
        ]);
        $correctedSignature = $this->signatureOver($corrected, new PromptRegistry());
        $this->refresh($correctedSignature);

        self::assertNotSame($untyped, $correctedSignature->current()[CatalogSignature::TOOLS]);
    }

    public function testAddingAGetRouteChangesBothToolsAndResourcesSignatures(): void
    {
        $router    = new Router('');
        $signature = $this->signatureOver($router, new PromptRegistry());
        $before    = $signature->current();

        $this->registerRoute($router, 'GET', '/api/plugin/things', 'plugin_list_things');
        $this->refresh($signature);
        $after = $signature->current();

        self::assertNotSame($before[CatalogSignature::TOOLS], $after[CatalogSignature::TOOLS]);
        self::assertNotSame($before[CatalogSignature::RESOURCES], $after[CatalogSignature::RESOURCES]);
    }

    public function testAddingAPromptChangesOnlyThePromptsSignature(): void
    {
        $prompts   = new PromptRegistry();
        $signature = $this->signatureOver(new Router(''), $prompts);
        $before    = $signature->current();

        $prompts->register(new Prompt(name: 'plugin.triage', description: 'Triage'));
        $this->refresh($signature);
        $after = $signature->current();

        self::assertNotSame($before[CatalogSignature::PROMPTS], $after[CatalogSignature::PROMPTS]);
        self::assertSame($before[CatalogSignature::TOOLS], $after[CatalogSignature::TOOLS]);
        self::assertSame($before[CatalogSignature::RESOURCES], $after[CatalogSignature::RESOURCES]);
    }

    /**
     * The memo is the reason a registry-change seam has to exist at all: without
     * invalidate() a worker keeps reporting the catalogue it had, so this pins
     * the behaviour the wiring in public/index.php is compensating for.
     */
    public function testMemoHidesAChangeUntilInvalidated(): void
    {
        $router    = new Router('');
        $signature = $this->signatureOver($router, new PromptRegistry());
        $before    = $signature->current();

        $this->registerRoute($router, 'POST', '/api/plugin/things', 'plugin_create_thing');
        ToolDeriver::clearCache();

        self::assertSame($before, $signature->current(), 'memo still describes the previous catalogue');

        $signature->invalidate();

        self::assertNotSame($before, $signature->current());
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function signatureOver(Router $router, PromptRegistry $prompts): CatalogSignature
    {
        return new CatalogSignature(
            new ToolDeriver([], [], $router),
            new ResourceDeriver([], $router),
            $prompts,
        );
    }

    /** Mirrors what the host's registry-change listener does after a reload. */
    private function refresh(CatalogSignature $signature): void
    {
        ToolDeriver::clearCache();
        $signature->invalidate();
    }

    /** @param array<string, mixed>|null $schema */
    private function registerRoute(
        Router $router,
        string $method,
        string $path,
        string $operationId,
        ?array $schema = null,
    ): void {
        $router->registerUnversioned(
            $method,
            $path,
            static fn (): SdkResponse => new SdkResponse(200, '', []),
            null,
            null,
            null,
            $schema ?? ['operationId' => $operationId, 'summary' => 'A plugin route'],
        );
    }
}
