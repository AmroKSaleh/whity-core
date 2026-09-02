<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Document\Qr;

use PHPUnit\Framework\TestCase;
use Whity\Core\Document\Qr\QrTemplateComposer;

/**
 * Scope 3 of #1036: where the verification code sits, and what happens when
 * nobody said.
 *
 * THE TWO FAILURES THESE TESTS EXIST TO CATCH, both of them silent:
 *
 *   ON, AND NOTHING PRINTED. The tenant switch and the template say a document
 *   carries a code, the author never placed the element, and the artifact ships
 *   claiming to be tracked while carrying nothing. Somebody photographs it,
 *   scans nothing, and concludes the organisation's verification does not work.
 *
 *   OFF, AND SOMETHING PRINTED. A template still has the element on it, the
 *   binding resolves to nothing, and every document of a tenant that switched
 *   the feature off carries a dashed empty box in the corner.
 *
 * The geometry assertions are written as PROPERTIES of the page — "the code lies
 * wholly inside the printable area" — rather than as the arithmetic the composer
 * uses. Re-stating `width - margin - size` here would be comparing the function
 * against a copy of itself, which is a green check that survives the formula
 * being wrong in both places.
 */
final class QrTemplateComposerTest extends TestCase
{
    // ── ON: a code is always produced ────────────────────────────────────────

    /**
     * The switch is on, nobody placed anything, and the artifact still carries a
     * code — which is the whole rule.
     */
    public function testAnEnabledTemplateWithNoAuthoredCodeGetsOneSupplied(): void
    {
        $result = QrTemplateComposer::compose($this->a4WithNoCode(), true);

        self::assertTrue($result['placed']);
        self::assertTrue($result['supplied']);
        self::assertTrue(QrTemplateComposer::hasVerificationElement($result['data']));
    }

    /** The supplied code is a `qr` element bound to the reserved key, not a literal. */
    public function testTheSuppliedCodeCarriesTheBindingAndNoLiteralValue(): void
    {
        $result = QrTemplateComposer::compose($this->a4WithNoCode(), true);

        $qr = $this->verificationElementOf($result['data']);

        self::assertSame('qr', $qr['type']);
        self::assertSame(QrTemplateComposer::VERIFICATION_BINDING, $qr['binding']);
        // A literal fallback would be a QR that silently pointed somewhere else
        // the day the binding failed to resolve.
        self::assertSame('', $qr['value']);
    }

    /**
     * It goes on the LAST page — where a signature block, a stamp and a footer
     * already live on the documents this feature is for.
     */
    public function testTheSuppliedCodeGoesOnTheLastPage(): void
    {
        $template = $this->a4WithNoCode();
        $template['pages'][] = ['id' => 'p2', 'elements' => []];
        $template['pages'][] = ['id' => 'p3', 'elements' => []];

        $result = QrTemplateComposer::compose($template, true);

        $pages = $result['data']['pages'];
        self::assertCount(3, $pages);
        self::assertSame([], $pages[0]['elements']);
        self::assertSame([], $pages[1]['elements']);
        self::assertNotSame([], $pages[2]['elements']);
    }

    /**
     * The code lies wholly inside the printable area, stated as a property of
     * the page rather than as the composer's own arithmetic.
     */
    public function testTheSuppliedCodeLiesWhollyInsideThePrintableArea(): void
    {
        $template = $this->a4WithNoCode();
        $page = $template['page'];

        $result = QrTemplateComposer::compose($template, true);

        foreach ($this->suppliedElementsOf($result['data']) as $element) {
            self::assertGreaterThanOrEqual($page['marginMm'], $element['x']);
            self::assertGreaterThanOrEqual($page['marginMm'], $element['y']);
            self::assertLessThanOrEqual(
                $page['widthMm'] - $page['marginMm'],
                $element['x'] + $element['w'],
                'the element overflows the right margin'
            );
            self::assertLessThanOrEqual(
                $page['heightMm'] - $page['marginMm'],
                $element['y'] + $element['h'],
                'the element overflows the bottom margin'
            );
        }
    }

    /** It sits above whatever was already drawn, rather than under a background. */
    public function testTheSuppliedCodeSitsAboveEveryExistingElement(): void
    {
        $template = $this->a4WithNoCode();
        $template['pages'][0]['elements'][] = [
            'id' => 'bg', 'type' => 'rect', 'x' => 0, 'y' => 0, 'w' => 210, 'h' => 297,
            'rotation' => 0, 'z' => 41, 'fill' => '#eee', 'stroke' => '#000',
            'strokeWidth' => 0, 'radius' => 0,
        ];

        $result = QrTemplateComposer::compose($template, true);

        $qr = $this->verificationElementOf($result['data']);
        self::assertGreaterThan(41, $qr['z']);
    }

