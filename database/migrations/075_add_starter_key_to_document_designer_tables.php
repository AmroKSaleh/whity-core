<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Add a stable `starter_key` to `document_templates` / `document_blocks`
 * (WC-515 REMAINING #3 — per-tenant starter seeding).
 *
 * The per-tenant seeder ({@see \Whity\Core\Document\DocumentStarterSeeder})
 * must be idempotent AND upgrade-safe: re-running it (e.g. a retried
 * tenant.created dispatch, or a later release that adds a NEW starter to the
 * set) must insert only the starters missing for that tenant, never duplicate
 * one already seeded, and never touch/clobber a user's edits to an existing
 * one. Keying that check on `name` (the display title) is fragile — a user is
 * free to rename their copy of a seeded "Invoice" template (there is no lock
 * on `is_system` rows), which would make it look "missing" and get reseeded
 * as a duplicate. `starter_key` is a small, STABLE, internal identifier (e.g.
 * 'invoice', 'sys-header') set ONLY by the seeder — never accepted from the
 * CRUD API request bodies (DocumentTemplatesApiHandler / DocumentBlocksApi
 * Handler do not read it), so a user can rename or otherwise edit a starter
 * freely without it losing its identity for future reseed checks. It is not
 * read by the repositories' public listForTenant()/findById() row shape
 * either (see DocumentRecordTrait::normalizeRow), so this is purely
 * seeder-internal bookkeeping — no OpenAPI/API contract change.
 *
 * Nullable (ordinary user-created rows never set it) + indexed per tenant for
 * the seeder's "which starters do I already have" lookup. Additive +
 * idempotent (ADD COLUMN IF NOT EXISTS); down() drops the columns/indexes.
 */
class AddStarterKeyToDocumentDesignerTables
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_templates ADD COLUMN IF NOT EXISTS starter_key VARCHAR(64)');
        $db->exec('ALTER TABLE document_blocks ADD COLUMN IF NOT EXISTS starter_key VARCHAR(64)');

        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_templates_tenant_starter ON document_templates(tenant_id, starter_key)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_blocks_tenant_starter ON document_blocks(tenant_id, starter_key)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS idx_document_blocks_tenant_starter');
        $db->exec('DROP INDEX IF EXISTS idx_document_templates_tenant_starter');

        $db->exec('ALTER TABLE document_blocks DROP COLUMN IF EXISTS starter_key');
        $db->exec('ALTER TABLE document_templates DROP COLUMN IF EXISTS starter_key');
    }
}
