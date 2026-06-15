<?php

declare(strict_types=1);

namespace Whity\Core\Tenant;

use Whity\Sdk\Tenant\TenantTableRegistry;

/**
 * Bridges whity-core's table sets into the SDK's portable
 * {@see TenantTableRegistry} (WC-194).
 *
 * The SDK's {@see \Whity\Sdk\Tenant\TenantPredicateScanner} is the single
 * source of truth for the scan logic, but it is schema-agnostic: it is told
 * which tables are tenant-owned / global per call. This factory supplies the
 * HOST's answer — derived from {@see TenantOwnedTables} and
 * {@see SanctionedGlobalTables}, which remain the canonical, migration-pinned
 * lists for core — so the core CI guard and any plugin merging the host
 * registry see exactly core's schema.
 *
 * Keeping core's lists where they are (rather than moving them into the SDK)
 * is deliberate: they describe whity-core's OWN schema, not a contract every
 * plugin shares. The SDK owns the mechanism; the host owns its data.
 */
final class CoreTenantTableRegistry
{
    private function __construct()
    {
    }

    /**
     * The registry describing whity-core's tenant-owned and sanctioned-global
     * tables. A plugin's conformance test merges this into its own registry so
     * an unscoped query against a CORE tenant table is flagged too.
     */
    public static function build(): TenantTableRegistry
    {
        $tenantOwned = [];
        foreach (TenantOwnedTables::all() as $table) {
            $tenantOwned[$table] = 'whity-core tenant-owned table';
        }

        $global = [];
        foreach (SanctionedGlobalTables::all() as $table) {
            $global[$table] = SanctionedGlobalTables::reasonFor($table) ?? 'sanctioned global';
        }

        return new TenantTableRegistry($tenantOwned, $global);
    }
}
