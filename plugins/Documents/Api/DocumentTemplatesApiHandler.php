<?php

declare(strict_types=1);

namespace Documents\Api;

use PDO;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Sql\SequenceAllocator;
use Whity\Sdk\Sync\SyncController;

/**
 * Sync-capable CRUD over the Documents plugin`s `document_templates` table,
 * offline-first.
 *
 * A thin adapter: resolves the caller`s tenant from {@see TenantContext} and
 * delegates every verb to {@see SyncController} ({@see DocumentTemplateResource}),
 * with the full sync lifecycle (idempotent create on clientUuid,
 * If-Match/baseVersion 409, tombstones, the `?updatedSince` changes feed). UPDATE
 * is a full-row replace, the sync model.
 */
final class DocumentTemplatesApiHandler
{
    private SyncController $sync;

    public function __construct(PDO $db, SequenceAllocator $sequences)
    {
        $this->sync = new SyncController($db, $sequences, new DocumentTemplateResource());
    }

    /** GET /api/document-templates -- live list, or the changes feed with ?updatedSince=<cursor>. */
    public function list(Request $request): Response
    {
        return $this->sync->list($request, TenantContext::getTenantId());
    }

    /** @param array<string, string> $params */
    public function get(Request $request, array $params = []): Response
    {
        return $this->sync->get($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }

    /** POST /api/document-templates -- create in the caller's tenant, idempotent on clientUuid. */
    public function create(Request $request): Response
    {
        return $this->sync->create($request, TenantContext::getTenantId());
    }

    /** @param array<string, string> $params */
    public function update(Request $request, array $params = []): Response
    {
        return $this->sync->update($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }

    /** @param array<string, string> $params */
    public function delete(Request $request, array $params = []): Response
    {
        return $this->sync->delete($request, TenantContext::getTenantId(), (int) ($params['id'] ?? 0));
    }
}
