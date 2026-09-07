<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Report;

use PHPUnit\Framework\TestCase;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\i18n\LanguageRepository;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\i18n\TranslationRepository;
use Whity\Core\Report\ReportAssembler;
use Whity\Core\Report\ReportColumn;
use Whity\Core\Tenant\StaticTenantContextAdapter;
use Whity\Sdk\Render\DocumentRenderer as SdkRenderer;
use Whity\Sdk\Render\FlowDocument;
use Whity\Sdk\Render\IssuedDocument;
use Whity\Sdk\Render\RenderedDocument;

/**
 * Turning rows into a document (#947 item 6).
 *
 * The assembler is the only part of a report that decides what a reader
 * actually SEES, so what is pinned here is the small set of things that would
 * make a printed page wrong in a way nobody could correct after the fact — a
 * truncation that does not announce itself, a column of values under the wrong
 * heading, a date read in the wrong zone.
 *
 * `build()` is exercised rather than `issue()` throughout: the document's shape
 * is the thing under test and it needs no render tier, which is the same split
 * the render service makes between its verdict logic and its browser.
 */
final class ReportAssemblerTest extends TestCase
{
    private ReportAssembler $assembler;

    protected function setUp(): void
    {
        $this->assembler = new ReportAssembler($this->neverRenders(), $this->labels());
    }

    public function testPrintsTheRowsUnderTheDeclaredHeadings(): void
    {
        $payload = $this->assembler->build(
            'Issued documents',
            [ReportColumn::text('reference', 'Reference'), ReportColumn::text('title', 'Title')],
            [['reference' => '12', 'title' => 'Contract']],
            1,
            'en',
            false,
        )->toPayload();

        $table = $this->tableIn($payload);

        self::assertSame(['Reference', 'Title'], $table['columns']);
        self::assertSame([['12', 'Contract']], $table['rows']);
    }

    public function testOrdersValuesByTheDECLAREDColumnsNotTheRowsOwnKeyOrder(): void
    {
        // The failure this prevents is a table that is entirely plausible and
        // entirely wrong: a source whose SELECT lists columns in a different
        // order would otherwise print every value under its neighbour's
        // heading, and nothing about the document would look off.
        $payload = $this->assembler->build(
            'R',
            [ReportColumn::text('a', 'A'), ReportColumn::text('b', 'B')],
            [['b' => 'second', 'a' => 'first']],
            1,
            'en',
            false,
        )->toPayload();

        self::assertSame([['first', 'second']], $this->tableIn($payload)['rows']);
    }

    public function testSaysOnThePageWhenTheReportWasTruncated(): void
    {
        // The whole reason ReportSourceInterface has a total() separate from
        // rows(). A reader holding a printed subset has no other way to know it
        // is a subset, and a printed page cannot be corrected afterwards.
        $payload = $this->assembler->build(
            'R',
            [ReportColumn::text('a', 'A')],
            [['a' => 'one'], ['a' => 'two']],
            40000,
            'en',
            false,
        )->toPayload();

        $summary = $payload['content'][1]['text'];

        self::assertStringContainsString('2', $summary);
        self::assertStringContainsString('40,000', $summary);
        self::assertStringContainsString('not the full set', $summary);
    }

    public function testStatesTheCountEvenWhenNothingWasCut(): void
    {
        // Always printed, not only when it differs. A reader who learned the
        // line appears when something was cut could otherwise conclude
        // something from its ABSENCE on a page they were handed alone.
        $payload = $this->assembler->build(
            'R',
            [ReportColumn::text('a', 'A')],
            [['a' => 'one']],
            1,
            'en',
            false,
        )->toPayload();

        self::assertStringContainsString('all 1', $payload['content'][1]['text']);
    }

    public function testAnEmptyReportSaysSoInsteadOfPrintingABareHeaderRow(): void
    {
        // A document that ends after its title reads as a broken render, and
        // somebody will re-run it.
        $payload = $this->assembler->build('R', [ReportColumn::text('a', 'A')], [], 0, 'en', false)->toPayload();

        $types = array_column($payload['content'], 'type');

        self::assertNotContains('table', $types);
        self::assertStringContainsString('No records matched', $payload['content'][2]['text']);
    }

