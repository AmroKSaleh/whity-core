<?php

declare(strict_types=1);

namespace Tests\Unit\Core\i18n;

use PHPUnit\Framework\TestCase;
use Whity\Core\i18n\TranslationKeyExtractor;

/**
 * Detection-logic tests for the English-catalogue extractor.
 *
 * The extractor is the ONLY producer of the English catalogue: whatever it reads
 * out of `t('key', 'English')` is what ends up in database/i18n/*.json and, from
 * there, in front of a translator. So both halves of its contract need pinning:
 *
 *   TEETH    — a key it cannot read (computed at runtime) must be REPORTED, not
 *              silently skipped, or a screen ships with strings no translator
 *              ever sees and the catalogue looks complete.
 *   RESTRAINT — a `t()` in a comment, a `t()` on another object, or a `/` that
 *              only looks like a regex must NOT change the catalogue. A false
 *              positive seeds words no user reads; a swallowed call site drops
 *              real ones.
 *
 * Everything is driven through `scanSource()` — the same unit-test seam as
 * {@see \Whity\Core\Tenant\TenantPredicateGuard::scanSource()} — so no test here
 * depends on the repository's actual TSX. Only the merge/conflict behaviour of
 * `extractFiles()` needs real files, and those are written to a temp directory.
 */
final class TranslationKeyExtractorTest extends TestCase
{
    /** Stands in for a real screen, spelled the repo-relative way CI reports it. */
    private const FILE = 'web/app/probe/page.tsx';

    /** Irrelevant to scanSource(); only extractFiles() resolves paths against it. */
    private const BASE_DIR = '/repo';

    /** @var list<string> Files a test created, removed in tearDown(). */
    private array $tempPaths = [];

    private ?string $tempDir = null;

