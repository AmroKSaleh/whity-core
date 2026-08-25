<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\i18n\TranslationCatalog;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\i18n\TranslationSync;

/**
 * Seeding the extracted English catalogue into `translations` — and, far more
 * importantly, never taking anything out of it.
 *
 * WHY THESE TESTS RUN AGAINST THE REAL SCHEMA
 * -------------------------------------------
 * The whole behaviour under test is about ROWS: which ones exist, which ones are
 * left alone, and the NULL-tenant scoping that decides both. A mocked PDO would
 * assert that the class issued the SQL its author expected, which is the one
 * thing that is never in doubt. So the schema comes from the production
 * migrations ({@see SchemaFromMigrations}), NULL-vs-value tenant scoping behaves
 * as it will in production, and migration 091's already-seeded `auth` domain is
 * present exactly as it is on a real install.
 *
 * THE PROPERTY THAT MATTERS MOST
 * ------------------------------
 * The sync INSERTS and does nothing else. Its failure mode is silent and
 * unrecoverable — a translator spends a week filling in Arabic, someone runs the
 * sync, and it is gone — so it is pinned twice over: behaviourally (an existing
 * row is byte-identical afterwards, in every language and every tenant scope)
 * and structurally (the class's own source contains no UPDATE and no DELETE).
 */
final class TranslationSyncTest extends TestCase
{
    /** A fixed timestamp, so "the row was not touched" is checkable to the byte. */
    private const STAMP = '2020-01-01 00:00:00';

    private PDO $pdo;

    private TranslationSync $sync;

    protected function setUp(): void
    {
        $this->pdo = SchemaFromMigrations::make();
        $this->sync = new TranslationSync($this->pdo);
    }

    /**
     * Regression: the sync seeds AGAINST an existing language row and refuses to
     * invent one, so the migrations have to have put `en` and `ar` there. If they
     * ever stop, every test below would fail on a RuntimeException rather than on
     * what it actually tests — and production would fail the same way.
     */
    public function testMigrationsSeedTheBaseLanguages(): void
    {
        self::assertGreaterThan(0, $this->languageId(TranslationCatalog::SOURCE_LANGUAGE));
        self::assertGreaterThan(0, $this->languageId('ar'));
        self::assertSame('en', TranslationCatalog::SOURCE_LANGUAGE);
    }

    // ─── Inserting ───────────────────────────────────────────────────────────

    /**
     * Regression: a key that never reaches a row is a key no translator can see,
     * so an extraction that stops at a file has delivered nothing. The rows must
     * be SYSTEM DEFAULTS (`tenant_id IS NULL`): a row written into a tenant would
     * be invisible to every other tenant and would shadow nothing.
     */
    public function testMissingKeysAreInsertedAsSystemDefaultsInEnglish(): void
    {
        $report = $this->sync->sync([
            'probe' => ['greeting.hello' => 'Hello', 'greeting.bye' => 'Goodbye'],
        ]);

        self::assertSame(
            [
                ['domain' => 'probe', 'key' => 'greeting.hello', 'text' => 'Hello'],
                ['domain' => 'probe', 'key' => 'greeting.bye', 'text' => 'Goodbye'],
            ],
            $report['inserted']
        );
        self::assertSame(0, $report['present']);
        self::assertSame([], $report['divergent']);
        self::assertFalse($report['dryRun']);
        self::assertSame('en', $report['language']['code']);

        $rows = $this->rowsInDomain('probe');
        self::assertCount(2, $rows);
        foreach ($rows as $row) {
            self::assertNull($row['tenant_id'], 'Extraction never writes into a tenant.');
            self::assertSame($this->languageId('en'), (int) $row['language_id']);
        }
        self::assertSame(
            ['greeting.bye' => 'Goodbye', 'greeting.hello' => 'Hello'],
            $this->systemDefaultsInDomain('probe')
        );
    }

