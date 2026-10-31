<?php

declare(strict_types=1);

namespace Whity\Core\Plugin\Tenant;

use PDO;

/**
 * Data access for the shared record store (WHIT-761; migration 168).
 *
 * TENANT-OWNED: every statement binds tenant_id, plugin_key and record_type,
 * so a record id from another workspace, plugin or type is reported absent.
 * Callers validate values first ({@see RecordValues}); this class stores them.
 */
final class TenantRecordRepository
{
    public const LIST_MAX = 200;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Records newest first, `$after` being the last id of the previous page.
     *
     * @return list<array<string, mixed>>
     */
    public function list(int $tenantId, string $pluginKey, string $type, ?string $state, ?int $after, int $limit): array
    {
        $sql = 'SELECT id, state, data, created_at, updated_at FROM plugin_records
                 WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key AND record_type = :record_type';
        $params = [':tenant_id' => $tenantId, ':plugin_key' => $pluginKey, ':record_type' => $type];
        if ($state !== null) {
            $sql .= ' AND state = :state';
            $params[':state'] = $state;
        }
        if ($after !== null) {
            $sql .= ' AND id < :after';
            $params[':after'] = $after;
        }
        $sql .= ' ORDER BY id DESC LIMIT ' . max(1, min(self::LIST_MAX, $limit));
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return array_map([self::class, 'row'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /** @return array<string, mixed>|null */
    public function find(int $tenantId, string $pluginKey, string $type, int $id): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT id, state, data, created_at, updated_at FROM plugin_records
              WHERE id = :id AND tenant_id = :tenant_id AND plugin_key = :plugin_key AND record_type = :record_type'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':plugin_key' => $pluginKey, ':record_type' => $type]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? self::row($row) : null;
    }

    /**
     * True when every id is a record of that type in this workspace.
     *
     * @param list<int> $ids
     */
    public function allExist(int $tenantId, string $pluginKey, string $type, array $ids): bool
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return true;
        }
        $placeholders = implode(', ', array_map(static fn (int $i): string => ':id' . $i, array_keys($ids)));
        $stmt = $this->db->prepare(
            "SELECT COUNT(*) FROM plugin_records
              WHERE tenant_id = :tenant_id AND plugin_key = :plugin_key AND record_type = :record_type AND id IN ({$placeholders})"
        );
        $params = [':tenant_id' => $tenantId, ':plugin_key' => $pluginKey, ':record_type' => $type];
        foreach ($ids as $i => $id) {
            $params[':id' . $i] = $id;
        }
        $stmt->execute($params);

        return (int) $stmt->fetchColumn() === count($ids);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(int $tenantId, string $pluginKey, string $type, ?string $state, array $data, ?int $actor): int
    {
        $stmt = $this->db->prepare(
            'INSERT INTO plugin_records (tenant_id, plugin_key, record_type, state, data, created_by, updated_by)
             VALUES (:tenant_id, :plugin_key, :record_type, :state, :data, :created_by, :updated_by)'
        );
        $stmt->execute([
            ':tenant_id' => $tenantId,
            ':plugin_key' => $pluginKey,
            ':record_type' => $type,
            ':state' => $state,
            ':data' => self::encode($data),
            ':created_by' => $actor,
            ':updated_by' => $actor,
        ]);

        return (int) $this->db->lastInsertId();
    }

    /**
     * Replace a record's data (the caller merged the change) and, when given,
     * its state. False when it isn't this workspace's record of that type.
     *
     * @param array<string, mixed> $data
     */
    public function update(int $tenantId, string $pluginKey, string $type, int $id, array $data, ?string $state, ?int $actor): bool
    {
        $sql = 'UPDATE plugin_records SET data = :data, updated_by = :updated_by, updated_at = CURRENT_TIMESTAMP';
        $params = [':data' => self::encode($data), ':updated_by' => $actor];
        if ($state !== null) {
            $sql .= ', state = :state';
            $params[':state'] = $state;
        }
        $sql .= ' WHERE id = :id AND tenant_id = :tenant_id AND plugin_key = :plugin_key AND record_type = :record_type';
        $params += [':id' => $id, ':tenant_id' => $tenantId, ':plugin_key' => $pluginKey, ':record_type' => $type];
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);

        return $stmt->rowCount() > 0;
    }

    public function delete(int $tenantId, string $pluginKey, string $type, int $id): bool
    {
        $stmt = $this->db->prepare(
            'DELETE FROM plugin_records
              WHERE id = :id AND tenant_id = :tenant_id AND plugin_key = :plugin_key AND record_type = :record_type'
        );
        $stmt->execute([':id' => $id, ':tenant_id' => $tenantId, ':plugin_key' => $pluginKey, ':record_type' => $type]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function row(array $row): array
    {
        $data = json_decode((string) $row['data'], true);

        return [
            'id' => (int) $row['id'],
            'state' => $row['state'] === null ? null : (string) $row['state'],
            'data' => is_array($data) ? $data : [],
            'created_at' => (string) $row['created_at'],
            'updated_at' => (string) $row['updated_at'],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function encode(array $data): string
    {
        return (string) json_encode((object) $data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }
}
