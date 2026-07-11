<?php

declare(strict_types=1);

namespace Whity\Storage;

use Whity\Core\Entitlement\EntitlementRegistry;
use Whity\Core\Entitlement\EntitlementService;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Storage\S3\S3Config;
use Whity\Storage\S3\S3StorageDriver;
use Whity\Storage\S3\StreamObjectHttpTransport;

/**
 * Resolves the {@see StorageDriverInterface} to use for a given tenant
 * (WC-storage).
 *
 * A tenant uses its OWN configured object-storage backend only when BOTH:
 *   1. it holds the `storage.custom_backend` entitlement, AND
 *   2. it has a `tenant_storage_config` row.
 * Otherwise it falls back to the platform-default driver (the one built once at
 * boot from the global storage settings). So an unconfigured or unentitled
 * tenant is indistinguishable from today — this is behaviour-preserving until a
 * tenant is both entitled and configured.
 *
 * Resolved drivers are memoised for the worker's lifetime, matching how the
 * default driver is itself built once at boot; a tenant that changes its storage
 * config picks it up on the next worker recycle. The secret is decrypted only
 * here, never surfaced to any API.
 *
 * Stateless w.r.t. requests (only the per-tenant driver memo) — safe for a
 * FrankenPHP worker.
 */
final class TenantStorageResolver
{
    private StorageDriverInterface $default;
    private TenantStorageConfigRepository $configs;
    private EntitlementService $entitlements;
    private EncryptedSecretStore $secrets;

    /** @var array<int, StorageDriverInterface> Per-tenant driver memo. */
    private array $cache = [];

    public function __construct(
        StorageDriverInterface $default,
        TenantStorageConfigRepository $configs,
        EntitlementService $entitlements,
        EncryptedSecretStore $secrets,
    ) {
        $this->default = $default;
        $this->configs = $configs;
        $this->entitlements = $entitlements;
        $this->secrets = $secrets;
    }

    /**
     * The storage driver for the given tenant — its own backend when entitled and
     * configured, else the platform default.
     *
     * @throws StorageException When the tenant is entitled and configured but the
     *         config is unusable (e.g. missing secret) — failing loudly rather
     *         than silently splitting reads/writes across backends.
     */
    public function forTenant(int $tenantId): StorageDriverInterface
    {
        if (array_key_exists($tenantId, $this->cache)) {
            return $this->cache[$tenantId];
        }

        return $this->cache[$tenantId] = $this->resolve($tenantId);
    }

    private function resolve(int $tenantId): StorageDriverInterface
    {
        // A non-positive tenant id (system / unresolved) always uses the default.
        if ($tenantId <= 0) {
            return $this->default;
        }

        // Not entitled → the tenant may not use a custom backend, even if a stale
        // config row exists.
        if (!$this->entitlements->isGranted($tenantId, EntitlementRegistry::STORAGE_CUSTOM_BACKEND)) {
            return $this->default;
        }

        $config = $this->configs->findForTenant($tenantId);
        if ($config === null) {
            return $this->default;
        }

        return $this->buildDriver($tenantId, $config);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildDriver(int $tenantId, array $config): StorageDriverInterface
    {
        $driver = (string) ($config['driver'] ?? 's3');
        if ($driver !== 's3') {
            // Unknown/unsupported custom backend kind — fail safe to the default
            // rather than guess. (A future 'google_drive' branch lands here.)
            return $this->default;
        }

        $ciphertext = $this->configs->findSecretCiphertext($tenantId);
        if ($ciphertext === null || $ciphertext === '') {
            throw new StorageException(
                "Tenant {$tenantId} has a custom storage backend but no secret; refusing to fall back."
            );
        }

        $publicBaseUrl = $config['public_base_url'] ?? null;
        $s3 = new S3Config(
            endpoint: (string) $config['endpoint'],
            region: (string) $config['region'],
            bucket: (string) $config['bucket'],
            accessKey: (string) $config['access_key'],
            secretKey: $this->secrets->decrypt($ciphertext),
            pathStyle: (bool) $config['path_style'],
            publicBaseUrl: is_string($publicBaseUrl) && $publicBaseUrl !== '' ? $publicBaseUrl : null,
        );

        return new S3StorageDriver($s3, new StreamObjectHttpTransport());
    }
}
