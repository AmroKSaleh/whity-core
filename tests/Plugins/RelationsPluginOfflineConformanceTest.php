<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Relations\RelationsPlugin;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/Relations/RelationsPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/Relations/Migrations/CreatePersonsTable.php';

/**
 * Runs the shared offline-host conformance suite against the Relations plugin —
 * crucially, it migrates on an EMPTY SQLite-flavoured engine (no core `persons`),
 * so the plugin's fresh-build `CREATE TABLE persons` actually executes. This is
 * the path the desktop's offline host hits and the one every other Relations
 * test misses (they all run where core migration 018 already owns `persons`, so
 * the `IF NOT EXISTS` CREATE is a no-op). It's the check that would have caught
 * the bare `DEFAULT NOW()` crash-loop before it shipped.
 */
final class RelationsPluginOfflineConformanceTest extends OfflinePluginHostConformanceTestCase
{
    protected function pluginUnderTest(): PluginInterface
    {
        return new RelationsPlugin();
    }
}
