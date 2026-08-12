<?php

declare(strict_types=1);

namespace Tests\Plugins;

use DemoCatalog\Migrations\AddSyncColumnsToDemoCatalogItems;
use DemoCatalog\Migrations\CreateDemoCatalogItemsTable;
use DemoCatalog\Migrations\RetireDemoCatalogChangeSeqTable;
use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Tenant\TenantTableRegistry;
use Whity\Sdk\Testing\TenantIsolationConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/Migrations/CreateDemoCatalogItemsTable.php';
require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/Migrations/AddSyncColumnsToDemoCatalogItems.php';
require_once dirname(__DIR__, 2) . '/plugins/DemoCatalog/Migrations/RetireDemoCatalogChangeSeqTable.php';

/**
 * Conformance fixture (mirrors HelloWorldTenantConformanceTest, WC-194): proves
 * the in-tree DemoCatalog pilot plugin PASSES the SDK tenant-isolation
 * conformance kit — the same way an out-of-repo plugin (depending only on
 * whity/plugin-sdk) would run it in its own CI.
 *
 * DemoCatalog passes all three checks:
 *  1. migration linter — its `demo_catalog_items` CREATE TABLE declares `tenant_id`;
 *  2. handler-scoping scanner — DemoCatalogApiHandler binds a `tenant_id`
 *     predicate on every non-system-tenant SELECT/UPDATE (the system-tenant
 *     unscoped branches are the documented "sees all" exception, each carrying
 *     a `@tenant-guard-ignore:` annotation);
 *  3. RealEngine — applying its migration to in-memory SQLite yields a
 *     `demo_catalog_items` table that physically carries `tenant_id`.
 */
final class DemoCatalogTenantConformanceTest extends TenantIsolationConformanceTestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../plugins/DemoCatalog';

    /**
     * DemoCatalog declares its own `demo_catalog_items` table tenant-owned, and
     * MERGES the host registry so an unscoped query against a CORE tenant table
     * would be flagged too — exactly what a distributable plugin does.
     */
    protected function tenantTableRegistry(): TenantTableRegistry
    {
        return TenantTableRegistry::for([
            'demo_catalog_items' => 'DemoCatalog items; carries tenant_id (CreateDemoCatalogItemsTable).',
        ])->withGlobal(
            'demo_catalog_change_seq',
            'RETIRED: the plugin-owned monotonic change-sequence counter. The changes-feed '
            . 'cursor now comes from the host allocator and the table is dropped by '
            . 'RetireDemoCatalogChangeSeqTable — but AddSyncColumnsToDemoCatalogItems (and '
            . 'the retirement rollback) still contain a CREATE TABLE for it, which the '
            . 'migration linter reads, so the exemption stays declared for as long as that '
            . 'historical statement does. It held no tenant data.'
        )->merge(CoreTenantTableRegistry::build());
    }

    protected function migrationsDirectory(): string
    {
        return self::PLUGIN_DIR . '/Migrations';
    }

    /**
     * @return list<string>
     */
    protected function handlerSourceDirectories(): array
    {
        return [self::PLUGIN_DIR . '/Api'];
    }

    /**
     * @return list<MigrationInterface>
     */
    protected function schemaMigrations(): array
    {
        return [
            new CreateDemoCatalogItemsTable(),
            new AddSyncColumnsToDemoCatalogItems(),
            new RetireDemoCatalogChangeSeqTable(),
        ];
    }

    /**
     * Only the plugin's OWN table is created by its migration; the merged-in
     * host tables are not (the plugin does not re-create core's schema).
     *
     * @return list<string>
     */
    protected function ownTenantTables(): array
    {
        return ['demo_catalog_items'];
    }
}
