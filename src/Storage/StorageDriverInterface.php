<?php

declare(strict_types=1);

namespace Whity\Storage;

/**
 * Contract for all storage backend drivers.
 *
 * Design invariants:
 *
 * - **Opaque tenant-prefixed keys**: callers always pass a fully-qualified key
 *   such as `tenants/42/plugins/elmak/exam_sheet_7.pdf`. The driver treats this
 *   as an opaque address and maps it to whatever backend path or object name it
 *   needs internally. Callers must never receive raw filesystem paths or bucket
 *   paths in return.
 *
 * - **No raw path exposure**: drivers must not leak internal paths. Public-URL
 *   or signed-URL methods return externally accessible URLs, never server paths.
 *
 * - **Uniform error surface**: all methods throw {@see StorageException} on
 *   failure (missing key, permission error, network error, etc.) so callers need
 *   only catch one exception type.
 *
 * Use {@see StorageKey} to build canonical tenant-scoped keys before calling any
 * method on this interface.
 */
interface StorageDriverInterface
{
    // ------------------------------------------------------------------
    // Core write / read
    // ------------------------------------------------------------------

    /**
     * Store $contents at the given $key.
     *
     * @param array<string, mixed> $metadata Driver-specific metadata
     *                                       (e.g. Content-Type for S3).
     *
     * @throws StorageException On write failure.
     */
    public function put(string $key, string $contents, array $metadata = []): void;

    /**
     * Retrieve the full contents of the file at $key.
     *
     * @throws StorageException If the key does not exist or cannot be read.
     */
    public function get(string $key): string;

    /**
     * Return an open PHP stream resource for the file at $key.
     *
     * The caller is responsible for closing the returned stream with fclose().
     *
     * @return resource
     *
     * @throws StorageException If the key does not exist or cannot be streamed.
     */
    public function getStream(string $key): mixed;

    /**
     * Delete the file at $key.
     *
     * @throws StorageException On deletion failure.
     */
    public function delete(string $key): void;

    /**
     * Return true if a file exists at $key, false otherwise.
     *
     * @throws StorageException On unexpected driver errors.
     */
    public function exists(string $key): bool;

    /**
     * Copy the file at $source to $destination.
     *
     * @throws StorageException If $source does not exist or the copy fails.
     */
    public function copy(string $source, string $destination): void;

    /**
     * Move (rename) the file at $source to $destination.
     *
     * @throws StorageException If $source does not exist or the move fails.
     */
    public function move(string $source, string $destination): void;

    // ------------------------------------------------------------------
    // Metadata
    // ------------------------------------------------------------------

    /**
     * Return the file size in bytes.
     *
     * @throws StorageException If the key does not exist.
     */
    public function size(string $key): int;

    /**
     * Return the MIME type of the file (e.g. `application/pdf`).
     *
     * @throws StorageException If the key does not exist or MIME cannot be determined.
     */
    public function mimeType(string $key): string;

    /**
     * Return the last-modified time as a Unix timestamp.
     *
     * @throws StorageException If the key does not exist.
     */
    public function lastModified(string $key): int;

    // ------------------------------------------------------------------
    // URL generation
    // ------------------------------------------------------------------

    /**
     * Generate a short-lived signed URL for private access.
     *
     * The signed URL grants temporary read access without requiring
     * authentication headers, suitable for browser downloads or redirects.
     *
     * @param int $expiresInSeconds Seconds until the URL expires.
     *                              Default is driver-specific (typically 3600).
     *
     * @throws StorageException If a signed URL cannot be generated.
     */
    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): string;

    /**
     * Return the public URL for a publicly accessible file.
     *
     * @throws \RuntimeException   If the driver does not support public URLs
     *                             (e.g. a private-only S3 bucket).
     * @throws StorageException    On unexpected driver errors.
     */
    public function publicUrl(string $key): string;
}
