<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * Editor M4c (DOCUMENT-AUTOMATION.md phase A): `documents.origin_key`, the
 * caller's own name for what a document is ("the invoice for order 123"), so
 * issuing it twice returns the first document instead of a second one. A
 * plugin reacting to a redelivered event, or a client retrying a request,
 * never issues the same thing twice.
 *
 * Unique per tenant where set; most documents have none. Additive and
 * idempotent; down() drops what up() added.
 */
class AddOriginKeyToDocuments
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE documents ADD COLUMN IF NOT EXISTS origin_key VARCHAR(160)');
        $db->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS uq_documents_tenant_origin_key
             ON documents(tenant_id, origin_key) WHERE origin_key IS NOT NULL'
        );
    }

    public static function down(Database $db): void
    {
        $db->exec('DROP INDEX IF EXISTS uq_documents_tenant_origin_key');
        $db->exec('ALTER TABLE documents DROP COLUMN IF EXISTS origin_key');
    }
}
