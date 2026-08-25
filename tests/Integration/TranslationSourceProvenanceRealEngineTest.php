<?php

declare(strict_types=1);

namespace Tests\Integration;

use Database\Migrations\AddTranslationSourceProvenance;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\i18n\TranslationSync;
use Whity\Database\Database;

/**
 * Real-engine tests for `translations.source_managed` (#1057, migration 124) —
 * the fact that lets a deploy correct a stale string without reverting a human's.
 *
 * WHAT THE COLUMN IS FOR
 * ----------------------
 * `i18n:sync` inserted new keys and never rewrote existing rows, and the runtime
 * prefers a stored row over the English compiled into the call site. So a
 * CORRECTION to an existing key never reached an install that had already been
 * seeded: new keys arrived, fixes did not, permanently and silently, while the
 * source file went on looking authoritative to everyone reading the repo.
 *
 * That was tolerated because nothing could tell a row the sync seeded from a row
 * a human wrote — migration 121's own `down()` says so in as many words: "an
 * edited row is indistinguishable from a seeded one by then". This column is
 * that distinction, and these tests are about whether it is drawn correctly on a
 * database that already exists.
 *
 * WHY THIS LIVES IN tests/Integration
 * -----------------------------------
 * So it runs on BOTH engines, following {@see AuthMethodRealEngineTest} and for
 * the same reason. Every part of this migration is somewhere SQLite and
 * PostgreSQL can disagree: a `NOT NULL BOOLEAN DEFAULT FALSE` added to a
 * populated table, `TRUE`/`FALSE` literals on an engine with no native boolean
 * type, and a backfill that compares two timestamp columns which one engine
 * stores as `TIMESTAMP` and the other as `TEXT`. A SQLite-only green would prove
 * nothing about the deployments that actually have the frozen copy on them.
 *
 * THE BACKFILL IS THE PART THAT MATTERS
 * --------------------------------------
 * A fresh install is easy — every row is written by a sync that knows about the
 * column. The installs this defect actually hurts are the old ones, and for
 * those the provenance has to be RECONSTRUCTED from evidence already on the row.
 * `updated_at > created_at` is exactly "a human PATCHed this", audited rather
 * than assumed: the only two `UPDATE translations` statements in the repository
 * are in {@see TranslationRepository::update()}, reachable only from the
 * `/admin/translations` PATCH endpoint, and every INSERT path writes both
 * timestamps from the same `NOW()`.
 *
 * Every planted row here is given a deliberately WRONG value first, so no
 * assertion can be satisfied by the column default — a backfill that silently
 * did nothing would otherwise pass most of this file.
 */
