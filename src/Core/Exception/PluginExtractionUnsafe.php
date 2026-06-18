<?php

declare(strict_types=1);

namespace Whity\Core\Exception;

/**
 * Raised when extracting an uploaded archive would be unsafe (WC-220).
 *
 * Covers the zip hardening guards: a zip-slip entry whose normalized target
 * escapes the extraction root (a `..` component, an absolute path, or a
 * drive-letter/`\\` path), and the zip-bomb caps (entry count, per-entry
 * uncompressed size, total uncompressed size, overall compression ratio). On
 * any of these NOTHING is written outside the extraction root. Maps to HTTP 400.
 */
final class PluginExtractionUnsafe extends PluginInstallException
{
}
