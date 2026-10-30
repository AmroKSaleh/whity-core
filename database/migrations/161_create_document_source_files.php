<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Images and files placed in an editor document (editor M3; E.13).
 *
 * One TENANT-OWNED row per upload, bound to the document_sources row it was
 * uploaded into. The bytes live in the tenant's storage under
 * `tenants/{tenant}/document-sources/{source}/{random}.{ext}`; the row is the
 * only way to reach them, so a file is readable exactly when its document is
 * (GET /api/document-sources/{id}/files/{fileId}/content). The block carries
 * the row's id, never the storage key.
 *
 * Rows are not deleted when a block is removed: undo, other drafts and the
 * published version may still point at the file. They go with the document
 * (ON DELETE CASCADE); sweeping unreferenced files is later work.
 *
 * Registered in CoreTables; every query binds tenant_id. Idempotent and
 * reversible.
 */
class CreateDocumentSourceFiles
{
    public static function up(Database $db): void
    {
        $db->exec("
            CREATE TABLE IF NOT EXISTS document_source_files (
                id                      BIGSERIAL     NOT NULL PRIMARY KEY,
                tenant_id               INTEGER       NOT NULL REFERENCES tenants(id) ON DELETE CASCADE,
                source_id               BIGINT        NOT NULL REFERENCES document_sources(id) ON DELETE CASCADE,
                storage_key             VARCHAR(512)  NOT NULL UNIQUE,
                content_type            VARCHAR(128)  NOT NULL,
                byte_size               BIGINT        NOT NULL,
                checksum_sha256         CHAR(64)      NOT NULL,
                filename                VARCHAR(255),
                uploaded_by_profile_id  INTEGER       REFERENCES profiles(id) ON DELETE SET NULL,
                created_at              TIMESTAMP     NOT NULL DEFAULT NOW()
            )
        ");
        $db->exec('CREATE INDEX IF NOT EXISTS idx_document_source_files_tenant_source ON document_source_files(tenant_id, source_id)');
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP TABLE IF EXISTS document_source_files CASCADE');
    }
}
