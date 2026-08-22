<?php

declare(strict_types=1);

namespace Whity\Core\Notification;

use PDO;
use Whity\Core\Db\DbBool;

/**
 * Data-access layer for `user_notification_preferences` (WC-notifications): a
 * profile's per-(type, channel) delivery toggles. Every query is scoped to
 * (tenant, profile) — the tenant_id predicate lives in the SQL literal
 * (guard-visible) and the profile_id predicate self-scopes to the caller.
 *
 * `type` is a notification type key or the sentinel '*' (a channel-wide toggle).
 * The resolver ({@see NotificationPreferenceResolver}) applies precedence; this
 * layer is pure CRUD.
 */
final class NotificationPreferenceRepository
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    /**
     * List a profile's preference rows in a tenant.
     *
     * @return list<array{type: string, channel: string, enabled: bool}>
     */
    public function listForProfile(int $tenantId, int $profileId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT type, channel, enabled FROM user_notification_preferences
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id
              ORDER BY type ASC, channel ASC'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':profile_id' => $profileId]);
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_map(static fn (array $r): array => [
            'type'    => (string) $r['type'],
            'channel' => (string) $r['channel'],
            'enabled' => self::toBool($r['enabled']),
        ], $rows));
    }

    /**
     * Upsert one (type, channel) toggle for a profile in a tenant. Idempotent via
     * the UNIQUE(tenant_id, profile_id, type, channel) index.
     */
    public function set(int $tenantId, int $profileId, string $type, string $channel, bool $enabled): void
    {
        // The enabled flag is a trusted derived boolean; inject as a SQL LITERAL
        // so it types correctly on both Postgres and the SQLite test engine.
        $enabledSql = $enabled ? 'TRUE' : 'FALSE';
        $stmt = $this->pdo->prepare(
            "INSERT INTO user_notification_preferences (tenant_id, profile_id, type, channel, enabled, created_at, updated_at)
             VALUES (:tenant_id, :profile_id, :type, :channel, {$enabledSql}, NOW(), NOW())
             ON CONFLICT (tenant_id, profile_id, type, channel)
             DO UPDATE SET enabled = {$enabledSql}, updated_at = NOW()"
        );
        $stmt->execute([
            ':tenant_id'  => $tenantId,
            ':profile_id' => $profileId,
            ':type'       => $type,
            ':channel'    => $channel,
        ]);
    }

    /**
     * Clear one of a profile's toggles (revert to the default). Returns whether a
     * row was removed.
     */
    public function delete(int $tenantId, int $profileId, string $type, string $channel): bool
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM user_notification_preferences
              WHERE tenant_id = :tenant_id AND profile_id = :profile_id AND type = :type AND channel = :channel'
        );
        $stmt->execute([
            ':tenant_id'  => $tenantId,
            ':profile_id' => $profileId,
            ':type'       => $type,
            ':channel'    => $channel,
        ]);

        return $stmt->rowCount() > 0;
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
