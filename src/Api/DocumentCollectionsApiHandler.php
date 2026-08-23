<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * A person's own filing of documents (#978, implementing #947 item 5):
 *
 *   GET    /api/document-collections                              (documents:read)
 *   POST   /api/document-collections                              (documents:read)
 *   PATCH  /api/document-collections/{id}                         (documents:read)
 *   DELETE /api/document-collections/{id}                         (documents:read)
 *   PUT    /api/document-collections/{id}/documents/{documentId}  (documents:read)
 *   DELETE /api/document-collections/{id}/documents/{documentId}  (documents:read)
 *   PUT    /api/documents/{id}/star                               (documents:read)
 *   DELETE /api/documents/{id}/star                               (documents:read)
 *
 * WHY NO NEW PERMISSION
 * ---------------------
 * Every route here is gated on `documents:read`, and a `documents:organize`
 * alongside it was considered and rejected. A permission earns its existence by
 * being withholdable from somebody who holds its neighbours — that is what
 * `documents:read:all` is for (migration 109), and what separates
 * `documents:publish` from `documents:write`. There is no coherent
 * administrator who wants a colleague to read documents but not to keep a
 * private list of which ones they care about: a collection is invisible to
 * everyone else, confers nothing, and is deleted with its owner. A permission
 * that nobody would ever revoke separately is not a control, it is a second
 * name for the one beside it — and every one of those has to be granted,
 * documented and explained in the roles UI forever.
 *
 * The write routes carry the READ permission for the same reason: what is being
 * written is the caller's own bookmark, not the document.
 *
 * WHAT IS ENFORCED INSTEAD
 * ------------------------
 * Two things, and both matter more than a route gate would.
 *
 *  1. OWNERSHIP. Every collection is looked up by (id, tenant, PROFILE), so
 *     another person's id is NOT FOUND rather than forbidden — collection ids
 *     are enumerable integers, and a 403 would let a colleague's filing be
 *     mapped by walking them.
 *
 *  2. VISIBILITY, ON THE WAY IN AND ON THE WAY OUT. A document can only be
 *     filed if the caller may see it, so the endpoint cannot be used to probe
 *     which document ids exist. And reading THROUGH a collection re-applies the
 *     visibility policy — see {@see \Whity\Core\Document\DocumentRepository} —
 *     because visibility narrows over time and a pointer stored last March must
 *     not become a standing exemption from a rule applied since.
 *
 * STARRING IS A COLLECTION WITH A WELL-KNOWN KEY
 * ----------------------------------------------
 * {@see star()} resolves-or-creates the caller's `starred` collection and files
 * into it, so a star and a pile are one storage shape, one set of SQL and one
 * lifecycle. Migration 111 argues the trade in full. The two star routes exist
 * anyway because the alternative is a client that must fetch the collection
 * list, find the keyed row, handle its absence and create it before it can
 * render a star — four round trips of ceremony for a one-click affordance.
 *
 * A keyed collection cannot be renamed or deleted through the API (409): the
 * star control addresses it BY KEY, so a client that deleted it would find its
 * own star silently re-creating a different row, and one that renamed it would
 * be renaming something the UI does not label from the row anyway.
 */
final class DocumentCollectionsApiHandler
{
    /** Matches `document_collections.name` (migration 114). */
    private const MAX_NAME_LENGTH = 160;

    public function __construct(
        private readonly DocumentCollectionRepository $collections,
        private readonly DocumentRepository $documents,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    /** GET /api/document-collections — the caller's own, with item counts. */
    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        return Response::json([
            'data' => array_map(
                static fn (array $row): array => DocumentPresenter::collection($row),
                $this->collections->listOwned($tenantId, $callerId)
            ),
        ]);
    }

    /** POST /api/document-collections — create one. */
    public function create(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $name = self::name(JsonBody::parsed($request));
        if ($name instanceof Response) {
            return $name;
        }

        // No pre-check for a duplicate name: check-then-write is a race two
        // clicks apart, and the unique constraint is the only answer that
        // cannot be stale. `system_key` is never accepted from a client — a
        // caller minting its own well-known key would be claiming the star
        // control's target.
        try {
            $id = $this->collections->create($tenantId, $callerId, $name);
        } catch (\Throwable $e) {
            if (self::isUniqueViolation($e)) {
                return Response::error('You already have a collection with that name', 409);
            }
            throw $e;
        }

        $created = $this->collections->findOwned($id, $tenantId, $callerId);
        if ($created === null) {
            return Response::error('Collection could not be read back after creation', 500);
        }

        return Response::json(['data' => DocumentPresenter::collection($created)], 201);
    }

