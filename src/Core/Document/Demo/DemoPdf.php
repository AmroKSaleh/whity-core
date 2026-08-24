<?php

declare(strict_types=1);

namespace Whity\Core\Document\Demo;

/**
 * A small, GENUINELY VALID PDF for the demo's stored artifacts.
 *
 * WHY THE BYTES ARE NOT RENDERED
 * ------------------------------
 * They cannot be, from a seeder. {@see \Whity\Core\Document\Render\DocumentRenderer}
 * produces artifact bytes by calling out over HTTP to the separate
 * `whity_render` container (headless Chromium + Puppeteer, ADR 0012), which is
 * an OPT-IN compose profile that is off by default, needs `RENDER_SERVICE_URL`
 * and a `RENDER_SHARED_SECRET`, and 503s cleanly when absent. `seed` therefore
 * cannot drive it, and must not require an operator to stand up a Chromium
 * container before their designer has anything in it.
 *
 * So the RECORD half of #947 item 1 is driven for real —
 * {@see \Whity\Core\Document\DocumentIssuer} writes the object through the
 * configured storage driver, computes the SHA-256, and inserts the
 * `document_artifacts` row, exactly as a persisted render does — and only the
 * payload is local. That is the honest division: everything the database and the
 * storage driver see is real, and the one thing that is a stand-in is the one
 * thing that needs a browser.
 *
 * WHY A REAL PDF AND NOT A PLACEHOLDER STRING
 * -------------------------------------------
 * Because the artifact is going to be OPENED. `document_artifacts.content_type`
 * says `application/pdf`, the viewer streams the bytes with that type, and a
 * browser handed 40 bytes of "demo artifact" under that content type shows a
 * broken-document error. The demo would then look like the viewer is broken —
 * the one reading it must not produce. 700-odd bytes of hand-assembled PDF is a
 * cheaper answer than a dependency: no library is added, and the file is small
 * enough that its whole structure is visible below.
 *
 * The xref offsets are COMPUTED rather than written as literals. Most viewers
 * tolerate a wrong xref table, which is exactly why hardcoding the offsets
 * would be a trap: any edit to the text below shifts them, nothing would fail,
 * and the artifact would be subtly malformed for whichever viewer is strict.
 */
final class DemoPdf
{
    /** A4 in PostScript points (72/inch), matching the starters' 210x297mm page. */
    private const WIDTH_PT = 595;
    private const HEIGHT_PT = 842;

    private function __construct()
    {
    }

    /**
     * One page carrying a heading and a few caption lines.
     *
     * @param list<string> $captions Lines drawn under the heading, smaller.
     */
    public static function page(string $heading, array $captions = []): string
    {
        $content = "BT\n/F1 20 Tf\n60 760 Td\n(" . self::escape($heading) . ") Tj\nET\n";

        $y = 720;
        foreach ($captions as $caption) {
            $content .= "BT\n/F1 11 Tf\n60 {$y} Td\n(" . self::escape($caption) . ") Tj\nET\n";
            $y -= 18;
        }

        // A rule under the heading, so the page is visibly a rendered document
        // rather than a blank sheet with a line of text on it.
        $content .= "0.5 w\n60 745 m\n535 745 l\nS\n";

        return self::assemble($content);
    }

    /**
     * Wrap a content stream in the five objects a one-page PDF needs, then build
     * the cross-reference table from the offsets the assembly actually produced.
     */
    private static function assemble(string $content): string
    {
        $objects = [
            1 => "<< /Type /Catalog /Pages 2 0 R >>",
            2 => "<< /Type /Pages /Kids [3 0 R] /Count 1 >>",
            3 => '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 ' . self::WIDTH_PT . ' ' . self::HEIGHT_PT . ']'
                . ' /Resources << /Font << /F1 4 0 R >> >> /Contents 5 0 R >>',
            4 => "<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>",
            5 => '<< /Length ' . strlen($content) . " >>\nstream\n" . $content . "endstream",
        ];

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $size = count($objects) + 1;

        $xrefOffset = strlen($pdf);
        $pdf .= "xref\n" . '0 ' . $size . "\n";
        // The free head entry, then one entry per object. Each is exactly 20
        // bytes — the width is part of the format, which is why every field is
        // padded rather than printed.
        $pdf .= "0000000000 65535 f \n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }

        $pdf .= "trailer\n" . '<< /Size ' . $size . " /Root 1 0 R >>\n";
        $pdf .= "startxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    /**
     * Escape the three characters that are syntax inside a PDF literal string.
     *
     * The backslash is built with {@see chr()} rather than written as an escape
     * because this file's own escaping is one layer of confusion too many
     * otherwise — and because a stray literal backslash here would corrupt every
     * artifact while still producing a file that opens.
     */
    private static function escape(string $text): string
    {
        $backslash = chr(92);

        return str_replace(
            [$backslash, '(', ')'],
            [$backslash . $backslash, $backslash . '(', $backslash . ')'],
            $text
        );
    }
}
