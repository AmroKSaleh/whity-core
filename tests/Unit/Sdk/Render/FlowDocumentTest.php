<?php

declare(strict_types=1);

namespace Tests\Unit\Sdk\Render;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Render\FlowDocument;
use Whity\Sdk\Render\PageSpec;
use Whity\Sdk\Render\RenderRejectedException;

/**
 * The plugin-facing builder (SDK 1.41, #1072).
 *
 * What is worth pinning here is NOT that a paragraph produces a paragraph. It
 * is the small set of things the builder refuses, and the small set it declines
 * to invent — because the whole argument for a builder over a documented JSON
 * shape is that a plugin author's mistake fails at their own call site with
 * their own stack on it, instead of arriving as a 422 from a Node service
 * naming an index in a tree they never typed.
 *
 * So every refusal below is a mistake somebody will actually make, and each
 * test says which one.
 */
final class FlowDocumentTest extends TestCase
{
    public function testAssemblesTheWireShapeTheRendererExpects(): void
    {
        $payload = FlowDocument::create()
            ->withTitle('Quarterly report')
            ->heading(1, 'Summary')
            ->paragraph('It went well.')
            ->toPayload();

        $this->assertSame(['preset' => 'a4'], $payload['page']);
        $this->assertSame('ltr', $payload['direction']);
        $this->assertSame('Quarterly report', $payload['title']);
        $this->assertSame(
            [
                ['type' => 'heading', 'level' => 1, 'text' => 'Summary'],
                ['type' => 'paragraph', 'text' => 'It went well.'],
            ],
            $payload['content']
        );
    }

    public function testOmitsEverythingTheCallerDidNotSet(): void
    {
        // An absent key and a key set to a default are different requests: the
        // renderer supplies its own defaults for labels, header, footer and
        // front matter, and sending empty ones would overwrite them with
        // nothing. This is the difference between "no header" and "the usual
        // header", which is exactly the pair withFooter() exists to separate.
        $payload = FlowDocument::create()->paragraph('x')->toPayload();

        $this->assertArrayNotHasKey('title', $payload);
        $this->assertArrayNotHasKey('labels', $payload);
        $this->assertArrayNotHasKey('header', $payload);
        $this->assertArrayNotHasKey('footer', $payload);
        $this->assertArrayNotHasKey('frontMatter', $payload);
        $this->assertArrayNotHasKey('lang', $payload);
    }

    public function testAnEmptyFooterIsSentAndMeansNoPageNumbers(): void
    {
        // The renderer defaults the footer to a page-number line, so the ONLY
        // way to get a document without one is to send an explicitly empty
        // footer. If this ever started being omitted like the other unset
        // fields, a certificate would silently regain "Page 1 of 1".
        $payload = FlowDocument::create()->paragraph('x')->withFooter()->toPayload();

        $this->assertSame([], $payload['footer']);
    }

    public function testRefusesAHeadingLevelOutsideOneToSix(): void
    {
        // Clamping would be worse than refusing: a level of 0 is a caller's
        // own outline arithmetic being wrong, and silently promoting it to 1
        // produces a plausible document with the wrong structure — and a
        // contents list that agrees with the wrong structure.
        $this->expectException(RenderRejectedException::class);
        $this->expectExceptionMessage('Heading level must be between 1 and 6, got 0');

        FlowDocument::create()->heading(0, 'Nope');
    }

    public function testRefusesARemoteFigureSource(): void
    {
        // Not a style rule. An http(s) source makes every render an outbound
        // fetch from inside the render tier — a request-forgery surface, and a
        // source of two renders of the same content differing.
        $this->expectException(RenderRejectedException::class);
        $this->expectExceptionMessage('data: URI');

        FlowDocument::create()->figure('https://example.test/logo.png');
    }

    public function testRefusesATableRowThatIsAMapRatherThanAList(): void
    {
        // The mistake worth catching in PHP: ['name' => 'x'] encodes as a JSON
        // object, the renderer refuses the whole document, and the only clue
        // the caller gets back is a block index.
        //
        // Built through an untyped variable on purpose. Static analysis catches
        // this shape when the rows are a literal, which is exactly the case
        // that never reaches production — real rows are assembled from a query,
        // and a `fetchAll()` that forgot FETCH_NUM produces precisely this.
        /** @var array<int, mixed> $rowsFromAQuery */
        $rowsFromAQuery = [['name' => 'Ada']];

        $this->expectException(RenderRejectedException::class);
        $this->expectExceptionMessage('Table row 0 must be a list of cell values, not a map');

        FlowDocument::create()->table(['Name'], $rowsFromAQuery);
    }

