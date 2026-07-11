<?php

declare(strict_types=1);

namespace Whity\Storage;

/**
 * A {@see StorageDriverInterface} that routes each operation to the correct
 * per-tenant backend, parsing the tenant id from the storage key (WC-storage).
 *
 * Every canonical key is `tenants/{id}/...` ({@see StorageKey}), so this driver
 * needs no tenant CONTEXT — it reads the tenant straight off the key and asks the
 * {@see TenantStorageResolver} for that tenant's driver. That is what lets it sit
 * transparently behind the EXISTING branding consumers (which already build
 * tenant-scoped keys) with no constructor changes, AND serve the PUBLIC,
 * context-less asset path correctly (the tenant is in the URL-derived key, not
 * TenantContext).
 *
 * A key that does not carry a tenant segment routes to the platform default —
 * the resolver's own fallback for tenant 0 / unconfigured tenants.
 */
final class TenantRoutingStorageDriver implements StorageDriverInterface
{
    private StorageDriverInterface $default;
    private TenantStorageResolver $resolver;

    public function __construct(StorageDriverInterface $default, TenantStorageResolver $resolver)
    {
        $this->default = $default;
        $this->resolver = $resolver;
    }

    public function put(string $key, string $contents, array $metadata = []): void
    {
        $this->driverForKey($key)->put($key, $contents, $metadata);
    }

    public function get(string $key): string
    {
        return $this->driverForKey($key)->get($key);
    }

    public function getStream(string $key): mixed
    {
        return $this->driverForKey($key)->getStream($key);
    }

    public function delete(string $key): void
    {
        $this->driverForKey($key)->delete($key);
    }

    public function exists(string $key): bool
    {
        return $this->driverForKey($key)->exists($key);
    }

    public function copy(string $source, string $destination): void
    {
        // copy/move within one tenant's namespace: route by the SOURCE key (the
        // bytes being read). Branding never copies across tenants; a source and
        // destination in different tenants would be a caller bug, not a mode.
        $this->driverForKey($source)->copy($source, $destination);
    }

    public function move(string $source, string $destination): void
    {
        $this->driverForKey($source)->move($source, $destination);
    }

    public function size(string $key): int
    {
        return $this->driverForKey($key)->size($key);
    }

    public function mimeType(string $key): string
    {
        return $this->driverForKey($key)->mimeType($key);
    }

    public function lastModified(string $key): int
    {
        return $this->driverForKey($key)->lastModified($key);
    }

    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): string
    {
        return $this->driverForKey($key)->temporaryUrl($key, $expiresInSeconds);
    }

    public function publicUrl(string $key): string
    {
        return $this->driverForKey($key)->publicUrl($key);
    }

    /**
     * The backend for the tenant encoded in $key, or the platform default when
     * the key carries no tenant segment.
     */
    private function driverForKey(string $key): StorageDriverInterface
    {
        $tenantId = StorageKey::tenantId($key);

        return $tenantId !== null ? $this->resolver->forTenant($tenantId) : $this->default;
    }
}
