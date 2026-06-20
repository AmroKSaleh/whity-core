<?php

declare(strict_types=1);

namespace Whity\Storage;

/**
 * Local-filesystem StorageDriver (Tenant Branding — first concrete driver).
 *
 * Stores opaque keys (e.g. `tenants/1/branding/logo_wide-<hash>.png`) as files
 * under an absolute root. Every resolved path is verified to stay within the
 * root (defense-in-depth against traversal; keys are already sanitized by
 * StorageKey upstream). Has no public web path: publicUrl()/temporaryUrl()
 * throw — branding URLs are built by BrandingService and served by the asset
 * route. Throws StorageException on all IO failures (uniform error surface).
 */
final class LocalStorageDriver implements StorageDriverInterface
{
    private string $root;

    public function __construct(string $root)
    {
        $this->root = rtrim($root, '/\\');
    }

    public function put(string $key, string $contents, array $metadata = []): void
    {
        $path = $this->resolve($key);
        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new StorageException("Cannot create directory for key: {$key}");
        }
        if (@file_put_contents($path, $contents) === false) {
            throw new StorageException("Cannot write key: {$key}");
        }
    }

    public function get(string $key): string
    {
        $path = $this->resolve($key);
        if (!is_file($path)) {
            throw new StorageException("Key not found: {$key}");
        }
        $data = @file_get_contents($path);
        if ($data === false) {
            throw new StorageException("Cannot read key: {$key}");
        }
        return $data;
    }

    /** @return resource */
    public function getStream(string $key): mixed
    {
        $path = $this->resolve($key);
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            throw new StorageException("Cannot open key: {$key}");
        }
        return $fh;
    }

    public function delete(string $key): void
    {
        $path = $this->resolve($key);
        if (is_file($path) && !@unlink($path)) {
            throw new StorageException("Cannot delete key: {$key}");
        }
    }

    public function exists(string $key): bool
    {
        return is_file($this->resolve($key));
    }

    public function copy(string $source, string $destination): void
    {
        $this->put($destination, $this->get($source));
    }

    public function move(string $source, string $destination): void
    {
        $this->copy($source, $destination);
        $this->delete($source);
    }

    public function size(string $key): int
    {
        $path = $this->resolve($key);
        if (!is_file($path)) {
            throw new StorageException("Key not found: {$key}");
        }
        $size = @filesize($path);
        if ($size === false) {
            throw new StorageException("Cannot stat key: {$key}");
        }
        return $size;
    }

    public function mimeType(string $key): string
    {
        return match (strtolower(pathinfo($key, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            'ico' => 'image/x-icon',
            default => 'application/octet-stream',
        };
    }

    public function lastModified(string $key): int
    {
        $path = $this->resolve($key);
        if (!is_file($path)) {
            throw new StorageException("Key not found: {$key}");
        }
        $mtime = @filemtime($path);
        if ($mtime === false) {
            throw new StorageException("Cannot stat key: {$key}");
        }
        return $mtime;
    }

    public function temporaryUrl(string $key, int $expiresInSeconds = 3600): string
    {
        throw new \RuntimeException('LocalStorageDriver does not support temporary URLs.');
    }

    public function publicUrl(string $key): string
    {
        throw new \RuntimeException('LocalStorageDriver does not support public URLs.');
    }

    /**
     * Resolve an opaque key to an absolute path under the root and verify it
     * cannot escape (defense-in-depth against traversal).
     */
    private function resolve(string $key): string
    {
        $normalized = str_replace('\\', '/', $key);
        if (str_contains($normalized, "\0") || str_contains($normalized, '../') || str_starts_with($normalized, '/')) {
            throw new StorageException("Unsafe storage key: {$key}");
        }
        $path = $this->root . '/' . ltrim($normalized, '/');
        // Final guard: the lexically-resolved path must remain within root.
        if (!str_starts_with($path, $this->root . '/')) {
            throw new StorageException("Storage key escapes root: {$key}");
        }
        return $path;
    }
}
