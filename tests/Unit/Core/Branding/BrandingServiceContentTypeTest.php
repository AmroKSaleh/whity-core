<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Branding;

use PHPUnit\Framework\TestCase;
use Whity\Storage\MimeTypes;

/**
 * A stored asset must carry its real content type (#786).
 *
 * The defect this pins is DURABLE rather than transient, which is why it is
 * asserted at the WRITE path. `S3StorageDriver::put()` falls back to
 * `application/octet-stream` when it receives no `ContentType`, and the object
 * then holds that type in the bucket permanently — `mimeType()` afterwards
 * reports what the bucket holds, so nothing downstream can repair it.
 *
 * The local driver hides the bug: its `mimeType()` derives from the KEY's
 * extension at read time, so it answers correctly regardless of what `put()`
 * was told. Anything routed through the local driver therefore passes either
 * way, which is exactly why this went unnoticed until an install switched to
 * S3.
 */
final class BrandingServiceContentTypeTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function assetProvider(): array
    {
        return [
            'png'  => ['png', 'image/png'],
            'webp' => ['webp', 'image/webp'],
            'svg'  => ['svg', 'image/svg+xml'],
            'ico'  => ['ico', 'image/x-icon'],
        ];
    }

    /**
     * @dataProvider assetProvider
     */
    public function testTheValidatedExtensionResolvesToItsRealType(
        string $ext,
        string $expectedMime
    ): void {
        self::assertSame(
            $expectedMime,
            MimeTypes::forExtension($ext),
            "a .{$ext} asset must resolve to {$expectedMime}, not application/octet-stream"
        );
    }

    /** Case is not significant — the extension may arrive however it was written. */
    public function testTheLookupIsCaseInsensitive(): void
    {
        self::assertSame('image/png', MimeTypes::forExtension('PNG'));
    }

    /**
     * An unknown extension still resolves to something storable.
     *
     * The generic type is the correct answer here — it is only a defect when it
     * is used for a type we DO know, which the cases above cover.
     */
    public function testAnUnknownExtensionFallsBackToTheGenericType(): void
    {
        self::assertSame('application/octet-stream', MimeTypes::forExtension('bin'));
    }
}
