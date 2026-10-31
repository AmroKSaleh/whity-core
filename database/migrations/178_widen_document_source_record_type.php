<?php

declare(strict_types=1);

namespace Database\Migrations;

use PDO;
use Whity\Database\Database;

/**
 * Widens `document_sources.record_type` from VARCHAR(64) to VARCHAR(160)
 * (WHIT-742).
 *
 * A workspace plugin's record type is `<plugin name>.<type>`, and the manifest
 * allows 64 characters for each, so a valid manifest can name a record type of
 * up to 129 characters. At 64 a template for it couldn't be saved, for a reason
 * its author can't see. 160 leaves room, matching `documents.origin_key`.
 *
 * PostgreSQL only: SQLite doesn't enforce VARCHAR length, so there it is a
 * no-op. `down()` restores VARCHAR(64), safe only while no stored type is
 * longer; it is for the migration-cycle rollback.
 */
class WidenDocumentSourceRecordType
{
    public static function up(Database $db): void
    {
        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }

        $db->exec('ALTER TABLE document_sources ALTER COLUMN record_type TYPE VARCHAR(160)');
    }

    public static function down(Database $db): void
    {
        if ($db->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'pgsql') {
            return;
        }

        $db->exec('ALTER TABLE document_sources ALTER COLUMN record_type TYPE VARCHAR(64)');
    }
}
