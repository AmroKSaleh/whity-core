<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Whity\Core\i18n\TranslationCatalog;

/**
 * The committed English catalogue: `database/i18n/<domain>.json`.
 *
 * Two properties carry the whole design and both are pinned here.
 *
 *  1. THE BYTES ARE A FUNCTION OF THE STRINGS, AND OF NOTHING ELSE. The CI drift
 *     gate regenerates the catalogue and diffs it against the checkout, so any
 *     non-determinism — key order following PHP's array order, a trailing
 *     newline that comes and goes — turns a green build red on a PR that changed
 *     no copy at all, and trains everyone to re-run the generator until it
 *     agrees. Sorted, pretty-printed, exactly one trailing newline, byte-stable.
 *
 *  2. WRITING IS A SYNC, NOT AN APPEND. The catalogue MIRRORS the source: a
 *     domain that leaves the code leaves the directory. (The database is the
 *     opposite — see {@see \Whity\Core\i18n\TranslationSync} — because rows there
 *     carry human work and files here do not.)
 *
 * Everything runs against a throwaway directory; nothing here touches the real
 * database/i18n.
 */
final class TranslationCatalogTest extends TestCase
{
    private ?string $baseDir = null;

    protected function tearDown(): void
    {
        if ($this->baseDir !== null) {
            self::removeTree($this->baseDir);
            $this->baseDir = null;
        }
    }

    // ─── Round trip ──────────────────────────────────────────────────────────

    /**
     * Regression: the catalogue is the vehicle that carries the strings from the
     * frontend source (which the release image does not contain) to the seeding
     * command (which runs inside it). If what comes back out is not what went in,
     * every string downstream is wrong, and the loss is invisible until a screen
     * renders in the wrong language.
     */
    public function testWriteThenReadReturnsTheSameCatalogue(): void
    {
        $catalog = new TranslationCatalog($this->baseDir());

        $catalog->write([
            'auth' => ['login.submit' => 'Sign in', 'login.email.label' => 'Email'],
            'acme:catalog' => ['item.name' => 'Name'],
        ]);

        self::assertSame(
            [
                'acme:catalog' => ['item.name' => 'Name'],
                'auth' => ['login.email.label' => 'Email', 'login.submit' => 'Sign in'],
            ],
            $catalog->read(),
            'Domains and keys come back sorted, and with every string intact.'
        );
    }

    /**
     * Regression: a catalogue no reviewer can read is not reviewable, which was
     * one of the three reasons for committing it at all. One key per line, sorted,
     * so a new string shows up as exactly one added line in the diff.
     */
    public function testRenderIsSortedAndPrettyPrinted(): void
    {
        $rendered = TranslationCatalog::render('auth', [
            'login.submit' => 'Sign in',
            'login.email.label' => 'Email',
        ]);

        self::assertStringContainsString("\n    \"keys\": {\n", $rendered, 'Pretty-printed, four-space indent.');
        self::assertLessThan(
            (int) strpos($rendered, '"login.submit"'),
            (int) strpos($rendered, '"login.email.label"'),
            'Keys are sorted, whatever order the extractor happened to produce them in.'
        );
    }

    /**
     * Regression: the drift gate compares BYTES. If PHP's insertion order leaked
     * into the file, the same source would render differently depending on which
     * screen an extraction visited first — a diff with no change in it, on a PR
     * that touched no copy.
     */
    public function testRenderIsByteIdenticalRegardlessOfInputOrder(): void
    {
        self::assertSame(
            TranslationCatalog::render('auth', ['a.one' => 'One', 'b.two' => 'Two', 'c.three' => 'Three']),
            TranslationCatalog::render('auth', ['c.three' => 'Three', 'a.one' => 'One', 'b.two' => 'Two'])
        );
    }

    /**
     * Regression: exactly one trailing newline. Zero makes every editor that
     * fixes it produce a spurious diff; two make the generator disagree with the
     * checkout for ever. POSIX says one.
     */
    public function testRenderEndsInExactlyOneNewline(): void
    {
        $rendered = TranslationCatalog::render('auth', ['login.submit' => 'Sign in']);

        self::assertSame(rtrim($rendered, "\n") . "\n", $rendered);
    }

    // ─── Writing is a sync ───────────────────────────────────────────────────