    /**
     * PATCH /api/document-collections/{id} — rename one.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $resolved = $this->resolveOwnCollection($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $collection] = $resolved;

        if ($collection['system_key'] !== null) {
            return Response::error('A built-in collection cannot be renamed', 409);
        }

        $name = self::name(JsonBody::parsed($request));
        if ($name instanceof Response) {
            return $name;
        }

        try {
            $this->collections->rename((int) $collection['id'], $tenantId, $callerId, $name);
        } catch (\Throwable $e) {
            if (self::isUniqueViolation($e)) {
                return Response::error('You already have a collection with that name', 409);
            }
            throw $e;
        }

        $updated = $this->collections->findOwned((int) $collection['id'], $tenantId, $callerId);
        if ($updated === null) {
            return Response::error('Collection could not be read back after renaming', 500);
        }

        return Response::json(['data' => DocumentPresenter::collection($updated)]);
    }

    /**
     * DELETE /api/document-collections/{id}.
     *
     * The documents are untouched. That is the whole difference between a
     * collection and a folder, and it is why deleting one needs no confirmation
     * beyond the client's own: nothing is destroyed except an opinion.
     *
     * @param array<string, string> $params
     */
    public function delete(Request $request, array $params): Response
    {
        $resolved = $this->resolveOwnCollection($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $collection] = $resolved;

        if ($collection['system_key'] !== null) {
            return Response::error('A built-in collection cannot be deleted', 409);
        }

        $this->collections->delete((int) $collection['id'], $tenantId, $callerId);

        // The `{data: {id, message}}` envelope every core delete returns
        // (MutationResponse), not a bare message: the generated client types the
        // whole surface from one component, and a second shape here would be a
        // second thing every consumer has to special-case.
        return Response::json(['data' => ['id' => (int) $collection['id'], 'message' => 'Collection deleted']]);
    }

    /**
     * PUT /api/document-collections/{id}/documents/{documentId} — file a
     * document. Idempotent.
     *
     * @param array<string, string> $params
     */
    public function addDocument(Request $request, array $params): Response
    {
        $resolved = $this->resolveOwnCollection($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $collection] = $resolved;

        $documentId = $this->resolveVisibleDocumentId($params, $tenantId, $callerId);
        if ($documentId instanceof Response) {
            return $documentId;
        }

        $this->collections->addItem($tenantId, (int) $collection['id'], $documentId);

        return $this->membershipResponse($tenantId, (int) $collection['id'], $documentId);
    }

    /**
     * DELETE /api/document-collections/{id}/documents/{documentId}. Idempotent.
     *
     * Removal does NOT re-check the document's visibility. Un-filing something
     * the caller can no longer see is exactly the case a person needs most —
     * refusing it would leave a row they own, cannot read and cannot get rid of.
     *
     * @param array<string, string> $params
     */
    public function removeDocument(Request $request, array $params): Response
    {
        $resolved = $this->resolveOwnCollection($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $collection] = $resolved;

        $documentId = (int) ($params['documentId'] ?? 0);
        $this->collections->removeItem($tenantId, (int) $collection['id'], $documentId);

        return $this->membershipResponse($tenantId, (int) $collection['id'], $documentId);
    }

    /**
     * PUT /api/documents/{id}/star — file into the caller's starred collection,
     * creating it on first use.
     *
     * @param array<string, string> $params
     */
    public function star(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $documentId = $this->resolveVisibleDocumentId($params, $tenantId, $callerId);
        if ($documentId instanceof Response) {
            return $documentId;
        }

        $starred = $this->resolveOrCreateStarred($tenantId, $callerId);
        $this->collections->addItem($tenantId, (int) $starred['id'], $documentId);

        return Response::json([
            'data' => DocumentPresenter::collection($starred),
            'starred' => true,
        ]);
    }

    /**
     * DELETE /api/documents/{id}/star.
     *
     * Un-starring a document when no starred collection exists is a 200, not a
     * 404: the caller asked for a state ("this is not starred") that is already
     * true, and creating the collection just to delete a row from it would write
     * a row to record an absence.
     *
     * @param array<string, string> $params
     */
    public function unstar(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $documentId = (int) ($params['id'] ?? 0);
        $starred = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $callerId
        );

        if ($starred !== null) {
            $this->collections->removeItem($tenantId, (int) $starred['id'], $documentId);
        }