    /**
     * Regression: the command is run on every deploy. If a replay duplicated the
     * catalogue, the translations console would fill with pairs of identical keys
     * and an admin editing one would leave the other in place. The NULL tenant_id
     * is precisely why the unique index cannot prevent this by itself — both
     * engines treat NULL as distinct from every other NULL — so the guard is the
     * NOT EXISTS, and it is what this pins.
     */
    public function testRunningTwiceInsertsNothingTheSecondTime(): void
    {
        $catalog = ['probe' => ['greeting.hello' => 'Hello', 'greeting.bye' => 'Goodbye']];

        $this->sync->sync($catalog);
        $afterFirst = $this->countRows();

        $second = $this->sync->sync($catalog);

        self::assertSame([], $second['inserted']);
        self::assertSame(2, $second['present']);
        self::assertSame($afterFirst, $this->countRows(), 'A replay must not add a single row.');
        self::assertCount(2, $this->rowsInDomain('probe'));
    }

    // ─── The property that matters most ──────────────────────────────────────

    /**
     * Regression: THE test this class exists for. A human has edited the English
     * wording in the console and a translator has finished the Arabic; both rows
     * predate this run. The sync must leave them BYTE-identical — same
     * translation, same updated_at — and report the English divergence rather
     * than "correcting" it.
     *
     * Overwriting here destroys work that exists nowhere else: the Arabic came
     * from a person, not from source, and no regeneration can bring it back. The
     * loss is also invisible — the command reports success, the screens still
     * render (in English), and nothing in any diff records what went missing.
     *
     * SINCE #1057 this also pins the DEFAULT of `source_managed`, and that is
     * why it still passes untouched after the sync learned to write. These rows
     * are inserted by a fixture that says nothing about provenance, so they take
     * the column's default of FALSE and are unreachable by the refresh. That is
     * the intended safety property: a row written by any path that has never
     * heard of the column — a fixture, a plugin, a future writer — is hands off
     * until something explicitly claims it. If the default were ever flipped to
     * TRUE, this test is what would notice.
     */
    public function testRowsNothingHasClaimedAreNeverOverwritten(): void
    {
        $this->insertRow('en', 'probe', 'greeting.hello', 'Hand-edited English', null);
        $this->insertRow('ar', 'probe', 'greeting.hello', 'مرحباً', null);

        $before = $this->rowsInDomain('probe');
        self::assertCount(2, $before);

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Source wording']]);

        $after = $this->rowsInDomain('probe');

        self::assertSame($before, $after, 'Not one byte of an existing row may change.');
        self::assertSame('Hand-edited English', $after[0]['translation']);
        self::assertSame(self::STAMP, $after[0]['updated_at']);
        self::assertSame('مرحباً', $after[1]['translation'], 'A finished Arabic translation is human work.');
        self::assertSame(self::STAMP, $after[1]['updated_at']);

        self::assertSame([], $report['inserted']);
        self::assertSame(
            [[
                'domain' => 'probe',
                'key' => 'greeting.hello',
                'database' => 'Hand-edited English',
                'source' => 'Source wording',
            ]],
            $report['divergent'],
            'The divergence is REPORTED so a person can decide, never silently resolved.'
        );
    }

    /**
     * Regression: a tenant override lives in the same table, distinguished only
     * by `tenant_id`. Two failures are possible and both are pinned: writing over
     * the tenant's wording (their customisation, silently reverted), and reading
     * the tenant's row as though it were the system default (which would suppress
     * the system-default insert and leave every OTHER tenant with no string).
     */
    public function testTenantOverrideIsUntouchedAndDoesNotBlockTheSystemDefault(): void
    {
        $this->insertRow('en', 'probe', 'greeting.hello', 'Tenant wording', 5);
        $before = $this->rowsInDomain('probe');

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Source wording']]);

        self::assertSame(
            [['domain' => 'probe', 'key' => 'greeting.hello', 'text' => 'Source wording']],
            $report['inserted'],
            'A tenant override is not a system default and must not suppress the insert.'
        );

        $rows = $this->rowsInDomain('probe');
        self::assertCount(2, $rows);

        $tenantRows = array_values(array_filter($rows, static fn (array $row): bool => $row['tenant_id'] !== null));
        self::assertSame($before, $tenantRows, 'The tenant\'s own wording is untouched.');

        self::assertSame(['greeting.hello' => 'Source wording'], $this->systemDefaultsInDomain('probe'));
    }

