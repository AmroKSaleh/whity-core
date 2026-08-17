<?php

declare(strict_types=1);

namespace Tests\Plugins;

use Documents\DocumentsPlugin;
use Whity\Sdk\PluginInterface;
use Whity\Sdk\Testing\OfflinePluginHostConformanceTestCase;

require_once dirname(__DIR__, 2) . '/plugins/Documents/DocumentsPlugin.php';
require_once dirname(__DIR__, 2) . '/plugins/Documents/Migrations/AugmentDocumentDesignerTables.php';

/**
 * Runs the shared offline-host conformance suite against the Documents plugin --
 * critically migrating on an EMPTY SQLite-flavoured engine (no core
 * document_templates/document_blocks), so the fresh-build CREATEs actually
 * execute. This is the path the desktop`s offline host hits and the one that
 * PROVES the migration`s offline-safe DDL builds -- `id SERIAL PRIMARY KEY` (the
 * shim rewrites it to the autoincrement INTEGER PRIMARY KEY), `data TEXT` (no
 * JSONB), `is_system BOOLEAN NOT NULL DEFAULT 0`, and PARENTHESISED
 * `DEFAULT (NOW())` -- exactly the Postgres-only-DDL class of bug the Relations
 * port hit live.
 */
final class DocumentsPluginOfflineConformanceTest extends OfflinePluginHostConformanceTestCase
{
    protected function pluginUnderTest(): PluginInterface
    {
        return new DocumentsPlugin();
    }
}
