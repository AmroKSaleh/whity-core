<?php

declare(strict_types=1);

namespace Tests\Plugins;

use HelloWorld\Migrations\CreateHelloGreetingsTable;
use Whity\Core\Tenant\CoreTenantTableRegistry;
use Whity\Sdk\MigrationInterface;
use Whity\Sdk\Tenant\TenantTableRegistry;
use Whity\Sdk\Testing\TenantIsolationConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/HelloWorld/Migrations/CreateHelloGreetingsTable.php';

/**
 * Conformance fixture (WC-194): proves the in-tree HelloWorld reference plugin
 * PASSES the SDK tenant-isolation conformance kit, and demonstrates exactly how
 * an out-of-repo plugin wires it.
 *
 * This is the CI fixture: it runs in whity-core's PHPUnit suite (so a
 * regression in the kit OR in HelloWorld's isolation fails core CI), while
 * being written purely against the SDK base case
 * ({@see TenantIsolationConformanceTestCase}) — the same surface a plugin
 * depending only on `whity/plugin-sdk` would extend.
 *
 * HelloWorld passes all three checks:
 *  1. migration linter — its `hello_greetings` CREATE TABLE declares `tenant_id`;
 *  2. handler-scoping scanner — GreetingsApiHandler binds a `tenant_id`
 *     predicate on every non-system-tenant SELECT/UPDATE/DELETE (the
 *     system-tenant unscoped branches are the documented "sees all" exception);
 *  3. RealEngine — applying its migration to in-memory SQLite yields a
 *     `hello_greetings` table that physically carries `tenant_id`.
 */
final class HelloWorldTenantConformanceTest extends TenantIsolationConformanceTestCase
{
    private const PLUGIN_DIR = __DIR__ . '/../../plugins/HelloWorld';

    /**
     * HelloWorld declares its own `hello_greetings` table tenant-owned, and
     * MERGES the host registry so an unscoped query against a CORE tenant table
     * would be flagged too — exactly what a distributable plugin does.
     */
    protected function tenantTableRegistry(): TenantTableRegistry
    {
        return TenantTableRegistry::for([
            'hello_greetings' => 'HelloWorld greetings; carries tenant_id (CreateHelloGreetingsTable).',
        ])->merge(CoreTenantTableRegistry::build());
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
        return [new CreateHelloGreetingsTable()];
    }

    /**
     * Only the plugin's OWN table is created by its migration; the merged-in
     * host tables are not (the plugin does not re-create core's schema).
     *
     * @return list<string>
     */
    protected function ownTenantTables(): array
    {
        return ['hello_greetings'];
    }
}
