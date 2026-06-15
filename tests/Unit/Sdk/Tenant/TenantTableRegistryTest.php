<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * The portable tenant-table registry (WC-194): the dependency-free model of a
 * host's / plugin's tenant-owned and sanctioned-global tables that the scanner
 * and linter consume.
 */
final class TenantTableRegistryTest extends TestCase
{
    public function testMembershipIsCaseInsensitive(): void
    {
        $registry = TenantTableRegistry::for(['Announcements' => 'r'], ['App_Settings' => 'g']);

        self::assertTrue($registry->isTenantOwned('announcements'));
        self::assertTrue($registry->isTenantOwned('ANNOUNCEMENTS'));
        self::assertTrue($registry->isGlobal('app_settings'));
        self::assertFalse($registry->isTenantOwned('app_settings'));
    }

    public function testWithMethodsAreImmutable(): void
    {
        $base = new TenantTableRegistry();
        $extended = $base->withTenantOwned('notes', 'plugin table');

        self::assertFalse($base->isTenantOwned('notes'), 'withTenantOwned must not mutate the original');
        self::assertTrue($extended->isTenantOwned('notes'));

        $withGlobal = $base->withGlobal('settings', 'global');
        self::assertFalse($base->isGlobal('settings'));
        self::assertTrue($withGlobal->isGlobal('settings'));
    }

    public function testMergeUnionsTenantOwnedTables(): void
    {
        $plugin = TenantTableRegistry::for(['announcements' => 'plugin']);
        $host = TenantTableRegistry::for(['users' => 'host', 'roles' => 'host'], ['revoked_tokens' => 'global']);

        $merged = $plugin->merge($host);

        self::assertTrue($merged->isTenantOwned('announcements'));
        self::assertTrue($merged->isTenantOwned('users'));
        self::assertTrue($merged->isTenantOwned('roles'));
        self::assertTrue($merged->isGlobal('revoked_tokens'));
    }

    public function testTableListsAreExposed(): void
    {
        $registry = TenantTableRegistry::for(['a' => 'x', 'b' => 'y'], ['g' => 'z']);

        $owned = $registry->tenantOwnedTables();
        sort($owned);
        self::assertSame(['a', 'b'], $owned);
        self::assertSame(['g'], $registry->globalTables());
    }
}