    /**
     * Regression: a domain deleted from the source must leave the directory, and
     * the removal must be REPORTED. A catalogue that only ever grew would keep
     * seeding strings no screen renders, and the operator running the generator
     * would have no idea a file had gone.
     */
    public function testWriteDeletesTheFileOfADomainThatVanishedFromSource(): void
    {
        $catalog = new TranslationCatalog($this->baseDir());
        $catalog->write(['auth' => ['login.submit' => 'Sign in'], 'retired' => ['old.key' => 'Old']]);

        $result = $catalog->write(['auth' => ['login.submit' => 'Sign in']]);

        self::assertSame(['retired.json'], $result['deleted']);
        self::assertFileDoesNotExist($catalog->directory() . '/retired.json');
        self::assertFileExists($catalog->directory() . '/auth.json');
        self::assertSame(['auth'], array_keys($catalog->read()));
    }

    /**
     * Regression: idempotence at the FILE level. Re-running the generator with
     * nothing changed must not rewrite the file — a rewritten mtime (or, on a
     * checkout with different line endings, rewritten bytes) turns "regenerate to
     * check" into a step that always dirties the working tree.
     */
    public function testWriteReportsUnchangedWhenNothingChanged(): void
    {
        $catalog = new TranslationCatalog($this->baseDir());
        $strings = ['auth' => ['login.submit' => 'Sign in']];

        $first = $catalog->write($strings);
        $second = $catalog->write($strings);

        self::assertSame(['auth.json'], $first['written']);
        self::assertSame([], $first['unchanged']);
        self::assertSame([], $second['written'], 'A second identical run must write nothing.');
        self::assertSame(['auth.json'], $second['unchanged']);
        self::assertSame([], $second['deleted']);
    }

    /**
     * Regression: `acme:catalog` is a legal domain and an illegal Windows
     * filename. Encoding must be reversible, because {@see read()} infers a
     * domain from a filename whenever a file carries no `domain` field — an
     * encoding that did not round-trip would silently rename a plugin's whole
     * bundle and orphan every row already seeded under the real name.
     *
     * It must also be INJECTIVE. An underscore separator was not: a domain slug
     * is `[a-z][a-z0-9_]*`, so `acme__catalog` is itself a valid bare domain
     * and would have shared a file with `acme:catalog` — one silently
     * overwriting the other on every regeneration, and the deletion sweep
     * seeing only one of the two expected names.
     */
    public function testPluginNamespaceIsEncodedInTheFileNameAndDecodesBack(): void
    {
        self::assertSame('acme-catalog.json', TranslationCatalog::fileNameFor('acme:catalog'));
        self::assertSame('auth.json', TranslationCatalog::fileNameFor('auth'));

        self::assertSame('acme:catalog', TranslationCatalog::domainFromFileName('acme-catalog.json'));
        self::assertSame('auth', TranslationCatalog::domainFromFileName('auth.json'));

        foreach (['auth', 'common', 'acme:catalog', 'demo_catalog:record', 'acme__catalog'] as $domain) {
            self::assertSame(
                $domain,
                TranslationCatalog::domainFromFileName(TranslationCatalog::fileNameFor($domain)),
                "The file name for '{$domain}' must decode back to it."
            );
        }

        self::assertNotSame(
            TranslationCatalog::fileNameFor('acme:catalog'),
            TranslationCatalog::fileNameFor('acme__catalog'),
            'Two distinct legal domains must never share one catalogue file.'
        );
    }

    // ─── Locale catalogues: the half a human writes ──────────────────────────

    /**
     * THE REGRESSION THAT WOULD DESTROY THE MOST WORK, so it is the first test
     * here.
     *
     * `write()` MIRRORS the source: a domain that leaves the code loses its
     * file, and the deletion sweep removes anything in the directory it did not
     * expect. A hand-written language lives INSIDE that same directory. If the
     * sweep treated `ar/` as an unexpected entry, one ordinary
     * `whity-cli i18n:extract` — the command every frontend PR is told to run —
     * would silently delete every translation anyone had ever written, and the
     * diff would look like a normal regeneration.
     *
     * `is_file()` is the whole guard. This test is what stops someone
     * "simplifying" it to a `.json` suffix check.
     */
    public function testEnglishRegenerationNeverTouchesALocaleDirectory(): void
    {
        $catalog = new TranslationCatalog($this->baseDir());
        $catalog->write(['auth' => ['login.submit' => 'Sign in'], 'retired' => ['old.key' => 'Old']]);
        $catalog->writeLocale('ar', ['auth' => ['login.submit' => 'تسجيل الدخول']]);

        // A regeneration that RETIRES a whole domain — the case that deletes.
        $result = $catalog->write(['auth' => ['login.submit' => 'Sign in']]);

        self::assertSame(['retired.json'], $result['deleted'], 'The English file still goes.');
        self::assertSame(
            ['auth' => ['login.submit' => 'تسجيل الدخول']],
            $catalog->readLocale('ar'),
            'The Arabic survived a full English regeneration, untouched.'
        );
        self::assertSame(['ar'], $catalog->localeCodes());
    }

