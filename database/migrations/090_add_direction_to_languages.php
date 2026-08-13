<?php

declare(strict_types=1);

namespace Database\Migrations;

use Whity\Database\Database;

/**
 * AddDirectionToLanguages migration
 *
 * Adds `languages.direction` — the writing direction ('ltr' or 'rtl') that the
 * interface inherits when that language is selected.
 *
 * Why a COLUMN and not a code branch
 * ----------------------------------
 * Direction is a PROPERTY OF THE LANGUAGE, not a separate preference the user
 * sets alongside it. Storing it on the row means adding Hebrew, Farsi, Urdu or
 * Divehi later is a DATA change (one INSERT through the admin languages API),
 * never a code change — there is deliberately no `code === 'ar'` test anywhere
 * in the stack. This mirrors the project rule against hardcoded tunables: the
 * value lives on the record, and the code only ever reads it.
 *
 *  - VARCHAR(3) NOT NULL DEFAULT 'ltr': every language that predates this
 *    column becomes left-to-right, which is correct for the only pre-existing
 *    non-Arabic seed ('en') and the safe assumption for any other.
 *  - A CHECK constraint pins the value to the two directions CSS/HTML `dir`
 *    accepts, so a bad write fails at the database rather than silently
 *    producing an unrenderable `dir` attribute. (`auto` is deliberately NOT
 *    allowed: it resolves per text node, which is the wrong granularity for a
 *    whole interface.)
 *  - `languages` is a GLOBAL table (no tenant_id at all — see
 *    LanguagesApiHandler's docblock), so this column needs no tenant predicate.
 *
 * The base seed (migration 082) predates this column, so its two rows are
 * corrected here: 'ar' is right-to-left, 'en' keeps the 'ltr' default.
 *
 * Idempotent (IF NOT EXISTS + an UPDATE that is safe to replay) and fully
 * reversible via down().
 */
class AddDirectionToLanguages
{
    public static function up(Database $db): void
    {
        $db->exec(
            "ALTER TABLE languages
             ADD COLUMN IF NOT EXISTS direction VARCHAR(3) NOT NULL DEFAULT 'ltr'
             CHECK (direction IN ('ltr', 'rtl'))"
        );

        // Correct the base seed: Arabic is right-to-left. English keeps the
        // 'ltr' default, so it needs no statement of its own.
        $db->exec("UPDATE languages SET direction = 'rtl', updated_at = NOW() WHERE code = 'ar'");
    }

    public static function down(Database $db): void
    {
        $db->exec('ALTER TABLE languages DROP COLUMN IF EXISTS direction');
    }
}
