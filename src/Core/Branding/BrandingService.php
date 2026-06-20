<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Storage\StorageKey;

/**
 * The branding domain boundary (Tenant Branding). Resolves a tenant's effective
 * branding from the settings layer (tenant → global → default), builds public
 * asset URLs, and (Slice 2) validates + stores uploaded assets. The ONLY place
 * that knows the branding key names, the favicon fallback chain, and the URL
 * shape. Exposes ONLY branding fields — never other settings.
 */
final class BrandingService
{
    public function __construct(private readonly SettingsService $settings)
    {
    }

    /**
     * The effective branding for a tenant. site_name resolves tenant→global→
     * default; each logo/favicon resolves independently; favicon falls back to
     * the square logo, then null.
     */
    public function effective(int $tenantId): Branding
    {
        $values = $this->settings->effective($tenantId);

        $wide = $this->urlOrNull($values[SettingsRegistry::BRANDING_LOGO_WIDE] ?? '');
        $square = $this->urlOrNull($values[SettingsRegistry::BRANDING_LOGO_SQUARE] ?? '');
        $favicon = $this->urlOrNull($values[SettingsRegistry::BRANDING_FAVICON] ?? '');

        return new Branding(
            siteName: $values[SettingsRegistry::SITE_NAME] ?? SettingsRegistry::defaultFor(SettingsRegistry::SITE_NAME),
            logoWideUrl: $wide,
            logoSquareUrl: $square,
            faviconUrl: $favicon ?? $square,
        );
    }

    /**
     * Build the public asset-route URL for a stored branding key:
     *   tenants/{tid}/branding/{name}  ->  /api/v1/branding/asset/{tid}/{name}
     */
    public function assetUrl(string $storageKey): string
    {
        $tenantId = StorageKey::tenantId($storageKey);
        $name = basename($storageKey);
        return sprintf('/api/v1/branding/asset/%d/%s', $tenantId ?? 0, $name);
    }

    private function urlOrNull(string $storageKey): ?string
    {
        return $storageKey === '' ? null : $this->assetUrl($storageKey);
    }
}