    public function testRefusesADocumentWithNoContent(): void
    {
        $this->expectException(RenderRejectedException::class);
        $this->expectExceptionMessage('at least one block of content');

        FlowDocument::create()->withTitle('Empty')->toPayload();
    }

    public function testRefusesADirectionItCannotLayOut(): void
    {
        $this->expectException(RenderRejectedException::class);

        FlowDocument::create(null, 'sideways');
    }

    public function testDoesNotNumberAnythingItself(): void
    {
        // Numbering is the renderer's, assigned in document order, and there is
        // deliberately no way to set one here. A caller that numbered its own
        // tables would have to renumber on every insert, and any disagreement
        // with the generated list of tables would be invisible until somebody
        // read the printed document.
        $payload = FlowDocument::create()
            ->table(['A'], [['1']], caption: 'First')
            ->table(['B'], [['2']], caption: 'Second')
            ->toPayload();

        foreach ($payload['content'] as $block) {
            $this->assertArrayNotHasKey('number', $block);
            $this->assertArrayNotHasKey('anchorId', $block);
            $this->assertArrayNotHasKey('label', $block);
        }
    }

    public function testRightToLeftIsSettledAtCreationAndDefaultsTheLanguage(): void
    {
        // Direction is not decoration: it picks the default caption words, the
        // footer wording, and the base direction every mixed Arabic/Latin run
        // resolves against. It is fixed at creation so no document can have
        // paragraphs resolved two different ways.
        $payload = FlowDocument::rightToLeft()
            ->heading(1, 'المقدمة')
            ->toPayload();

        $this->assertSame('rtl', $payload['direction']);
        // `lang` stays absent so the renderer applies its own rtl default (ar)
        // rather than this class carrying a second copy of that mapping.
        $this->assertArrayNotHasKey('lang', $payload);

        $explicit = FlowDocument::rightToLeft()->withLang('ur')->paragraph('x')->toPayload();
        $this->assertSame('ur', $explicit['lang']);
    }

    public function testFrontMatterIsDeclaredNotAssembled(): void
    {
        $payload = FlowDocument::create()
            ->withContents()
            ->withListOfTables('Tables')
            ->withListOfFigures()
            ->heading(1, 'One')
            ->toPayload();

        $this->assertSame(
            [
                ['kind' => 'contents', 'maxLevel' => 3],
                ['kind' => 'tables', 'title' => 'Tables'],
                ['kind' => 'figures'],
            ],
            $payload['frontMatter']
        );
    }

    public function testPageMarginsCarryTheirUnitIntoTheKeyName(): void
    {
        // The renderer reads topMm/rightMm/bottomMm/leftMm. The suffix is added
        // by PageSpec so a caller never types a unit into a key and has it
        // silently ignored — an ignored margin is a document that looks nearly
        // right, which is the hardest kind of wrong to notice.
        $payload = FlowDocument::create(PageSpec::a4()->withMargins(top: 30.0, left: 15.0))
            ->paragraph('x')
            ->toPayload();

        $this->assertSame(
            ['preset' => 'a4', 'margin' => ['topMm' => 30.0, 'leftMm' => 15.0]],
            $payload['page']
        );
    }

    public function testAnExplicitPageSizeRefusesNonsenseDimensions(): void
    {
        // A zero-width page is not a document the renderer can fail
        // informatively on; it is a layout where nothing ever fits, so every
        // block is pushed to a fresh page forever.
        $this->expectException(RenderRejectedException::class);

        PageSpec::ofSize(0.0, 297.0);
    }

    public function testCountsItsOwnBlocksForTheHostCeiling(): void
    {
        $document = FlowDocument::create();
        $this->assertTrue($document->isEmpty());
        $this->assertSame(0, $document->blockCount());

        $document->heading(1, 'A')->paragraph('B')->pageBreak();

        $this->assertFalse($document->isEmpty());
        $this->assertSame(3, $document->blockCount());
    }

    public function testIsAMutableBuilderRatherThanAnImmutableValue(): void
    {
        // Pinned because it is the one place this SDK departs from its own
        // convention, and a well-meaning refactor to copy-on-append would make
        // assembling the hundred-page document this seam exists for quadratic.
        $document = FlowDocument::create();

        $this->assertSame($document, $document->paragraph('one'));
        $this->assertSame(1, $document->blockCount());
    }
}
