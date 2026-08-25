<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Ui;

use PHPUnit\Framework\TestCase;
use Whity\Core\Ui\DateDisplayScanner;

/**
 * Detection-logic tests for the #1068 date-display guard.
 *
 * THE POINT OF THIS FILE. A guard whose failure path has never run is not a
 * guard — it is an empty grep result somebody has decided to trust. The scanner
 * behind `scripts/ci-date-display-guard.php` reports nothing at all on a clean
 * tree, which is exactly what a scanner that has silently stopped matching also
 * reports, so the only way to know it still has teeth is to hand it something it
 * must flag. Every rule below is exercised in both directions: a source it MUST
 * flag, and a neighbouring one it must NOT.
 *
 * The restraint half matters as much as the teeth. A guard that fires on correct
 * code is worse than no guard at all, because it trains people to annotate
 * rather than to think — and there is real correct code here that looks close to
 * a violation: a number formatted with `toLocaleString`, a comment discussing
 * `toLocaleDateString`, a fallback to a translated literal.
 */
final class DateDisplayScannerTest extends TestCase
{
    private DateDisplayScanner $scanner;

    protected function setUp(): void
    {
        $this->scanner = new DateDisplayScanner();
    }

    /**
     * @return list<string> the violation codes found, in line order
     */
    private function codes(string $source): array
    {
        return array_map(
            static fn (array $v): string => $v['code'],
            $this->scanner->scanSource($source, 'Probe.tsx')
        );
    }

    // ---------------------------------------------------------------- teeth

    public function testLocaleDateMethodsAreFlagged(): void
    {
        foreach ([
            'toLocaleDateString',
            'toLocaleTimeString',
            'toDateString',
            'toTimeString',
            'toUTCString',
        ] as $method) {
            self::assertSame(
                ['LOCALE_METHOD'],
                $this->codes("const a = new Date(row.created_at).{$method}();"),
                "{$method} must be flagged"
            );
        }
    }

    public function testToLocaleStringOnADateIsFlagged(): void
    {
        self::assertSame(['LOCALE_STRING'], $this->codes('const a = new Date(x).toLocaleString(locale);'));
        self::assertSame(['LOCALE_STRING'], $this->codes('const a = row.created_at.toLocaleString();'));
    }

    public function testIntlDateFormattersAreFlagged(): void
    {
        self::assertSame(['INTL'], $this->codes("const f = new Intl.DateTimeFormat('en');"));
        self::assertSame(['INTL'], $this->codes("const f = new Intl.RelativeTimeFormat('en');"));
    }

    /**
     * The subtle one, and the reason the shared formatters return null rather
     * than the raw value: `formatter(x) ?? x` prints the wire timestamp the
     * formatter has just declined to print, in code that reads as defensive.
     */
    public function testAFallbackToTheRawValueIsFlagged(): void
    {
        self::assertSame(
            ['RAW_FALLBACK'],
            $this->codes('const w = dates.dateTime(event.occurred_at) ?? event.occurred_at;')
        );
        self::assertSame(
            ['RAW_FALLBACK'],
            $this->codes('const w = dates.date(row.created_at) ?? row.created_at;')
        );
    }

    public function testARawTimestampRenderedInJsxIsFlagged(): void
    {
        self::assertSame(['RAW_RENDER'], $this->codes('<span>{row.occurred_at}</span>'));
        self::assertSame(['RAW_RENDER'], $this->codes('<span>{ver.releasedAt}</span>'));
        self::assertSame(['RAW_RENDER'], $this->codes('<span>{phase.data.issued_on}</span>'));
    }

    public function testARawTimestampHandedToARenderingPropIsFlagged(): void
    {
        self::assertContains('RAW_PROP', $this->codes("t('x', { when: code.revoked_at })"));
        self::assertContains('RAW_PROP', $this->codes('<Fact label={person.birthDate} />'));
    }

    // ------------------------------------------------------------ restraint

    /**
     * `toLocaleString` is also how a NUMBER is formatted, correctly, in two
     * places in this tree. Flagging those would make the guard a nuisance on
     * code that has nothing to do with dates.
     */
    public function testANumberFormattedWithToLocaleStringIsNotFlagged(): void
    {
        self::assertSame([], $this->codes('const n = audience.count.toLocaleString();'));
        self::assertSame([], $this->codes('const n = totalRows.toLocaleString();'));
    }

    /**
     * The file this guard exists to protect discusses `toLocaleString` at length
     * in its own header, and so does this one.
     */
    public function testAMentionInACommentIsNotFlagged(): void
    {
        self::assertSame([], $this->codes("// never call toLocaleDateString() yourself\nconst a = 1;"));
        self::assertSame([], $this->codes("/* Intl.DateTimeFormat is not allowed here */\nconst a = 1;"));
        self::assertSame([], $this->codes("const s = 'call toLocaleTimeString for this';"));
    }

    public function testAFallbackToALiteralIsNotFlagged(): void
    {
        self::assertSame([], $this->codes("const w = dates.dateTime(x.created_at) ?? '\u{2014}';"));
    }

