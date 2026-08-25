<?php

declare(strict_types=1);

namespace Database\Migrations;

use Throwable;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationSync;
use Whity\Database\Database;

/**
 * AddTranslationSourceProvenance (#1057) — which rows the catalogue OWNS.
 *
 * THE DEFECT
 * ----------
 * `i18n:sync` inserted new keys and left existing rows alone, and the runtime
 * prefers a stored row over the English fallback compiled into the call site
 * (see `packages/features/src/i18n/useTranslation.ts`). So a CORRECTION to the
 * English text of an existing key never reached an install that had already
 * been seeded. New keys arrived; corrections did not, permanently and silently,
 * while the source file went on looking authoritative to everyone reading it.
 *
 * That is not a cosmetic gap. Several of the sentences frozen this way were
 * FALSE: the public QR verification page told an unauthenticated reader that
 * `branding.siteName` — the PLATFORM's name — had issued the document, so it
 * named the wrong organisation, and the same sentence rendered on a REFUSAL
 * page asserting a confirmation that had not happened (#1051).
 *
 * WHY THE OLD BEHAVIOUR EXISTED, AND WHAT IT WAS ACTUALLY PROTECTING
 * ------------------------------------------------------------------
 * `TranslationSync` was insert-only on purpose, and the purpose is real: a
 * translator's finished Arabic, and an administrator's edited English, both
 * live in rows the sync can reach, and losing them is silent and unrecoverable.
 *
 * But it was NOT protecting tenant overrides, which is the reason usually
 * given. A tenant override is a SEPARATE ROW carrying `tenant_id = N`; the sync
 * only ever writes `tenant_id IS NULL`; and
 * {@see \Whity\Api\TranslationsApiHandler::writeAccessFor()} forbids a regular
 * tenant from writing a system-default row at all. Tenant wording was out of
 * reach before this migration and is out of reach after it, by the tenant
 * predicate alone. What insert-only really protected was human edits to the
 * SYSTEM-DEFAULT rows — in English, and in every translated language seeded
 * from `database/i18n/<code>/`.
 *
 * So the question was never "refresh or protect". It was whether core can tell
 * a row it seeded from a row a human has since written. Migration 121's own
 * `down()` names the missing fact exactly — "an edited row is indistinguishable
 * from a seeded one by then". This column is that fact.
 *
 * THE COLUMN, AND WHY IT DEFAULTS TO FALSE
 * -----------------------------------------
 * `source_managed` is true only while a row still says what the committed
 * catalogue says it should. {@see TranslationSync} is the ONLY statement in the
 * codebase that sets it true; {@see \Whity\Core\i18n\TranslationRepository}
 * clears it in the same statement that writes a human's new text, so saving a
 * string in /admin/translations is the moment the row stops belonging to the
 * catalogue.
 *
 * The default is FALSE, deliberately, and it is the safety property of the
 * whole design: a row written by any path that has never heard of this column —
 * a plugin, a backend template, a future writer — is HANDS OFF until something
 * explicitly claims it. The two mistakes are not symmetric. A wrong "managed"
 * verdict silently reverts a human's work; a wrong "not managed" verdict just
 * leaves the status quo, where a correction does not arrive. The default has to
 * fail in the second direction.
 *
 * This mirrors `is_system` on the designer tables (migration 059) rather than
 * reusing it: `is_system` answers "is this a system row", which every row here
 * already is, and `src/OpenAPI/CoreApiSchemas.php` has already had to record
 * that `is_system` cannot be stretched to answer a second question.
 *
 * BACKFILL — EXACT, NOT A GUESS
 * ------------------------------
 * Existing installs have rows with no provenance, and they are the population
 * this defect hurts most, so getting the backfill right IS the migration.
 *
 * `updated_at > created_at` is precisely "a human PATCHed this row", audited
 * rather than assumed: the only two `UPDATE translations` statements in the
 * entire repository are in `TranslationRepository::update()`, reachable only
 * from `TranslationsApiHandler::update()` behind `translations:manage`; and
 * every INSERT path (this file, 091, 121, the sync, the repository's create)
 * writes `created_at` and `updated_at` from the SAME `NOW()`. So a row whose
 * timestamps still match has never been written to since it was inserted.
 *
 * The backfill is scoped to `tenant_id IS NULL` as well. A tenant override the
 * console created also has matching timestamps, and while the sync's tenant
 * predicate means marking it would be harmless today, it would be a false
 * statement on the row — and the next reader of this column should not have to
 * know about a second guard for it to be true.
 *
 * WHY THIS MIGRATION ALSO RE-SEEDS
 * ---------------------------------
 * Adding the column fixes nothing by itself. Migration 121 put the catalogues
 * in, and a recorded migration never runs again, so on every existing database
 * the corrected strings would sit in the image unread until an operator
 * happened to type `i18n:sync`. Migration 121's docblock makes this exact point
 * about 091 — "`i18n:sync` is a command a human runs, and nothing runs it" —
 * and `migrate run` is still the one step every install performs. So the seed
 * runs here too, now convergent rather than insert-only, and the corrections
 * land on the same command that installs the column.
 *
 * Residual, and worth stating plainly: the NEXT copy correction after this ships
 * still needs `i18n:sync` or another migration, because nothing re-runs a
 * recorded one. Making the catalogue seed a repeatable post-migrate step is the
 * real answer and is a change to the migration runner, not to this file.
 *
 * Idempotent (IF NOT EXISTS; the backfill only ever sets true, and only on rows
 * still at the default) and reversible via down().
 */
