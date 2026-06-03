<?php

namespace Whity\Tests\OpenAPI;

use PHPUnit\Framework\TestCase;
use Whity\OpenAPI\SchemaGenerator;
use Whity\Core\PluginLoader;
use Whity\Core\PluginInterface;
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
