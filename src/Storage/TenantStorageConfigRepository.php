<?php

declare(strict_types=1);

namespace Whity\Storage;

use PDO;

/**
 * Data-access layer for `tenant_storage_config` — a tenant's own object-storage
 * backend (WC-storage).
 *
 * TENANT-OWNED (see {@see \Whity\Core\Tenant\TenantOwnedTables}): every row
 * belongs to one tenant and every SELECT/UPDATE/DELETE binds an explicit
 * `tenant_id` predicate, so a row written under one tenant can never be read or
 * mutated under another.
 *
 * The secret is WRITE-ONLY: {@see findForTenant()} omits `secret_encrypted` and
 * exposes only `has_secret`, mirroring IdentityProviderRepository. Only
 * {@see findSecretCiphertext()} returns the ciphertext, for the resolver to
 * decrypt when building the driver — it is never surfaced to any API.
 *
 * Values persist as-is; the caller encrypts the secret via EncryptedSecretStore
 * before {@see upsert()}.
 */
final class TenantStorageConfigRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * The tenant's storage config WITHOUT the secret (adds `has_secret`), or null
     * when the tenant has configured no custom backend.
     *
     * @return array<string, mixed>|null
     */
    public function findForTenant(int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, tenant_id, driver, endpoint, region, bucket, access_key,
                    secret_encrypted, path_style, public_base_url, created_at, updated_at
             FROM tenant_storage_config WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return $this->normalizeRow($row);
    }

    /**
     * The encrypted secret ciphertext for the tenant's backend, or null when
     * absent. The ONLY path that returns the secret material — for the resolver.
     */
    public function findSecretCiphertext(int $tenantId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT secret_encrypted FROM tenant_storage_config WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        $value = $stmt->fetchColumn();

        return ($value === false || $value === null) ? null : (string) $value;
    }

    /**
     * Insert or replace the tenant's storage config.
     *
     * @param array{driver?: string, endpoint: string, region: string, bucket: string,
     *              access_key: string, secret_encrypted: string, path_style?: bool,
     *              public_base_url?: ?string} $data
     */
    public function upsert(int $tenantId, array $data): void
    {
        $stmt = $this->db->prepare(
            'INSERT INTO tenant_storage_config
                 (tenant_id, driver, endpoint, region, bucket, access_key,
                  secret_encrypted, path_style, public_base_url, created_at, updated_at)
             VALUES
                 (:tenant_id, :driver, :endpoint, :region, :bucket, :access_key,
                  :secret_encrypted, :path_style, :public_base_url, NOW(), NOW())
             ON CONFLICT (tenant_id) DO UPDATE SET
                 driver           = EXCLUDED.driver,
                 endpoint         = EXCLUDED.endpoint,
                 region           = EXCLUDED.region,
                 bucket           = EXCLUDED.bucket,
                 access_key       = EXCLUDED.access_key,
                 secret_encrypted = EXCLUDED.secret_encrypted,
                 path_style       = EXCLUDED.path_style,
                 public_base_url  = EXCLUDED.public_base_url,
                 updated_at       = NOW()'
        );
        $stmt->execute([
            ':tenant_id'        => $tenantId,
            ':driver'           => $data['driver'] ?? 's3',
            ':endpoint'         => $data['endpoint'],
            ':region'           => $data['region'],
            ':bucket'           => $data['bucket'],
            ':access_key'       => $data['access_key'],
            ':secret_encrypted' => $data['secret_encrypted'],
            ':path_style'       => ($data['path_style'] ?? true) ? 1 : 0,
            ':public_base_url'  => $data['public_base_url'] ?? null,
        ]);
    }

    /**
     * Delete the tenant's storage config (reverting it to the platform default).
     * Returns rows removed (0 when absent).
     */
    public function delete(int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM tenant_storage_config WHERE tenant_id = :tenant_id'
        );
        $stmt->execute([':tenant_id' => $tenantId]);

        return $stmt->rowCount();
    }

    /**
     * Cast DB columns to proper PHP types and swap the secret for a `has_secret`
     * flag. PostgreSQL's PDO returns everything as strings; booleans come back as
     * 't'/'f' (the (bool)'f' === true trap), so path_style is normalised safely.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'              => (int) $row['id'],
            'tenant_id'       => (int) $row['tenant_id'],
            'driver'          => (string) $row['driver'],
            'endpoint'        => (string) $row['endpoint'],
            'region'          => (string) $row['region'],
            'bucket'          => (string) $row['bucket'],
            'access_key'      => (string) $row['access_key'],
            'has_secret'      => isset($row['secret_encrypted']) && (string) $row['secret_encrypted'] !== '',
            'path_style'      => self::toBool($row['path_style']),
            'public_base_url' => $row['public_base_url'] !== null ? (string) $row['public_base_url'] : null,
            'created_at'      => (string) $row['created_at'],
            'updated_at'      => (string) $row['updated_at'],
        ];
    }

    /**
     * Portable DB-boolean coercion: PG returns 't'/'f' strings (and (bool)'f' is
     * TRUE), SQLite 0/1, in-process a real bool.
     */
    private static function toBool(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'f', 'false', 'no'], true);
    }
}
