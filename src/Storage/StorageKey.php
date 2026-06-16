<?php

declare(strict_types=1);

namespace Whity\Storage;

/**
 * Value-object helper for building and parsing canonical tenant-scoped storage
 * keys.
 *
 * Key format: `tenants/{tenantId}/{plugin}/{filename}`
 *
 * All builder methods sanitize each segment to prevent directory traversal and
 * other injection attacks:
 *  - null bytes are stripped
 *  - backslashes are normalised to forward slashes
 *  - leading slashes are stripped
 *  - `..` path components are removed
 *  - the filename segment is further reduced to its basename only
 */
final class StorageKey
{
    /**
     * Build a canonical tenant-scoped storage key.
     *
     * Result format: `tenants/{tenantId}/{plugin}/{filename}`
     *
     * Each segment is sanitized: null bytes stripped, backslashes converted,
     * leading slashes removed, `..` traversals eliminated, and the filename is
     * reduced to its basename.
     *
     * @param int    $tenantId Numeric tenant identifier.
     * @param string $plugin   Plugin slug or sub-directory within the tenant.
     * @param string $filename File name (will be reduced to basename only).
     *
     * @return string The sanitized canonical key.
     */
    public static function build(int $tenantId, string $plugin, string $filename): string
    {
        $pluginSegment   = self::sanitizeSegment($plugin);
        $filenameSegment = self::sanitizeFilename($filename);

        return sprintf('tenants/%d/%s/%s', $tenantId, $pluginSegment, $filenameSegment);
    }

    /**
     * Extract the tenant ID from a key built by {@see self::build()}.
     *
     * Returns null if the key does not match the expected format
     * (`tenants/{integer}/...`).
     */
    public static function tenantId(string $key): ?int
    {
        if (preg_match('#^tenants/(\d+)/.+$#', $key, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
    }

    // ------------------------------------------------------------------
    // Internal helpers
    // ------------------------------------------------------------------

    /**
     * Sanitize a generic path segment (plugin slug, sub-directory, etc.).
     *
     * Strips null bytes, converts backslashes, removes leading slashes, and
     * eliminates all `..` components.
     */
    private static function sanitizeSegment(string $segment): string
    {
        // Strip null bytes
        $segment = str_replace("\0", '', $segment);

        // Normalize directory separators
        $segment = str_replace('\\', '/', $segment);

        // Strip leading slashes
        $segment = ltrim($segment, '/');

        // Remove any .. components
        $parts    = explode('/', $segment);
        $safe     = [];
        foreach ($parts as $part) {
            if ($part === '..' || $part === '.') {
                continue;
            }
            $safe[] = $part;
        }

        return implode('/', $safe);
    }

    /**
     * Sanitize a filename segment.
     *
     * Applies {@see self::sanitizeSegment()} first, then reduces the result to
     * its basename only to prevent any remaining traversal via path separators.
     */
    private static function sanitizeFilename(string $filename): string
    {
        $sanitized = self::sanitizeSegment($filename);

        return basename($sanitized);
    }
}