    /**
     * The mirror of the above: the English read must not pick the locale
     * directory up as a domain either. `ar/auth.json` is not a domain called
     * `ar`, and a `read()` that returned one would feed it straight into the
     * drift comparison as an unexplained extra domain.
     */
    public function testEnglishReadDoesNotSeeALocaleDirectory(): void
    {
        $catalog = new TranslationCatalog($this->baseDir());
        $catalog->write(['auth' => ['login.submit' => 'Sign in']]);
        $catalog->writeLocale('ar', ['auth' => ['login.submit' => 'تسجيل الدخول']]);

        self::assertSame(['auth'], array_keys($catalog->read()));
    }

    /**
     * Regression: writing a language is ADDITIVE, unlike writing English.
     * There is no generator that could put a hand-written domain back, so a
     * `writeLocale` that mirrored would delete a translator's work the first
     * time somebody saved one domain in isolation.
     */
    public function testWriteLocaleDoesNotPruneDomainsItWasNotGiven(): void
    {
        $catalog = new TranslationCatalog($this->baseDir());
        $catalog->writeLocale('ar', [
            'auth' => ['login.submit' => 'تسجيل الدخول'],
            'documents' => ['organizer.title' => 'المستندات'],
        ]);

        $catalog->writeLocale('ar', ['auth' => ['login.submit' => 'تسجيل الدخول']]);

        self::assertSame(
            ['auth', 'documents'],
            array_keys($catalog->readLocale('ar')),
            'A domain absent from the write keeps its file.'
        );
    }

    /**
     * Regression: the locale file's bytes are a function of its strings, for the
     * same reason the English one's are — the guard re-renders and compares, so
     * a translator whose editor reorders keys must see one fixable complaint
     * rather than a diff nobody can review.
     */
    public function testRenderLocaleIsSortedAndByteStable(): void
    {
        $one = TranslationCatalog::renderLocale('auth', 'ar', ['b.two' => 'اثنان', 'a.one' => 'واحد']);
        $two = TranslationCatalog::renderLocale('auth', 'ar', ['a.one' => 'واحد', 'b.two' => 'اثنان']);

        self::assertSame($one, $two);
        self::assertLessThan((int) strpos($one, '"b.two"'), (int) strpos($one, '"a.one"'));
        self::assertStringContainsString('"language": "ar"', $one);
        self::assertStringContainsString('واحد', $one, 'Arabic is written raw, not as escapes.');
        self::assertSame(rtrim($one, "\n") . "\n", $one);
    }

    /**
     * Regression: a language code becomes a PATH SEGMENT. Traversal or an
     * absolute path here would let a catalogue be read from, or written to,
     * anywhere the process can reach.
     */
    public function testALanguageCodeThatIsNotAUsablePathSegmentIsRefused(): void
    {
        $catalog = new TranslationCatalog($this->baseDir());

        foreach (['..', '../../etc', 'ar/../..', '/etc', 'AR', '', 'a', 'toolongcode'] as $bad) {
            try {
                $catalog->localeDirectory($bad);
                self::fail("'{$bad}' must not be accepted as a language code.");
            } catch (RuntimeException) {
                self::assertTrue(true);
            }
        }

        foreach (['ar', 'en', 'en-US', 'zh-Hant'] as $good) {
            self::assertStringEndsWith('/' . $good, $catalog->localeDirectory($good));
        }
    }

    /**
     * Regression: coverage counts against the ENGLISH catalogue, so "100%"
     * means "every key a screen can ask for", not "every key this file happens
     * to contain". Counting the other way round would report a language with
     * three stale strings in it as complete.
     */
    public function testCoverageCountsAgainstTheEnglishCatalogue(): void
    {
        $coverage = TranslationCatalog::coverage(
            ['auth' => ['a' => 'A', 'b' => 'B'], 'documents' => ['c' => 'C']],
            ['auth' => ['a' => 'أ']]
        );

        self::assertSame(3, $coverage['total']);
        self::assertSame(1, $coverage['translated']);
        self::assertSame(2, $coverage['missing']);
        self::assertSame(0, $coverage['orphans']);
        self::assertSame(
            ['total' => 2, 'translated' => 1, 'missing' => 1, 'orphans' => []],
            $coverage['domains']['auth']
        );
        self::assertSame(
            ['total' => 1, 'translated' => 0, 'missing' => 1, 'orphans' => []],
            $coverage['domains']['documents'],
            'A domain with no file at all is 0/n, not absent from the report.'
        );
    }

