<?php

namespace Tests\Unit\Core\RBAC;

use PHPUnit\Framework\TestCase;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Hooks\HookManager;

/**
 * Tests for PermissionRegistry class
 */
class PermissionRegistryTest extends TestCase
{
    private PermissionRegistry $registry;

    protected function setUp(): void
    {
        $this->registry = new PermissionRegistry();
    }

    /**
     * Test registerPermissions() stores plugin permissions
     */
    public function testRegisterPermissionsStoresPluginPermissions(): void
    {
        $pluginId = 'test-plugin';
        $permissions = ['create', 'read', 'update', 'delete'];

        $this->registry->registerPermissions($pluginId, $permissions);

        $result = $this->registry->getPluginPermissions($pluginId);
        $this->assertEquals($permissions, $result);
    }

    /**
     * Test permissionExists() returns true for registered permission
     */
    public function testPermissionExistsReturnsTrueForRegisteredPermission(): void
    {
        $this->registry->registerPermissions('plugin1', ['read', 'write']);

        $this->assertTrue($this->registry->permissionExists('read'));
        $this->assertTrue($this->registry->permissionExists('write'));
    }

    /**
     * Test permissionExists() returns false for unregistered permission
     */
    public function testPermissionExistsReturnsFalseForUnregisteredPermission(): void
    {
        $this->registry->registerPermissions('plugin1', ['read']);

        $this->assertFalse($this->registry->permissionExists('write'));
        $this->assertFalse($this->registry->permissionExists('nonexistent'));
    }

    /**
     * Test getAllActivePermissions() returns all plugin permissions
     */
    public function testGetAllActivePermissionsReturnsAllPluginPermissions(): void
    {
        $this->registry->registerPermissions('plugin1', ['read', 'write']);
        $this->registry->registerPermissions('plugin2', ['delete', 'create']);

        $result = $this->registry->getAllActivePermissions();

        $this->assertIsArray($result);
        $this->assertArrayHasKey('plugin1', $result);
        $this->assertArrayHasKey('plugin2', $result);
        $this->assertEquals(['read', 'write'], $result['plugin1']);
        $this->assertEquals(['delete', 'create'], $result['plugin2']);
    }

    /**
     * Test getRegisteredPlugins() returns plugin IDs
     */
    public function testGetRegisteredPluginsReturnsPluginIds(): void
    {
        $this->registry->registerPermissions('plugin1', ['read']);
        $this->registry->registerPermissions('plugin2', ['write']);
        $this->registry->registerPermissions('plugin3', ['delete']);

        $result = $this->registry->getRegisteredPlugins();

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
        $this->assertContains('plugin1', $result);
        $this->assertContains('plugin2', $result);
        $this->assertContains('plugin3', $result);
    }

    /**
     * Test registerPermissions() accepts empty permission array
     */
    public function testRegisterPermissionsWithEmptyArrayIsAllowed(): void
    {
        $pluginId = 'empty-plugin';

        // Should not throw
        $this->registry->registerPermissions($pluginId, []);

        $result = $this->registry->getPluginPermissions($pluginId);
        $this->assertEquals([], $result);

        $plugins = $this->registry->getRegisteredPlugins();
        $this->assertContains($pluginId, $plugins);
    }

    /**
     * Test getPluginPermissions() returns empty array for unregistered plugin
     */
    public function testGetPluginPermissionsReturnsEmptyArrayForUnregisteredPlugin(): void
    {
        $result = $this->registry->getPluginPermissions('nonexistent-plugin');

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    /**
     * Test optional HookManager dispatches permission.registered hook
     */
    public function testHookManagerDispatchesPermissionRegisteredHook(): void
    {
        $hookManager = $this->createMock(HookManager::class);
        $hookManager->expects($this->once())
            ->method('dispatch')
            ->with(
                $this->equalTo('permission.registered'),
                $this->callback(function($data) {
                    return isset($data['plugin_id']) && isset($data['permissions']);
                })
            );

        $registry = new PermissionRegistry($hookManager);
        $registry->registerPermissions('test-plugin', ['read', 'write']);
    }
}