    /**
     * Regression: `--dry-run` is what an operator uses to see what a sync would do
     * to a production database before letting it. A dry run that wrote anything
     * would make the safety check the very thing that caused the change.
     */
    public function testDryRunReportsWithoutWriting(): void
    {
        $before = $this->countRows();

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Hello']], 'en', true);

        self::assertTrue($report['dryRun']);
        self::assertSame(
            [['domain' => 'probe', 'key' => 'greeting.hello', 'text' => 'Hello']],
            $report['inserted'],
            'A dry run still reports exactly what it would insert.'
        );
        self::assertSame($before, $this->countRows(), 'A dry run writes nothing.');
        self::assertSame([], $this->rowsInDomain('probe'));
    }

    // ─── Corrections arrive (#1057) ──────────────────────────────────────────

    /**
     * Regression: THE defect. A key is seeded, the English is later found to be
     * WRONG and corrected in source, and the correction must reach a database
     * that was seeded before it.
     *
     * It did not, and the consequence was not cosmetic: the public QR
     * verification page told an unauthenticated reader that the PLATFORM had
     * issued their document, naming the wrong organisation entirely (#1051).
     * The fix went into the repo. Every already-seeded install kept the false
     * sentence, because the runtime prefers the stored row over the English in
     * the call site and nothing ever rewrote the row.
     *
     * The two texts here differ in the way that matters — one names the wrong
     * party, the other names the right one — so a sync that silently did nothing
     * could not pass this by coincidence.
     */
    public function testACorrectionToAnExistingKeyReachesAnAlreadySeededRow(): void
    {
        $wrong = 'This page confirms only that Whity issued a document.';
        $right = 'This page confirms only that the issuing organisation issued a document.';

        $this->sync->sync(['probe' => ['verify.scope' => $wrong]]);
        self::assertSame(['verify.scope' => $wrong], $this->systemDefaultsInDomain('probe'));

        $report = $this->sync->sync(['probe' => ['verify.scope' => $right]]);

        self::assertSame(
            ['verify.scope' => $right],
            $this->systemDefaultsInDomain('probe'),
            'A correction in source must reach a row that was seeded before it.'
        );
        self::assertSame(
            [['domain' => 'probe', 'key' => 'verify.scope', 'from' => $wrong, 'to' => $right]],
            $report['updated']
        );
        self::assertSame([], $report['inserted'], 'The key already had a row; this is a refresh, not an insert.');
        self::assertSame([], $report['divergent'], 'Nobody edited this row, so there is nothing to report as divergent.');
    }

    /**
     * Regression: a refresh must not multiply rows or move a key between scopes.
     * The bug this guards is a "refresh" implemented as delete-then-insert,
     * which would take any translation of that key with it and hand the row a
     * new id that nothing else in the database is expecting.
     */
    public function testARefreshRewritesTheSameRowRatherThanReplacingIt(): void
    {
        $this->sync->sync(['probe' => ['greeting.hello' => 'Hello']]);
        $before = $this->rowsInDomain('probe');
        self::assertCount(1, $before);

        $this->sync->sync(['probe' => ['greeting.hello' => 'Good morning']]);
        $after = $this->rowsInDomain('probe');

        self::assertCount(1, $after, 'A refresh must not leave a second row behind.');
        self::assertSame($before[0]['id'], $after[0]['id'], 'The same row is rewritten, never replaced.');
        self::assertNull($after[0]['tenant_id'], 'It is still a system default.');
        self::assertSame('Good morning', $after[0]['translation']);
    }

