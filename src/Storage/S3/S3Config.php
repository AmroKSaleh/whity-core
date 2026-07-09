<?php

declare(strict_types=1);

namespace Whity\Storage\S3;

/**
 * Immutable connection config for an S3-compatible object store (WC-b8c5a271).
 *
 * Works for AWS S3, Cloudflare R2, Backblaze B2 and MinIO. Resolved at wiring
 * time from GLOBAL admin settings (operator-level; the secret via
 * EncryptedSecretStore) — the driver itself is config-agnostic.
 */
final class S3Config
{
    /**
     * @param string      $endpoint      Base endpoint, scheme + host only, no bucket/path,
     *                                    no trailing slash (e.g. https://s3.us-east-1.amazonaws.com,
     *                                    https://<acct>.r2.cloudflarestorage.com, https://minio.internal:9000).
     * @param bool        $pathStyle     true → path-style (endpoint/bucket/key); false → virtual-hosted
     *                                    (bucket.endpoint/key). R2/MinIO need path-style; default true (broadest compat).
     * @param string|null $publicBaseUrl If the bucket is public / fronted by a CDN, the base URL for
     *                                    publicUrl() (e.g. https://cdn.example.com). null → publicUrl() throws.
     */
    public function __construct(
        public readonly string $endpoint,
        public readonly string $region,
        public readonly string $bucket,
        public readonly string $accessKey,
        public readonly string $secretKey,
        public readonly bool $pathStyle = true,
        public readonly ?string $publicBaseUrl = null,
    ) {
    }
}
