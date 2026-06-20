<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

/** The validated, ready-to-store form of an uploaded branding asset. */
final readonly class ValidatedAsset
{
    public function __construct(public string $ext, public string $bytes)
    {
    }
}

/**
 * Validates uploaded branding assets by CONTENT (Tenant Branding). The client
 * filename/extension/MIME are untrusted; the kind is decided by magic bytes.
 * Enforces a per-asset-key format allowlist + size cap and runs SVG through the
 * sanitizer (storing the sanitized bytes). Throws BrandingAssetRejectedException
 * on any violation.
 */
final class BrandingAssetValidator
{
    private const MAX_LOGO_BYTES = 2 * 1024 * 1024;    // 2 MiB
    private const MAX_FAVICON_BYTES = 1 * 1024 * 1024; // 1 MiB

    /** @var array<string, list<string>> assetKey => accepted extensions. */
    private const ACCEPTED = [
        BrandingAssetKind::LOGO_WIDE => ['png', 'webp', 'svg'],
        BrandingAssetKind::LOGO_SQUARE => ['png', 'webp', 'svg'],
        BrandingAssetKind::FAVICON => ['ico', 'png'],
    ];

    public function __construct(private readonly SvgSanitizer $svgSanitizer)
    {
    }

    public function validate(string $assetKey, string $rawBytes): ValidatedAsset
    {
        if (!BrandingAssetKind::isValid($assetKey)) {
            throw new BrandingAssetRejectedException("Unknown branding asset key: {$assetKey}");
        }

        $max = $assetKey === BrandingAssetKind::FAVICON ? self::MAX_FAVICON_BYTES : self::MAX_LOGO_BYTES;
        if ($rawBytes === '') {
            throw new BrandingAssetRejectedException('The uploaded asset is empty.');
        }
        if (strlen($rawBytes) > $max) {
            throw new BrandingAssetRejectedException('The uploaded asset exceeds the maximum allowed size.');
        }

        $ext = $this->detectExtension($rawBytes);
        if ($ext === null || !in_array($ext, self::ACCEPTED[$assetKey], true)) {
            throw new BrandingAssetRejectedException("Unsupported file type for {$assetKey}.");
        }

        if ($ext === 'svg') {
            // sanitize() throws SvgRejectedException; surface as a rejection.
            try {
                $clean = $this->svgSanitizer->sanitize($rawBytes);
            } catch (SvgRejectedException $e) {
                throw new BrandingAssetRejectedException('The SVG could not be safely sanitized: ' . $e->getMessage());
            }
            return new ValidatedAsset('svg', $clean);
        }

        return new ValidatedAsset($ext, $rawBytes);
    }

    /** Detect a branding-allowed type by magic bytes; null if unrecognized. */
    private function detectExtension(string $bytes): ?string
    {
        if (str_starts_with($bytes, "\x89PNG\r\n\x1a\n")) {
            return 'png';
        }
        if (str_starts_with($bytes, "\x00\x00\x01\x00")) {
            return 'ico';
        }
        // WEBP: "RIFF"...."WEBP"
        if (str_starts_with($bytes, 'RIFF') && strlen($bytes) >= 12 && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        // SVG: XML text whose first significant token is <svg or <?xml ... <svg.
        $head = ltrim(substr($bytes, 0, 512), "\xEF\xBB\xBF \t\r\n");
        if (str_starts_with($head, '<?xml') || str_starts_with($head, '<svg')) {
            if (stripos($bytes, '<svg') !== false) {
                return 'svg';
            }
        }
        return null;
    }
}