final class AddTranslationSourceProvenance
{
    public static function up(Database $db): void
    {
        // Additive, backward-compatible, and NOT NULL DEFAULT FALSE so every
        // existing row gets a defined value without a table rewrite deciding
        // anything. See the class docblock for why FALSE is the safe default.
        $db->exec(
            'ALTER TABLE translations
                 ADD COLUMN IF NOT EXISTS source_managed BOOLEAN NOT NULL DEFAULT FALSE'
        );

        // Backfill: a system-default row nobody has written to since it was
        // inserted is still the catalogue's. See the class docblock for the
        // audit behind `updated_at <= created_at`.
        //
        // Only ever sets TRUE, and only on rows still at the default, so a
        // re-run cannot un-claim a row the sync has since refreshed (that
        // refresh moves `updated_at` past `created_at` while keeping the flag).
        $db->exec(
            'UPDATE translations
                SET source_managed = TRUE
              WHERE source_managed = FALSE
                AND tenant_id IS NULL
                AND updated_at <= created_at'
        );

        self::reseed($db);
    }

    /**
     * Put the committed catalogues through the now-convergent sync, so the
     * corrections in the image reach a database that was seeded before them.
     *
     * Deliberately a copy of migration 121's structure rather than a call into
     * it: a migration is a record of what ran at a point in time, and one
     * migration invoking another's `up()` makes both files' behaviour depend on
     * whichever was edited last.
     */
    private static function reseed(Database $db): void
    {
        $baseDir = dirname(__DIR__, 2);
        $catalog = new TranslationCatalog($baseDir);

        $source = $catalog->read();
        if ($source === []) {
            // A checkout with an empty database/i18n is legitimate (nobody has
            // run `i18n:extract` yet). The column is installed either way, and
            // failing here would make the install unbootable over missing copy.
            return;
        }

        $pdo = $db->getPdo();
        $sync = new TranslationSync($pdo);

        // One transaction for the whole seed, for migration 121's reasons: per-key
        // NOT EXISTS guards mean thousands of statements, and in autocommit that
        // was measured at seven and a half minutes of `migrate run`.
        $owned = !$pdo->inTransaction();
        if ($owned) {
            $pdo->beginTransaction();
        }

        try {
            self::seed($sync, $pdo, TranslationCatalog::SOURCE_LANGUAGE, $source);

            foreach ($catalog->localeCodes() as $code) {
                $translated = $catalog->readLocale($code);
                if ($translated !== []) {
                    self::seed($sync, $pdo, $code, $translated);
                }
            }

            if ($owned) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($owned && $pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        }
    }

    /**
     * Seed one language, skipping it entirely if `languages` has no row for it.
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
            // One language failing to seed must not take the install with it.
            // The strings are still in the committed catalogue, and
            // `whity-cli i18n:sync --all` puts them in once the cause is fixed.
        }
    }

    public static function down(Database $db): void
    {
        // Drops the column and nothing else. The re-seed in up() is not
        // reversed, for migration 121's reason: reversing an insert of
        // reference data destroys work it did not create. Dropping the column
        // returns the table to its previous shape, and the sync falls back to
        // insert-only behaviour because no row can be claimed any more.
        $db->exec('ALTER TABLE translations DROP COLUMN IF EXISTS source_managed');
    }
}
