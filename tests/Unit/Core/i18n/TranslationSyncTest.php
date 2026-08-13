<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PDO;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\i18n\TranslationCatalog;
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
     */
    public function testExistingRowsAreNeverOverwritten(): void
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
     * Regression: insert-only is enforced STRUCTURALLY, not by review.
     *
     * Every other test here proves the class does not destroy translations
     * TODAY. This one is about tomorrow: the failure mode — silently deleting a
     * translator's week of work — leaves nothing in a diff to notice, no error
     * in a log, and no visibly broken screen (the fallback chain renders English
     * either way). By the time anyone finds out, the backup that had the rows is
     * gone too. So the class's own source text must contain no UPDATE and no
     * DELETE statement at all: an author who adds one has to delete this test
     * first, which is a thing a reviewer CAN see.
     *
     * Comments are stripped before the check — the class docblock states this
     * very promise in prose, and prose about a rule must not be able to break it.
     */
    public function testSourceContainsNoUpdateOrDeleteStatement(): void
    {
        $path = (new ReflectionClass(TranslationSync::class))->getFileName();
        $source = $path === false ? '' : (string) file_get_contents($path);
        self::assertNotSame('', $source, 'TranslationSync.php must be readable for this check to mean anything.');

        $code = '';
        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }
                $code .= $token[1];
                continue;
            }
            $code .= $token;
        }

        self::assertStringNotContainsStringIgnoringCase(
            'UPDATE ',
            $code,
            'TranslationSync must never write over an existing row: a hand-edited English string '
            . 'and a finished Arabic translation both live in rows this class can reach.'
        );
        self::assertStringNotContainsStringIgnoringCase(
            'DELETE ',
            $code,
            'TranslationSync must never remove a row: a key that vanished from source is reported '
            . 'as dead and left for a person to delete through the console.'
        );
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
