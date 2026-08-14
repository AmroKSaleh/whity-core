<?php

declare(strict_types=1);

namespace Whity\Storage;

/**
 * Extension → content type, in ONE place.
 *
 * This map previously lived only inside {@see LocalStorageDriver::mimeType()},
 * where it runs at READ time. That works for local storage and hides a durable
 * defect on any object store: {@see \Whity\Storage\S3\S3StorageDriver::put()}
 * writes `application/octet-stream` when nothing supplies a `ContentType`, and
 * the object then carries that type in the bucket permanently. Reading it back
 * reports what the bucket holds, so a read-time map cannot repair it — the type
 * has to be right at the moment of the write.
 *
 * Hence a shared lookup rather than a second copy of the match: the read path
 * and the write path must agree, and two lists that have to agree eventually
 * do not.
 *
 * Deliberately small and allowlist-shaped. This exists to type the assets the
 * platform itself stores (branding logos, favicons); it is not a general
 * mime-database and should not grow into one. An unknown extension resolving to
 * the generic type is correct — that is only a defect when it happens to a type
 * we DO know.
 */
final class MimeTypes
{
    /** The generic type, used when nothing better is known. */
    public const FALLBACK = 'application/octet-stream';

    /** @var array<string, string> */
    private const MAP = [
        'png'  => 'image/png',
        'webp' => 'image/webp',
        'svg'  => 'image/svg+xml',
        'ico'  => 'image/x-icon',
    ];

    private function __construct()
    {
    }

    /**
     * The content type for a bare extension (no leading dot).
     *
     * Case-insensitive: the extension may arrive from a storage key, a
     * validator, or a filename, and none of those guarantee case.
     */
    public static function forExtension(string $extension): string
    {
        return self::MAP[strtolower($extension)] ?? self::FALLBACK;
    }

    /** The content type implied by a storage key's extension. */
    public static function forKey(string $key): string
    {
        return self::forExtension(pathinfo($key, PATHINFO_EXTENSION));
    }
}