final class TranslationSourceProvenanceRealEngineTest extends TestCase
{
    /** Distinct, ordered, and far enough apart that no clock resolution can blur them. */
    private const SEEDED_AT = '2020-01-01 00:00:00';
    private const EDITED_AT = '2021-06-15 12:30:00';

    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
    }

    // ==================== the backfill ====================

    /**
     * Regression: THE question this migration exists to answer, asked of a
     * database that predates it.
     *
     * Four rows, covering the whole matrix: an untouched system default (the
     * catalogue's), one a human has since edited (theirs), a tenant override
     * (never the catalogue's, whatever its timestamps say), and an untouched
     * system default in a translated language (also the catalogue's — the freeze
     * applies to `database/i18n/ar/` exactly as it does to English).
     *
     * All four are forced to the WRONG value before `up()` runs, so a backfill
     * that did nothing at all would fail on every one of them rather than
     * passing on the three the default happens to suit.
     */
    public function testTheBackfillReconstructsProvenanceForRowsThatPredateTheColumn(): void
    {
        $untouched = $this->plantRow('en', 'probe', 'greeting.hello', 'Hello', null);
        $edited = $this->plantRow('en', 'probe', 'greeting.bye', 'Farewell', null, edited: true);
        $override = $this->plantRow('en', 'probe', 'greeting.hello', 'Tenant wording', 5);
        $arabic = $this->plantRow('ar', 'probe', 'greeting.hello', 'مرحبا', null);

        // The pre-124 state, as far as this column is concerned: nothing carries
        // the answer the backfill is supposed to produce.
        $this->pdo->exec('UPDATE translations SET source_managed = FALSE');

        AddTranslationSourceProvenance::up($this->database());

        self::assertTrue(
            $this->isSourceManaged($untouched),
            'A system default nobody has written to since it was inserted is still the catalogue\'s.'
        );
        self::assertFalse(
            $this->isSourceManaged($edited),
            'updated_at later than created_at is exactly "a human saved this in the console".'
        );
        self::assertFalse(
            $this->isSourceManaged($override),
            'A tenant override is never the catalogue\'s, however its timestamps read.'
        );
        self::assertTrue(
            $this->isSourceManaged($arabic),
            'A committed translation freezes the same way English does, so it is claimed the same way.'
        );
    }

    /**
     * Regression: the backfill must never UN-claim a row.
     *
     * After a refresh the sync leaves `updated_at` later than `created_at` — the
     * row genuinely changed — while the row is still the catalogue's. A backfill
     * expressed as "set the flag to whether the timestamps match" rather than
     * "claim rows whose timestamps match" would quietly release every row the
     * sync had ever corrected, and the NEXT deploy would then decline to correct
     * it again. The defect would come back looking like a different bug.
     */
    public function testASecondRunDoesNotReleaseARowTheSyncHasSinceRefreshed(): void
    {
        $db = $this->database();
        $sync = new TranslationSync($this->pdo);

        $sync->sync(['probe' => ['greeting.hello' => 'Hello']]);
        $sync->sync(['probe' => ['greeting.hello' => 'Good morning']]);

        $id = $this->idOf('en', 'probe', 'greeting.hello', null);
        self::assertTrue($this->isSourceManaged($id), 'precondition: the sync refreshed and kept its claim');
        $this->pdo->exec("UPDATE translations SET updated_at = '" . self::EDITED_AT . "' WHERE id = {$id}");

        AddTranslationSourceProvenance::up($db);

        self::assertTrue(
            $this->isSourceManaged($id),
            'A re-run only ever claims rows; it must not release one the sync still owns.'
        );
    }

    /**
     * up() twice, then down() and up() again, on whichever engine is running.
     *
     * Re-running migrations is a real operation here — `scripts/ci-local.sh pg`
     * runs `migrate run` twice on purpose — and this migration both adds a
     * column and performs a seed, so a naive second run has two ways to fail.
     */
    public function testTheMigrationIsIdempotentAndReversible(): void
    {
        $db = $this->database();
        $id = $this->plantRow('en', 'probe', 'greeting.hello', 'Hello', null);
        $this->pdo->exec('UPDATE translations SET source_managed = FALSE');

        AddTranslationSourceProvenance::up($db);
        AddTranslationSourceProvenance::up($db);
        self::assertTrue($this->isSourceManaged($id), 'a second up() must be a no-op, not a failure');

        AddTranslationSourceProvenance::down($db);
        self::assertFalse($this->hasSourceManagedColumn(), 'down() must drop the column');

        AddTranslationSourceProvenance::up($db);
        self::assertTrue(
            $this->hasSourceManagedColumn(),
            'up() after down() must reinstate the column from data it never destroyed'
        );
    }

    /**
     * Regression: `down()` must not take the strings with it.
     *
     * Dropping the column returns the sync to insert-only behaviour, which is a
     * degradation and not a disaster. Deleting the rows would destroy every
     * translation on the install, so the rollback path is checked to leave them
     * alone — this is the direction that cannot be undone.
     */
    public function testDownDropsTheColumnAndKeepsEveryString(): void
    {
        $this->plantRow('en', 'probe', 'greeting.hello', 'Hello', null);
        $before = $this->scalar('SELECT COUNT(*) FROM translations');

        AddTranslationSourceProvenance::down($this->database());

        self::assertSame(
            $before,
            $this->scalar('SELECT COUNT(*) FROM translations'),
            'A rollback of a provenance column must not delete a single translation.'
        );
        self::assertSame(
            'Hello',
            $this->scalar("SELECT translation FROM translations WHERE domain = 'probe' AND key = 'greeting.hello'")
        );
    }

    /**
     * Regression: the sync must RUN, and run safely, against a table that has
     * no provenance column at all.
     *
     * This is not a hypothetical robustness test — it is a fresh-install
     * blocker that a SQLite-only suite hides completely. Migration 121 seeds the
     * catalogues and runs BEFORE 124 adds the column, so on every new install
     * the sync executes at least once against a pre-124 table. Referencing
     * `source_managed` there raises `42703`, and on PostgreSQL that aborts the
     * whole seeding transaction: migration 121 fails, `migrate run` stops, and
     * the install never completes. SQLite hides it because migration 121
     * swallows the exception and the transaction survives, so the seed simply
     * does nothing and 124 quietly cleans up afterwards.
     *
     * The required behaviour without the column is exactly the pre-#1057
     * behaviour: insert what is missing, rewrite nothing, and report every
     * difference as divergent — because with no provenance there is no way to
     * know whose text a row holds.
     */
    public function testTheSyncFallsBackToInsertOnlyWhenTheColumnIsNotThereYet(): void
    {
        $this->plantRow('en', 'probe', 'greeting.hello', 'Seeded earlier', null);

        // Return the table to its migration-121 shape.
        AddTranslationSourceProvenance::down($this->database());
        self::assertFalse($this->hasSourceManagedColumn(), 'precondition: the column is gone');

        $report = (new TranslationSync($this->pdo))->sync([
            'probe' => ['greeting.hello' => 'Corrected wording', 'greeting.bye' => 'Goodbye'],
        ]);

        self::assertSame(
            [['domain' => 'probe', 'key' => 'greeting.bye', 'text' => 'Goodbye']],
            $report['inserted'],
            'A missing key is still seeded — this is the path every fresh install takes.'
        );
        self::assertSame([], $report['updated'], 'With no provenance, nothing may be rewritten.');
        self::assertCount(1, $report['divergent'], 'The difference is reported instead.');
        self::assertSame(
            'Seeded earlier',
            $this->scalar(
                "SELECT translation FROM translations WHERE domain = 'probe' AND key = 'greeting.hello'"
            ),
            'An existing row is left exactly as it was.'
        );
    }

    // ==================== who may claim a row ====================

    /**
     * Regression: saving a string in /admin/translations is what releases the
     * catalogue's claim on it, and it happens in the same statement that writes
     * the text.
     *
     * If it were a second statement, a failure between the two would leave a row
     * carrying a human's words and the catalogue's claim on them — which the
     * next sync would silently revert, in exactly the way this column exists to
     * prevent. Asserted through the repository method the PATCH endpoint calls,
     * because a test that set the column by hand would still pass if the
     * endpoint forgot to clear it.
     */
    public function testSavingAStringInTheConsoleReleasesTheCatalogueClaim(): void
    {
        $sync = new TranslationSync($this->pdo);
        $sync->sync(['probe' => ['greeting.hello' => 'Hello']]);

        $id = $this->idOf('en', 'probe', 'greeting.hello', null);
        self::assertTrue($this->isSourceManaged($id), 'the sync claims what it writes');

        self::assertTrue((new TranslationRepository($this->pdo))->update($id, 'Welcome back', null));

        self::assertFalse(
            $this->isSourceManaged($id),
            'The moment a person saves a string, the row is theirs and no deploy may rewrite it.'
        );
    }

    /**
     * Regression: a key invented in the console belongs to the person who
     * invented it, from the moment it exists.
     *
     * It has no source text to be refreshed FROM, so today the sync would never
     * visit it. But if a key of that name later appears in the catalogue, the
     * flag is what stops the two colliding silently — and the column default
     * plus this explicit FALSE are the only reasons the answer does not depend
     * on that coincidence.
     */
    public function testAKeyCreatedInTheConsoleIsNeverTheCataloguesToRewrite(): void
    {
        $language = $this->languageId('en');
        $created = (new TranslationRepository($this->pdo))
            ->create($language, 'probe', 'operator.note', 'Written by hand', null);

        self::assertNotNull($created);
        self::assertFalse($created->sourceManaged);
        self::assertFalse($this->isSourceManaged($created->id));
    }

    /**
     * Regression: the sync is the ONLY writer that may claim a row, and it
     * claims exactly what it inserts.
     *
     * Pinned in both directions at once — the row it wrote is claimed, and a row
     * that was already there when it ran is not — because "only the sync claims"
     * is the premise the entire refresh rests on.
     */
    public function testOnlyTheSyncClaimsRowsAndOnlyTheOnesItWrote(): void
    {
        $preexisting = $this->plantRow('en', 'probe', 'greeting.bye', 'Goodbye', null);
        $this->pdo->exec("UPDATE translations SET source_managed = FALSE WHERE id = {$preexisting}");

        (new TranslationSync($this->pdo))->sync([
            'probe' => ['greeting.hello' => 'Hello', 'greeting.bye' => 'Goodbye'],
        ]);

        self::assertTrue(
            $this->isSourceManaged($this->idOf('en', 'probe', 'greeting.hello', null)),
            'The sync claims the row it inserted.'
        );
        self::assertFalse(
            $this->isSourceManaged($preexisting),
            'A row that was already there is not claimed by a sync that merely agreed with it.'
        );
    }

    // ==================== helpers ====================

    private function database(): Database
    {
        $pdo = $this->pdo;

        return Database::withFactory(static fn (): PDO => $pdo);
    }

    /**
     * Insert a row the way a pre-124 install has them: explicit timestamps, and
     * `updated_at` moved past `created_at` only when a human is meant to have
     * edited it.
     */
    private function plantRow(
        string $languageCode,
        string $domain,
        string $key,
        string $translation,
        ?int $tenantId,
        bool $edited = false,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO translations (language_id, domain, key, translation, tenant_id, created_at, updated_at)
             VALUES (:language_id, :domain, :key, :translation, :tenant_id, :created_at, :updated_at)'
        );
        $statement->execute([
            ':language_id' => $this->languageId($languageCode),
            ':domain' => $domain,
            ':key' => $key,
            ':translation' => $translation,
            ':tenant_id' => $tenantId,
            ':created_at' => self::SEEDED_AT,
            ':updated_at' => $edited ? self::EDITED_AT : self::SEEDED_AT,
        ]);

        return $this->idOf($languageCode, $domain, $key, $tenantId);
    }

    private function idOf(string $languageCode, string $domain, string $key, ?int $tenantId): int
    {
        $scope = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant_id';
        $statement = $this->pdo->prepare(
            "SELECT id FROM translations
             WHERE language_id = :language_id AND domain = :domain AND key = :key AND {$scope}"
        );

        $params = [
            ':language_id' => $this->languageId($languageCode),
            ':domain' => $domain,
            ':key' => $key,
        ];
        if ($tenantId !== null) {
            $params[':tenant_id'] = $tenantId;
        }
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }

    /**
     * Read the flag through the model, so the engines' different BOOLEAN
     * representations are resolved the one way production resolves them.
     */
    private function isSourceManaged(int $id): bool
    {
        $translation = (new TranslationRepository($this->pdo))->findById($id);
        self::assertNotNull($translation, "No translation row with id {$id}.");

        return $translation->sourceManaged;
    }

    /**
     * One scalar, as a string. Via prepare() rather than query() so the result
     * is a statement and not `PDOStatement|false` — the engines return the
     * counts and the text with different PHP types, and comparing them as
     * strings is the one form that means the same thing on both.
     */
    private function scalar(string $sql): string
    {
        $statement = $this->pdo->prepare($sql);
        self::assertNotFalse($statement, "Could not prepare: {$sql}");
        $statement->execute();

        return (string) $statement->fetchColumn();
    }

    private function languageId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM languages WHERE code = :code');
        $statement->execute([':code' => $code]);

        return (int) $statement->fetchColumn();
    }

    private function hasSourceManagedColumn(): bool
    {
        try {
            $this->pdo->query('SELECT source_managed FROM translations LIMIT 1');

            return true;
        } catch (PDOException) {
            return false;
        }
    }

    /** Guards the assumption every test above rests on. */
    public function testTheSourceLanguageIsEnglish(): void
    {
        self::assertSame('en', TranslationCatalog::SOURCE_LANGUAGE);
    }
}
