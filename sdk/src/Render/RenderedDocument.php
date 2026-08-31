<?php

declare(strict_types=1);

namespace Whity\Sdk\Render;

/**
 * A rendered flowing document: the bytes, plus the facts only the renderer
 * could know (SDK 1.41).
 *
 * A flowing document is DEFINED by not knowing its own length in advance —
 * how many pages a content tree becomes is decided by the paginator, at render
 * time, from the content. So a caller that wants to record the page count
 * alongside the stored file has to be told it, and the alternative (re-parsing
 * the PDF it was just handed, to count back out something the renderer already
 * reported) is a second and worse implementation of a solved problem.
 *
 * `frontMatterPages` is separate rather than folded into the total because the
 * two answer different questions. "How long is this document" is the total;
 * "what page does the body start on" is the front matter — and a generated
 * contents list is exactly the thing whose length nobody can predict, since
 * adding entries to it can push it onto another page and renumber everything
 * after.
 *
 * The constructor is private and {@see of()} is the only way in, matching the
 * rest of this SDK's result types: a host builds these, a plugin reads them,
 * and a value object a plugin could mint itself is one a plugin can be
 * mistaken about.
 */
final class RenderedDocument
{
    /**
     * @param string $bytes            The rendered PDF.
     * @param int    $pageCount        Total pages, front matter included.
     * @param int    $frontMatterPages How many of those are generated front
     *                                 matter; 0 when none was asked for.
     */
    private function __construct(
        public readonly string $bytes,
        public readonly int $pageCount,
        public readonly int $frontMatterPages,
    ) {
    }

    public static function of(string $bytes, int $pageCount, int $frontMatterPages = 0): self
    {
        return new self($bytes, max(0, $pageCount), max(0, $frontMatterPages));
    }

    /**
     * Pages of actual content: the total less the generated front matter.
     *
     * Never negative, even if a host reported a front-matter count larger than
     * the total. That combination is not meaningful and this is not the place
     * to raise it — a reader asking how much body there is wants a number they
     * can print, not an exception from a getter.
     */
    public function bodyPageCount(): int
    {
        return max(0, $this->pageCount - $this->frontMatterPages);
    }

    /** Size of the rendered file, in bytes. */
    public function byteSize(): int
    {
        return strlen($this->bytes);
    }
}
