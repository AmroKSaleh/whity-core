<?php

declare(strict_types=1);

namespace Whity\Core\DesktopPlugins;

/**
 * The outcome of a completed release: the row now in `desktop_plugin_releases`
 * and the package on disk it points at.
 */
final class ReleaseResult
{
    public function __construct(
        public readonly string $name,
        public readonly string $version,
        public readonly string $sha256,
        public readonly int $sizeBytes,
        /** Path relative to the handler's storage dir, as stored in the row. */
        public readonly string $storagePath,
        /** Absolute path to the package on disk. */
        public readonly string $absolutePath,
        public readonly int $entryCount,
        /** True when an existing (name, version) release was replaced (--force). */
        public readonly bool $replacedExisting,
    ) {
    }
}
