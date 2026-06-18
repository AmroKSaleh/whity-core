<?php

namespace Whity\Tests\OpenAPI;

use PHPUnit\Framework\TestCase;
use Whity\OpenAPI\SchemaGenerator;
use Whity\Core\PluginLoader;
use Whity\Core\Router;
use Whity\Sdk\PluginInterface;
use Whity\Core\Request;
use Whity\Core\Response;

class SchemaGeneratorTest extends TestCase
{
    public function testGenerateSchemaFromPlugins(): void
    {
        $mockPlugin = $this->createMockPlugin('/api/admin/stats', 'GET', 'admin');
        $mockLoader = $this->createMock(PluginLoader::class);
        $mockLoader->method('getPlugins')->willReturn([$mockPlugin]);

        $generator = new SchemaGenerator('Whity API', '1.0.0', $mockLoader);
        $spec = $generator->generate();

        // Check OpenAPI version and basic structure
        $this->assertIsArray($spec);
        $this->assertEquals('3.0.0', $spec['openapi']);
        $this->assertEquals('Whity API', $spec['info']['title']);
    }

    public function testSchemaIncludesPluginRoutes(): void
    {
        $mockPlugin = $this->createMockPlugin('/api/admin/stats', 'GET', 'admin');
        $mockLoader = $this->createMock(PluginLoader::class);
        $mockLoader->method('getPlugins')->willReturn([$mockPlugin]);

        $generator = new SchemaGenerator('Whity API', '1.0.0', $mockLoader);
        $spec = $generator->generate();

        // Plugin should be in paths
        $this->assertArrayHasKey('paths', $spec);
        $this->assertArrayHasKey('/api/admin/stats', $spec['paths']);
    }

    public function testSchemaIncludesBearerAuth(): void
    {
        $mockPlugin = $this->createMockPlugin('/api/admin/stats', 'GET', 'admin');
        $mockLoader = $this->createMock(PluginLoader::class);
        $mockLoader->method('getPlugins')->willReturn([$mockPlugin]);

        $generator = new SchemaGenerator('Whity API', '1.0.0', $mockLoader);
        $spec = $generator->generate();

        // Bearer auth should be configured
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('securitySchemes', $spec['components']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }

    public function testSchemaGeneratesCorrectTags(): void
    {
        $mockPlugin = $this->createMockPlugin('/api/admin/stats', 'GET', 'admin');
        $mockLoader = $this->createMock(PluginLoader::class);
        $mockLoader->method('getPlugins')->willReturn([$mockPlugin]);

        $generator = new SchemaGenerator('Whity API', '1.0.0', $mockLoader);
        $spec = $generator->generate();

        // AdminStats is at /api/admin/stats, tag should be "Admin" (2nd path segment after /api/)
        $this->assertArrayHasKey('/api/admin/stats', $spec['paths']);
        $statsOperation = $spec['paths']['/api/admin/stats']['get'];
        $this->assertContains('Admin', $statsOperation['tags']);
    }

    public function testAuthenticatedPermissionedOperationGetsStandardErrorEnvelopes(): void
    {
        $router = new Router('');
        $router->register('GET', '/api/admin/stats', static fn() => null, 'admin', null, 'view:stats');

        $operation = $this->generateOverRouter($router)['paths']['/api/admin/stats']['get'];
        $responses = $operation['responses'];

        foreach (['401', '403', '404', '405', '500'] as $code) {
            $this->assertArrayHasKey($code, $responses, "Expected error response {$code}");
            $this->assertSame(
                '#/components/schemas/Error',
                $responses[$code]['content']['application/json']['schema']['$ref'],
                "Response {$code} must \$ref the Error component"
            );
        }
    }

    public function testPublicOperationOmitsAuthErrorsButKeepsTransportErrors(): void
    {
        $router = new Router('');
        $router->register('GET', '/api/public/ping', static fn() => null);

        $responses = $this->generateOverRouter($router)['paths']['/api/public/ping']['get']['responses'];

        $this->assertArrayHasKey('404', $responses);
        $this->assertArrayHasKey('405', $responses);
        $this->assertArrayHasKey('500', $responses);
        $this->assertArrayNotHasKey('401', $responses);
        $this->assertArrayNotHasKey('403', $responses);
    }

    public function testOperationWithRequestBodyGetsBadRequestEnvelope(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/widgets', static fn() => null, null, null, null, [
            'request' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
            'responses' => [201 => ['description' => 'Created']],
        ]);