    /**
     * Regression: a committed Arabic correction must reach a seeded row too.
     * `--language=ar` and migration 121 seed `database/i18n/ar/` exactly the way
     * English is seeded, so a mistranslation fixed in a committed file was
     * frozen on every existing install by the identical mechanism.
     */
    public function testACommittedArabicCorrectionReachesAnAlreadySeededRow(): void
    {
        $this->sync->sync(['probe' => ['greeting.hello' => 'مرحبا']], 'ar');

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'أهلاً وسهلاً']], 'ar');

        self::assertSame('ar', $report['language']['code']);
        self::assertSame(
            [['domain' => 'probe', 'key' => 'greeting.hello', 'from' => 'مرحبا', 'to' => 'أهلاً وسهلاً']],
            $report['updated']
        );
        self::assertSame(
            'أهلاً وسهلاً',
            $this->translationOf('ar', 'probe', 'greeting.hello'),
            'A corrected committed translation must reach the row, in any language.'
        );
    }

    /**
     * Regression: `--dry-run` is what an operator runs against production BEFORE
     * letting a deploy rewrite copy. Now that the sync can change a sentence a
     * user is already reading, a dry run that performed the refresh would make
     * the safety check the thing that caused the change.
     */
    public function testDryRunReportsARefreshWithoutPerformingIt(): void
    {
        $this->sync->sync(['probe' => ['greeting.hello' => 'Hello']]);

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Good morning']], 'en', true);

        self::assertTrue($report['dryRun']);
        self::assertSame(
            [['domain' => 'probe', 'key' => 'greeting.hello', 'from' => 'Hello', 'to' => 'Good morning']],
            $report['updated'],
            'A dry run still reports every sentence it would change.'
        );
        self::assertSame(
            ['greeting.hello' => 'Hello'],
            $this->systemDefaultsInDomain('probe'),
            'A dry run writes nothing.'
        );
    }

    // ─── Customisations survive (#1057) ──────────────────────────────────────

    /**
     * Regression: the other direction, and the one a fix that "just refreshes
     * everything" destroys. A string SAVED IN THE CONSOLE must survive every
     * later deploy, however much the source has moved on.
     *
     * The edit is made through the real write path — the repository method the
     * PATCH endpoint calls — rather than by planting a row with the flag already
     * cleared. That is deliberate: the claim being tested is that saving a
     * string is what releases the catalogue's claim on it, so the test has to go
     * through the thing that does the releasing. A test that set the column by
     * hand would still pass if the endpoint forgot to clear it, which is exactly
     * the regression that would matter.
     */
    public function testAStringSavedInTheConsoleSurvivesACorrection(): void
    {
        $this->sync->sync(['probe' => ['greeting.hello' => 'Hello']]);
        $seeded = $this->rowsInDomain('probe')[0];

        // What /admin/translations does when an administrator presses save.
        $repository = new TranslationRepository($this->pdo);
        self::assertTrue($repository->update((int) $seeded['id'], 'Welcome back', null));

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Good morning']]);

        self::assertSame(
            ['greeting.hello' => 'Welcome back'],
            $this->systemDefaultsInDomain('probe'),
            "A deploy must not revert a string an administrator saved."
        );
        self::assertSame([], $report['updated'], 'A human-authored row is not a refresh candidate.');
        self::assertSame(
            [[
                'domain' => 'probe',
                'key' => 'greeting.hello',
                'database' => 'Welcome back',
                'source' => 'Good morning',
            ]],
            $report['divergent'],
            'The divergence is REPORTED so a person can decide, never silently resolved.'
        );
    }

    /**
     * Regression: the same protection for a translator, which is the case the
     * insert-only rule was really written for. A finished Arabic string that
     * somebody improved in the console must survive a correction to the
     * committed Arabic file underneath it.
     *
     * This is strictly MORE protection than insert-only gave: it covers the
     * translator's edit even when the committed file for that key changes, which
     * is the situation that used to have no answer at all.
     */
    public function testATranslatorsEditSurvivesACorrectionToTheCommittedFile(): void
    {
        $this->sync->sync(['probe' => ['greeting.hello' => 'مرحبا']], 'ar');
        $seeded = $this->rowsInDomain('probe')[0];

        $repository = new TranslationRepository($this->pdo);
        self::assertTrue($repository->update((int) $seeded['id'], 'السلام عليكم', null));

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'أهلاً وسهلاً']], 'ar');

        self::assertSame(
            'السلام عليكم',
            $this->translationOf('ar', 'probe', 'greeting.hello'),
            "A translator's own wording is work that exists nowhere else."
        );
        self::assertSame([], $report['updated']);
        self::assertCount(1, $report['divergent']);
    }

    /**
     * Regression: a tenant's customised wording survives a refresh, and the run
     * REPORTS how many it stepped around.
     *
     * The protection itself is structural and predates #1057 — a tenant override
     * is a separate row carrying its tenant_id, and every statement in the sync
     * is scoped to `tenant_id IS NULL`. The count is what makes that checkable
     * by an operator running a deploy against a customised install, who
     * otherwise has no way to see that the number of rows left alone is the
     * number they expect.
     */
    public function testATenantOverrideSurvivesACorrectionAndIsCounted(): void
    {
        $this->sync->sync(['probe' => ['greeting.hello' => 'Hello']]);
        $this->insertRow('en', 'probe', 'greeting.hello', 'Tenant wording', 5);
        $this->insertRow('en', 'probe', 'greeting.hello', 'Other tenant wording', 9);

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Good morning']]);

        self::assertSame(
            'Tenant wording',
            $this->translationOf('en', 'probe', 'greeting.hello', 5),
            "A refresh must not reach into a tenant's own wording."
        );
        self::assertSame('Other tenant wording', $this->translationOf('en', 'probe', 'greeting.hello', 9));
        self::assertSame(
            ['rows' => 2, 'tenants' => 2],
            $report['overridden'],
            'The operator has to be able to see that two customised rows were stepped around.'
        );
        self::assertSame(
            ['greeting.hello' => 'Good morning'],
            $this->systemDefaultsInDomain('probe'),
            'The system default still refreshes; a tenant override shadows it, it does not freeze it.'
        );
    }

    /**
     * Regression: the reassurance count must not be padded. A tenant's own
     * invented key, or one in a domain this catalogue does not cover, was never
     * in the path of this run, and counting it would report protection that was
     * never exercised.
     */
    public function testTheOverrideCountIgnoresKeysThisRunNeverConsidered(): void
    {
        $this->insertRow('en', 'probe', 'greeting.hello', 'Tenant wording', 5);
        $this->insertRow('en', 'probe', 'tenant.invented', 'Their own key', 5);
        $this->insertRow('en', 'legacy', 'plugin.one', 'A plugin string', 5);

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Good morning']]);

        self::assertSame(
            ['rows' => 1, 'tenants' => 1],
            $report['overridden'],
            'Only overrides of keys this catalogue covers were stepped around.'
        );
    }

    // ─── Reporting, not deleting ─────────────────────────────────────────────

    /**
     * Regression: a key whose call site is gone is REPORTED and LEFT. It may
     * carry translations in languages nobody can regenerate, and it legitimately
     * outlives its last call site for the length of a refactor — so deleting on
     * the strength of a scan would make a rename indistinguishable from data
     * loss, in the direction that cannot be undone.
     */
    public function testDeadKeysAreReportedAndLeftInPlace(): void
    {
        $this->insertRow('en', 'probe', 'greeting.gone', 'Retired string', null);

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Hello']]);

        self::assertSame(
            [['domain' => 'probe', 'key' => 'greeting.gone', 'text' => 'Retired string']],
            $report['dead']
        );
        self::assertSame(
            1,
            $this->countRows('domain = :domain AND key = :key', [':domain' => 'probe', ':key' => 'greeting.gone']),
            'A dead key is reported for a person to remove through the console, never deleted here.'
        );
    }

    /**
     * Regression: a domain the frontend source never produced — seeded by a
     * plugin, a backend template, or by hand in the console — is not stale, and
     * an extraction run has no opinion about it. Folding those keys into `dead`
     * would put a plugin's entire string set on a list of things to delete.
     */
    public function testUnmanagedDomainsAreReportedAndTheirKeysAreNotDead(): void
    {
        $this->insertRow('en', 'legacy', 'plugin.one', 'One', null);
        $this->insertRow('en', 'legacy', 'plugin.two', 'Two', null);

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Hello']]);

        self::assertArrayHasKey('legacy', $report['unmanaged']);
        self::assertSame(2, $report['unmanaged']['legacy']);
        self::assertArrayHasKey(
            'auth',
            $report['unmanaged'],
            'Migration 091 seeds `auth` directly; a catalogue that does not cover it must say so.'
        );
        self::assertArrayNotHasKey('probe', $report['unmanaged'], 'A catalogued domain is managed by definition.');

        self::assertSame(
            [],
            array_values(array_filter(
                $report['dead'],
                static fn (array $entry): bool => $entry['domain'] === 'legacy'
            )),
            'Keys of an unmanaged domain are never dead — nothing was expected to produce them.'
        );
        self::assertSame(2, $this->countRows('domain = :domain', [':domain' => 'legacy']));
    }

    /**
     * Regression: English is the only language derivable from source, because the
     * second argument of `t('login.submit', 'Sign in')` IS the English text.
     * Copying it into another language's rows would report a language as complete
     * on the coverage view while every string in it is still English — an
     * untranslated key must be VISIBLY untranslated.
     */
    public function testOnlyTheSourceLanguageIsEverTouched(): void
    {
        $arabicId = $this->languageId('ar');
        $before = $this->countRows('language_id = :id', [':id' => $arabicId]);

        $report = $this->sync->sync(['probe' => ['greeting.hello' => 'Hello', 'greeting.bye' => 'Goodbye']]);

        self::assertSame(TranslationCatalog::SOURCE_LANGUAGE, $report['language']['code']);
        self::assertSame(
            $before,
            $this->countRows('language_id = :id', [':id' => $arabicId]),
            'Arabic gained no rows; the sync seeds the source language and nothing else.'
        );
        self::assertSame(
            0,
            $this->countRows('language_id = :id AND domain = :domain', [':id' => $arabicId, ':domain' => 'probe']),
            'Not even as a placeholder copy of the English.'
        );
        self::assertCount(2, $report['inserted']);
    }

    // ─── The structural guarantee ────────────────────────────────────────────

    /**
     * Regression: the safety of the refresh is enforced STRUCTURALLY, not by
     * review.
     *
     * THIS TEST USED TO SAY "no UPDATE at all", and the class used to insert and
     * nothing else. That was a real guarantee protecting real work, and #1057 is
     * what it cost: because no statement could rewrite a row, a CORRECTION to an
     * existing English string never reached an install that had already been
     * seeded, and the product went on rendering sentences that were false. So
     * the guarantee was not dropped, it was NARROWED to the thing that actually
     * had to be impossible — and narrowing it deliberately, here, is the point:
     * the old version of this test was designed so that an author who wanted to
     * write to a row had to come and argue with it first.
     *
     * The argument is that "never write" was a proxy for "never write over
     * somebody's work", and `source_managed` (migration 124) now expresses the
     * real thing. So:
     *
     *   - DELETE is still forbidden outright. Nothing about #1057 needs a row
     *     removed, and a key that vanished from source is reported as dead.
     *   - Every UPDATE must carry BOTH predicates in its own WHERE clause.
     *     `tenant_id IS NULL` keeps a tenant's row unreachable; `source_managed
     *     = TRUE` keeps a human's row unreachable. On the statement, not in the
     *     PHP above it — so a bug in the loop that decides which keys to pass
     *     cannot become a lost translation.
     *
     * The failure mode has not changed and is why this is pinned on the source
     * text rather than left to a behavioural test: silently overwriting a
     * translator's week of work leaves nothing in a diff, no error in a log, and
     * no visibly broken screen, because the fallback chain renders English
     * either way. By the time anyone finds out, the backup is gone.
     *
     * Comments are stripped before the check — the class docblock states these
     * promises in prose, and prose about a rule must not be able to satisfy it.
     */
    public function testEveryUpdateIsScopedToRowsNobodyHasEditedAndNothingIsDeleted(): void
    {
        $path = (new ReflectionClass(TranslationSync::class))->getFileName();
        $source = $path === false ? '' : (string) file_get_contents($path);
        self::assertNotSame('', $source, 'TranslationSync.php must be readable for this check to mean anything.');

        $code = '';
        /** @var list<string> $literals */
        $literals = [];
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                if ($token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $literals[] = $token[1];
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        self::assertStringNotContainsStringIgnoringCase(
            'DELETE ',
            $code,
            'TranslationSync must never remove a row: a key that vanished from source is reported '
            . 'as dead and left for a person to delete through the console.'
        );

        $updates = array_values(array_filter(
            $literals,
            static fn (string $literal): bool => stripos($literal, 'UPDATE ') !== false
        ));

        // Not a vacuous pass. If someone reverts the class to insert-only, the
        // #1057 defect is back and this test must say so rather than going
        // quietly green on "no UPDATE found".
        self::assertNotSame(
            [],
            $updates,
            'TranslationSync must still refresh source-managed rows — without an UPDATE, a corrected '
            . 'English string never reaches an install that was already seeded (#1057).'
        );

        foreach ($updates as $statement) {
            self::assertStringContainsStringIgnoringCase(
                'tenant_id IS NULL',
                $statement,
                "Every UPDATE here must carry the tenant predicate on the statement itself, so a "
                . "tenant's own wording cannot be reached by a seeding run: {$statement}"
            );
            self::assertStringContainsStringIgnoringCase(
                'source_managed = TRUE',
                $statement,
                'Every UPDATE here must require source_managed, so a string somebody saved in '
                . "/admin/translations cannot be reverted by a deploy: {$statement}"
            );
        }
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function languageId(string $code): int
    {
        $statement = $this->pdo->prepare('SELECT id FROM languages WHERE code = :code');
        $statement->execute([':code' => $code]);

        return (int) $statement->fetchColumn();
    }

    private function insertRow(
        string $languageCode,
        string $domain,
        string $key,
        string $translation,
        ?int $tenantId,
    ): void {
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
            ':created_at' => self::STAMP,
            ':updated_at' => self::STAMP,
        ]);
    }

    /**
     * Every row of a domain, in insertion order, with every column that a
     * "nothing was touched" comparison needs.
     *
     * @return list<array<string, mixed>>
     */
    private function rowsInDomain(string $domain): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, language_id, domain, key, translation, tenant_id, created_at, updated_at
             FROM translations WHERE domain = :domain ORDER BY id'
        );
        $statement->execute([':domain' => $domain]);

        /** @var list<array<string, mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }

    /**
     * The text of one row, in one language and one tenant scope.
     *
     * Scope is part of the question, not a detail: the whole subject of these
     * tests is which of several rows for the same key got written to.
     */
    private function translationOf(string $languageCode, string $domain, string $key, ?int $tenantId = null): ?string
    {
        $scope = $tenantId === null ? 'tenant_id IS NULL' : 'tenant_id = :tenant_id';
        $statement = $this->pdo->prepare(
            "SELECT translation FROM translations
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

        $text = $statement->fetchColumn();

        return $text === false ? null : (string) $text;
    }

    /**
     * The system-default English strings of a domain, as key => text.
     *
     * @return array<string, string>
     */
    private function systemDefaultsInDomain(string $domain): array
    {
        $statement = $this->pdo->prepare(
            'SELECT key, translation FROM translations
             WHERE domain = :domain AND language_id = :language_id AND tenant_id IS NULL
             ORDER BY key'
        );
        $statement->execute([
            ':domain' => $domain,
            ':language_id' => $this->languageId(TranslationCatalog::SOURCE_LANGUAGE),
        ]);

        $strings = [];
        /** @var array<string, mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $strings[(string) $row['key']] = (string) $row['translation'];
        }

        return $strings;
    }

    /**
     * @param array<string, string|int|null> $params
     */
    private function countRows(string $where = '1 = 1', array $params = []): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM translations WHERE ' . $where);
        $statement->execute($params);

        return (int) $statement->fetchColumn();
    }
}