    /**
     * Regression: an orphan is the one failure that LOOKS like progress. A key
     * renamed at the call site leaves its translation behind; the file gets
     * longer, and a coverage number that counted it would go UP while the screen
     * stayed English. It must be reported, and it must not be counted as
     * translated.
     */
    public function testCoverageReportsOrphansAndNeverCountsThemAsTranslated(): void
    {
        $coverage = TranslationCatalog::coverage(
            ['auth' => ['kept' => 'Kept']],
            ['auth' => ['kept' => 'محفوظ', 'renamed.away' => 'يتيم'], 'retired' => ['x' => 'س']]
        );

        self::assertSame(1, $coverage['total'], 'Only English keys are in the denominator.');
        self::assertSame(1, $coverage['translated']);
        self::assertSame(2, $coverage['orphans']);
        self::assertSame(['renamed.away'], $coverage['domains']['auth']['orphans']);
        self::assertSame(
            ['x'],
            $coverage['domains']['retired']['orphans'],
            'A domain English has retired entirely is all orphans, not silently skipped.'
        );
    }

    /**
     * Regression: an EMPTY translation is worse than a missing one — it renders
     * as an empty string rather than falling back to English — so it must never
     * be counted as done. The CI guard rejects it outright; this pins the
     * counting half.
     */
    public function testCoverageDoesNotCountAnEmptyStringAsTranslated(): void
    {
        $coverage = TranslationCatalog::coverage(
            ['auth' => ['a' => 'A', 'b' => 'B']],
            ['auth' => ['a' => '', 'b' => 'ب']]
        );

        self::assertSame(1, $coverage['translated']);
        self::assertSame(1, $coverage['missing']);
    }

    // ─── The drift gate's comparison ─────────────────────────────────────────

    /**
     * Regression: the three ways source and catalogue can disagree, each needing
     * a different fix from the author — a NEW string, a REWORDED string, and a
     * string whose call site is gone. Collapsing them into one "differs" would
     * leave the CI message unable to say what to do about it.
     */
    public function testDiffReportsAddedChangedAndRemovedKeys(): void
    {
        $differences = TranslationCatalog::diff(
            ['auth' => ['a.new' => 'New', 'a.changed' => 'Source wording', 'a.same' => 'Same']],
            ['auth' => ['a.changed' => 'Catalogue wording', 'a.same' => 'Same', 'a.gone' => 'Gone']]
        );

        self::assertSame(
            [
                ['domain' => 'auth', 'key' => 'a.changed', 'kind' => 'text-changed'],
                ['domain' => 'auth', 'key' => 'a.gone', 'kind' => 'absent-from-source'],
                ['domain' => 'auth', 'key' => 'a.new', 'kind' => 'missing-from-catalogue'],
            ],
            array_map(
                static fn (array $d): array => ['domain' => $d['domain'], 'key' => $d['key'], 'kind' => $d['kind']],
                $differences
            ),
            'Sorted by domain then key, so the CI output reads the same on every run.'
        );

        self::assertSame(
            'catalogue says "Catalogue wording", source says "Source wording"',
            $differences[0]['detail'],
            'A reworded string must show BOTH wordings; the author has to choose one.'
        );
        self::assertSame('New', $differences[2]['detail']);
    }

    /**
     * Regression: the gate's green path. Two equal catalogues must produce an
     * EMPTY list — a diff that reported anything here would fail every build and
     * be switched off within the week.
     */
    public function testDiffOfEqualCataloguesIsEmpty(): void
    {
        $catalog = [
            'auth' => ['login.submit' => 'Sign in'],
            'acme:catalog' => ['item.name' => 'Name'],
        ];

        self::assertSame([], TranslationCatalog::diff($catalog, $catalog));
        self::assertSame([], TranslationCatalog::diff([], []));
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    private function baseDir(): string
    {
        return $this->baseDir ??= sys_get_temp_dir() . '/whity-i18n-catalog-' . bin2hex(random_bytes(6));
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path)) {
            unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            self::removeTree($path . '/' . $entry);
        }

        rmdir($path);
    }
}
