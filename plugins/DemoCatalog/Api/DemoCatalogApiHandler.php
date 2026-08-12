<?php

declare(strict_types=1);

namespace DemoCatalog\Api;

use PDO;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Sql\SequenceAllocator;

/**
 * Tenant-scoped, SYNC-CAPABLE CRUD over the plugin's `demo_catalog_items` table.
 *
 * The DB-backed half of the DemoCatalog pilot (WC-features-pilot), extended for
 * offline-first two-way sync (WC-desktop-sync):
 *   - CREATE is idempotent on a client-supplied `clientUuid` (a retried offline
 *     create returns the existing row, never a duplicate);
 *   - UPDATE / DELETE support optimistic concurrency — an `If-Match` header (or
 *     body `baseVersion`) that doesn't match the row's current `version` yields
 *     409 with the current `serverItem`, so a client can field-level resolve;
 *   - DELETE is a soft-delete tombstone (`deleted_at`), so deletions propagate;
 *   - a CHANGES FEED (`?updatedSince=<cursor>`) returns rows — including
 *     tombstones — with `change_seq > cursor`, for incremental pull.
 * Every write bumps the row's `version` and stamps a fresh global `change_seq`.
 *
 * Tenant scoping is unchanged: every non-system-tenant statement carries an
 * explicit `tenant_id` predicate from {@see TenantContext}; the SYSTEM tenant
 * (id 0) is the documented unscoped "sees all" exception (each such branch
 * annotated `@tenant-guard-ignore:`).
 *
 * The change-feed cursor is allocated by the host
 * ({@see \Whity\Sdk\Sql\SequenceAllocator}). This plugin used to own a one-row
 * `demo_catalog_change_seq` table for it, created by its own migration and read
 * through a driver branch whose SQLite half was a read-then-write across two
 * statements — so two concurrent writers could stamp two rows with one cursor
 * and a puller would see one of them and never the other. That table, that
 * migration and that branch are gone; the plugin asks for a number.
 */
final class DemoCatalogApiHandler
{
    private const SYSTEM_TENANT_ID = 0;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_DESCRIPTION_LENGTH = 2000;
    private const DEFAULT_FEED_LIMIT = 100;
    private const MAX_FEED_LIMIT = 500;

    /** @var list<string> */
    private const VALID_STATUSES = ['active', 'archived'];

    /** The full projection selected for every read. */
    private const COLS = 'id, tenant_id, name, description, status, version, '
        . 'client_uuid, deleted_at, updated_by, change_seq, created_at, updated_at';

    private PDO $db;

    /**
     * The host's sequence allocator, injected rather than reached for: the
     * plugin names the SDK contract and never the host class behind it.
     */
    private SequenceAllocator $sequences;

    /** Set by {@see validatedInput()} on failure; read by callers to build the 400. */
    private string $lastValidationError = '';

    public function __construct(PDO $db, SequenceAllocator $sequences)
    {
        $this->db = $db;
        $this->sequences = $sequences;
    }

    // ==================== reads ====================

