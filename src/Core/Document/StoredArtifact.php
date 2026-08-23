<?php

declare(strict_types=1);

namespace Whity\Core\Document;

/**
 * What {@see DocumentArtifactStore::put()} wrote: the address it claimed, and
 * the two facts about the bytes that make the write verifiable afterwards.
 *
 * A value object rather than a three-element array because these three travel
 * together into {@see DocumentArtifactRepository::create()} and getting the
 * size and the checksum the wrong way round in an array literal is a mistake
 * nothing would catch — both are strings by the time they reach the database.
 */
final class StoredArtifact
{
    public function __construct(
        /** The opaque, tenant-prefixed storage key the object was written to. */
        public readonly string $storageKey,
        /** The content type written with the object (and recorded on the row). */
        public readonly string $contentType,
        /** Size in bytes, measured from what was actually written. */
        public readonly int $byteSize,
        /** Lowercase hex SHA-256 of the bytes, so a later read can be proven identical. */
        public readonly string $checksumSha256,
    ) {
    }
}
