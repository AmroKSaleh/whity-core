<?php

declare(strict_types=1);

namespace Whity\Core\Identity;

use PDO;

/**
 * Repository for the `identity_providers` table (WC-e6287).
 *
 * Per-tenant SSO/OIDC provider configurations. TENANT-OWNED: every statement
 * binds `tenant_id`, so a tenant can only read or mutate its own providers — a
 * cross-tenant read returns null and a cross-tenant update/delete affects zero
 * rows (proven in IdentityProviderRepositoryRealEngineTest).
 *
 * SECRET HANDLING: `client_secret_encrypted` holds EncryptedSecretStore
 * ciphertext. The normal read methods ({@see listForTenant()}, {@see findById()},
 * {@see findByProviderKey()}) DELIBERATELY omit it and expose only `has_secret`,
 * so the admin API can never echo it back. The OIDC engine reads the ciphertext
 * via the explicit {@see findClientSecretCiphertext()} and decrypts it itself.
 */
final class IdentityProviderRepository
{
    private PDO $db;

    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Insert a provider config for a tenant. Fields are validated by the caller
     * (IdentityProvidersApiHandler); client_secret_encrypted must already be
     * EncryptedSecretStore ciphertext, never plaintext.
     *
     * @param array<string, mixed> $data provider_key, display_name, client_id,
     *   issuer (required); client_secret_encrypted, discovery_url, scopes, domain,
     *   enabled (optional).
     * @return int The new row's id.
     */
    public function insert(int $tenantId, array $data): int
    {
        // `enabled` is a CONTROLLED boolean (never user text), so it is inlined as
        // a TRUE/FALSE literal — portable across Postgres + the SQLite test shim.
        // Binding an int/PHP-bool to a PG boolean column is the classic 42804 trap
        // (and PHP false binds as '' which PG rejects), so avoid it entirely.
        $enabledLiteral = ($data['enabled'] ?? true) ? 'TRUE' : 'FALSE';

        $stmt = $this->db->prepare(
            "INSERT INTO identity_providers
                 (tenant_id, provider_key, display_name, client_id, client_secret_encrypted,
                  issuer, discovery_url, scopes, domain, enabled, created_at, updated_at)
             VALUES (:tenant_id, :provider_key, :display_name, :client_id, :client_secret_encrypted,
                  :issuer, :discovery_url, :scopes, :domain, {$enabledLiteral}, NOW(), NOW())"
        );
        $stmt->execute([
            ':tenant_id'               => $tenantId,
            ':provider_key'            => $data['provider_key'],
            ':display_name'            => $data['display_name'],
            ':client_id'               => $data['client_id'],
            ':client_secret_encrypted' => $data['client_secret_encrypted'] ?? null,
            ':issuer'                  => $data['issuer'],
            ':discovery_url'           => $data['discovery_url'] ?? null,
            ':scopes'                  => $data['scopes'] ?? 'openid email profile',
            ':domain'                  => $data['domain'] ?? null,
        ]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * @return list<array<string, mixed>> Providers for the tenant (secret omitted).
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM identity_providers WHERE tenant_id = :tenant_id ORDER BY provider_key ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_map(fn(array $r): array => $this->normalizeRow($r), $rows);
    }

    /**
     * @return array<string, mixed>|null The provider (secret omitted), or null / wrong tenant.
     */
    public function findById(int $id, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM identity_providers WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Resolve an ENABLED provider by its key for a tenant (secret omitted) — the
     * sign-in-initiation lookup.
     *
     * @return array<string, mixed>|null
     */
    public function findEnabledByProviderKey(int $tenantId, string $providerKey): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT * FROM identity_providers
             WHERE tenant_id = :tenant_id AND provider_key = :provider_key AND enabled = TRUE
             LIMIT 1'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':provider_key' => $providerKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row !== false ? $this->normalizeRow($row) : null;
    }

    /**
     * Read the encrypted client secret for a provider — used ONLY by the OIDC
     * engine's token exchange, never surfaced to an API response. Tenant-scoped.
     */
    public function findClientSecretCiphertext(int $id, int $tenantId): ?string
    {
        $stmt = $this->db->prepare(
            'SELECT client_secret_encrypted FROM identity_providers WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        $value = $stmt->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }
        return (string) $value;
    }

    /**
     * Update mutable fields of a provider, scoped to the tenant. Only keys present
     * in $data are changed; client_secret_encrypted is updated only when the key
     * is present (so an edit that doesn't re-send the secret keeps the old one).
     *
     * @param array<string, mixed> $data
     * @return int Rows affected (0 if not found / wrong tenant).
     */
    public function update(int $id, int $tenantId, array $data): int
    {
        $allowed = ['provider_key', 'display_name', 'client_id', 'client_secret_encrypted',
                    'issuer', 'discovery_url', 'scopes', 'domain'];
        $sets = [];
        $params = [':id' => $id, ':tenant_id' => $tenantId];
        foreach ($allowed as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $sets[] = "{$col} = :{$col}";
            $params[":{$col}"] = $data[$col];
        }
        // `enabled` (controlled boolean) inlined as a TRUE/FALSE literal — portable,
        // and avoids the PG boolean-bind trap (see insert()).
        if (array_key_exists('enabled', $data)) {
            $sets[] = 'enabled = ' . ((bool) $data['enabled'] ? 'TRUE' : 'FALSE');
        }
        if ($sets === []) {
            return 0;
        }
        $sets[] = 'updated_at = NOW()';

        $sql = 'UPDATE identity_providers SET ' . implode(', ', $sets)
             . ' WHERE id = :id AND tenant_id = :tenant_id';
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * @return int Rows affected (0 if not found / wrong tenant).
     */
    public function delete(int $id, int $tenantId): int
    {
        $stmt = $this->db->prepare(
            'DELETE FROM identity_providers WHERE id = :id AND tenant_id = :tenant_id'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        return $stmt->rowCount();
    }

    /**
     * Cast columns and OMIT the encrypted secret, exposing only whether one is set.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function normalizeRow(array $row): array
    {
        return [
            'id'            => (int) $row['id'],
            'tenant_id'     => (int) $row['tenant_id'],
            'provider_key'  => (string) $row['provider_key'],
            'display_name'  => (string) $row['display_name'],
            'client_id'     => (string) $row['client_id'],
            'has_secret'    => isset($row['client_secret_encrypted']) && (string) $row['client_secret_encrypted'] !== '',
            'issuer'        => (string) $row['issuer'],
            'discovery_url' => $row['discovery_url'] !== null ? (string) $row['discovery_url'] : null,
            'scopes'        => (string) $row['scopes'],
            'domain'        => $row['domain'] !== null ? (string) $row['domain'] : null,
            'enabled'       => (bool) $row['enabled'],
            'created_at'    => (string) $row['created_at'],
            'updated_at'    => (string) $row['updated_at'],
        ];
    }
}
