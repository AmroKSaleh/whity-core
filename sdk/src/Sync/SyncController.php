<?php

declare(strict_types=1);

namespace Whity\Sdk\Sync;

use InvalidArgumentException;
use PDO;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * The reusable offline-first two-way sync engine (WC-desktop-sync, generalized).
 *
 * Drives the complete sync lifecycle for any {@see SyncableResource} table, so a
 * ported feature gets desktop-grade sync — the same wire contract the desktop
 * sync engine already speaks for DemoCatalog — for free:
 *
 *   - CREATE idempotent on `(tenant_id, client_uuid)` (retried offline create
 *     returns the existing row, 200, never a duplicate; a genuine create → 201);
 *   - UPDATE / DELETE optimistic concurrency via `If-Match: <version>` (or body
 *     `baseVersion`): a mismatch (or a tombstoned row) → 409 with `serverItem`;
 *   - DELETE is a soft-delete tombstone (`deleted_at`), idempotent;
 *   - a CHANGES FEED (`?updatedSince=<cursor>`) returns rows — tombstones
 *     included — with `change_seq > cursor`, ordered ascending, for incremental
 *     pull; response carries `{ data, cursor, hasMore }`.
 *
 * Every write bumps `version` and stamps a fresh platform-wide `change_seq` from
 * the host {@see SequenceAllocator} (unique + monotonic, gaps harmless because
 * the feed is `change_seq > since`).
 *
 * TENANT SCOPING is enforced here, once: every non-system statement carries an
 * explicit `tenant_id` predicate; the SYSTEM tenant (id 0) is the documented
 * unscoped "sees all" read/administrative context and can never OWN a created
 * row. `$tenantId` is resolved by the caller (from the host's tenant context)
 * and passed in — the SDK never reaches into core.
 *
 * SQL SAFETY: the table and column names come from the (trusted, code-defined)
 * SyncableResource and are validated against `^[a-z_][a-z0-9_]*$` at
 * construction before any interpolation; every value is bound.
 */
final class SyncController
{
    public const SYSTEM_TENANT_ID = 0;
    private const DEFAULT_FEED_LIMIT = 100;
    private const MAX_FEED_LIMIT = 500;
    private const CLIENT_UUID_MAX = 36;

    /** The sync columns every syncable table carries, appended after the domain columns. */
    private const SYNC_COLUMNS = ['version', 'client_uuid', 'deleted_at', 'updated_by', 'change_seq', 'created_at', 'updated_at'];

    private readonly string $table;
    /** @var list<string> */
    private readonly array $domainColumns;
    private readonly string $projection;

    public function __construct(
        private readonly PDO $db,
        private readonly SequenceAllocator $sequences,
        private readonly SyncableResource $resource,
    ) {
        $this->table = self::assertIdentifier($resource->table(), 'table');
        $this->domainColumns = array_map(
            static fn (string $c): string => self::assertIdentifier($c, 'column'),
            array_values($resource->domainColumns()),
        );
        $this->projection = 'id, tenant_id, '
            . implode(', ', array_merge($this->domainColumns, self::SYNC_COLUMNS));
    }

    // ==================== reads ====================

    /**
     * The tenant's live rows (newest first), OR — when `?updatedSince=<cursor>`
     * is present — the incremental changes feed.
     */
    public function list(Request $request, ?int $tenantId): Response
    {
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $query = $this->queryParams($request);
        if (array_key_exists('updatedSince', $query)) {
            return $this->changesFeed($tenantId, $query);
        }

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $stmt = $this->db->prepare(
                    'SELECT ' . $this->projection . ' FROM ' . $this->table
                    . ' WHERE deleted_at IS NULL ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare(
                    'SELECT ' . $this->projection . ' FROM ' . $this->table
                    . ' WHERE tenant_id = :tenant_id AND deleted_at IS NULL ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute([':tenant_id' => $tenantId]);
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return Response::json(['data' => array_map([$this, 'toPublicItem'], $rows)], 200);
        } catch (\Throwable) {
            return Response::error('Failed to fetch items', 500);
        }
    }

    /**
     * @param array<string, mixed> $query
     */
    private function changesFeed(int $tenantId, array $query): Response
    {
        $since = (int) ($query['updatedSince'] ?? 0);
        $includeDeleted = $this->truthy($query['includeDeleted'] ?? '1'); // feed defaults to including tombstones
        $limit = max(1, min((int) ($query['limit'] ?? self::DEFAULT_FEED_LIMIT), self::MAX_FEED_LIMIT));
        $fetch = $limit + 1; // one extra row → hasMore

        try {
            $tombstoneClause = $includeDeleted ? '' : ' AND deleted_at IS NULL';
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                $sql = 'SELECT ' . $this->projection . ' FROM ' . $this->table
                    . ' WHERE change_seq > :since' . $tombstoneClause
                    . ' ORDER BY change_seq ASC LIMIT ' . $fetch;
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':since' => $since]);
            } else {
                $sql = 'SELECT ' . $this->projection . ' FROM ' . $this->table
                    . ' WHERE tenant_id = :tenant_id AND change_seq > :since' . $tombstoneClause
                    . ' ORDER BY change_seq ASC LIMIT ' . $fetch;
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':tenant_id' => $tenantId, ':since' => $since]);
            }

            /** @var array<int, array<string, mixed>> $rows */
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $hasMore = count($rows) > $limit;
            if ($hasMore) {
                array_pop($rows);
            }
            $cursor = $since;
            foreach ($rows as $row) {
                $cursor = max($cursor, (int) ($row['change_seq'] ?? 0));
            }

            return Response::json([
                'data' => array_map([$this, 'toPublicItem'], $rows),
                'cursor' => (string) $cursor,
                'hasMore' => $hasMore,
            ], 200);
        } catch (\Throwable) {
            return Response::error('Failed to fetch changes', 500);
        }
    }

    /** One row in the caller's tenant (including a tombstone). */
    public function get(Request $request, ?int $tenantId, int $id): Response
    {
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $row = $this->findScoped($id, $tenantId);

        return $row === null
            ? Response::error('Item not found', 404)
            : Response::json(['data' => $this->toPublicItem($row)], 200);
    }

    // ==================== writes ====================

    /** Create in the caller's tenant, idempotent on `clientUuid`. */
    public function create(Request $request, ?int $tenantId): Response
    {
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            return Response::error('Cannot create an item in the system tenant', 403);
        }

        $body = $this->decodeBody($request);
        $validated = $this->resource->validate($body, true);
        if ($validated['ok'] === false) {
            return Response::error($validated['error'], 400);
        }
        $clientUuid = $this->clientUuid($body);

        try {
            $seq = $this->nextChangeSeq();

            $cols = array_merge(['tenant_id', 'client_uuid'], $this->domainColumns, ['version', 'change_seq', 'created_at', 'updated_at']);
            $placeholders = array_merge(
                [':tenant_id', ':client_uuid'],
                array_map(static fn (string $c): string => ':' . $c, $this->domainColumns),
                ['1', ':seq', 'NOW()', 'NOW()'],
            );
            $sql = 'INSERT INTO ' . $this->table . ' (' . implode(', ', $cols) . ')'
                . ' VALUES (' . implode(', ', $placeholders) . ')'
                . ' ON CONFLICT (tenant_id, client_uuid) DO NOTHING';

            $bind = [':tenant_id' => $tenantId, ':client_uuid' => $clientUuid, ':seq' => $seq];
            foreach ($this->domainColumns as $col) {
                $bind[':' . $col] = $validated['values'][$col] ?? null;
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($bind);
            $created = $stmt->rowCount() === 1;

            $row = $this->findByClientUuid($clientUuid, $tenantId);
            if ($row === null) {
                return Response::error('Failed to create item', 500);
            }

            return Response::json(['data' => $this->toPublicItem($row)], $created ? 201 : 200);
        } catch (\Throwable) {
            return Response::error('Failed to create item', 500);
        }
    }

    /** Update in the caller's tenant, optimistic-concurrency guarded. */
    public function update(Request $request, ?int $tenantId, int $id): Response
    {
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $body = $this->decodeBody($request);
        $validated = $this->resource->validate($body, true);
        if ($validated['ok'] === false) {
            return Response::error($validated['error'], 400);
        }
        $baseVersion = $this->baseVersion($request, $body);

        try {
            $seq = $this->nextChangeSeq();

            $setClause = implode(', ', array_map(static fn (string $c): string => "{$c} = :{$c}", $this->domainColumns))
                . ', version = version + 1, change_seq = :seq, updated_at = NOW()';

            $bind = [':seq' => $seq, ':id' => $id];
            foreach ($this->domainColumns as $col) {
                $bind[':' . $col] = $validated['values'][$col] ?? null;
            }

            $where = 'id = :id';
            if ($tenantId !== self::SYSTEM_TENANT_ID) {
                $where .= ' AND tenant_id = :tenant_id';
                $bind[':tenant_id'] = $tenantId;
            }
            if ($baseVersion !== null) {
                $where .= ' AND version = :base';
                $bind[':base'] = $baseVersion;
            }
            $where .= ' AND deleted_at IS NULL';

            $stmt = $this->db->prepare('UPDATE ' . $this->table . ' SET ' . $setClause . ' WHERE ' . $where);
            $stmt->execute($bind);

            if ($stmt->rowCount() === 1) {
                $row = $this->findScoped($id, $tenantId);
                return $row === null
                    ? Response::error('Item not found', 404)
                    : Response::json(['data' => $this->toPublicItem($row)], 200);
            }

            return $this->missingOrConflict($id, $tenantId);
        } catch (\Throwable) {
            return Response::error('Failed to update item', 500);
        }
    }

    /** Soft-delete (tombstone) in the caller's tenant; idempotent. */
    public function delete(Request $request, ?int $tenantId, int $id): Response
    {
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $baseVersion = $this->baseVersion($request, []);

        try {
            $seq = $this->nextChangeSeq();
            $bind = [':seq' => $seq, ':id' => $id];

            $where = 'id = :id';
            if ($tenantId !== self::SYSTEM_TENANT_ID) {
                $where .= ' AND tenant_id = :tenant_id';
                $bind[':tenant_id'] = $tenantId;
            }
            if ($baseVersion !== null) {
                $where .= ' AND version = :base';
                $bind[':base'] = $baseVersion;
            }
            $where .= ' AND deleted_at IS NULL';

            $stmt = $this->db->prepare(
                'UPDATE ' . $this->table
                . ' SET deleted_at = NOW(), version = version + 1, change_seq = :seq, updated_at = NOW()'
                . ' WHERE ' . $where
            );
            $stmt->execute($bind);

            if ($stmt->rowCount() === 1) {
                $row = $this->findScoped($id, $tenantId);
                return $row === null
                    ? Response::error('Item not found', 404)
                    : Response::json(['data' => $this->toPublicItem($row)], 200);
            }

            // 404 (missing), idempotent 200 (already a tombstone), or 409 (a base
            // version was given but doesn't match a still-live row).
            $current = $this->findScoped($id, $tenantId);
            if ($current === null) {
                return Response::error('Item not found', 404);
            }
            if (($current['deleted_at'] ?? null) !== null) {
                return Response::json(['data' => $this->toPublicItem($current)], 200);
            }
            return Response::json(['error' => 'Version conflict', 'serverItem' => $this->toPublicItem($current)], 409);
        } catch (\Throwable) {
            return Response::error('Failed to delete item', 500);
        }
    }

    // ==================== internals ====================

    private function missingOrConflict(int $id, int $tenantId): Response
    {
        $current = $this->findScoped($id, $tenantId);
        if ($current === null) {
            return Response::error('Item not found', 404);
        }

        return Response::json(['error' => 'Version conflict', 'serverItem' => $this->toPublicItem($current)], 409);
    }

    private function nextChangeSeq(): int
    {
        return $this->sequences->nextPlatformWide($this->resource->sequenceKey());
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findScoped(int $id, int $tenantId): ?array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            $stmt = $this->db->prepare('SELECT ' . $this->projection . ' FROM ' . $this->table . ' WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT ' . $this->projection . ' FROM ' . $this->table . ' WHERE id = :id AND tenant_id = :tenant_id'
            );
            $stmt->execute([':id' => $id, ':tenant_id' => $tenantId]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findByClientUuid(string $clientUuid, int $tenantId): ?array
    {
        $stmt = $this->db->prepare(
            'SELECT ' . $this->projection . ' FROM ' . $this->table
            . ' WHERE tenant_id = :tenant_id AND client_uuid = :client_uuid'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':client_uuid' => $clientUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function baseVersion(Request $request, array $body): ?int
    {
        $ifMatch = $request->getHeader('If-Match');
        if (is_string($ifMatch)) {
            $trimmed = trim($ifMatch, "\" \t");
            if (preg_match('/^\d+$/', $trimmed) === 1) {
                return (int) $trimmed;
            }
        }
        $bodyValue = $body['baseVersion'] ?? null;

        return is_int($bodyValue) ? $bodyValue : null;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function clientUuid(array $body): string
    {
        $clientUuid = $body['clientUuid'] ?? null;
        if (is_string($clientUuid) && $clientUuid !== '' && mb_strlen($clientUuid) <= self::CLIENT_UUID_MAX) {
            return $clientUuid;
        }

        return self::uuid4();
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublicItem(array $row): array
    {
        $sync = [
            'id' => (int) ($row['id'] ?? 0),
            'tenantId' => (int) ($row['tenant_id'] ?? 0),
            'clientUuid' => isset($row['client_uuid']) ? (string) $row['client_uuid'] : null,
            'version' => (int) ($row['version'] ?? 1),
            'deletedAt' => isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
            'updatedBy' => isset($row['updated_by']) && $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];

        return array_merge($sync, $this->resource->toPublicFields($row));
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeBody(Request $request): array
    {
        $body = json_decode($request->getBody(), true);

        return is_array($body) ? $body : [];
    }

    /**
     * @return array<string, mixed>
     */
    private function queryParams(Request $request): array
    {
        $params = $_GET;
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            parse_str($query, $fromPath);
            $params = array_merge($params, $fromPath);
        }

        return $params;
    }

    private function truthy(mixed $value): bool
    {
        return $value === '1' || $value === 1 || $value === true
            || (is_string($value) && strtolower($value) === 'true');
    }

    private static function assertIdentifier(string $identifier, string $kind): string
    {
        if (preg_match('/^[a-z_][a-z0-9_]*$/', $identifier) !== 1) {
            throw new InvalidArgumentException("Unsafe SQL {$kind} identifier: '{$identifier}'");
        }

        return $identifier;
    }

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