    /** An AUTHORED placement is left exactly where the author put it. */
    public function testAnAuthoredCodeIsNeitherMovedNorDuplicated(): void
    {
        $template = $this->a4WithNoCode();
        $template['pages'][0]['elements'][] = $this->authoredCodeAt(12.0, 34.0);

        $result = QrTemplateComposer::compose($template, true);

        self::assertTrue($result['placed']);
        self::assertFalse($result['supplied'], 'the author placed one; nothing should be supplied');
        self::assertSame($template, $result['data']);
        self::assertCount(1, $this->verificationElementsOf($result['data']));
    }

    // ── OFF: nothing is printed ──────────────────────────────────────────────

    /**
     * With the code off, an authored element is REMOVED rather than left to
     * resolve to nothing and print an empty box.
     */
    public function testADisabledTemplateHasItsAuthoredCodeRemoved(): void
    {
        $template = $this->a4WithNoCode();
        $template['pages'][0]['elements'][] = $this->authoredCodeAt(12.0, 34.0);

        $result = QrTemplateComposer::compose($template, false);

        self::assertFalse($result['placed']);
        self::assertFalse(QrTemplateComposer::hasVerificationElement($result['data']));
        self::assertSame([], $result['data']['pages'][0]['elements']);
    }

    /** Removal takes the verification element and nothing else. */
    public function testRemovalLeavesEveryOtherElementUntouched(): void
    {
        $template = $this->a4WithNoCode();
        $ordinary = [
            'id' => 'title', 'type' => 'text', 'x' => 10, 'y' => 10, 'w' => 100, 'h' => 10,
            'rotation' => 0, 'z' => 1, 'text' => 'Decision', 'style' => [],
        ];
        // An author's OWN qr element, with no binding — this must survive, or
        // switching verification off would delete a QR somebody drew for their
        // own reasons.
        $ownQr = [
            'id' => 'mine', 'type' => 'qr', 'x' => 5, 'y' => 5, 'w' => 20, 'h' => 20,
            'rotation' => 0, 'z' => 2, 'value' => 'https://example.test/anything',
        ];
        $template['pages'][0]['elements'] = [$ordinary, $this->authoredCodeAt(1.0, 1.0), $ownQr];

        $result = QrTemplateComposer::compose($template, false);

        self::assertSame([$ordinary, $ownQr], $result['data']['pages'][0]['elements']);
    }

    // ── the honest refusal ───────────────────────────────────────────────────

    /**
     * A page too small to carry a scannable code gets NONE, and the caller is
     * told — rather than a decorative symbol nobody can read.
     *
     * 20x14mm with a 2mm margin leaves 10mm of usable height, below the 12mm
     * floor. The number is derived from the page here, not from the composer:
     * whatever the floor is, a page this small cannot satisfy it.
     */
    public function testAPageTooSmallForAScannableCodeGetsNone(): void
    {
        $template = $this->a4WithNoCode();
        $template['page'] = ['widthMm' => 20, 'heightMm' => 14, 'marginMm' => 2, 'background' => '#fff'];

        $result = QrTemplateComposer::compose($template, true);

        self::assertFalse($result['placed'], 'an unscannable code is worse than none');
        self::assertFalse(QrTemplateComposer::hasVerificationElement($result['data']));
    }

    /** A template with no pages cannot be given a code, and says so. */
    public function testATemplateWithNoPagesReportsNothingPlaced(): void
    {
        $template = $this->a4WithNoCode();
        $template['pages'] = [];

        $result = QrTemplateComposer::compose($template, true);

        self::assertFalse($result['placed']);
    }

    // ── the payload ──────────────────────────────────────────────────────────

    /**
     * The reserved values reach EVERY row.
     *
     * A label sheet is one document of N physical things, and a code on only the
     * first would leave the rest unverifiable while looking exactly like the
     * part that is.
     */
    public function testTheReservedValuesReachEveryRow(): void
    {
        $rows = [['serial' => 'SN-0001'], ['serial' => 'SN-0002'], ['serial' => 'SN-0003']];

        $merged = QrTemplateComposer::rowsWith($rows, 'https://x.test/verify/abc', 'ABCD-1234-5678');

        self::assertCount(3, $merged);
        foreach ($merged as $index => $row) {
            self::assertSame('https://x.test/verify/abc', $row[QrTemplateComposer::VERIFICATION_BINDING]);
            self::assertSame('ABCD-1234-5678', $row[QrTemplateComposer::REFERENCE_BINDING]);
            self::assertSame($rows[$index]['serial'], $row['serial'], 'the row keeps its own values');
        }
    }