    protected function tearDown(): void
    {
        foreach ($this->tempPaths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->tempPaths = [];

        if ($this->tempDir !== null && is_dir($this->tempDir)) {
            rmdir($this->tempDir);
        }
        $this->tempDir = null;
    }

    // ─── The happy path ──────────────────────────────────────────────────────

    /**
     * Regression: the base case. A bound `t` plus a literal key and a literal
     * English string must produce exactly one catalogue entry carrying BOTH
     * halves plus the location — if any of the five fields is wrong, every
     * downstream artefact (catalogue file, seeded row, CI drift message) is too.
     */
    public function testPlainCallYieldsOneEntryWithDomainKeyTextAndLine(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');

        export function SubmitButton() {
          return t('login.submit', 'Sign in');
        }
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame(
            [[
                'domain' => 'auth',
                'key' => 'login.submit',
                'text' => 'Sign in',
                'file' => self::FILE,
                'line' => 4,
            ]],
            $scan['entries']
        );
    }

    /**
     * Regression: a call Prettier has wrapped across lines is still a call. A
     * line-based scanner would miss it entirely (the string silently never
     * reaches the catalogue), and a naive multi-line one would report the line of
     * the KEY rather than of the call — sending a reviewer to the wrong line of
     * a CI failure.
     */
    public function testMultiLineCallIsFoundAndReportsTheLineOfTheCall(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');

        const label = t(
          'multi.line',
          'Across lines'
        );
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertCount(1, $scan['entries']);
        self::assertSame('multi.line', $scan['entries'][0]['key']);
        self::assertSame('Across lines', $scan['entries'][0]['text']);
        self::assertSame(3, $scan['entries'][0]['line'], 'The call opens on line 3, not the key on line 4.');
    }

    // ─── Restraint ───────────────────────────────────────────────────────────

    /**
     * Regression: a commented-out `t()` must not become a catalogue entry. This
     * is not hypothetical — the reference conversion (the sign-in screen)
     * documents an anti-pattern as a `t()` call inside a JSX comment, and a scan
     * that read it would seed a key no screen renders and no user reads.
     */
    public function testCallsInsideCommentsAreNotCatalogued(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');

        // t('commented.line', 'Never seeded');
        /* t('commented.block', 'Never seeded either') */
        const markup = <div>{/* t('commented.jsx', 'Nor this') */}</div>;

        const real = t('real.key', 'Real');
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame(['real.key'], self::keys($scan['entries']));
    }

    /**
     * Regression: only a BARE call to the bound identifier is a call site.
     * `obj.t(...)` is somebody else's method and `format(...)` merely ends in the
     * bound name — cataloguing either invents keys out of unrelated code, and
     * (worse) makes the "one key, one English string" conflict check fire on
     * strings that were never translations at all.
     */
    public function testMethodCallsAndSimilarlyNamedFunctionsAreNotCallSites(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');

        const a = obj.t('nope.one', 'Not a call site');
        const b = format('nope.two', 'Neither is this');
        const c = t('real.key', 'Real');
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame(['real.key'], self::keys($scan['entries']));
    }

    // ─── Teeth: keys the scanner cannot read ─────────────────────────────────

    /**
     * Regression: THE central guarantee. A computed key is invisible to any
     * scanner, so the one unacceptable behaviour is to skip it quietly — the
     * catalogue would then look complete while a whole lookup table of strings
     * never reaches a translator. It must be reported until the file says what
     * those keys are.
     */
    public function testDynamicKeyWithNoDeclarationIsReported(): void
    {
        $scan = $this->scan(self::DYNAMIC_KEY_SOURCE);

        self::assertSame([], $scan['entries']);
        self::assertSame(['undeclared-dynamic-key'], self::codes($scan['problems']));
        self::assertSame(4, $scan['problems'][0]['line']);
        self::assertStringContainsString(
            TranslationKeyExtractor::KEYS_TAG,
            $scan['problems'][0]['message'],
            'The remediation must name the tag the author has to write.'
        );
    }

    /**
     * Regression: an `@i18n-keys` block is the REMEDY, so it has to actually
     * work — it must both silence the alarm and contribute the declared strings
     * to the catalogue. A version that silenced without contributing would be
     * strictly worse than the alarm it replaced.
     */
    public function testDynamicKeyDeclaredByAnI18nKeysBlockIsResolved(): void
    {
        $source = <<<'TSX'
        /**
         * @i18n-keys auth
         *   sso.error.denied = Sign-in was cancelled.
         *   sso.error.failed = Sign-in failed. Please try again.
         */
        const t = useTranslation('auth');

        export function message(entry: { key: string; fallback: string }): string {
          return t(entry.key, entry.fallback);
        }
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame(
            [
                [
                    'domain' => 'auth',
                    'key' => 'sso.error.denied',
                    'text' => 'Sign-in was cancelled.',
                    'file' => self::FILE,
                    'line' => 3,
                ],
                [
                    'domain' => 'auth',
                    'key' => 'sso.error.failed',
                    'text' => 'Sign-in failed. Please try again.',
                    'file' => self::FILE,
                    'line' => 4,
                ],
            ],
            $scan['entries']
        );
    }

    /**
     * Regression: the escape hatch for keys that genuinely cannot be enumerated
     * (tenant data, plugin data). It suppresses the alarm and contributes
     * NOTHING — an ignore that quietly invented catalogue entries would put
     * runtime data in front of a translator.
     */
    public function testReasonedDynamicIgnoreSuppressesWithoutInventingEntries(): void
    {
        $source = <<<'TSX'
        // @i18n-dynamic-ignore: field labels are tenant data, not source strings
        const t = useTranslation('auth');

        export function label(field: { key: string }): string {
          return t(field.key);
        }
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame([], $scan['entries']);
    }

    /**
     * Regression: the same doctrine as `@tenant-guard-ignore:` — a decision with
     * no reason recorded is indistinguishable from a silenced alarm. A bare tag
     * must therefore be a SECOND problem, and must leave the original one
     * standing; a version that suppressed on the tag alone would let anyone mute
     * the gate with twenty characters and no explanation.
     */
    public function testDynamicIgnoreWithoutAReasonDoesNotSuppress(): void
    {
        $source = <<<'TSX'
        // @i18n-dynamic-ignore:
        const t = useTranslation('auth');

        export function label(field: { key: string }): string {
          return t(field.key);
        }
        TSX;

        $scan = $this->scan($source);

        self::assertSame(
            ['unreasoned-ignore', 'undeclared-dynamic-key'],
            self::codes($scan['problems']),
            'A reason-less ignore is itself a problem AND does not suppress the dynamic key.'
        );
        self::assertSame([], $scan['entries']);
    }

    // ─── Teeth: the English string ───────────────────────────────────────────

    /**
     * Regression: the second argument IS the English catalogue. Without it there
     * is nothing to render before the bundle arrives, nothing for a reviewer to
     * read in the diff, and nothing to seed — so a key with no source text has
     * to be reported rather than catalogued as an empty string.
     */
    public function testCallWithNoEnglishTextIsReported(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');
        const label = t('login.submit');
        TSX;

        $scan = $this->scan($source);

        self::assertSame(['missing-source-text'], self::codes($scan['problems']));
        self::assertSame([], $scan['entries']);
    }

    /**
     * Regression: the fallback may legitimately live in an `@i18n-keys` block
     * instead of at the call site (a lookup table of keys is exactly that
     * shape). Reporting it anyway would make the declaration useless and push
     * authors to duplicate the English text in two places, where the two can
     * then disagree.
     */
    public function testCallWithNoEnglishTextIsAcceptedWhenTheKeyIsDeclared(): void
    {
        $source = <<<'TSX'
        /**
         * @i18n-keys auth
         *   login.submit = Sign in
         */
        const t = useTranslation('auth');
        const label = t('login.submit');
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame(['login.submit'], self::keys($scan['entries']));
        self::assertSame('Sign in', $scan['entries'][0]['text']);
    }

    /**
     * Regression: a backtick with no `${}` is just a string and its text is
     * knowable, so refusing it would flag correct code.
     */
    public function testTemplateLiteralWithoutSubstitutionIsReadAsText(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');
        const label = t('login.submit', `Sign in`);
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame('Sign in', $scan['entries'][0]['text']);
    }

    /**
     * Regression: a substituted template's value does not exist until runtime,
     * so there is no English string to catalogue. Cataloguing the raw
     * `Hello ${name}` would seed a JavaScript expression as a user-facing
     * sentence — and one no translator could reorder for another language.
     */
    public function testTemplateLiteralWithSubstitutionIsNotSourceText(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');
        const label = t('login.greeting', `Hello ${name}`);
        TSX;

        $scan = $this->scan($source);

        self::assertSame(['missing-source-text'], self::codes($scan['problems']));
        self::assertSame([], $scan['entries']);
    }

    /**
     * Regression: the catalogue must hold what the SCREEN renders, not what the
     * source file spells. `It\'s` renders as `It's`; seeding the backslash would
     * put it on screen in every language, and would make the CI drift check
     * compare a decoded database row against an encoded catalogue for ever.
     */
    public function testEscapeSequencesAreDecoded(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');
        const a = t('escape.apostrophe', 'It\'s here');
        const b = t('escape.quotes', "Don\'t \"quote\" me");
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame("It's here", $scan['entries'][0]['text']);
        self::assertSame('Don\'t "quote" me', $scan['entries'][1]['text']);
    }

    /**
     * Regression: a curly apostrophe and a non-breaking space are ordinary
     * things to find in copy, and a decoder that kept only the escape's first
     * character turned `café` into `cafu00e9` — which is then the English a
     * translator is shown, and the English every user reads.
     */
    public function testUnicodeEscapesAreDecodedRatherThanFlattened(): void
    {
        // The escapes are spliced in rather than typed: a literal backslash-u
        // inside a PHP source file is exactly the thing under test, and writing
        // one here invites the next editor to normalise it away.
        $source = str_replace('%BS%', chr(92), <<<'TSX'
        const t = useTranslation('auth');
        const a = t('escape.unicode', 'caf%BS%u00e9 %BS%u2019n ready');
        const b = t('escape.braced', 'emoji %BS%u{1F600} here');
        const c = t('escape.hex', 'ma%BS%xF1ana');
        TSX);

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame("caf\u{00e9} \u{2019}n ready", $scan['entries'][0]['text']);
        self::assertSame("emoji \u{1F600} here", $scan['entries'][1]['text']);
        self::assertSame("ma\u{00F1}ana", $scan['entries'][2]['text']);
    }

    /**
     * Restraint: a NUL escape is legal JavaScript and impossible product copy,
     * and PostgreSQL refuses a NUL byte in a text column — so decoding it would
     * let the scanner produce a catalogue that cannot be seeded at all.
     */
    public function testANulEscapeIsNotDecodedIntoAnUnseedableByte(): void
    {
        $source = str_replace('%BS%', chr(92), <<<'TSX'
        const t = useTranslation('auth');
        const a = t('escape.nul', 'before%BS%0after');
        TSX);

        $scan = $this->scan($source);

        self::assertStringNotContainsString(chr(0), $scan['entries'][0]['text']);
    }

    // ─── Which domain a call belongs to ──────────────────────────────────────

    /**
     * Regression: a screen that also uses `common` is the norm, not the
     * exception. Attributing every call in the file to the first binding would
     * write half the strings into the wrong bundle — where the client, which
     * fetches one domain at a time, would never find them.
     */
    public function testTwoDomainsInOneFileAreAttributedToTheirOwnBinding(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('reports');
        const tc = useTranslation('common');

        const one = t('page.title', 'Reports');
        const two = tc('button.save', 'Save');
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame(
            [['reports', 'page.title'], ['common', 'button.save']],
            array_map(
                static fn (array $entry): array => [$entry['domain'], $entry['key']],
                $scan['entries']
            )
        );
    }

    /**
     * Regression: `ssoErrorMessage(t, reason)` — a helper handed the translate
     * function — is how the reference screen factors its error copy. Refusing to
     * resolve it would make the pattern unusable; the file has exactly one
     * domain, so there is nothing to guess.
     */
    public function testHelperParameterInheritsTheFilesSoleDomain(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');

        export function ssoErrorMessage(translate: TranslateFn, reason: string): string {
          return translate('sso.error.denied', 'Sign-in was cancelled.');
        }
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame('auth', $scan['entries'][0]['domain']);
        self::assertSame('sso.error.denied', $scan['entries'][0]['key']);
    }

    /**
     * Regression: with two domains in the file there is no sole domain to
     * inherit, and a GUESS here is the expensive kind of wrong — the domain is
     * written into every seeded row, so a wrong one is a data migration to undo,
     * not an edit. Report instead.
     */
    public function testHelperParameterWithTwoDomainsInTheFileIsUnresolved(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');
        const tc = useTranslation('common');

        export function ssoErrorMessage(translate: TranslateFn): string {
          return translate('sso.error.denied', 'Sign-in was cancelled.');
        }
        TSX;

        $scan = $this->scan($source);

        self::assertSame(['unresolved-domain'], self::codes($scan['problems']));
        self::assertSame([], $scan['entries']);
    }

    /**
     * Regression: a computed domain hides which BUNDLE the strings belong to,
     * which is the same class of blindness as a computed key and gets the same
     * treatment — reported, never guessed.
     */
    public function testComputedDomainIsReported(): void
    {
        $source = <<<'TSX'
        const t = useTranslation(domainFromProps);
        TSX;

        $scan = $this->scan($source);

        self::assertSame(['dynamic-domain'], self::codes($scan['problems']));
        self::assertSame([], $scan['entries']);
    }

    /**
     * Regression: the domain shape is decided in exactly one place
     * ({@see \Whity\Core\i18n\TranslationDomain}); the extractor must ask there
     * rather than catalogue a domain no read path could ever fetch back.
     */
    public function testMalformedDomainIsReported(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('Bad:Domain:x');
        const label = t('login.submit', 'Sign in');
        TSX;

        $scan = $this->scan($source);

        self::assertSame(['invalid-domain'], self::codes($scan['problems']));
        self::assertSame([], $scan['entries']);
    }

    /**
     * Regression: keys are named for the SCREEN and are dot-delimited lowercase.
     * A capitalised or otherwise off-convention key seeded once is permanent —
     * renaming it later orphans that string in every other language at once — so
     * the convention is enforced at extraction time, not by review.
     */
    public function testMalformedKeyIsReported(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');
        const label = t('Login.Email', 'Email');
        TSX;

        $scan = $this->scan($source);

        self::assertSame(['invalid-key'], self::codes($scan['problems']));
        self::assertSame([], $scan['entries']);
    }

    // ─── JSX safety ──────────────────────────────────────────────────────────

    /**
     * Regression: an over-eager regex-literal detector. JSX is full of `/`: the
     * `/>` that closes a self-closing element and the `/` of every `</tag>`.
     * Treat the first as the start of a regular expression and it runs to the
     * second, swallowing everything between — including the `t()` call and its
     * strings — and the screen's copy vanishes from the catalogue with no error
     * anywhere. This exact line shape (an icon and a translated label side by
     * side) is the most common in the codebase.
     */
    public function testSelfClosingJsxElementDoesNotSwallowTheCallBesideIt(): void
    {
        $source = <<<'TSX'
        const t = useTranslation('auth');

        export function Row() {
          return <div><Icon size={16} /> <span>{t('row.label', 'Hello')}</span></div>;
        }
        TSX;

        $scan = $this->scan($source);

        self::assertSame([], $scan['problems']);
        self::assertSame(['row.label'], self::keys($scan['entries']));
        self::assertSame('Hello', $scan['entries'][0]['text']);
    }

    /**
     * Regression: the other side of the same trade-off. `/['"]/g` — the ordinary
     * way to strip quotes, and common in components just above their markup — is
     * a REAL regex whose quotes are punctuation, not string delimiters. Read them
     * as delimiters and every quote after it on that line pairs up one position
     * out: the key and the English text of a call beside it become fragments of
     * whatever the shifted pairing produced. Both placements are pinned, because
     * the same-line one is where the misalignment actually reaches a call site.
     */
    public function testRegexLiteralContainingQuotesDoesNotBreakTheNextCall(): void
    {
        $onTheLineBefore = $this->scan(<<<'TSX'
            const t = useTranslation('auth');

            export function clean(value: string): string {
              const stripped = value.replace(/['"]/g, '');
              return stripped + t('clean.done', 'Cleaned');
            }
            TSX);

        self::assertSame([], $onTheLineBefore['problems']);
        self::assertSame(['clean.done'], self::keys($onTheLineBefore['entries']));
        self::assertSame('Cleaned', $onTheLineBefore['entries'][0]['text']);

        $onTheSameLine = $this->scan(<<<'TSX'
            const t = useTranslation('auth');
            const label = value.replace(/['"]/g, '') + t('clean.done', 'Cleaned');
            TSX);

        self::assertSame([], $onTheSameLine['problems']);
        self::assertSame(
            [[
                'domain' => 'auth',
                'key' => 'clean.done',
                'text' => 'Cleaned',
                'file' => self::FILE,
                'line' => 2,
            ]],
            $onTheSameLine['entries'],
            'A call sharing a line with a quote-carrying regex keeps its own key and text.'
        );
    }

    // ─── Merging across files ────────────────────────────────────────────────

    /**
     * Regression: one key is one string. Two screens giving the same key two
     * different English wordings is unresolvable — whichever is extracted first
     * wins, so the other screen silently renders somebody else's copy, and a
     * translator sees only one of the two. It must be reported so an author
     * gives the second wording its own key.
     */
    public function testConflictingEnglishTextForOneKeyAcrossFilesIsReported(): void
    {
        $alpha = $this->writeTempFile('alpha.tsx', <<<'TSX'
            const t = useTranslation('auth');
            export const A = t('shared.label', 'One wording');
            TSX);
        $beta = $this->writeTempFile('beta.tsx', <<<'TSX'
            const t = useTranslation('auth');
            export const B = t('shared.label', 'Another wording');
            TSX);

        $report = (new TranslationKeyExtractor($this->tempDir()))->extractFiles([$alpha, $beta]);

        self::assertSame(['conflicting-source-text'], self::codes($report['problems']));
        self::assertStringContainsString('alpha.tsx:2', $report['problems'][0]['message']);
        self::assertSame(['auth' => ['shared.label' => 'One wording']], $report['catalog']);
        self::assertSame(2, $report['files']);
    }

    /**
     * Regression: the same string used on two screens is NORMAL — a shared
     * `common` key is the point of a shared domain. Flagging it would make the
     * gate fire on correct code, and folding it twice would put a duplicate in
     * front of a translator.
     */
    public function testIdenticalEnglishTextInTwoFilesIsNotAConflict(): void
    {
        $alpha = $this->writeTempFile('alpha.tsx', <<<'TSX'
            const t = useTranslation('common');
            export const A = t('button.save', 'Save');
            TSX);
        $beta = $this->writeTempFile('beta.tsx', <<<'TSX'
            const t = useTranslation('common');
            export const B = t('button.save', 'Save');
            TSX);

        $report = (new TranslationKeyExtractor($this->tempDir()))->extractFiles([$alpha, $beta]);

        self::assertSame([], $report['problems']);
        self::assertSame(['common' => ['button.save' => 'Save']], $report['catalog']);
    }

    // ─── Helpers ─────────────────────────────────────────────────────────────

    /** A dynamic call site, reused by the declared / undeclared / ignored trio. */
    private const DYNAMIC_KEY_SOURCE = <<<'TSX'
        const t = useTranslation('auth');

        export function message(entry: { key: string; fallback: string }): string {
          return t(entry.key, entry.fallback);
        }
        TSX;

    /**
     * @return array{
     *     entries: list<array{domain: string, key: string, text: string, file: string, line: int}>,
     *     problems: list<array{file: string, line: int, code: string, message: string}>
     * }
     */
    private function scan(string $source): array
    {
        return (new TranslationKeyExtractor(self::BASE_DIR))->scanSource($source, self::FILE);
    }

    /**
     * @param list<array{file: string, line: int, code: string, message: string}> $problems
     * @return list<string>
     */
    private static function codes(array $problems): array
    {
        return array_map(static fn (array $problem): string => $problem['code'], $problems);
    }

    /**
     * @param list<array{domain: string, key: string, text: string, file: string, line: int}> $entries
     * @return list<string>
     */
    private static function keys(array $entries): array
    {
        return array_map(static fn (array $entry): string => $entry['key'], $entries);
    }

    private function tempDir(): string
    {
        if ($this->tempDir === null) {
            $dir = sys_get_temp_dir() . '/whity-i18n-extract-' . bin2hex(random_bytes(6));
            mkdir($dir, 0o777, true);
            $this->tempDir = $dir;
        }

        return $this->tempDir;
    }

    private function writeTempFile(string $name, string $contents): string
    {
        $path = $this->tempDir() . '/' . $name;
        file_put_contents($path, $contents);
        $this->tempPaths[] = $path;

        return $path;
    }
}
