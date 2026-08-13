<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PHPUnit\Framework\TestCase;
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
