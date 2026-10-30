<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Published versions of editor documents (editor M4; E.9).
 *
 * Templates have a draft and a published version: edits stay a draft until
 * Publish, and Whity Render and agents use the published version. Publishing
 * copies the draft's title, page setup and blocks into one row here and sets
 * document_sources.published_version to its number. Rows are INSERT-ONLY, so
 * the history keeps every published version and a PDF can always be traced
 * to what printed it.
 *
 * Registered in CoreTables; every query binds tenant_id. Idempotent and
 * reversible.
 */
class CreateDocumentSourceVersions
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_source_versions (
                id                       BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id                INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                source_id                BIGINT        NOT NULL REFERENCES document_sources(id) ON DELETE CASCADE,
                version                  INTEGER       NOT NULL,
                title                    VARCHAR(255)  NOT NULL,
                page                     JSONB,
                blocks                   JSONB         NOT NULL,
                published_by_profile_id  INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                published_at             TIMESTAMP     NOT NULL DEFAULT NOW(),
                UNIQUE (source_id, version)
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_source_versions_tenant_source ON document_source_versions(tenant_id, source_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_source_versions CASCADE');
    }
}
