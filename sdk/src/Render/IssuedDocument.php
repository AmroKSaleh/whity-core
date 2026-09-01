<?php

declare(strict_types=1);

namespace Whity\Sdk\Render;

/**
 * A rendered document that the platform now OWNS (SDK 1.41).
 *
 * What {@see DocumentRenderer::issue()} answers, and the difference between it
 * and {@see RenderedDocument} is the whole reason both exist. A
 * `RenderedDocument` is bytes: a plugin got what it asked for and is free to
 * throw it away. An `IssuedDocument` is a RECORD — it has an id, an immutable
 * artifact behind it, and everything the platform already builds on top of a
 * document identity applies to it without the plugin arranging any of it:
 * storage routed to the tenant's own bucket, corrections appended rather than
 * overwritten, routing, verification, the organizer's folders.
 *
 * Which is why the bytes are NOT on this object. They are already stored, at
 * `contentUrl`, and a plugin that held them as well would be keeping a second
 * copy of a thing whose defining property is that there is one of it. A plugin
 * that wants the bytes back asks the platform for them by id.
 *
 * `contentUrl` is null when the artifact could not be stored — the record still
 * exists and can be re-rendered against later, so a null here means "not yet",
 * never "lost".
 */
final class IssuedDocument
{
    /**
     * @param int         $documentId       The platform's document id.
     * @param string      $title            As recorded on the document.
     * @param int         $pageCount        Total pages, front matter included.
     * @param int         $frontMatterPages How many of those are front matter.
     * @param int         $byteSize         Size of the stored artifact.
     * @param string|null $contentUrl       Where the artifact is fetchable, or
     *                                      null when none was stored.
     */
    private function __construct(
        public readonly int $documentId,
        public readonly string $title,
        public readonly int $pageCount,
        public readonly int $frontMatterPages,
        public readonly int $byteSize,
        public readonly ?string $contentUrl,
    ) {
    }

    public static function of(
        int $documentId,
        string $title,
        int $pageCount,
        int $frontMatterPages,
        int $byteSize,
        ?string $contentUrl,
    ): self {
        return new self(
            $documentId,
            $title,
            max(0, $pageCount),
            max(0, $frontMatterPages),
            max(0, $byteSize),
            $contentUrl,
        );
    }

    /** Whether the bytes are fetchable. False means "not yet" — see the class note. */
    public function hasArtifact(): bool
    {
        return $this->contentUrl !== null;
    }
}