        $operation = $this->generateOverRouter($router)['paths']['/api/widgets']['post'];

        $this->assertArrayHasKey('requestBody', $operation);
        $this->assertArrayHasKey('400', $operation['responses']);
        $this->assertSame(
            '#/components/schemas/Error',
            $operation['responses']['400']['content']['application/json']['schema']['$ref']
        );
    }

    public function testDeclaredResponsesAreNotOverwrittenByInjectedEnvelopes(): void
    {
        $router = new Router('');
        $router->register('GET', '/api/admin/widgets', static fn() => null, 'admin', null, 'view:widgets', [
            'responses' => [
                200 => ['description' => 'OK'],
                403 => ['description' => 'Custom forbidden text'],
                422 => ['description' => 'Unprocessable entity'],
            ],
        ]);

        $responses = $this->generateOverRouter($router)['paths']['/api/admin/widgets']['get']['responses'];

        // Declared 403 keeps its custom description (not clobbered by the generic envelope).
        $this->assertSame('Custom forbidden text', $responses['403']['description']);
        $this->assertArrayNotHasKey('content', $responses['403']);
        // Declared 422 survives; we never inject 422.
        $this->assertSame('Unprocessable entity', $responses['422']['description']);
        // Injected universal codes are still added alongside.
        $this->assertArrayHasKey('404', $responses);
        $this->assertArrayHasKey('405', $responses);
        $this->assertArrayHasKey('500', $responses);
        // 401 is still injected (authenticated) since it was not declared.
        $this->assertArrayHasKey('401', $responses);
    }

    public function testInjectedEnvelopesKeepSpecValidAndDeterministic(): void
    {
        $build = function (): array {
            $router = new Router('');
            $router->register('GET', '/api/admin/stats', static fn() => null, 'admin', null, 'view:stats');
            $router->register('POST', '/api/widgets', static fn() => null, null, null, null, [
                'request' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]],
                'responses' => [201 => ['description' => 'Created']],
            ]);
            $router->register('GET', '/api/public/ping', static fn() => null);

            $loader = $this->createMock(PluginLoader::class);
            $loader->method('getPlugins')->willReturn([]);

            return (new SchemaGenerator('Whity API', '1.0.0', $loader, $router))->generateAndValidate();
        };

        $first = $build();
        $this->assertSame([], $first['errors'], 'Injected error envelopes must keep the spec structurally valid');

        // Determinism: encoding two generations is byte-identical.
        $this->assertSame(
            SchemaGenerator::encode($first['spec']),
            SchemaGenerator::encode($build()['spec'])
        );
    }

    /**
     * Generate a spec over a Router (the preferred source) with an empty
     * plugin set, returning the built spec array.
     *
     * @param Router $router The router carrying the registered routes.
     * @return array<string, mixed>
     */
    private function generateOverRouter(Router $router): array
    {
        $loader = $this->createMock(PluginLoader::class);
        $loader->method('getPlugins')->willReturn([]);

        return (new SchemaGenerator('Whity API', '1.0.0', $loader, $router))->generate();
    }

    /**
     * Create a mock plugin for testing
     *
     * @param string $route Route path
     * @param string $method HTTP method
     * @param string|null $requiredRole Required role
     * @return PluginInterface
     */
    private function createMockPlugin(string $route, string $method, ?string $requiredRole): PluginInterface
    {
        $mock = $this->createMock(PluginInterface::class);
        $mock->method('getName')->willReturn('MockPlugin');
        $mock->method('getVersion')->willReturn('1.0.0');
        $mock->method('getRoutes')->willReturn([
            [
                'method' => $method,
                'path' => $route,
                'handler' => function() {},
                'requiredRole' => $requiredRole,
            ]
        ]);
        $mock->method('getPermissions')->willReturn([]);
        $mock->method('getHooks')->willReturn([]);
        $mock->method('getMigrations')->willReturn([]);

        return $mock;
    }
}
