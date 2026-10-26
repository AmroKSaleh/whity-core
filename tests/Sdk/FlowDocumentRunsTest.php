<?php

declare(strict_types=1);

namespace Tests\Sdk;

use PHPUnit\Framework\TestCase;
use Whity\Sdk\Render\FlowDocument;
use Whity\Sdk\Render\RenderRejectedException;

/**
 * Inline runs on the SDK's flowing-document façade (WHIT-681, SDK 1.44).
 *
 * The render service validates these rules too and would answer 422. That is
 * not a reason to skip them here — it is the reason to have them here. A
 * plugin author gets the refusal where the mistake was written rather than as
 * an HTTP status from a service they did not call directly, and a round trip
 * through a browser is an expensive way to learn that two strings disagree.
 *
 * The two rules are refused rather than repaired, and both refusals are worth
 * a test because both are the kind a well-meaning future change would soften:
 *
 *  - RUNS MUST RECONSTRUCT THE TEXT. `text` is what every plain-text consumer
 *    reads — interpolation, the outline, the contents entry, an export. Runs
 *    that disagree with it print one thing and export another, forever and
 *    silently. Repairing it means guessing which half the caller meant.
 *  - AN href IS SCHEME-ALLOW-LISTED. It is the only value in a body that is
 *    interpreted rather than displayed, and the flow HTML is loaded by a real
 *    browser in the render container in order to be printed.
 */
final class FlowDocumentRunsTest extends TestCase
{
    /** @return array<string, mixed> The first content block of a built payload. */
    private function firstBlock(FlowDocument $doc): array
    {
        $payload = $doc->toPayload();

        return $payload['content'][0];
    }

    public function testAParagraphWithoutRunsCarriesNoRunsKeyAtAll(): void
    {
        // Purely additive: nothing that exists today gains a field, so no
        // stored document has to be migrated and no consumer has to cope with
        // an empty array where there used to be nothing.
        $block = $this->firstBlock(FlowDocument::create()->paragraph('plain'));

        self::assertSame(['type' => 'paragraph', 'text' => 'plain'], $block);
    }

    public function testRunsAreEmittedWhenTheyDescribeTheText(): void
    {
        $runs = [['text' => 'a '], ['text' => 'bold', 'bold' => true], ['text' => ' word']];

        $block = $this->firstBlock(FlowDocument::create()->paragraph('a bold word', $runs));

        self::assertSame($runs, $block['runs']);
        self::assertSame('a bold word', $block['text']);
    }

    public function testHeadingsTakeRunsToo(): void
    {
        $runs = [['text' => 'A '], ['text' => 'stressed', 'italic' => true], ['text' => ' title']];

        $block = $this->firstBlock(
            FlowDocument::create()->heading(1, 'A stressed title', true, false, $runs)
        );

        self::assertSame($runs, $block['runs']);
    }

    public function testRunsThatDoNotReconstructTheTextAreRefusedNamingBothStrings(): void
    {
        $this->expectException(RenderRejectedException::class);
        // Both strings in the message, because "runs must reconstruct the text"
        // without saying what they actually spell leaves the author diffing by
        // eye.
        $this->expectExceptionMessageMatches('/a BOLD word.*a bold word|a bold word.*a BOLD word/s');

        FlowDocument::create()->paragraph('a bold word', [
            ['text' => 'a '],
            ['text' => 'BOLD', 'bold' => true],
            ['text' => ' word'],
        ]);
    }

    public function testAnEmptyRunListIsRefusedAgainstNonEmptyText(): void
    {
        // The degenerate case of the same rule, and the one a caller hits by
        // building runs in a loop that never ran.
        $this->expectException(RenderRejectedException::class);

        FlowDocument::create()->paragraph('text', []);
    }

    /** @return list<array{0: string}> */
    public static function legitimateSchemes(): array
    {
        return [['https://example.test/a'], ['http://example.test/a'], ['mailto:someone@example.test']];
    }

    /** @dataProvider legitimateSchemes */
    public function testALinkMayUseHttpHttpsOrMailto(string $href): void
    {
        $block = $this->firstBlock(
            FlowDocument::create()->paragraph('link', [['text' => 'link', 'href' => $href]])
        );

        self::assertSame($href, $block['runs'][0]['href']);
    }

    /** @return list<array{0: string}> */
    public static function dangerousOrUnusableSchemes(): array
    {
        return [
            ['javascript:alert(1)'],
            ['JavaScript:alert(1)'],
            ['  javascript:alert(1)'],
            ['data:text/html,<script>alert(1)</script>'],
            ['/relative/path'],
            ['example.test/no-scheme'],
        ];
    }

    /** @dataProvider dangerousOrUnusableSchemes */
    public function testAnHrefOutsideTheAllowListIsRefused(string $href): void
    {
        // What makes this safe is that the list is CLOSED and the match is
        // ANCHORED — not the case-insensitivity or the trim, both of which only
        // widen what is accepted. The mixed-case and padded variants are here
        // to pin that widening them does not open a hole, which is a different
        // claim from them being the defence.
        $this->expectException(RenderRejectedException::class);

        FlowDocument::create()->paragraph('link', [['text' => 'link', 'href' => $href]]);
    }

    public function testAPaddedButLegitimateUrlIsStoredTRIMMED(): void
    {
        // Because trimming is allowed at all, the value that was VALIDATED has
        // to be the value that is EMITTED. Validating a trimmed copy while
        // shipping the untrimmed original is the same mistake as runs that do
        // not match their text: two strings, one checked, the other printed.
        $block = $this->firstBlock(
            FlowDocument::create()->paragraph('link', [['text' => 'link', 'href' => "  https://example.test/a\n"]])
        );

        self::assertSame('https://example.test/a', $block['runs'][0]['href']);
    }

    public function testARunMustCarryStringText(): void
    {
        $this->expectException(RenderRejectedException::class);

        FlowDocument::create()->paragraph('x', [['bold' => true]]);
    }

    public function testAMarkMustBeBoolean(): void
    {
        $this->expectException(RenderRejectedException::class);

        FlowDocument::create()->paragraph('x', [['text' => 'x', 'bold' => 'yes']]);
    }
}
