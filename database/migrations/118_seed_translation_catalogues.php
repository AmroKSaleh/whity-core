<?php

declare(strict_types=1);

namespace Database\Migrations;

use Throwable;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationSync;
use Whity\Database\Database;

/**
 * SeedTranslationCatalogues — put the committed catalogues into the database, in
 * every language that has one.
 *
 * THE GAP THIS CLOSES
 * -------------------
 * Migration 091 seeded 60 strings of the `auth` domain in English and Arabic,
 * and described itself as the template every later screen would follow. Nothing
 * followed it. The reason is in {@see \Whity\Cli\Commands\I18nCommand}: a
 * numbered migration per screen would have every agent colliding on the next
 * number, so `i18n:sync` became the seeding route instead — correctly, for
 * English.
 *
 * But `i18n:sync` is a command a human runs, and nothing runs it. So a fresh
 * install finished migrating with 60 Arabic rows in it, and an operator who
 * chose Arabic got a sign-in screen in Arabic and an entire product in English.
 * Not because the translations were bad, but because there was no point at which
 * anything put them in the database. Six of the seven domains had no Arabic at
 * all, and the seventh had 18% of one.
 *
 * `migrate run` is the one step every install performs, so this is where the
 * strings have to enter. It is deliberately NOT a replacement for `i18n:sync`:
 * that command still exists, still runs against an already-deployed database,
 * and is still how a catalogue added after this migration ran gets in.
 *
 * WHY IT READS FILES INSTEAD OF HOLDING AN ARRAY
 * ---------------------------------------------
 * 2,850 keys in two languages is 5,700 array entries, which is not a file anyone
 * reviews, and which would be a verbatim copy of `database/i18n/` that could
 * drift from it silently. `database/` ships inside the release image (`web/`
 * does not — see {@see TranslationCatalog}), so the files are readable from
 * exactly where this runs.
 *
 * INSERT-ONLY, LIKE EVERYTHING ELSE THAT TOUCHES THIS TABLE
 * --------------------------------------------------------
 * {@see TranslationSync} contains no UPDATE and no DELETE, and a test asserts
 * that about its source text. So this migration cannot overwrite a string a
 * translator edited in the console, and running it after 091 leaves 091's 60
 * rows exactly as they are. That is also why `down()` is careful: see below.
 */
class SeedTranslationCatalogues
{
    public static function up(Database $db): void
    {
        $baseDir = dirname(__DIR__, 2);
        $catalog = new TranslationCatalog($baseDir);

        $source = $catalog->read();
        if ($source === []) {
            // No catalogue to seed from. This is not a failure: a checkout with
            // an empty database/i18n is a legitimate state (nobody has run
            // `i18n:extract` yet), and failing the migration would make the
            // whole install unbootable over missing copy.
            return;
        }

        $pdo = $db->getPdo();
        $sync = new TranslationSync($pdo);

        // English first, so a language that is somehow missing its own row for a
        // key still has the source to fall back to in the console.
        self::seed($sync, $pdo, TranslationCatalog::SOURCE_LANGUAGE, $source);

        foreach ($catalog->localeCodes() as $code) {
            $translated = $catalog->readLocale($code);
            if ($translated !== []) {
                self::seed($sync, $pdo, $code, $translated);
            }
        }
    }

    /**
     * Seed one language, skipping it entirely if the `languages` table has no
     * row for it.
     *
     * A catalogue directory can legitimately exist for a language this
     * deployment has not installed — someone committed `de/` before anybody
     * added German. {@see TranslationSync::sync()} throws on an unknown code,
     * which is right for a CLI invocation a human typed and wrong here: a
     * migration that aborts the whole install over a directory name would be a
     * far worse failure than the one it prevents.
     *
     * @param array<string, array<string, string>> $catalog
     */
    private static function seed(TranslationSync $sync, \PDO $pdo, string $code, array $catalog): void
    {
        $statement = $pdo->prepare('SELECT id FROM languages WHERE code = :code');
        $statement->execute([':code' => $code]);
        if ($statement->fetchColumn() === false) {
            return;
        }

        try {
            $sync->sync($catalog, $code);
        } catch (Throwable) {
            // A single language failing to seed must not take the install with
            // it. The strings are still in the committed catalogue, and
            // `whity-cli i18n:sync --all` puts them in whenever the cause is
            // fixed.
        }
    }

    public static function down(Database $db): void
    {
        // Deliberately does nothing, and this is the one decision in the file
        // worth arguing about.
        //
        // The obvious down() deletes every system-default row in the domains
        // this seeded. That would also delete every row 091 seeded, every row
        // `i18n:sync` inserted, and — the part that matters — every English
        // string an administrator has since EDITED in /admin/translations, since
        // an edited row is indistinguishable from a seeded one by then.
        //
        // A rollback is supposed to undo a schema change. This migration makes
        // no schema change: it inserts reference data that other things
        // accumulate on top of. Reversing it destroys work it did not create,
        // so it does not reverse. Re-running up() is idempotent, which is the
        // property that actually matters here.
    }
}
