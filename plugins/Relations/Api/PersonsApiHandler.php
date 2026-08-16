<?php

declare(strict_types=1);

namespace Relations\Api;

use PDO;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Sql\SequenceAllocator;
use Whity\Sdk\Sync\SyncController;

/**
 * Sync-capable CRUD over the Relations plugin's `persons` table, offline-first.
 *
 * A thin adapter: it resolves the caller's tenant from {@see TenantContext} and
 * delegates every verb to {@see SyncController} (idempotent create, If-Match/
 * baseVersion 409, tombstones, the `?updatedSince` changes feed), with `persons`
 * described by {@see PersonResource}. This is the sync spine the desktop app
 * reconciles against.
 *
 * First slice: the graph-derived list (relationCount + reciprocal relations),
 * search/pagination, and the profile-linkage edit/delete guard are added by the
 * repository/edges slices; here persons is a clean syncable resource.
 *
 * UPDATE IS A FULL ROW REPLACE (the sync model — a client pushes its whole local
 * copy), unlike core's PersonsApiHandler, whose PATCH merged only the fields
 * present. So an update MUST carry every field: an omitted birthDate/notes is
 * written as null. This is correct for the desktop sync client (it always holds
 * the full row); at the R3 cutover the web caller must send full rows, or a
 * partial-merge path is added then.
 */
final class PersonsApiHandler
{
    private SyncController $sync;

    public function __construct(PDO $db, SequenceAllocator $sequences)
    {
        $this->sync = new SyncController($db, $sequences, new PersonResource());
    }

    /** GET /api/persons — live list, or the changes feed with ?updatedSince=<cursor>. */
    public function list(Request $request): Response
    {
        return $this->sync->list($request, TenantContext::getTenantId());
    }

    /**
     * GET /api/persons/{id} — one person in the caller's tenant (incl. tombstone).
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function get(Request $request, array $params = []): Response
    {
        return $this->sync->get($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }

    /** POST /api/persons — create in the caller's tenant, idempotent on clientUuid. */
    public function create(Request $request): Response
    {
        return $this->sync->create($request, TenantContext::getTenantId());
    }

    /**
     * PATCH /api/persons/{id} — update, optimistic-concurrency guarded.
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function update(Request $request, array $params = []): Response
    {
        return $this->sync->update($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }

    /**
     * DELETE /api/persons/{id} — soft-delete (tombstone), idempotent.
     *
     * @param array<string, string> $params Router path captures (the `{id}`).
     */
    public function delete(Request $request, array $params = []): Response
    {
        return $this->sync->delete($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }
}