        return Response::json([
            'data' => $starred === null ? null : DocumentPresenter::collection($starred),
            'starred' => false,
        ]);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * The caller's starred collection, created on first use.
     *
     * Lazily rather than seeded per member: seeding would write a row for every
     * profile in every tenant to record something nobody has done. The insert
     * races two concurrent first stars, so a unique violation is read as "the
     * other request won" and the row is fetched rather than reported as an error
     * — the caller's intent is satisfied either way.
     *
     * @return array<string, mixed>
     */
    private function resolveOrCreateStarred(int $tenantId, int $callerId): array
    {
        $existing = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $callerId
        );
        if ($existing !== null) {
            return $existing;
        }

        try {
            $id = $this->collections->create(
                $tenantId,
                $callerId,
                DocumentCollectionRepository::STARRED_DEFAULT_NAME,
                DocumentCollectionRepository::STARRED_KEY
            );
            $created = $this->collections->findOwned($id, $tenantId, $callerId);
            if ($created !== null) {
                return $created;
            }
        } catch (\Throwable $e) {
            if (!self::isUniqueViolation($e)) {
                throw $e;
            }
        }

        $raced = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $callerId
        );
        if ($raced === null) {
            throw new \RuntimeException('The starred collection could not be created or read back.');
        }

        return $raced;
    }

    /**
     * The state after an idempotent filing change, READ BACK rather than
     * asserted.
     *
     * Returning `in_collection: true` because the insert was issued would be a
     * claim about a row nobody looked at, and two clicks on a star racing a
     * concurrent un-star from another tab both end here. 200 rather than 201
     * in both directions: the resource whose state changed is the collection,
     * which already existed, and a client that had to branch on 200-vs-201 to
     * render a filled star would be branching on which click happened to win.
     */
    private function membershipResponse(int $tenantId, int $collectionId, int $documentId): Response
    {
        return Response::json([
            'data' => [
                'collection_id' => $collectionId,
                'document_id' => $documentId,
                'in_collection' => $this->collections->contains($tenantId, $collectionId, $documentId),
            ],
        ]);
    }

    /**
     * A document id the caller may see, or a 404 Response.
     *
     * The visibility check is what stops this endpoint being a document-id
     * oracle: without it, "filed" and "not found" would differ for ids the
     * caller has no business knowing exist.
     *
     * @param array<string, string> $params
     */
    private function resolveVisibleDocumentId(array $params, int $tenantId, int $callerId): int|Response
    {
        $documentId = (int) ($params['documentId'] ?? $params['id'] ?? 0);
        $document = $this->documents->findById($documentId, $tenantId);

        if ($document === null
            || !$this->visibility->canView($document, $callerId, $this->permissionResolver($callerId, $tenantId))) {
            return Response::error('Document not found', 404);
        }

        return $documentId;
    }

    /**
     * Resolve the tenant, the caller, and a collection the caller OWNS.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolveOwnCollection(Request $request, array $params): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $collection = $this->collections->findOwned((int) ($params['id'] ?? 0), $tenantId, $callerId);
        if ($collection === null) {
            return Response::error('Collection not found', 404);
        }

        return [$tenantId, $callerId, $collection];
    }

    /**
     * Validate a collection name from a request body.
     *
     * @param array<string, mixed> $body
     */
    private static function name(array $body): string|Response
    {
        $raw = $body['name'] ?? null;
        if (!is_string($raw)) {
            return Response::error('name is required', 422);
        }

        $name = trim($raw);
        if ($name === '') {
            return Response::error('name is required', 422);
        }
        // Measured in characters, not bytes: the column is VARCHAR(160), which
        // PostgreSQL counts in characters, and an Arabic collection name would
        // otherwise be refused at roughly half the length an English one is.
        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            return Response::error('name must be at most ' . self::MAX_NAME_LENGTH . ' characters', 422);
        }

        return $name;
    }

    /**
     * Whether a driver exception is a unique-constraint violation.
     *
     * SQLSTATE 23505 on PostgreSQL; SQLite reports 23000 with a message naming
     * the constraint. Both are checked because the test suite builds its schema
     * on SQLite and a check that only understood PostgreSQL would turn a
     * duplicate name into an uncaught 500 in exactly the engine CI runs most.
     */
    private static function isUniqueViolation(\Throwable $e): bool
    {
        if (!$e instanceof \PDOException) {
            return false;
        }

        $sqlState = (string) ($e->errorInfo[0] ?? $e->getCode());

        return $sqlState === '23505'
            || ($sqlState === '23000' && str_contains(strtoupper($e->getMessage()), 'UNIQUE'));
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response. Mirrors
     * {@see DocumentsApiHandler}'s.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }

    /**
     * @return callable(string): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        $set = array_fill_keys($this->roleChecker->getEffectivePermissionsForProfile($callerId, $tenantId), true);

        return static fn (string $permission): bool => isset($set[$permission]);
    }
}
