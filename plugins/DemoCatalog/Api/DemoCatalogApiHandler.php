<?php

declare(strict_types=1);

namespace DemoCatalog\Api;

use PDO;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Sql\SequenceAllocator;
use Whity\Sdk\Sync\SyncController;

/**
 * Tenant-scoped, SYNC-CAPABLE CRUD over the plugin's `demo_catalog_items` table.
 *
 * The DB-backed half of the DemoCatalog pilot (WC-features-pilot / WC-desktop-sync).
 * The offline-first two-way sync LIFECYCLE — idempotent create on `clientUuid`,
 * optimistic-concurrency update/delete (`If-Match`/`baseVersion` → 409 with
 * `serverItem`), soft-delete tombstones, and the `?updatedSince=<cursor>` changes
 * feed — now lives in the reusable {@see SyncController}; this handler is a thin
 * adapter that resolves the caller's tenant from {@see TenantContext} and
 * delegates, with `demo_catalog_items` described by {@see DemoCatalogResource}.
 *
 * Behaviour is byte-for-byte what this class used to implement by hand (proven
 * by the DemoCatalog sync/items real-engine suites) — the pilot is now the first
 * CONSUMER of the shared engine rather than its own bespoke copy (ADR 0003).
 */
final class DemoCatalogApiHandler
{
    private SyncController $sync;

    public function __construct(PDO $db, SequenceAllocator $sequences)
    {
        $this->sync = new SyncController($db, $sequences, new DemoCatalogResource());
    }

    /**
     * GET /api/demo-catalog/items — the tenant's live items (newest first), OR,
     * when `?updatedSince=<cursor>` is present, the incremental changes feed.
     */
    public function list(Request $request): Response
    {
        return $this->sync->list($request, TenantContext::getTenantId());
    }

    /**
     * GET /api/demo-catalog/items/{id} — one item in the caller's tenant
     * (including a tombstone, so a sync client can observe a server-side delete).
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function get(Request $request, array $params = []): Response
    {
        return $this->sync->get($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }

    /**
     * POST /api/demo-catalog/items — create in the caller's tenant, IDEMPOTENT on
     * `clientUuid` (201 on a genuine create, 200 on a retried one).
     */
    public function create(Request $request): Response
    {
        return $this->sync->create($request, TenantContext::getTenantId());
    }

    /**
     * PATCH /api/demo-catalog/items/{id} — update in the caller's tenant,
     * optimistic-concurrency guarded (409 with `serverItem` on a stale edit).
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function update(Request $request, array $params = []): Response
    {
        return $this->sync->update($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }

    /**
     * DELETE /api/demo-catalog/items/{id} — soft-delete (tombstone) in the
     * caller's tenant; If-Match/`baseVersion` guarded and idempotent.
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function delete(Request $request, array $params = []): Response
    {
        return $this->sync->delete($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }
}
