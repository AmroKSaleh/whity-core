<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * A document names its theme, and a published version keeps the theme it was
 * published with (editor M4d; E.44, DOCUMENT-THEMES.md 4.2 and 4.3).
 *
 * - `document_sources.theme`: the draft's `{ ref, overrides }`, saved with the
 *   draft (`PUT …/draft`), so it rides the draft's ETag and is undoable.
 * - `document_source_versions.theme`: the same, copied at publish.
 * - `document_source_versions.theme_resolved`: the full resolved theme at
 *   publish. Printing a published version, issuing from it and correcting an
 *   issued document read this and never resolve again, so a theme change
 *   reaches a template only when a person republishes it.
 *
 * NULL means "no theme named": the workspace default applies, and a version
 * published before this migration prints with today's resolution. Additive,
 * idempotent and reversible.
 */
class AddThemeToDocumentSources
{
    public static function up(Database $db): void
    {
        $db->exec('ALTER TABLE document_sources ADD COLUMN IF NOT EXISTS theme JSONB');
        $db->exec('ALTER TABLE document_source_versions ADD COLUMN IF NOT EXISTS theme JSONB');
        $db->exec('ALTER TABLE document_source_versions ADD COLUMN IF NOT EXISTS theme_resolved JSONB');
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE document_source_versions DROP COLUMN IF EXISTS theme_resolved');
        $db->exec('ALTER TABLE document_source_versions DROP COLUMN IF EXISTS theme');
        $db->exec('ALTER TABLE document_sources DROP COLUMN IF EXISTS theme');
    }
}
