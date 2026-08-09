<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Tenant;

use PHPUnit\Framework\TestCase;
use Whity\Core\Tenant\CoreTables;
use Whity\Core\Tenant\TableOwnershipException;
use Whity\Core\Tenant\TableOwnershipRegistry;
use Whity\Core\Tenant\TenantOwnedTables;

/**
 * WC-723 Piece 1: table ownership must be a fact the LOADER stamped, not a
 * claim the plugin made.
 *
 * The SDK's TenantTableRegistry could never answer "who owns this?" — it stores
 * a rationale string, its merge() erases origin, and the plugin constructs it
 * itself. These pin the three properties that make the replacement trustworthy:
 * a plugin is attributed by the name the host supplied, it cannot claim a table
 * somebody already owns, and it cannot pass itself off as core.
 */
final class TableOwnershipRegistryTest extends TestCase
{
    // ==================== Loader-stamped attribution ====================

    public function testOwnershipIsAttributedToTheSourceTheLoaderSupplied(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->register('Acme', ['acme_records' => TableOwnershipRegistry::SCOPE_TENANT]);

        self::assertSame('acme', $registry->ownerOf('acme_records'));
        self::assertTrue($registry->isOwnedBy('acme_records', 'Acme'));
        self::assertFalse(
            $registry->isOwnedBy('acme_records', 'Globex'),
            'Another plugin must never be recognised as the owner.'
        );
    }

    public function testAPluginNameIsNormalisedTheSameWayEverywhere(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->register('Acme\\Widgets\\AcmeWidgets', ['widget_rows' => TableOwnershipRegistry::SCOPE_TENANT]);

        self::assertSame('acmewidgets', $registry->ownerOf('widget_rows'));
        self::assertTrue(
            $registry->isOwnedBy('widget_rows', 'Acme\\Widgets\\AcmeWidgets'),
            'The same plugin name must resolve to the same owner on the way in and the way out.'
        );
    }

    public function testTableNamesAreCaseInsensitive(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->register('Acme', ['Acme_Records' => TableOwnershipRegistry::SCOPE_TENANT]);

        self::assertSame('acme', $registry->ownerOf('ACME_RECORDS'));
    }

    // ==================== A plugin cannot claim what it does not own ====================

    public function testAPluginCannotClaimACoreTable(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->registerCoreTables();

        $this->expectException(TableOwnershipException::class);
        $this->expectExceptionMessage("already owned by 'core'");

        $registry->register('Impostor', ['memberships' => TableOwnershipRegistry::SCOPE_TENANT]);
    }

    public function testCoreTablesAreOwnedByCoreEvenWithoutAnExplicitBootstrapCall(): void
    {
        // Lazy core registration: a reader must never see a map missing core's
        // tables merely because bootstrap order changed, or the very first
        // plugin to load would be able to claim them.
        $registry = new TableOwnershipRegistry();

        self::assertSame(TableOwnershipRegistry::CORE_SOURCE, $registry->ownerOf('audit_log'));
        self::assertTrue($registry->isOwnedBy('roles', TableOwnershipRegistry::CORE_SOURCE));
    }

    public function testARefusedClaimDiscardsTheWholeDeclarationNotJustTheContestedTable(): void
    {
        $registry = new TableOwnershipRegistry();

        try {
            $registry->register('Impostor', [
                'impostor_own_table' => TableOwnershipRegistry::SCOPE_TENANT,
                'audit_log' => TableOwnershipRegistry::SCOPE_TENANT,
            ]);
            self::fail('Claiming a core table must be refused.');
        } catch (TableOwnershipException) {
            // expected
        }

        self::assertNull(
            $registry->ownerOf('impostor_own_table'),
            'A partially applied claim would make ownership depend on iteration order.'
        );
        self::assertSame(TableOwnershipRegistry::CORE_SOURCE, $registry->ownerOf('audit_log'));
    }

    public function testTwoPluginsCannotBothOwnTheSameTable(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->register('First', ['shared_name' => TableOwnershipRegistry::SCOPE_TENANT]);

        $this->expectException(TableOwnershipException::class);

        $registry->register('Second', ['shared_name' => TableOwnershipRegistry::SCOPE_TENANT]);
    }

