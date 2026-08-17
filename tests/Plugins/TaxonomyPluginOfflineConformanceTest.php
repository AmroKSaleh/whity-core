<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Taxonomy\TaxonomyPlugin;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/TaxonomyPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/Taxonomy/Migrations/CreateTaxonomyTables.php';

/**
 * Runs the shared offline-host conformance suite against the Taxonomy plugin —
 * critically migrating on an EMPTY SQLite-flavoured engine (no core tag_groups/
 * tags), so the fresh-build CREATEs actually execute. This is the path the
 * desktop's offline host hits and the one that catches Postgres-only DDL before
 * it ships (a bare `NOW()` default, or a `JSONB` column the shim can't rewrite) —
 * exactly the class of bug the Relations port hit live.
 */
final class TaxonomyPluginOfflineConformanceTest extends OfflinePluginHostConformanceTestCase
{
    protected function pluginUnderTest(): PluginInterface
    {
        return new TaxonomyPlugin();
    }
}
