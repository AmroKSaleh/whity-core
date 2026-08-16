<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

/**
 * The result of building one desktop-plugin package: the checksum and size the
 * catalog row must carry (computed once, from the final zip on disk) plus the
 * package-shape stats the guards checked. Immutable by construction.
 */
final class PackageResult
{
    public function __construct(
        /** Plugin name == the zip's single top-level directory. */
        public readonly string $name,
        /** Absolute path to the built .zip on disk. */
        public readonly string $zipPath,
        /** Lowercase hex SHA-256 of the exact bytes of {@see $zipPath}. */
        public readonly string $sha256,
        /** Size of {@see $zipPath} in bytes (the packaged, compressed total). */
        public readonly int $sizeBytes,
        /** Number of entries in the archive. */
        public readonly int $entryCount,
        /** Sum of the uncompressed sizes of every entry. */
        public readonly int $uncompressedBytes,
    ) {
    }
}
