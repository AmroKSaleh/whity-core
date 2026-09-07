<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

/**
 * The result of a FLOWING render: the bytes plus the facts only the renderer
 * could know.
 *
 * The fixed-canvas mode needs no such type — one template page is one PDF
 * page, so the caller already knows the count before it asks. A flowing
 * document does not work that way: how many pages it becomes is decided by
 * the paginator, at render time, from the content. A caller that wants to
 * record the page count alongside the stored file therefore has to be TOLD
 * it, and the alternative — re-parsing the PDF it just received to count the
 * pages back out — is a second, worse implementation of something the
 * renderer already reported in a response header.
 */
final class RenderedDocument
{
    /**
     * @param string $bytes            The rendered PDF.
     * @param int    $pageCount        Total pages, front matter included.
     * @param int    $frontMatterPages How many of those pages are the
     *                                 generated front matter (0 when the
     *                                 payload asked for none).
     */
    public function __construct(
        public readonly string $bytes,
        public readonly int $pageCount,
        public readonly int $frontMatterPages = 0,
    ) {
    }
}
