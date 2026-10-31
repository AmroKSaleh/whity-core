<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Workspace document themes (editor M4d; E.44, DOCUMENT-THEMES.md 4.1).
 *
 * A theme decides how a document looks in the page view and in its PDF: the
 * print colour, whether headings take it too, and the letterhead's logo and
 * lines. Whity's standard theme lives in code and plugin themes come with M7;
 * the themes a workspace makes are stored here, one row per theme, addressed
 * by `key` (`tenant.<key>` in a document's theme ref).
 *
 * - `tokens` holds a partial, closed set of theme tokens; missing keys inherit
 *   from the theme it `extends` (one level only).
 * - `version` is bumped on every change and served as the ETag.
 * - `archived_at` archives instead of deleting while drafts or published
 *   versions still name the theme.
 *
 * No personal themes (owner, 31 Oct 2026). Registered in CoreTables; every
 * query binds tenant_id. Idempotent and reversible.
 */
class CreateDocumentThemes
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_themes (
                id            BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id     INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                theme_key     VARCHAR(64)   NOT NULL,
                name          VARCHAR(120)  NOT NULL,
                name_ar       VARCHAR(120)  NOT NULL,
                extends       VARCHAR(100),
                tokens        JSONB         NOT NULL,
                version       INTEGER       NOT NULL DEFAULT 1,
                created_by    BIGINT,
                updated_by    BIGINT,
                archived_at   TIMESTAMP,
                created_at    TIMESTAMP     NOT NULL DEFAULT NOW(),
                updated_at    TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (tenant_id, theme_key)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_themes_tenant_id ON document_themes(tenant_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_themes CASCADE');
    }
}