    /**
     * A template declaring a placeholder of the reserved name cannot overwrite
     * the real URL with its sample.
     *
     * The dotted namespace makes the collision unlikely; letting the author win
     * it anyway would be a way to print a QR pointing wherever they liked.
     */
    public function testAnAuthorCannotOverrideTheReservedValues(): void
    {
        $rows = [[QrTemplateComposer::VERIFICATION_BINDING => 'https://evil.test/']];

        $merged = QrTemplateComposer::rowsWith($rows, 'https://x.test/verify/abc', 'ABCD-1234-5678');

        self::assertSame('https://x.test/verify/abc', $merged[0][QrTemplateComposer::VERIFICATION_BINDING]);
    }

    // ── Where the supplied code lands, and what is under it ──────────────────

    public function testTheSuppliedCodeAvoidsWhatIsAlreadyInTheCorner(): void
    {
        // THE REPORTED DEFECT. The corner used to be a conclusion rather than a
        // candidate: the code was placed bottom-right at the highest z on the
        // page, so a footer band or a signature block underneath it got covered
        // — a symbol over artwork that a scanner cannot resolve and a reader
        // cannot report, because it looks deliberate.
        $template = $this->a4WithNoCode();
        $template['pages'][0]['elements'][] = [
            'id' => 'footer-band',
            'type' => 'rect',
            // The whole bottom of the page, inside the margin.
            'x' => 10.0, 'y' => 240.0, 'w' => 190.0, 'h' => 47.0,
            'rotation' => 0, 'z' => 1,
        ];

        $composed = QrTemplateComposer::compose($template, true);
        $code = $this->verificationElementOf($composed['data']);

        self::assertTrue($composed['placed']);
        self::assertLessThan(
            240.0,
            $code['y'] + $code['h'],
            'the code must clear the footer rather than sit on top of it'
        );
    }

    public function testAnEmptyPageStillPutsTheCodeInTheCornerInsetByItsQuietZone(): void
    {
        // The corner is still the FIRST preference — a clear page puts the code
        // exactly where every document issued before this change carries one.
        //
        // It is inset by ONE QUIET ZONE, and that is a real (small) change to
        // existing output rather than something to gloss over: the white backing
        // extends past the code on every side, and flush to the margin it would
        // spill into it — or off the sheet, where a printer's non-printable edge
        // would clip the very border that makes the code scannable. So the code
        // moves in by 2.4 mm on A4 and its backing occupies the space the code
        // used to.
        $composed = QrTemplateComposer::compose($this->a4WithNoCode(), true);
        $code = $this->verificationElementOf($composed['data']);

        $quiet = max(2.0, QrTemplateComposer::DEFAULT_SIZE_MM * 0.12);
        $expected = 210.0 - 10.0 - QrTemplateComposer::DEFAULT_SIZE_MM - $quiet;

        self::assertEqualsWithDelta($expected, $code['x'], 0.01);

        // And the backing lands exactly where the code used to, inside the
        // margin rather than over it.
        $backing = null;
        foreach ($composed['data']['pages'][0]['elements'] as $element) {
            if (($element['id'] ?? null) === QrTemplateComposer::DEFAULT_BACKING_ELEMENT_ID) {
                $backing = $element;
            }
        }
        self::assertNotNull($backing);
        self::assertEqualsWithDelta(210.0 - 10.0, $backing['x'] + $backing['w'], 0.01);
    }

    public function testAWhiteBackingIsDrawnUnderTheCode(): void
    {
        // A code is read by thresholding light against dark and needs an
        // unbroken light border. Printed onto a dark band or a photograph it has
        // neither, and it does not scan — it only looks as though it should.
        $composed = QrTemplateComposer::compose($this->a4WithNoCode(), true);
        $elements = $composed['data']['pages'][0]['elements'];

        $backing = null;
        $code = null;
        foreach ($elements as $element) {
            if (($element['id'] ?? null) === QrTemplateComposer::DEFAULT_BACKING_ELEMENT_ID) {
                $backing = $element;
            }
            if (($element['id'] ?? null) === QrTemplateComposer::DEFAULT_QR_ELEMENT_ID) {
                $code = $element;
            }
        }

        self::assertNotNull($backing, 'a white backing must be supplied with the code');
        self::assertNotNull($code);
        self::assertSame('#FFFFFF', $backing['style']['fill']);
        self::assertLessThan($code['z'], $backing['z'], 'the backing must be UNDER the code');

        // It must extend past the code on every side — that border IS the quiet
        // zone, and a backing flush to the symbol would not provide one.
        self::assertLessThan($code['x'], $backing['x']);
        self::assertLessThan($code['y'], $backing['y']);
        self::assertGreaterThan($code['x'] + $code['w'], $backing['x'] + $backing['w']);
    }

