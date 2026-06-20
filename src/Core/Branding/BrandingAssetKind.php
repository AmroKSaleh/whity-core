<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

use Whity\Core\Settings\SettingsRegistry;

/**
 * The three branding asset kinds and their mapping to registry setting keys
 * (Tenant Branding). The public `{key}` URL/route segment uses these short
 * names; the persisted setting uses the registry key.
 */
final class BrandingAssetKind
{
    public const LOGO_WIDE = 'logo_wide';
    public const LOGO_SQUARE = 'logo_square';
    public const FAVICON = 'favicon';

    private const TO_SETTING = [
        self::LOGO_WIDE => SettingsRegistry::BRANDING_LOGO_WIDE,
        self::LOGO_SQUARE => SettingsRegistry::BRANDING_LOGO_SQUARE,
        self::FAVICON => SettingsRegistry::BRANDING_FAVICON,
    ];

    private function __construct()
    {
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_keys(self::TO_SETTING);
    }

    public static function isValid(string $assetKey): bool
    {
        return array_key_exists($assetKey, self::TO_SETTING);
    }

    /** @throws \InvalidArgumentException When the asset key is unknown. */
    public static function settingKey(string $assetKey): string
    {
        if (!self::isValid($assetKey)) {
            throw new \InvalidArgumentException("Unknown branding asset key: {$assetKey}");
        }

        return self::TO_SETTING[$assetKey];
    }
}
