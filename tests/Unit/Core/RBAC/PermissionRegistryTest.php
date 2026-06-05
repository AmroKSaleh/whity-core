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
     * Test register() stores a source's permissions, retrievable via getBySource().
     */
    public function testRegisterStoresSourcePermissions(): void
    {
        $source = 'test-plugin';
        $permissions = ['posts:create', 'posts:read', 'posts:update', 'posts:delete'];

        $this->registry->register($source, $permissions);

        $result = $this->registry->getBySource($source);
        $this->assertEquals($permissions, $result);
    }

    /**
     * Test exists() returns true for a registered permission.
     */
    public function testExistsReturnsTrueForRegisteredPermissionFromSource(): void
    {
        $this->registry->register('plugin1', ['posts:read', 'posts:write']);

        $this->assertTrue($this->registry->exists('posts:read'));
        $this->assertTrue($this->registry->exists('posts:write'));
    }

    /**
     * Test exists() returns false for an unregistered permission.
     */
    public function testExistsReturnsFalseForUnregisteredPermissionFromSource(): void
    {
        $this->registry->register('plugin1', ['posts:read']);

        $this->assertFalse($this->registry->exists('posts:write'));
        $this->assertFalse($this->registry->exists('posts:nonexistent'));
    }

    /**
     * Test getAll() returns every registered permission tagged by its source.
     */
    public function testGetAllReturnsPermissionsTaggedBySource(): void
    {
        $this->registry->register('plugin1', ['posts:read', 'posts:write']);
        $this->registry->register('plugin2', ['files:delete', 'files:create']);

        $result = $this->registry->getAll();

        $this->assertSame('plugin1', $result['posts:read']);
        $this->assertSame('plugin1', $result['posts:write']);
        $this->assertSame('plugin2', $result['files:delete']);
        $this->assertSame('plugin2', $result['files:create']);
    }

    /**
     * Test getBySource() returns the permissions for each registered source.
     */
    public function testGetBySourceReturnsEachSourcesPermissions(): void
    {
        $this->registry->register('plugin1', ['posts:read']);
        $this->registry->register('plugin2', ['files:write']);
        $this->registry->register('plugin3', ['users:delete']);

        $this->assertSame(['posts:read'], $this->registry->getBySource('plugin1'));
        $this->assertSame(['files:write'], $this->registry->getBySource('plugin2'));
        $this->assertSame(['users:delete'], $this->registry->getBySource('plugin3'));
    }

    /**
     * Test register() accepts an empty permission array (registers an empty source).
     */
    public function testRegisterWithEmptyArrayIsAllowed(): void
    {
        $source = 'empty-plugin';

        // Should not throw.
        $this->registry->register($source, []);

        $this->assertSame([], $this->registry->getBySource($source));
    }

    /**
     * Test getBySource() returns an empty array for an unregistered source.
     */
    public function testGetBySourceReturnsEmptyArrayForUnregisteredSource(): void
    {
        $result = $this->registry->getBySource('nonexistent-plugin');

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
        $registry->register('test-plugin', ['posts:read', 'posts:write']);
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
     * register() stores under the given source and surfaces via exists()/getBySource()/getAll().
     */
    public function testRegisterSurfacesPermissionAcrossAllViews(): void
    {
        $this->registry->register('billing', ['invoices:read']);

        $this->assertTrue($this->registry->exists('invoices:read'));
        $this->assertSame(['invoices:read'], $this->registry->getBySource('billing'));
        $this->assertSame('billing', $this->registry->getAll()['invoices:read']);
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
        $this->assertTrue($registry->exists(CorePermissions::ROLES_MANAGE));
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