    public function testSwitchingTheCodeOffRemovesTheBackingAndTheReference(): void
    {
        // A LATENT BUG this change surfaced. `isVerificationElement()` matched
        // only on the binding, which the QR element carries and the other two do
        // not — so turning the feature off stripped the code and KEPT its
        // caption: a reference number printed under nothing, on every template
        // that had ever taken a supplied placement.
        $composed = QrTemplateComposer::compose($this->a4WithNoCode(), true);
        self::assertCount(3, $composed['data']['pages'][0]['elements'], 'backing + code + reference');

        $stripped = QrTemplateComposer::compose($composed['data'], false);

        self::assertSame([], $stripped['data']['pages'][0]['elements']);
        self::assertFalse($stripped['placed']);
    }

    public function testACrowdedPageStillGetsACodeRatherThanNone(): void
    {
        // Nowhere is clear at any size. The code is placed anyway, because
        // `placed` is DISCARDED by DocumentRenderer — returning the template
        // untouched would drop the verification code in complete silence, and a
        // document that claims to be tracked and carries nothing is the exact
        // failure the feature exists to prevent. An overlapping code is at least
        // visible, reportable and fixable.
        $template = $this->a4WithNoCode();
        $template['pages'][0]['elements'][] = [
            'id' => 'full-bleed',
            'type' => 'image',
            'x' => 0.0, 'y' => 0.0, 'w' => 210.0, 'h' => 297.0,
            'rotation' => 0, 'z' => 1,
        ];

        $composed = QrTemplateComposer::compose($template, true);

        self::assertTrue($composed['placed'], 'a crowded page must still carry a code');
        $code = $this->verificationElementOf($composed['data']);
        self::assertSame(QrTemplateComposer::DEFAULT_QR_ELEMENT_ID, $code['id']);
    }

    public function testAnAuthoredPlacementIsStillLeftExactlyWhereItWasPut(): void
    {
        // The search applies only to a SUPPLIED placement. An author who put the
        // code somewhere chose that spot, and moving it because something is
        // nearby would be this class overruling them.
        $template = $this->a4WithNoCode();
        $template['pages'][0]['elements'][] = $this->authoredCodeAt(12.0, 12.0);

        $composed = QrTemplateComposer::compose($template, true);
        $code = $this->verificationElementOf($composed['data']);

        self::assertSame(12.0, $code['x']);
        self::assertSame(12.0, $code['y']);
        self::assertFalse($composed['supplied']);
    }

    // ── fixtures ─────────────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function a4WithNoCode(): array
    {
        return [
            'version' => 2,
            'name' => 'Decision',
            'page' => ['widthMm' => 210, 'heightMm' => 297, 'marginMm' => 10, 'background' => '#ffffff'],
            'placeholders' => [],
            'pages' => [['id' => 'p1', 'elements' => []]],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function authoredCodeAt(float $x, float $y): array
    {
        return [
            'id' => 'authored-code',
            'type' => 'qr',
            'x' => $x,
            'y' => $y,
            'w' => 25.0,
            'h' => 25.0,
            'rotation' => 0,
            'z' => 9,
            'value' => '',
            'binding' => QrTemplateComposer::VERIFICATION_BINDING,
        ];
    }

    /**
     * @param array<string, mixed> $templateData
     * @return array<string, mixed>
     */
    private function verificationElementOf(array $templateData): array
    {
        $found = $this->verificationElementsOf($templateData);
        self::assertCount(1, $found, 'exactly one verification element is expected');

        return $found[0];
    }

    /**
     * @param array<string, mixed> $templateData
     * @return list<array<string, mixed>>
     */
    private function verificationElementsOf(array $templateData): array
    {
        $found = [];
        foreach ($templateData['pages'] as $page) {
            foreach ($page['elements'] as $element) {
                if (($element['binding'] ?? null) === QrTemplateComposer::VERIFICATION_BINDING) {
                    $found[] = $element;
                }
            }
        }

        return $found;
    }

    /**
     * Every element the composer added — the code and, when it fits, the printed
     * reference beneath it.
     *
     * @param array<string, mixed> $templateData
     * @return list<array<string, mixed>>
     */
    private function suppliedElementsOf(array $templateData): array
    {
        $ids = [
            QrTemplateComposer::DEFAULT_QR_ELEMENT_ID,
            QrTemplateComposer::DEFAULT_REFERENCE_ELEMENT_ID,
        ];

        $found = [];
        foreach ($templateData['pages'] as $page) {
            foreach ($page['elements'] as $element) {
                if (in_array($element['id'] ?? null, $ids, true)) {
                    $found[] = $element;
                }
            }
        }
        self::assertNotSame([], $found, 'the composer must have supplied at least the code');

        return $found;
    }
}