    public function testReRegisteringOnesOwnTablesIsIdempotent(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->register('Acme', ['acme_records' => TableOwnershipRegistry::SCOPE_TENANT]);
        $registry->register('Acme', ['acme_records' => TableOwnershipRegistry::SCOPE_TENANT]);

        self::assertSame(['acme_records'], $registry->tablesOf('Acme'));
    }

    public function testAPluginCannotRegisterUnderTheReservedCoreSource(): void
    {
        $this->expectException(TableOwnershipException::class);

        (new TableOwnershipRegistry())->register(TableOwnershipRegistry::CORE_SOURCE, [
            'sneaky' => TableOwnershipRegistry::SCOPE_TENANT,
        ]);
    }

    public function testASourceWithNoUsableSlugIsRefused(): void
    {
        $this->expectException(TableOwnershipException::class);

        (new TableOwnershipRegistry())->register('123', ['x_table' => TableOwnershipRegistry::SCOPE_TENANT]);
    }

    // ==================== Validation ====================

    public function testAMalformedTableNameIsRefused(): void
    {
        $this->expectException(TableOwnershipException::class);

        (new TableOwnershipRegistry())->register('Acme', [
            'acme records; DROP TABLE roles' => TableOwnershipRegistry::SCOPE_TENANT,
        ]);
    }

    public function testAnUnknownScopeIsRefused(): void
    {
        $this->expectException(TableOwnershipException::class);

        (new TableOwnershipRegistry())->register('Acme', ['acme_records' => 'sort_of_tenant']);
    }

    public function testAnOverlongIdentifierIsRefused(): void
    {
        $this->expectException(TableOwnershipException::class);

        (new TableOwnershipRegistry())->register('Acme', [
            'a' . str_repeat('b', 63) => TableOwnershipRegistry::SCOPE_TENANT,
        ]);
    }

    // ==================== Scope ====================

    public function testTenantScopeComesFromTheDeclaration(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->register('Acme', [
            'acme_records' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_counter' => TableOwnershipRegistry::SCOPE_GLOBAL,
        ]);

        self::assertTrue($registry->isTenantScoped('acme_records'));
        self::assertFalse($registry->isTenantScoped('acme_counter'));
    }

    public function testCoreTenantScopeIsDerivedFromTheMigrationPinnedList(): void
    {
        $registry = new TableOwnershipRegistry();

        self::assertTrue(
            $registry->isTenantScoped('audit_log'),
            'audit_log carries tenant_id per TenantOwnedTables.'
        );
        self::assertFalse(
            $registry->isTenantScoped('permissions'),
            'The permission catalogue carries no tenant_id.'
        );
        foreach (TenantOwnedTables::all() as $table) {
            self::assertTrue($registry->isTenantScoped($table), "{$table} must be registered tenant-scoped.");
        }
    }

    public function testAnUnclaimedTableIsOwnedByNobodyAndScopedByNobody(): void
    {
        $registry = new TableOwnershipRegistry();

        self::assertNull($registry->ownerOf('nobody_declared_this'));
        self::assertFalse($registry->isTenantScoped('nobody_declared_this'));
        self::assertFalse($registry->isOwnedBy('nobody_declared_this', 'Acme'));
    }

    // ==================== Promotion to the SDK's portable registry ====================

    public function testTheProjectionOntoTheSdkRegistrySeesLoadedPluginTables(): void
    {
        $registry = new TableOwnershipRegistry();
        $registry->register('Acme', [
            'acme_records' => TableOwnershipRegistry::SCOPE_TENANT,
            'acme_counter' => TableOwnershipRegistry::SCOPE_GLOBAL,
        ]);

        $projected = $registry->toTenantTableRegistry();

        self::assertTrue(
            $projected->isTenantOwned('acme_records'),
            'The runtime registry is what lets the scanner see a plugin table with no plugin-authored assembly.'
        );
        self::assertTrue($projected->isGlobal('acme_counter'));
        self::assertTrue($projected->isTenantOwned('audit_log'), 'Core tables come along.');
        self::assertFalse($projected->isTenantOwned('permissions'));
    }

    public function testEveryCoreTableIsClaimedSoNoneIsClaimable(): void
    {
        $registry = new TableOwnershipRegistry();

        foreach (CoreTables::all() as $table) {
            self::assertSame(
                TableOwnershipRegistry::CORE_SOURCE,
                $registry->ownerOf($table),
                "{$table} must be owned by core, or a plugin could claim it and guard over it."
            );
        }
    }
}
