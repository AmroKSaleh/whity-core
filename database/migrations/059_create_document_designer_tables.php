<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * WC-docdesigner document/label designer persistence (task 58cdd88a + ca1d8c03).
 *
 * Durable, tenant-scoped backing for the designer whose UI is already shipped
 * (client-only today: browser localStorage). Two TENANT-OWNED tables; the whole
 * client object is stored verbatim as JSON — the JSON is the contract (mirrors
 * web/lib/documents/types.ts DocTemplate v2 and blocks.ts DocBlock). We do NOT
 * shred elements into columns.
 *
 *   document_templates — a saved DocTemplate (versioned JSON in `data`).
 *   document_blocks     — a reusable block (a DocElement[] fragment in `data`);
 *                         documents reference it by POINTER (blockInstance), never
 *                         an inline copy, so edits propagate.
 *
 * Governance columns (RBAC-gated visibility, added 2026-07-11):
 *   scope               — visibility tier: personal (creator only) | tenant
 *                         (tenant-wide, RBAC-gated) | global (operator/all) |
 *                         system (seeded starter, all in tenant).
 *   required_permission — nullable RBAC tag; when set on a tenant/global row the
 *                         list/get API returns it only to callers holding it
 *                         (server-enforced — a technician never receives a
 *                         contracts template). null = visible to all in tenant.
 *   is_system           — true for seeded starters (idempotent, upgrade-safe
 *                         seeding must not clobber user edits).
 *
 * BOTH registered in TenantOwnedTables; every query binds tenant_id.
 * Idempotent (IF NOT EXISTS) and reversible.
 */
class CreateDocumentDesignerTables
{
    public static function up(Database $db): void
    {
        // NOTE: one literal create-table statement for each table (not a loop over
        // an interpolated name) — TenantOwnedTablesTest derives the tenant-owned
        // set by scanning this source for the table DDL, and the idempotency test
        // scans for the create keyword, so the names must appear literally.
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_templates (
                id                  BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id           INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name                VARCHAR(255)  NOT NULL,
                data                JSONB         NOT NULL,
                scope               VARCHAR(16)   NOT NULL DEFAULT 'personal',
                required_permission VARCHAR(128),
                is_system           BOOLEAN       NOT NULL DEFAULT FALSE,
                created_by          BIGINT,
                created_at          TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_templates_tenant_id ON document_templates(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_templates_tenant_scope ON document_templates(tenant_id, scope)');

        $db->exec("
            CREATE TABLE IF NOT EXISTS document_blocks (
                id                  BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id           INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                name                VARCHAR(255)  NOT NULL,
                data                JSONB         NOT NULL,
                scope               VARCHAR(16)   NOT NULL DEFAULT 'personal',
                required_permission VARCHAR(128),
                is_system           BOOLEAN       NOT NULL DEFAULT FALSE,
                created_by          BIGINT,
                created_at          TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at          TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_blocks_tenant_id ON document_blocks(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_blocks_tenant_scope ON document_blocks(tenant_id, scope)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_blocks CASCADE');
        $db->exec('DROP TABLE IF EXISTS document_templates CASCADE');
    }
}