    public function testPrintsNullAsEmptyRatherThanAsTheWordNull(): void
    {
        // Sounds obvious; reaches paper. A careless `?? 'null'` or a var_export
        // gives a column of the word "null", which is a document nobody can
        // hand to anybody.
        $payload = $this->assembler->build(
            'R',
            [ReportColumn::text('a', 'A'), ReportColumn::text('b', 'B')],
            [['a' => null]],
            1,
            'en',
            false,
        )->toPayload();

        self::assertSame([['', '']], $this->tableIn($payload)['rows']);
    }

    public function testGroupsNumbersAndFormatsDatesForReading(): void
    {
        $payload = $this->assembler->build(
            'R',
            [
                ReportColumn::number('amount', 'Amount'),
                ReportColumn::dateTime('at', 'At'),
                ReportColumn::boolean('ok', 'OK'),
            ],
            [['amount' => 1234567.5, 'at' => '2026-08-25 14:02:11', 'ok' => true]],
            1,
            'en',
            false,
        )->toPayload();

        self::assertSame([['1,234,567.50', '2026-08-25 14:02', 'Yes']], $this->tableIn($payload)['rows']);
    }

    public function testReadsAWireTimestampAsTheInstantTheServerMeant(): void
    {
        // PostgreSQL returns `2026-08-25 23:30:00` with no offset. Read in the
        // process's local zone instead of UTC, a report run from a machine east
        // of Greenwich prints a date one day off — which is exactly the kind of
        // wrong nobody notices until an audit.
        $payload = $this->assembler->build(
            'R',
            [ReportColumn::date('at', 'At')],
            [['at' => '2026-08-25 23:30:00']],
            1,
            'en',
            false,
        )->toPayload();

        self::assertSame([['2026-08-25']], $this->tableIn($payload)['rows']);
    }

    public function testPrintsAnUnparseableTimestampVerbatimRatherThanHidingIt(): void
    {
        // Printing nothing would hide a broken field; printing the raw value
        // tells a reader which record to go and look at.
        $payload = $this->assembler->build(
            'R',
            [ReportColumn::date('at', 'At')],
            [['at' => 'not a date at all']],
            1,
            'en',
            false,
        )->toPayload();

        self::assertSame([['not a date at all']], $this->tableIn($payload)['rows']);
    }

    public function testLaysAnArabicReportOutRightToLeft(): void
    {
        $payload = $this->assembler->build(
            'تقرير',
            [ReportColumn::text('a', 'البند')],
            [['a' => 'قيمة']],
            1,
            'ar',
            true,
        )->toPayload();

        self::assertSame('rtl', $payload['direction']);
        self::assertSame('ar', $payload['lang']);
    }

    public function testCaptionsTheTableSoItReachesTheGeneratedListOfTables(): void
    {
        // The renderer only lists CAPTIONED tables. An uncaptioned report table
        // would be numbered and then absent from the list of tables, which in a
        // multi-report document is a contents page that omits half its content.
        $payload = $this->assembler->build(
            'Issued documents',
            [ReportColumn::text('a', 'A')],
            [['a' => 'x']],
            1,
            'en',
            false,
        )->toPayload();

        self::assertSame('Issued documents', $this->tableIn($payload)['caption']);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function tableIn(array $payload): array
    {
        foreach ($payload['content'] as $block) {
            if (($block['type'] ?? null) === 'table') {
                return $block;
            }
        }

        self::fail('The document carries no table');
    }

    private function labels(): ServerLabels
    {
        // An unbooted registry: every lookup misses and ServerLabels falls back
        // to the declared English, which is what an untranslated tenant gets.
        $pdo = new \PDO('sqlite::memory:');

        return new ServerLabels(new LanguageRegistry(
            new LanguageRepository($pdo),
            new TranslationRepository($pdo),
            new StaticTenantContextAdapter(),
        ));
    }

    /**
     * A renderer that fails loudly if anything reaches it — every test here is
     * about the document's SHAPE, and one that quietly rendered would be
     * asserting against a double's canned answer instead.
     */
    private function neverRenders(): SdkRenderer
    {
        return new class implements SdkRenderer {
            public function isAvailable(): bool
            {
                return true;
            }

            public function render(FlowDocument $document): RenderedDocument
            {
                throw new \LogicException('These tests must not render.');
            }

            public function issue(FlowDocument $document, string $title): IssuedDocument
            {
                throw new \LogicException('These tests must not render.');
            }
        };
    }
}
