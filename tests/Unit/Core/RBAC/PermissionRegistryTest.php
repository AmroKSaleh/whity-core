<?php

namespace Tests\Unit\Core\RBAC;

use PHPUnit\Framework\TestCase;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\InvalidPermissionException;
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

    // ---------------------------------------------------------------------
    // WC-13: source-tagged registration API (register / getAll / exists /
    // getBySource) and core permission registration (issue #55).
    // ---------------------------------------------------------------------

    /**
     * AC #1: core + plugin permissions are all returned by getAll() with source tags.
     */
    public function testGetAllReturnsCoreAndPluginPermissionsWithSourceTags(): void
    {
        $this->registry->register('core', ['users:read', 'users:write', 'roles:manage']);
        $this->registry->register('invoices', ['invoices:read', 'invoices:write']);

        $all = $this->registry->getAll();

        $this->assertCount(5, $all);
        $this->assertSame('core', $all['users:read']);
        $this->assertSame('core', $all['users:write']);
        $this->assertSame('core', $all['roles:manage']);
        $this->assertSame('invoices', $all['invoices:read']);
        $this->assertSame('invoices', $all['invoices:write']);
    }

    /**
     * AC #2: exists() returns true for a registered permission.
     */
    public function testExistsReturnsTrueForRegisteredPermission(): void
    {
        $this->registry->register('core', ['users:delete']);

        $this->assertTrue($this->registry->exists('users:delete'));
    }

    /**
     * exists() returns false for an unregistered permission.
     */
    public function testExistsReturnsFalseForUnregisteredPermission(): void
    {
        $this->registry->register('core', ['users:read']);

        $this->assertFalse($this->registry->exists('users:delete'));
    }

    /**
     * register() is an alias of registerPermissions() and keeps both views in sync.
     */
    public function testRegisterIsBackwardCompatibleWithLegacyAccessors(): void
    {
        $this->registry->register('billing', ['invoices:read']);

        $this->assertTrue($this->registry->permissionExists('invoices:read'));
        $this->assertSame(['invoices:read'], $this->registry->getPluginPermissions('billing'));
        $this->assertContains('billing', $this->registry->getRegisteredPlugins());
    }

    /**
     * getBySource() returns only the permissions registered under a given source.
     */
    public function testGetBySourceReturnsOnlyThatSourcesPermissions(): void
    {
        $this->registry->register('core', ['users:read', 'users:write']);
        $this->registry->register('invoices', ['invoices:read']);

        $this->assertSame(['users:read', 'users:write'], $this->registry->getBySource('core'));
        $this->assertSame(['invoices:read'], $this->registry->getBySource('invoices'));
        $this->assertSame([], $this->registry->getBySource('unknown'));
    }

    /**
     * register() rejects permissions that do not match the resource:action pattern.
     */
    public function testRegisterRejectsInvalidPermissionFormat(): void
    {
        $this->expectException(InvalidPermissionException::class);

        $this->registry->register('bad', ['not-a-valid-permission']);
    }

    /**
     * register() rejects permissions with an empty segment.
     */
    public function testRegisterRejectsEmptySegments(): void
    {
        $this->expectException(InvalidPermissionException::class);

        $this->registry->register('bad', ['users:']);
    }

    /**
     * Issue #55: core permissions become available without any explicit caller wiring.
     */
    public function testRegisterCorePermissionsPopulatesRegistry(): void
    {
        $this->registry->registerCorePermissions();

        foreach (CorePermissions::all() as $permission) {
            $this->assertTrue(
                $this->registry->exists($permission),
                "Expected core permission {$permission} to be registered"
            );
            $this->assertSame(CorePermissions::SOURCE, $this->registry->getAll()[$permission]);
        }
    }

    /**
     * Issue #55: a fresh registry exposes core permissions lazily on first lookup,
     * so middleware works even when no explicit bootstrap call was made.
     */
    public function testCorePermissionsAreLazilyAvailableOnExists(): void
    {
        $registry = new PermissionRegistry();

        $this->assertTrue($registry->exists(CorePermissions::USERS_READ));
        $this->assertTrue($registry->permissionExists(CorePermissions::ROLES_MANAGE));
    }

    /**
     * Lazy core registration is idempotent and does not duplicate the core source.
     */
    public function testCorePermissionsRegisteredOnlyOnce(): void
    {
        $registry = new PermissionRegistry();

        $registry->registerCorePermissions();
        $registry->registerCorePermissions();

        $corePermissions = $registry->getBySource(CorePermissions::SOURCE);
        $this->assertSame(CorePermissions::all(), $corePermissions);
    }
}
