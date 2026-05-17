<?php

namespace Whity\Tests\OpenAPI;

use PHPUnit\Framework\TestCase;
use Whity\OpenAPI\SchemaBuilder;

class SchemaBuilderTest extends TestCase
{
    public function testCreateBasicOpenApiDocument(): void
    {
        $builder = new SchemaBuilder('Test API', '1.0.0');
        $spec = $builder->build();

        $this->assertIsArray($spec);
        $this->assertEquals('3.0.0', $spec['openapi']);
        $this->assertEquals('Test API', $spec['info']['title']);
        $this->assertEquals('1.0.0', $spec['info']['version']);
    }

    public function testAddPathWithMethod(): void
    {
        $builder = new SchemaBuilder('Test API', '1.0.0');
        $builder->addPath('/api/users', 'GET', [
            'summary' => 'List users',
            'tags' => ['Users'],
            'responses' => [
                '200' => ['description' => 'Success']
            ]
        ]);

        $spec = $builder->build();
        $this->assertArrayHasKey('/api/users', $spec['paths']);
        $this->assertArrayHasKey('get', $spec['paths']['/api/users']);
    }

    public function testAddBearerAuthSecurity(): void
    {
        $builder = new SchemaBuilder('Test API', '1.0.0');
        $builder->addBearerAuth();

        $spec = $builder->build();
        $this->assertArrayHasKey('components', $spec);
        $this->assertArrayHasKey('securitySchemes', $spec['components']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
    }
}
