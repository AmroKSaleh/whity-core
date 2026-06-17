<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\OpenApiHandler;
use Whity\Core\Hooks\HookManager;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\OpenAPI\CoreApiSchemas;

/**
 * WC-209: the dynamic OpenAPI endpoint.
 *
 * Proves the spec is served LIVE from the running router — newly registered
 * plugin routes appear and unregistered ones disappear at request time, with
 * no offline `generate:openapi` step. This is what keeps plugin CRUD screens
 * honest when a product plugin (which is never committed) is installed,
 * uninstalled or reloaded.
 */
final class OpenApiHandlerTest extends TestCase
{
    /**
     * Build a handler over a router carrying the full core catalogue plus the
     * reference plugins, exactly as the live application wires it.
     */
    private function handlerForRouter(Router $router): OpenApiHandler
    {
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            $router,
            null,
            new HookManager()
        );

        return new OpenApiHandler($router, $loader);
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->getBody(), true);
        $this->assertIsArray($decoded, 'The endpoint must return a JSON object');

        return $decoded;
    }

    /**
     * The endpoint returns a valid OpenAPI document with JSON content type.
     */
    public function testReturnsValidOpenApiDocument(): void
    {
        $router = new Router('/v1');
        CoreApiSchemas::registerRoutes($router);

        $response = $this->handlerForRouter($router)->handle(new Request('GET', '/api/openapi.json'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/json', $response->getHeaders()['content-type'] ?? null);

        $spec = $this->decode($response);
        $this->assertArrayHasKey('openapi', $spec);
        $this->assertArrayHasKey('paths', $spec);
        $this->assertSame(\Whity\Core\CoreVersion::VERSION, $spec['info']['version'] ?? null);
    }

    /**
     * Core routes from the catalogue are present (versioned under /api/v1).
     */
    public function testIncludesCoreRoutes(): void
    {
        $router = new Router('/v1');
        CoreApiSchemas::registerRoutes($router);

        $response = $this->handlerForRouter($router)->handle(new Request('GET', '/api/openapi.json'));
        $spec = $this->decode($response);

        $this->assertIsArray($spec['paths']);
        $this->assertArrayHasKey('/api/v1/users', $spec['paths']);
    }

    /**
     * THE CRUX: the spec follows the LIVE router. A route registered after the
     * handler is built appears in the output, and once unregistered it is gone
     * — proving request-time regeneration without any committed-file step.
     */
    public function testReflectsLiveRouterStateForPluginRoutes(): void
    {
        $router = new Router('/v1');
        CoreApiSchemas::registerRoutes($router);
        $handler = $this->handlerForRouter($router);

        // Before: the fake plugin route is absent.
        $before = $this->decode($handler->handle(new Request('GET', '/api/openapi.json')));
        $this->assertIsArray($before['paths']);
        $this->assertArrayNotHasKey('/api/v1/widgets', $before['paths']);

        // Register a fake plugin route (under a namespace, as PluginLoader does).
        $router->register(
            'GET',
            '/api/widgets',
            static fn (): Response => new Response(200, '[]'),
            null,
            'fake-plugin',
            null,
            ['summary' => 'List widgets', 'tags' => ['widgets']]
        );

        // After registration: the same handler now emits the new path.
        $withWidgets = $this->decode($handler->handle(new Request('GET', '/api/openapi.json')));
        $this->assertIsArray($withWidgets['paths']);
        $this->assertArrayHasKey(
            '/api/v1/widgets',
            $withWidgets['paths'],
            'A route registered on the live router must appear in the dynamic spec'
        );

        // Unregister the plugin's routes: the path disappears again.
        $router->unregisterByNamespace('fake-plugin');
        $without = $this->decode($handler->handle(new Request('GET', '/api/openapi.json')));
        $this->assertIsArray($without['paths']);
        $this->assertArrayNotHasKey(
            '/api/v1/widgets',
            $without['paths'],
            'An unregistered route must disappear from the dynamic spec without any regen step'
        );
    }

    /**
     * The encoded document is memoized per worker while the route set is
     * unchanged (the unauthenticated endpoint must not rebuild a ~200KB spec on
     * every hit), but a change to the route set rebuilds it. Proven by counting
     * how often the build seam actually runs.
     */
    public function testMemoizesDocumentUntilRouteSetChanges(): void
    {
        $router = new Router('/v1');
        CoreApiSchemas::registerRoutes($router);
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            $router,
            null,
            new HookManager()
        );

        $handler = new class ($router, $loader) extends OpenApiHandler {
            public int $builds = 0;

            protected function buildSpec(): string
            {
                $this->builds++;

                return parent::buildSpec();
            }
        };

        $req = new Request('GET', '/api/openapi.json');
        $handler->handle($req);
        $handler->handle($req);
        $this->assertSame(1, $handler->builds, 'Unchanged route set must be served from the memo');

        // Changing the route set must bust the memo and rebuild exactly once.
        $router->register(
            'GET',
            '/api/widgets',
            static fn (): Response => new Response(200, '[]'),
            null,
            'fake-plugin',
            null,
            ['summary' => 'List widgets', 'tags' => ['widgets']]
        );
        $handler->handle($req);
        $handler->handle($req);
        $this->assertSame(2, $handler->builds, 'A changed route set must rebuild once, then re-memo');
    }

    /**
     * A generation failure returns a generic 500 and never leaks raw exception
     * text (message or stack trace) to the client.
     */
    public function testFailurePathReturnsGenericErrorWithoutLeakingDetails(): void
    {
        $router = new Router('/v1');
        $loader = new PluginLoader(
            dirname(__DIR__, 2) . '/plugins',
            $router,
            null,
            new HookManager()
        );

        // Override the build seam to throw, simulating any internal failure
        // (e.g. an encoder or generator fault) without contriving bad input.
        $handler = new class ($router, $loader) extends OpenApiHandler {
            protected function buildSpec(): string
            {
                throw new \RuntimeException('boom: secret internal detail');
            }
        };

        $response = $handler->handle(new Request('GET', '/api/openapi.json'));

        $this->assertSame(500, $response->getStatusCode());
        $body = $response->getBody();
        $this->assertStringNotContainsString('boom', $body);
        $this->assertStringNotContainsString('secret internal detail', $body);
        $this->assertStringNotContainsString('RuntimeException', $body);
        $this->assertStringNotContainsString('Stack trace', $body);
    }
}