    /**
     * A translated string comes from the catalogue, not from the wire, so it
     * cannot be the timestamp the formatter declined to print.
     */
    public function testAFallbackToATranslatedStringIsNotFlagged(): void
    {
        self::assertSame(
            [],
            $this->codes("const w = dates.relative(x.created_at, labels) ?? t('when.unknown', 'unknown');")
        );
    }

    /**
     * Object destructuring and shorthand properties are `{ name }` too, which is
     * why only a MEMBER expression (never valid destructuring) is flagged.
     */
    public function testABareIdentifierInBracesIsNotFlagged(): void
    {
        self::assertSame([], $this->codes('const { created_at } = row;'));
        self::assertSame([], $this->codes('const payload = { created_at };'));
    }

    public function testAFieldWhoseNameMerelyContainsDateIsNotFlagged(): void
    {
        self::assertSame([], $this->codes('<span>{row.candidate_name}</span>'));
        self::assertSame([], $this->codes('<span>{form.update}</span>'));
    }

    // ----------------------------------------------------------- annotation

    public function testAReasonedAnnotationSuppresses(): void
    {
        $source = "// @date-display-ignore: a time zone name, not an instant\n"
            . "const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;";

        self::assertSame([], $this->codes($source));
    }

    /**
     * A run of `//` lines is several tokens to a lexer and one comment to the
     * person who wrote it. Without this, a reason long enough to be useful
     * pushes its own subject out of the suppression window — which would teach
     * authors to write short unhelpful reasons.
     */
    public function testAMultiLineReasonStillCoversItsSubject(): void
    {
        $source = "// @date-display-ignore: the server already reduced this to a date\n"
            . "// for a public page, and re-parsing it here would reintroduce the\n"
            . "// time of day that endpoint exists not to disclose.\n"
            . "<Fact value={phase.data.issued_on} />";

        self::assertSame([], $this->codes($source));
    }

    /**
     * The reason IS the mechanism. A bare tag is a silencer, and silencers are
     * what this guard exists instead of.
     */
    public function testAnAnnotationWithNoReasonDoesNotSuppress(): void
    {
        $source = "// @date-display-ignore:\n<span>{row.created_at}</span>";

        self::assertSame(['RAW_RENDER'], $this->codes($source));
    }

    public function testAnAnnotationDoesNotSuppressUnrelatedCodeFurtherDown(): void
    {
        $source = "// @date-display-ignore: this one is fine\n"
            . "const tz = Intl.DateTimeFormat().resolvedOptions().timeZone;\n"
            . "\n"
            . "\n"
            . "\n"
            . "const a = new Date(row.created_at).toLocaleDateString();";

        self::assertSame(['LOCALE_METHOD'], $this->codes($source));
    }

    // ------------------------------------------------------------- coverage

    /**
     * The scan set, stated rather than assumed. A guard that quietly stopped
     * looking at `.tsx` would report a clean tree forever.
     */
    public function testScannableFileSelection(): void
    {
        self::assertTrue($this->scanner->isScannable('web/app/admin/page.tsx'));
        self::assertTrue($this->scanner->isScannable('web/lib/helper.ts'));

        // The sanctioned path IS the implementation.
        self::assertFalse($this->scanner->isScannable('packages/features/src/datetime/format.ts'));

        // Development surfaces no tenant ever sees.
        self::assertFalse($this->scanner->isScannable('packages/ui/src/data-table.stories.tsx'));
        self::assertFalse($this->scanner->isScannable('web/__tests__/thing.test.tsx'));
        self::assertFalse($this->scanner->isScannable('web/e2e/login.spec.ts'));
        self::assertFalse($this->scanner->isScannable('web/lib/api/schema.d.ts'));
        self::assertFalse($this->scanner->isScannable('web/node_modules/x/index.ts'));

        // Not TypeScript at all.
        self::assertFalse($this->scanner->isScannable('web/app/page.css'));
    }

    /**
     * The field-name heuristic, pinned against the SAME examples listed in
     * `isDateFieldName` in packages/features/src/datetime/format.ts. The two
     * copies are kept in step by hand — a PHP CI job cannot call the TypeScript
     * one — so a change to either that is not mirrored fails a test rather than
     * going quiet.
     *
     * @dataProvider fieldNames
     */
    public function testFieldNameHeuristic(string $name, bool $isDate): void
    {
        self::assertSame($isDate, DateDisplayScanner::isDateFieldName($name), $name);
    }

    /** @return list<array{0: string, 1: bool}> */
    public static function fieldNames(): array
    {
        return [
            ['created_at', true],
            ['updated_at', true],
            ['occurred_at', true],
            ['createdAt', true],
            ['birthDate', true],
            ['birth_date', true],
            ['timestamp', true],
            ['expires_at', true],
            ['grace_until', true],
            ['releasedAt', true],
            ['last_seen_at', true],
            // The verification page's vocabulary: a value the server already
            // reduced to a calendar date.
            ['issued_on', true],
            ['revoked_on', true],
            ['stage_on', true],
            // Names that merely CONTAIN a date word must not match: a false
            // positive hides a column a tenant asked to see.
            ['candidate_name', false],
            ['update', false],
            ['mandate', false],
            ['seat', false],
            ['format', false],
            ['name', false],
            ['status', false],
        ];
    }
}
