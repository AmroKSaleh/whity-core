<?php

declare(strict_types=1);

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Whity\Core\PluginInterface;
use Whity\Plugins\ExamplePlugin;

/**
 * PluginInterfaceTest
 *
 * Validates the design of PluginInterface using PHP Reflection,
 * and ensures that ExamplePlugin conforms to it.
 */
class PluginInterfaceTest extends TestCase
{
    /**
     * Test that PluginInterface is defined as an interface and contains all required methods with correct signatures.
     */
    public function testPluginInterfaceContracts(): void
    {
        $interface = PluginInterface::class;
        $this->assertTrue(interface_exists($interface), 'PluginInterface must exist.');

        $reflection = new ReflectionClass($interface);
        $this->assertTrue($reflection->isInterface(), 'PluginInterface must be an interface.');

        $expectedMethods = [
            'getName' => ['params' => 0, 'return' => 'string'],
            'getVersion' => ['params' => 0, 'return' => 'string'],
            'getRoutes' => ['params' => 0, 'return' => 'array'],
            'getPermissions' => ['params' => 0, 'return' => 'array'],
            'getHooks' => ['params' => 0, 'return' => 'array'],
            'getMigrations' => ['params' => 0, 'return' => 'array'],
        ];

        foreach ($expectedMethods as $methodName => $specs) {
            $this->assertTrue($reflection->hasMethod($methodName), "PluginInterface must declare method: {$methodName}");
            
            $method = $reflection->getMethod($methodName);
            $this->assertTrue($method->isPublic(), "Method {$methodName} must be public.");
            $this->assertFalse($method->isStatic(), "Method {$methodName} must not be static.");
            $this->assertCount($specs['params'], $method->getParameters(), "Method {$methodName} must have {$specs['params']} parameters.");
            
            $returnType = $method->getReturnType();
            $this->assertNotNull($returnType, "Method {$methodName} must define a return type.");
            $this->assertSame($specs['return'], $returnType->getName(), "Method {$methodName} return type must be {$specs['return']}.");
        }
    }

    /**
     * Test that ExamplePlugin implements the interface and returns valid mock structures.
     */
    public function testExamplePluginCompliance(): void
    {
        $plugin = new ExamplePlugin();
        $this->assertInstanceOf(PluginInterface::class, $plugin);

        $this->assertSame('ExamplePlugin', $plugin->getName());
        $this->assertSame('1.0.0', $plugin->getVersion());
        
        $routes = $plugin->getRoutes();
        $this->assertIsArray($routes);
        $this->assertCount(2, $routes);
        
        // Verify route 1
        $this->assertSame('GET', $routes[0]['method']);
        $this->assertSame('/api/example/hello', $routes[0]['path']);
        $this->assertIsCallable($routes[0]['handler']);
        $this->assertNull($routes[0]['requiredRole']);
        
        // Verify route 2
        $this->assertSame('POST', $routes[1]['method']);
        $this->assertSame('/api/example/secure', $routes[1]['path']);
        $this->assertIsCallable($routes[1]['handler']);
        $this->assertSame('admin', $routes[1]['requiredRole']);

        $permissions = $plugin->getPermissions();
        $this->assertIsArray($permissions);
        $this->assertContains('example:view', $permissions);
        $this->assertContains('example:admin', $permissions);

        $hooks = $plugin->getHooks();
        $this->assertIsArray($hooks);
        $this->assertArrayHasKey('user.login', $hooks);
        $this->assertIsCallable($hooks['user.login']);

        $migrations = $plugin->getMigrations();
        $this->assertIsArray($migrations);
        $this->assertEmpty($migrations);
    }
}
