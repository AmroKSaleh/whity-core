<?php

declare(strict_types=1);

namespace Whity\Core\Branding;

use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Storage\StorageDriverInterface;
use Whity\Storage\StorageKey;

/**
 * The branding domain boundary (Tenant Branding). Resolves a tenant's effective
 * branding from the settings layer (tenant → global → default), builds public
 * asset URLs, and validates + stores uploaded assets. The ONLY place that knows
 * the branding key names, the favicon fallback chain, and the URL shape. Exposes
 * ONLY branding fields — never other settings.
 */
final class BrandingService
{
    public function __construct(
        private readonly SettingsService $settings,
        private readonly ?StorageDriverInterface $storage = null,
        private readonly ?BrandingAssetValidator $validator = null,
    ) {
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

    /**
     * Validate + store an uploaded asset and update the reference. tenantId 0
     * writes the GLOBAL default; any other tenant writes its override. Returns
     * the new content-addressed storage key. Replaces any previous object (only
     * if the previous object belongs to this tenant — never deletes global objects
     * when clearing a tenant override).
     *
     * @throws \LogicException When constructed without storage/validator.
     * @throws \InvalidArgumentException When the asset key is unknown.
     * @throws BrandingAssetRejectedException Via the validator.
     */
    public function uploadAsset(int $tenantId, string $assetKey, string $rawBytes): string
    {
        if ($this->storage === null || $this->validator === null) {
            throw new \LogicException('BrandingService was constructed without storage/validator.');
        }
        if (!BrandingAssetKind::isValid($assetKey)) {
            throw new \InvalidArgumentException("Unknown branding asset key: {$assetKey}");
        }

        $validated = $this->validator->validate($assetKey, $rawBytes);
        $hash = substr(hash('sha256', $validated->bytes), 0, 16);
        $name = sprintf('%s-%s.%s', $assetKey, $hash, $validated->ext);
        $storageKey = StorageKey::build($tenantId, 'branding', $name);

        $settingKey = BrandingAssetKind::settingKey($assetKey);
        $previous = $this->currentStorageKey($tenantId, $settingKey);

        $this->storage->put($storageKey, $validated->bytes);
        $this->writeReference($tenantId, $settingKey, $storageKey);

        if (
            $previous !== null
            && $previous !== $storageKey
            && StorageKey::tenantId($previous) === $tenantId
            && $this->storage->exists($previous)
        ) {
            $this->storage->delete($previous);
        }

        return $storageKey;
    }

    /**
     * Clear an asset reference and delete its stored object (if any). Only
     * deletes the object if it belongs to this tenant (never deletes a global
     * default when clearing a tenant override).
     *
     * @throws \LogicException When constructed without storage.
     * @throws \InvalidArgumentException When the asset key is unknown.
     */
    public function clearAsset(int $tenantId, string $assetKey): void
    {
        if ($this->storage === null) {
            throw new \LogicException('BrandingService was constructed without storage.');
        }
        if (!BrandingAssetKind::isValid($assetKey)) {
            throw new \InvalidArgumentException("Unknown branding asset key: {$assetKey}");
        }
        $settingKey = BrandingAssetKind::settingKey($assetKey);
        $previous = $this->currentStorageKey($tenantId, $settingKey);
        $this->writeReference($tenantId, $settingKey, null);
        if (
            $previous !== null
            && StorageKey::tenantId($previous) === $tenantId
            && $this->storage->exists($previous)
        ) {
            $this->storage->delete($previous);
        }
    }

    /** The currently-referenced storage key for this scope, or null. */
    private function currentStorageKey(int $tenantId, string $settingKey): ?string
    {
        $value = $tenantId === SettingsService::SYSTEM_TENANT_ID
            ? ($this->settings->getGlobal()[$settingKey] ?? '')
            : ($this->settings->effective($tenantId)[$settingKey] ?? '');
        return $value === '' ? null : $value;
    }

    private function writeReference(int $tenantId, string $settingKey, ?string $storageKey): void
    {
        if ($tenantId === SettingsService::SYSTEM_TENANT_ID) {
            $this->settings->setGlobalAsset($settingKey, $storageKey);
        } else {
            $this->settings->setTenantAsset($tenantId, $settingKey, $storageKey);
        }
    }

    private function urlOrNull(string $storageKey): ?string
    {
        return $storageKey === '' ? null : $this->assetUrl($storageKey);
    }
}
