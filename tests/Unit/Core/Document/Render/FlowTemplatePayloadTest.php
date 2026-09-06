<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Render;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\FlowTemplatePayload;

/**
 * Turning a document-mode template into the payload `POST /render/flow` takes.
 *
 * WHAT THIS SEAM IS FOR. The designer grew a document mode and nothing
 * connected it to a renderer: `PrintDocument` iterates `template.pages` and
 * `DocumentRenderer` had no notion of a mode, so a flow-mode document printed
 * its CANVAS pages — for a document built entirely in flow mode, the blank
 * starting page. Authored, saved, and unprintable, with no error anywhere.
 *
 * The tests below are mostly about the two shapes not being the same shape. The
 * stored template says `contents: {...}`; the service takes
 * `frontMatter: [{kind: 'contents'}]`. Getting that translation wrong produces
 * a document that renders without the lists the author asked for, which looks
 * exactly like a document that never asked for them.
 */
final class FlowTemplatePayloadTest extends TestCase
{
    /**
     * @param array<string, mixed> $flow
     * @param array<string, mixed> $page
     * @return array<string, mixed>
     */
    private function template(array $flow = [], array $page = []): array
    {
        return [
            'version' => 2,
            'mode' => 'flow',
            'page' => $page + ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10],
            'pages' => [['id' => 'p1', 'elements' => []]],
            'flow' => $flow + ['blocks' => [['type' => 'paragraph', 'text' => 'Hello']]],
        ];
    }

    // ── which renderer a template wants ─────────────────────────────────────

    public function testFlowModeIsDetectedFromTheStoredMode(): void
    {
        self::assertTrue(FlowTemplatePayload::isFlowMode(['mode' => 'flow']));
        self::assertFalse(FlowTemplatePayload::isFlowMode(['mode' => 'canvas']));
        // Absent means canvas: every template written before document mode
        // existed has no `mode`, and treating those as flow would send the
        // whole existing library to a renderer that cannot draw it.
        self::assertFalse(FlowTemplatePayload::isFlowMode([]));
    }

    // ── content ─────────────────────────────────────────────────────────────

    public function testBlocksArePassedThroughUntouched(): void
    {
        $blocks = [
            ['type' => 'heading', 'level' => 1, 'text' => 'Title'],
            ['type' => 'paragraph', 'text' => 'Body'],
        ];

        $payload = FlowTemplatePayload::build($this->template(['blocks' => $blocks]));

        // Not translated, because they are ALREADY the renderer's vocabulary.
        // That is the point of the mode; a transformation here would be the
        // mapping layer #1186 rejected.
        self::assertSame($blocks, $payload['content']);
    }

    /**
     * Refused here rather than by the service.
     *
     * An empty flow document is the exact state a mode switch leaves behind, so
     * it is a state real people reach. The service's own refusal is a relayed
     * 422 about an array being empty; this one names the document.
     */
    public function testAnEmptyDocumentIsRefusedWithAMessageAboutTheDocument(): void
    {
        $this->expectException(DocumentRenderRejectedException::class);
        FlowTemplatePayload::build($this->template(['blocks' => []]));
    }

    public function testAMissingFlowTreeIsRefusedTheSameWay(): void
    {
        $this->expectException(DocumentRenderRejectedException::class);
        FlowTemplatePayload::build(['mode' => 'flow', 'page' => ['widthMm' => 210]]);
    }

    // ── the page box ────────────────────────────────────────────────────────

    public function testTheSingleTemplateMarginBecomesFourSides(): void
    {
        $payload = FlowTemplatePayload::build($this->template(page: ['marginMm' => 18]));

        self::assertSame(
            ['topMm' => 18.0, 'rightMm' => 18.0, 'bottomMm' => 18.0, 'leftMm' => 18.0],
            $payload['page']['margin']
        );
    }

    /**
     * The margin is sent even when it matches nothing special, because the
     * SERVICE's default is 25/20/25/20 — so omitting it would silently reflow a
     * document whose author had already set a margin on the page.
     */
    public function testTheMarginIsAlwaysSentRatherThanLeftToTheServiceDefault(): void
    {
        $payload = FlowTemplatePayload::build($this->template());
        self::assertArrayHasKey('margin', $payload['page']);
        self::assertSame(10.0, $payload['page']['margin']['topMm']);
    }

    public function testPageDimensionsCarryOver(): void
    {
        $payload = FlowTemplatePayload::build(
            $this->template(page: ['widthMm' => 148, 'heightMm' => 210])
        );

        self::assertSame(148.0, $payload['page']['widthMm']);
        self::assertSame(210.0, $payload['page']['heightMm']);
    }

    // ── front matter: the shape that differs ────────────────────────────────

    public function testNoFrontMatterKeyWhenTheAuthorAskedForNoLists(): void
    {
        $payload = FlowTemplatePayload::build($this->template());
        self::assertArrayNotHasKey('frontMatter', $payload);
    }

    public function testAContentsRequestBecomesAFrontMatterEntry(): void
    {
        $payload = FlowTemplatePayload::build($this->template(['contents' => []]));

        self::assertSame([['kind' => 'contents']], $payload['frontMatter']);
    }

    public function testAllThreeListsInTheOrderTheyArePrinted(): void
    {
        $payload = FlowTemplatePayload::build($this->template([
            'listOfFigures' => [],
            'contents' => [],
            'listOfTables' => [],
        ]));

        // Fixed order, not the order the keys happened to be written in: the
        // stored shape cannot express an order, and reading one out of a JSON
        // object's key order would be a preference nobody set.
        self::assertSame(
            ['contents', 'tables', 'figures'],
            array_column($payload['frontMatter'], 'kind')
        );
    }

    public function testATitleIsForwardedOnlyWhenTheAuthorGaveOne(): void
    {
        $withTitle = FlowTemplatePayload::build($this->template(['contents' => ['title' => 'Index']]));
        self::assertSame('Index', $withTitle['frontMatter'][0]['title']);

        // Absent, the service fills in its own localised label — right in both
        // languages, and better than this layer guessing in one of them.
        $without = FlowTemplatePayload::build($this->template(['contents' => ['title' => '']]));
        self::assertArrayNotHasKey('title', $without['frontMatter'][0]);
    }

    public function testMaxLevelIsSentForContentsAndNeverForTheOtherLists(): void
    {
        $payload = FlowTemplatePayload::build($this->template([
            'contents' => ['maxLevel' => 2],
            'listOfTables' => ['maxLevel' => 2],
        ]));

        self::assertSame(2, $payload['frontMatter'][0]['maxLevel']);
        // A list of tables has no levels to stop at, so forwarding it would be
        // a key the service reads and cannot use.
        self::assertArrayNotHasKey('maxLevel', $payload['frontMatter'][1]);
    }

    // ── the optional bands ──────────────────────────────────────────────────

    public function testHeaderAndFooterAreForwardedWhenSet(): void
    {
        $payload = FlowTemplatePayload::build($this->template([
            'header' => ['center' => 'Acme'],
            'footer' => ['end' => 'Confidential'],
        ]));

        self::assertSame(['center' => 'Acme'], $payload['header']);
        self::assertSame(['end' => 'Confidential'], $payload['footer']);
    }

    public function testEmptyBandsAreOmittedRatherThanSentEmpty(): void
    {
        $payload = FlowTemplatePayload::build($this->template(['header' => [], 'footer' => []]));

        // An empty header is not "a header with nothing in it" — the service
        // has its own footer default, and sending `{}` would replace it.
        self::assertArrayNotHasKey('header', $payload);
        self::assertArrayNotHasKey('footer', $payload);
    }

    // ── direction ───────────────────────────────────────────────────────────

    /**
     * Forwarded only when stored. The designer has no direction control yet, so
     * writing 'ltr' here would put a value in every payload the service already
     * defaults to — and would pin an Arabic document to the wrong direction the
     * day a control is added.
     */
    public function testDirectionIsForwardedOnlyWhenStored(): void
    {
        self::assertArrayNotHasKey('direction', FlowTemplatePayload::build($this->template()));
        self::assertSame('rtl', FlowTemplatePayload::build($this->template(['direction' => 'rtl']))['direction']);
        // Anything that is not one of the two is dropped rather than relayed
        // for the service to refuse.
        self::assertArrayNotHasKey(
            'direction',
            FlowTemplatePayload::build($this->template(['direction' => 'sideways']))
        );
    }
}
