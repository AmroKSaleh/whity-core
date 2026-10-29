<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Written documents for the block editor (design system 2.2, editor M2;
 * docs/design/IMPLEMENTATION-NOTES.md §3.1).
 *
 * One TENANT-OWNED row per template, letter, report, note, wiki page or label
 * sheet the editor writes. `documents` already means ISSUED documents (PDF
 * artifacts, routing, QR), hence the name. The draft's blocks are stored
 * as the WhityDocument contract's `Block[]`
 * (docs/design/handoff/editor/whity-document.ts) — the JSON is the contract,
 * as it is for `document_templates`. Comments and suggestions are NOT kept in
 * it: they get their own tables in M5, so the draft only ever changes through
 * a person's edit or an accepted suggestion.
 *
 *   draft_version      — bumped by every save; a save names the version it
 *                        was made against (If-Match), and a stale one is
 *                        refused with 409 instead of overwriting newer work.
 *   published_version  — set by Publish (M4); null for notes and wiki pages,
 *                        which save live and are never published (E.9).
 *
 * Visibility columns mirror `document_templates` (scope, required_permission,
 * created_by, owner_ou_id), so the same DocumentAccessPolicy filters both.
 *
 * Registered in CoreTables; every query binds tenant_id. Idempotent and
 * reversible.
 */
class CreateDocumentSources
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_sources (
                id                    BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id             INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                kind                  VARCHAR(16)   NOT NULL,
                title                 VARCHAR(255)  NOT NULL DEFAULT '',
                lang                  VARCHAR(8)    NOT NULL DEFAULT 'en',
                default_view          VARCHAR(16)   NOT NULL DEFAULT 'continuous',
                full_width            BOOLEAN       NOT NULL DEFAULT FALSE,
                page                  JSONB,
                record_type           VARCHAR(64),
                blocks                JSONB         NOT NULL,
                draft_version         INTEGER       NOT NULL DEFAULT 1,
                published_version     INTEGER,
                scope                 VARCHAR(16)   NOT NULL DEFAULT 'personal',
                required_permission   VARCHAR(128),
                created_by            BIGINT,
                owner_ou_id           BIGINT,
                updated_by            BIGINT,
                created_at            TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at            TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_sources_tenant_id ON document_sources(tenant_id)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_sources_tenant_kind ON document_sources(tenant_id, kind)');
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_sources_tenant_updated ON document_sources(tenant_id, updated_at)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_sources CASCADE');
    }
}
