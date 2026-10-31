<?php

declare(strict_types=1);

namespace Whity\Sdk\Documents;

/**
 * An issued document (SDK 1.56, WHIT-742). `replayed` is true when the
 * origin key already named it and nothing new was issued. `contentUrl` is
 * null while the document has no rendered file.
 */
final class IssueResult
{
    public function __construct(
        public readonly int $documentId,
        public readonly string $title,
        public readonly bool $replayed,
        public readonly ?string $contentUrl,
    ) {
    }
}
