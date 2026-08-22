<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Data-access layer for `tenant_notification_settings` (WC-notifications): a
 * tenant's per-channel SENDER configuration. Every query binds tenant_id in the
 * SQL literal (guard-visible) so a tenant only ever sees or edits its own config.
 *
 * SECRET HANDLING: the API-facing reads ({@see self::listForTenant()},
 * {@see self::findForChannel()}) NEVER return the stored `credentials_encrypted`
 * blob — only a `has_credentials` flag. {@see self::findWithCredentials()} is the
 * internal accessor a sending transport uses to obtain the (still-encrypted) blob
 * for decryption. `config` values and the encrypted blob are set by two separate
 * upserts so editing config never clobbers stored credentials and vice-versa.
 */
final class TenantNotificationSettingsRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * List a tenant's per-channel sender config — REDACTED (no secret blob).
     *
     * @return list<array<string, mixed>>
     */
    public function listForTenant(int $tenantId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_notification_settings WHERE tenant_id = :tenant_id ORDER BY channel ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => self::mapRedacted($r), $rows));
    }

    /**
     * One channel's sender config for a tenant — REDACTED (no secret blob).
     *
     * @return array<string, mixed>|null
     */
    public function findForChannel(int $tenantId, string $channel): ?array
    {
        $row = $this->rawForChannel($tenantId, $channel);

        return $row === null ? null : self::mapRedacted($row);
    }

    /**
     * One channel's sender config INCLUDING the encrypted credentials blob — for a
     * sending transport to decrypt. Tenant-scoped. NOT exposed over the API.
     *
     * @return array<string, mixed>|null
     */
    public function findWithCredentials(int $tenantId, string $channel): ?array
    {
        $row = $this->rawForChannel($tenantId, $channel);
        if ($row === null) {
            return null;
        }
        $mapped = self::mapRedacted($row);
        $mapped['credentials_encrypted'] = isset($row['credentials_encrypted']) && $row['credentials_encrypted'] !== null
            ? (string) $row['credentials_encrypted']
            : null;

        return $mapped;
    }

    /**
     * Upsert a channel's non-secret sender config (transport, from/reply-to,
     * provider config, enabled) WITHOUT touching stored credentials.
     *
     * @param array{transport?: string|null, from_address?: string|null, from_name?: string|null, reply_to?: string|null, config?: array<string, mixed>, enabled?: bool} $fields
     */
    public function upsertConfig(int $tenantId, string $channel, array $fields): void
    {
        $enabledSql = ($fields['enabled'] ?? true) ? 'TRUE' : 'FALSE';
        $stmt = $this->pdo->prepare(
            "INSERT INTO tenant_notification_settings
                (tenant_id, channel, transport, from_address, from_name, reply_to, config, enabled, created_at, updated_at)
             VALUES (:tenant_id, :channel, :transport, :from_address, :from_name, :reply_to, :config, {$enabledSql}, NOW(), NOW())
             ON CONFLICT (tenant_id, channel) DO UPDATE SET
                transport = :transport, from_address = :from_address, from_name = :from_name,
                reply_to = :reply_to, config = :config, enabled = {$enabledSql}, updated_at = NOW()"
        );
        $config = $fields['config'] ?? [];
        $stmt->execute([
            ':tenant_id'    => $tenantId,
            ':channel'      => $channel,
            ':transport'    => self::nullableString($fields['transport'] ?? null),
            ':from_address' => self::nullableString($fields['from_address'] ?? null),
            ':from_name'    => self::nullableString($fields['from_name'] ?? null),
            ':reply_to'     => self::nullableString($fields['reply_to'] ?? null),
            ':config'       => json_encode($config, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}',
        ]);
    }

    /**
     * Set or clear (null) a channel's ENCRYPTED credentials blob WITHOUT touching
     * the config. The caller encrypts before storing; this layer only persists.
     */
    public function setCredentials(int $tenantId, string $channel, ?string $encrypted): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO tenant_notification_settings (tenant_id, channel, credentials_encrypted, created_at, updated_at)
             VALUES (:tenant_id, :channel, :credentials, NOW(), NOW())
             ON CONFLICT (tenant_id, channel) DO UPDATE SET credentials_encrypted = :credentials, updated_at = NOW()'
        );
        $stmt->execute([
            ':tenant_id'   => $tenantId,
            ':channel'     => $channel,
            ':credentials' => $encrypted,
        ]);
    }

    /**
     * Delete a channel's sender config entirely (config + credentials).
     */
    public function delete(int $tenantId, string $channel): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM tenant_notification_settings WHERE tenant_id = :tenant_id AND channel = :channel'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':channel' => $channel]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function rawForChannel(int $tenantId, string $channel): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM tenant_notification_settings WHERE tenant_id = :tenant_id AND channel = :channel'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':channel' => $channel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    /**
     * Redacted public shape: NEVER includes the encrypted blob, only a boolean flag.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function mapRedacted(array $row): array
    {
        $config = json_decode((string) ($row['config'] ?? '{}'), true);
        $creds = $row['credentials_encrypted'] ?? null;

        return [
            'channel'         => (string) $row['channel'],
            'transport'       => isset($row['transport']) && $row['transport'] !== null ? (string) $row['transport'] : null,
            'from_address'    => isset($row['from_address']) && $row['from_address'] !== null ? (string) $row['from_address'] : null,
            'from_name'       => isset($row['from_name']) && $row['from_name'] !== null ? (string) $row['from_name'] : null,
            'reply_to'        => isset($row['reply_to']) && $row['reply_to'] !== null ? (string) $row['reply_to'] : null,
            'config'          => is_array($config) ? $config : [],
            'has_credentials' => $creds !== null && $creds !== '',
            'enabled'         => self::toBool($row['enabled'] ?? true),
        ];
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = trim((string) $value);

        return $s === '' ? null : $s;
    }

    /**
     * Coerce a DB boolean column to a real bool.
     *
     * Delegates to the canonical coercion (#891). {@see DbBool} records which
     * spellings each driver actually returns — measured on the PHP this
     * platform ships, not assumed — and why a bare `(bool)` cast is not an
     * equivalent substitute for it.
     */
    private static function toBool(mixed $value): bool
    {
        return DbBool::of($value);
    }
}
