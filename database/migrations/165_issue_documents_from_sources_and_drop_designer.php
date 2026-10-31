<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use RuntimeException;
use Whity\Core\Document\DocumentSourceRepository;
use Whity\Core\Document\DocumentSourceStarterSeeder;
use Whity\Core\Document\Legacy\ConversionLabels;
use Whity\Core\Document\Legacy\LegacyTemplateMigrator;
use Whity\Database\Database;

/**
 * Editor M4c: documents are issued from editor templates, and the old
 * Document designer goes.
 *
 * 1. `documents` records the editor template (`document_source_id`) and the
 *    published version (`document_source_version`) it was issued from, so a
 *    correction re-renders that version.
 * 2. Every tenant's old templates are converted into editor templates
 *    (LegacyTemplateMigrator, the owner's call on 31 Oct), with saved blocks
 *    placed where they were used, and every tenant gets the editor's
 *    starters. If any template fails to convert, the migration stops before
 *    anything is dropped and names it.
 * 3. A document issued from an old template points at its converted copy
 *    with no version: it keeps its PDFs, QR code and routes, can't be
 *    re-rendered, and its record page offers the copy to issue from.
 * 4. `document_blocks` and `document_templates` are dropped (on SQLite,
 *    `document_templates` is emptied instead: see the note in up()).
 *
 * This is one of the few migrations that runs application code (the
 * converter and the editor's repository), because the conversion has to
 * happen before the drop, in the same upgrade.
 *
 * down() removes the two columns and recreates the old tables empty, as
 * migrations 059, 075 and 117 left them; the old rows can't come back.
 */
class IssueDocumentsFromSourcesAndDropDesigner
{
    private const SYSTEM_TENANT_ID = 0;

    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS document_source_id BIGINT');
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS document_source_version INTEGER');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_documents_tenant_source ON documents(tenant_id, document_source_id)');

        $pdo = $db->getPdo();
        // Both tables, or this already ran (on SQLite an emptied
        // document_templates stays behind; see below).
        if (!self::tableExists($pdo, 'document_templates') || !self::tableExists($pdo, 'document_blocks')) {
            return;
        }

        $sources = new DocumentSourceRepository($pdo);
        $starters = new DocumentSourceStarterSeeder($sources);
        $migrator = new LegacyTemplateMigrator($pdo, $sources, null, ConversionLabels::fromCatalogue(dirname(__DIR__) . '/i18n'));

        $tenants = $pdo->prepare('SELECT id FROM tenants WHERE id <> :system ORDER BY id');
        $tenants->execute([':system' => self::SYSTEM_TENANT_ID]);
        $errors = [];
        foreach ($tenants->fetchAll(PDO::FETCH_COLUMN) as $tenantId) {
            // Strict: a starter that can't be written stops the upgrade (WHIT-803).
            $starters->seedForTenant((int) $tenantId, strict: true);
            $summary = $migrator->migrateTenant((int) $tenantId);
            foreach ($summary['errors'] as $error) {
                $errors[] = "tenant {$tenantId}, {$error}";
            }
        }
        if ($errors !== []) {
            throw new RuntimeException(
                'Some old document templates could not be converted, so the old designer was not removed: ' . implode('; ', $errors)
            );
        }

        $db->exec(
            'UPDATE documents SET document_source_id = (
                 SELECT MIN(s.id) FROM document_sources s
                 WHERE s.tenant_id = documents.tenant_id AND s.legacy_template_id = documents.document_template_id
             )
             WHERE document_template_id IS NOT NULL AND document_source_id IS NULL'
        );

        $db->exec('DROP TABLE IF EXISTS document_blocks CASCADE');
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            // CASCADE takes the foreign key from documents.document_template_id
            // with it; the column stays, as the old template's id, for history.
            $db->exec('DROP TABLE IF EXISTS document_templates CASCADE');
        } else {
            // SQLite keeps `documents`' foreign key to this table in the table's
            // own definition and can't drop it without rebuilding `documents`,
            // and a key naming a missing table breaks every later delete that
            // touches documents. So the table stays there, empty and unused.
            $db->exec('DELETE FROM document_templates');
        }
    }

    public static function down(Database $db): void
    {
        foreach ([
            '059_create_document_designer_tables.php'         => CreateDocumentDesignerTables::class,
            '075_add_starter_key_to_document_designer_tables.php' => AddStarterKeyToDocumentDesignerTables::class,
            '117_place_document_designer_rows_in_ous.php'     => PlaceDocumentDesignerRowsInOus::class,
        ] as $file => $class) {
            if (!class_exists($class, false)) {
                require_once __DIR__ . '/' . $file;
            }
            $class::up($db);
        }

        $db->exec('DROP INDEX IF EXISTS idx_documents_tenant_source');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS document_source_version');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS document_source_id');
    }

    private static function tableExists(PDO $pdo, string $table): bool
    {
        if ($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql') {
            return (int) $pdo->query("SELECT CASE WHEN to_regclass('" . $table . "') IS NULL THEN 0 ELSE 1 END")->fetchColumn() === 1;
        }
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :name");
        $stmt->execute([':name' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }
}