    /**
     * GET /api/demo-catalog/items — the tenant's live items (newest first), OR,
     * when `?updatedSince=<cursor>` is present, the incremental CHANGES FEED.
     */
    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $query = $this->queryParams($request);
        if (array_key_exists('updatedSince', $query)) {
            return $this->changesFeed($tenantId, $query);
        }

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                // @tenant-guard-ignore: system-tenant (id 0) branch — sees every tenant's items by design
                $stmt = $this->db->prepare(
                    'SELECT ' . self::COLS . ' FROM demo_catalog_items
                     WHERE deleted_at IS NULL ORDER BY created_at DESC, id DESC'
                );
                $stmt->execute();
            } else {
                $stmt = $this->db->prepare(
                    'SELECT ' . self::COLS . ' FROM demo_catalog_items
                     WHERE tenant_id = :tenant_id AND deleted_at IS NULL
                     ORDER BY created_at DESC, id DESC'
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
     * The incremental changes feed: rows with `change_seq > :since`, ordered by
     * `change_seq`, including tombstones by default so deletions propagate.
     *
     * @param array<string, mixed> $query
     */
    private function changesFeed(int $tenantId, array $query): Response
    {
        $since = (int) ($query['updatedSince'] ?? 0);
        $includeDeleted = $this->truthy($query['includeDeleted'] ?? '1'); // feed defaults to including tombstones
        $limit = (int) ($query['limit'] ?? self::DEFAULT_FEED_LIMIT);
        $limit = max(1, min($limit, self::MAX_FEED_LIMIT));
        $fetch = $limit + 1; // one extra row → hasMore

        try {
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                if ($includeDeleted) {
                    // @tenant-guard-ignore: system-tenant (id 0) feed — every tenant's changes by design
                    $sql = 'SELECT ' . self::COLS . ' FROM demo_catalog_items
                            WHERE change_seq > :since ORDER BY change_seq ASC LIMIT ' . $fetch;
                } else {
                    // @tenant-guard-ignore: system-tenant (id 0) feed — every tenant's changes by design
                    $sql = 'SELECT ' . self::COLS . ' FROM demo_catalog_items
                            WHERE change_seq > :since AND deleted_at IS NULL
                            ORDER BY change_seq ASC LIMIT ' . $fetch;
                }
                $stmt = $this->db->prepare($sql);
                $stmt->execute([':since' => $since]);
            } else {
                if ($includeDeleted) {
                    $sql = 'SELECT ' . self::COLS . ' FROM demo_catalog_items
                            WHERE tenant_id = :tenant_id AND change_seq > :since
                            ORDER BY change_seq ASC LIMIT ' . $fetch;
                } else {
                    $sql = 'SELECT ' . self::COLS . ' FROM demo_catalog_items
                            WHERE tenant_id = :tenant_id AND change_seq > :since AND deleted_at IS NULL
                            ORDER BY change_seq ASC LIMIT ' . $fetch;
                }
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

    /**
     * GET /api/demo-catalog/items/{id} — one item in the caller's tenant
     * (including a tombstone, so a sync client can observe a server-side delete).
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function get(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $row = $this->findScoped((int) ($params['id'] ?? 0), $tenantId);
        if ($row === null) {
            return Response::error('Item not found', 404);
        }

        return Response::json(['data' => $this->toPublicItem($row)], 200);
    }

    // ==================== writes ====================

    /**
     * POST /api/demo-catalog/items — create in the caller's tenant, IDEMPOTENT on
     * `clientUuid`: a retried offline create (same clientUuid) returns the
     * existing row (200) rather than a duplicate; a genuine create returns 201.
     */
    public function create(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            // Creating requires a concrete owning tenant — the system tenant is a
            // read/administrative context, never the owner of a new row.
            return Response::error('Cannot create an item in the system tenant', 403);
        }

        $input = $this->validatedInput($request, requireName: true);
        if ($input === null) {
            return Response::error($this->lastValidationError, 400);
        }
        $clientUuid = $input['clientUuid'] ?? self::uuid4();

        try {
            $seq = $this->nextChangeSeq();
            // INSERT is exempt from the tenant-predicate scanner; the (tenant_id,
            // client_uuid) unique index makes this idempotent per tenant.
            $stmt = $this->db->prepare(
                'INSERT INTO demo_catalog_items
                    (tenant_id, client_uuid, name, description, status, version, change_seq, created_at, updated_at)
                 VALUES (:tenant_id, :client_uuid, :name, :description, :status, 1, :seq, NOW(), NOW())
                 ON CONFLICT (tenant_id, client_uuid) DO NOTHING'
            );
            $stmt->execute([
                ':tenant_id' => $tenantId,
                ':client_uuid' => $clientUuid,
                ':name' => $input['name'],
                ':description' => $input['description'],
                ':status' => $input['status'],
                ':seq' => $seq,
            ]);

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

    /**
     * PATCH /api/demo-catalog/items/{id} — update in the caller's tenant.
     *
     * Optimistic concurrency: when the caller supplies `If-Match: <version>` (or
     * body `baseVersion`), a version mismatch (or a tombstoned row) returns 409
     * with the current `serverItem`. Absent, the update is a blind-but-versioned
     * write (preserves the plain web CRUD flow).
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function update(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $id = (int) ($params['id'] ?? 0);
        $input = $this->validatedInput($request, requireName: true);
        if ($input === null) {
            return Response::error($this->lastValidationError, 400);
        }
        $baseVersion = $this->baseVersion($request, $input);

        try {
            $seq = $this->nextChangeSeq();
            $bind = [
                ':name' => $input['name'],
                ':description' => $input['description'],
                ':status' => $input['status'],
                ':seq' => $seq,
                ':id' => $id,
            ];
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                if ($baseVersion !== null) {
                    // @tenant-guard-ignore: system-tenant (id 0) branch — may update any tenant's item by design
                    $sql = 'UPDATE demo_catalog_items
                            SET name = :name, description = :description, status = :status,
                                version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND version = :base AND deleted_at IS NULL';
                    $bind[':base'] = $baseVersion;
                } else {
                    // @tenant-guard-ignore: system-tenant (id 0) branch — may update any tenant's item by design
                    $sql = 'UPDATE demo_catalog_items
                            SET name = :name, description = :description, status = :status,
                                version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND deleted_at IS NULL';
                }
            } else {
                $bind[':tenant_id'] = $tenantId;
                if ($baseVersion !== null) {
                    $sql = 'UPDATE demo_catalog_items
                            SET name = :name, description = :description, status = :status,
                                version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND tenant_id = :tenant_id AND version = :base AND deleted_at IS NULL';
                    $bind[':base'] = $baseVersion;
                } else {
                    $sql = 'UPDATE demo_catalog_items
                            SET name = :name, description = :description, status = :status,
                                version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL';
                }
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($bind);

            if ($stmt->rowCount() === 1) {
                $row = $this->findScoped($id, $tenantId);
                return $row === null
                    ? Response::error('Item not found', 404)
                    : Response::json(['data' => $this->toPublicItem($row)], 200);
            }

            // No row updated: distinguish "missing" (404) from "version conflict" (409).
            $current = $this->findScoped($id, $tenantId);
            if ($current === null) {
                return Response::error('Item not found', 404);
            }
            return Response::json(
                ['error' => 'Version conflict', 'serverItem' => $this->toPublicItem($current)],
                409
            );
        } catch (\Throwable) {
            return Response::error('Failed to update item', 500);
        }
    }

    /**
     * DELETE /api/demo-catalog/items/{id} — soft-delete (tombstone) in the
     * caller's tenant. If-Match/`baseVersion` guarded (409 on mismatch);
     * idempotent (deleting an already-deleted row returns its tombstone).
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function delete(Request $request, array $params = []): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $id = (int) ($params['id'] ?? 0);
        $baseVersion = $this->baseVersion($request, []);

        try {
            $seq = $this->nextChangeSeq();
            $bind = [':seq' => $seq, ':id' => $id];
            if ($tenantId === self::SYSTEM_TENANT_ID) {
                if ($baseVersion !== null) {
                    // @tenant-guard-ignore: system-tenant (id 0) branch — may delete any tenant's item by design
                    $sql = 'UPDATE demo_catalog_items
                            SET deleted_at = NOW(), version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND version = :base AND deleted_at IS NULL';
                    $bind[':base'] = $baseVersion;
                } else {
                    // @tenant-guard-ignore: system-tenant (id 0) branch — may delete any tenant's item by design
                    $sql = 'UPDATE demo_catalog_items
                            SET deleted_at = NOW(), version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND deleted_at IS NULL';
                }
            } else {
                $bind[':tenant_id'] = $tenantId;
                if ($baseVersion !== null) {
                    $sql = 'UPDATE demo_catalog_items
                            SET deleted_at = NOW(), version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND tenant_id = :tenant_id AND version = :base AND deleted_at IS NULL';
                    $bind[':base'] = $baseVersion;
                } else {
                    $sql = 'UPDATE demo_catalog_items
                            SET deleted_at = NOW(), version = version + 1, change_seq = :seq, updated_at = NOW()
                            WHERE id = :id AND tenant_id = :tenant_id AND deleted_at IS NULL';
                }
            }

            $stmt = $this->db->prepare($sql);
            $stmt->execute($bind);

            if ($stmt->rowCount() === 1) {
                $row = $this->findScoped($id, $tenantId);
                return $row === null
                    ? Response::error('Item not found', 404)
                    : Response::json(['data' => $this->toPublicItem($row)], 200);
            }

            // No live row deleted: 404 (missing), idempotent 200 (already a
            // tombstone), or 409 (a base version was given but doesn't match a
            // still-live row).
            $current = $this->findScoped($id, $tenantId);
            if ($current === null) {
                return Response::error('Item not found', 404);
            }
            if (($current['deleted_at'] ?? null) !== null) {
                return Response::json(['data' => $this->toPublicItem($current)], 200);
            }
            return Response::json(
                ['error' => 'Version conflict', 'serverItem' => $this->toPublicItem($current)],
                409
            );
        } catch (\Throwable) {
            return Response::error('Failed to delete item', 500);
        }
    }

    // ==================== internals ====================

    /**
     * The next global change-feed cursor value.
     *
     * This used to be a plugin-owned one-row table plus a driver branch —
     * `UPDATE … RETURNING seq` on PostgreSQL, `UPDATE` then a separate `SELECT`
     * on SQLite. The SQLite half was a read-then-write across two statements,
     * so two concurrent writers could both read the same value and stamp two
     * rows with one cursor; a puller would then see one of them and never the
     * other, which for a sync feed is silent data loss rather than a
     * duplicate number.
     *
     * Now it is one call to the host's allocator: no table, no migration, no
     * SQL, and no branch. Gaps stay harmless here — the feed cursor is
     * `change_seq > since` — which is exactly the guarantee the allocator
     * offers: unique and monotonic, possibly skipping.
     */
    private function nextChangeSeq(): int
    {
        return $this->sequences->nextPlatformWide('democatalog:change_seq');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findScoped(int $id, int $tenantId): ?array
    {
        if ($tenantId === self::SYSTEM_TENANT_ID) {
            // @tenant-guard-ignore: system-tenant (id 0) branch — may read any tenant's item by design
            $stmt = $this->db->prepare('SELECT ' . self::COLS . ' FROM demo_catalog_items WHERE id = :id');
            $stmt->execute([':id' => $id]);
        } else {
            $stmt = $this->db->prepare(
                'SELECT ' . self::COLS . ' FROM demo_catalog_items WHERE id = :id AND tenant_id = :tenant_id'
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
            'SELECT ' . self::COLS . ' FROM demo_catalog_items
             WHERE tenant_id = :tenant_id AND client_uuid = :client_uuid'
        );
        $stmt->execute([':tenant_id' => $tenantId, ':client_uuid' => $clientUuid]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return is_array($row) ? $row : null;
    }

    /**
     * The caller's expected base version for optimistic concurrency: `If-Match`
     * header (quotes stripped) takes precedence over a body `baseVersion`. Null
     * when neither is a valid integer.
     *
     * @param array<string, mixed> $input
     */
    private function baseVersion(Request $request, array $input): ?int
    {
        $ifMatch = $request->getHeader('If-Match');
        if (is_string($ifMatch)) {
            $trimmed = trim($ifMatch, "\" \t");
            if (preg_match('/^\d+$/', $trimmed) === 1) {
                return (int) $trimmed;
            }
        }
        $body = $input['baseVersion'] ?? null;

        return is_int($body) ? $body : null;
    }

    /**
     * @return array{name: string, description: ?string, status: string, clientUuid?: string, baseVersion?: int}|null
     */
    private function validatedInput(Request $request, bool $requireName): ?array
    {
        $body = json_decode($request->getBody(), true);
        $body = is_array($body) ? $body : [];

        $name = $body['name'] ?? null;
        if ($requireName) {
            if (!is_string($name) || trim($name) === '' || mb_strlen($name) > self::MAX_NAME_LENGTH) {
                $this->lastValidationError = 'name must be a non-empty string of at most '
                    . self::MAX_NAME_LENGTH . ' characters';
                return null;
            }
        }

        $description = $body['description'] ?? null;
        if ($description !== null && (!is_string($description) || mb_strlen($description) > self::MAX_DESCRIPTION_LENGTH)) {
            $this->lastValidationError = 'description must be a string of at most '
                . self::MAX_DESCRIPTION_LENGTH . ' characters';
            return null;
        }

        $status = $body['status'] ?? 'active';
        if (!is_string($status) || !in_array($status, self::VALID_STATUSES, true)) {
            $this->lastValidationError = 'status must be one of: ' . implode(', ', self::VALID_STATUSES);
            return null;
        }

        $out = [
            'name' => (string) $name,
            'description' => is_string($description) ? $description : null,
            'status' => $status,
        ];

        $clientUuid = $body['clientUuid'] ?? null;
        if (is_string($clientUuid) && $clientUuid !== '' && mb_strlen($clientUuid) <= 36) {
            $out['clientUuid'] = $clientUuid;
        }
        $baseVersion = $body['baseVersion'] ?? null;
        if (is_int($baseVersion)) {
            $out['baseVersion'] = $baseVersion;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function toPublicItem(array $row): array
    {
        return [
            'id' => (int) ($row['id'] ?? 0),
            'tenantId' => (int) ($row['tenant_id'] ?? 0),
            'clientUuid' => isset($row['client_uuid']) ? (string) $row['client_uuid'] : null,
            'name' => (string) ($row['name'] ?? ''),
            'description' => isset($row['description']) ? (string) $row['description'] : null,
            'status' => (string) ($row['status'] ?? 'active'),
            'version' => (int) ($row['version'] ?? 1),
            'deletedAt' => isset($row['deleted_at']) && $row['deleted_at'] !== null ? (string) $row['deleted_at'] : null,
            'updatedBy' => isset($row['updated_by']) && $row['updated_by'] !== null ? (int) $row['updated_by'] : null,
            'createdAt' => isset($row['created_at']) ? (string) $row['created_at'] : null,
            'updatedAt' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
        ];
    }

    /**
     * Merge query params from the (router-stripped) path's query string and
     * `$_GET`, mirroring the core handlers' dual-source read.
     *
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

    private static function uuid4(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
