<?php

declare(strict_types=1);

namespace Whity\Api;

use PDO;
use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentCollectionRepository;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Organizer\CoreDocumentViews;
use Whity\Core\Document\Organizer\DocumentSubstrateRegistry;
use Whity\Core\Document\Organizer\DocumentViewContext;
use Whity\Core\Document\Organizer\DocumentViewPresenter;
use Whity\Core\Document\Organizer\DocumentViewRegistry;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\Ou\OuSubtree;
use Whity\Core\Ou\PrimaryMembershipOu;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Storage\StorageException;

/**
 * Issued documents (#947 item 1) and the organizer that browses them
 * (#947 item 5, via #978):
 *
 *   GET  /api/documents                                    (documents:read)
 *   GET  /api/documents/views                              (documents:read)
 *   GET  /api/documents/{id}                               (documents:read)
 *   GET  /api/documents/{id}/content                       (documents:read)
 *   GET  /api/documents/{id}/artifacts/{artifactId}/content (documents:read)
 *   POST /api/documents/{id}/render                        (documents:render)
 *
 * A document is the record; its artifacts are the immutable files that were
 * issued from it. Every route here is tenant-scoped by the repositories and
 * row-filtered by {@see DocumentVisibilityPolicy} on top — the route
 * permission is the baseline, never the whole answer.
 *
 * THE BROWSER IS A QUERY SURFACE, NOT A TREE (#978)
 * -------------------------------------------------
 * When item 1 shipped, this list was deliberately the plain one and this
 * docblock said so: inventing folder filters before the facts they read existed
 * would have left item 5 a half-shaped surface to unpick. #978 is item 5, and
 * the shape it arrives with is the one that argument implied — the list is
 * unchanged for a caller who names no view, and a `?view=` selects a folder from
 * {@see DocumentViewRegistry}.
 *
 * A folder is a QUERY. Nothing stores where a document lives, because a document
 * raised centrally and needed by fifteen units has no single home and any stored
 * answer has to be maintained as the organisation changes. The only stored thing
 * is a person's own filing — collections, migration 114 — which claims nothing
 * about the document.
 *
 * {@see views()} is what makes that honest from the outside: it returns the
 * folders this installation can actually COMPUTE. All six of item 5's are built,
 * and three of them — "awaiting me", "acted on by me", "passed through my unit"
 * — are absent on an installation that has not run migration 112, because the
 * routing facts they read are not recorded there. Nothing in this handler tests
 * for that; it asks {@see DocumentViewRegistry} what exists and reports it. An
 * empty "Awaiting me" would state *"nothing awaits you"*, which is false and
 * which the reader cannot check, so the folder is not offered at all.
 *
 * What IS here beyond reading is the re-render, because it is the observable
 * half of the immutability guarantee: {@see rerender()} appends a NEW artifact
 * and the previous one stays fetchable at its own URL, forever. A subsystem
 * that promised immutability with no way to supersede anything would never
 * have the promise tested.
 *
 * NO 403s ON A MISS
 * -----------------
 * A document the caller may not see is reported as missing, not as forbidden.
 * A 403 confirms the id exists, which for an enumerable integer id leaks the
 * shape of the tenant's activity — the same reasoning the template handlers
 * already apply. A view key naming a folder this installation cannot compute is
 * reported the same way, and for the same reason: from outside, it does not
 * exist.
 */
final class DocumentsApiHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly DocumentArtifactRepository $artifacts,
        private readonly DocumentArtifactStore $store,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentAccessPolicy $templatePolicy,
        private readonly DocumentRenderer $renderer,
        private readonly DocumentIssuer $issuer,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
        // ── #978: the organizer ──────────────────────────────────────────────
        private readonly DocumentViewRegistry $views,
        private readonly DocumentSubstrateRegistry $substrates,
        private readonly DocumentCollectionRepository $collections,
        // The connection, for the two OU-tree questions the organizer asks:
        // {@see PrimaryMembershipOu} (which unit is the caller in) and
        // {@see OuSubtree} (what lies beneath a unit). Both arrived with #947
        // item 3 as shared statics precisely so a second copy of either cannot
        // drift from the first, so this handler uses them rather than wrapping
        // them in collaborators of its own.
        private readonly PDO $db,
        // The WHERE half of TEMPLATE visibility (migration 117): a template
        // filed at an organizational unit is withheld from callers with no
        // standing there, so this path cannot become a way to reach a template
        // the designer's own list would not have shown.
        private readonly OuReachResolver $ouReach,
    ) {
    }

    /**
     * GET /api/documents/views — the folders this installation can compute.
     *
     * The response has two halves and they answer two different questions.
     * `views` is what the rail renders, each carrying whether THIS caller can
     * anchor it and, when not, why (#951: disabled with a reason, never
     * hidden). `unavailable_substrates` is what this installation does not
     * record and what would supply it — the answer to "why is there no inbox
     * here", which otherwise has no answer at all from outside.
     *
     * A view with a REQUIRED parameter is a template rather than a folder: the
     * client instantiates it (one rail entry per collection) rather than opening
     * it bare, so it is reported without a caller-level resolution instead of
     * being resolved with a missing parameter and reported unavailable — which
     * would be a true statement about the wrong thing.
     */
    public function views(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $viewContext = $this->viewContext($request, $tenantId, $callerId, null);
        if ($viewContext instanceof Response) {
            return $viewContext;
        }

        $data = [];
        foreach ($this->views->available() as $view) {
            $data[] = DocumentViewPresenter::view(
                $view,
                $view->requiredParameters() === [] ? $view->resolve($viewContext) : null
            );
        }

        return Response::json([
            'data' => $data,
            'unavailable_substrates' => array_map(
                static fn ($substrate): array => DocumentViewPresenter::substrate($substrate),
                $this->substrates->unavailable()
            ),
        ]);
    }

    /**
     * GET /api/documents — the caller's visible documents, newest first,
     * optionally narrowed to one of the organizer's folders.
     *
     * `?view=` names a folder, `?ou_id=` anchors the unit ones, `?collection_id=`
     * opens one of the caller's collections and `?q=` filters by title. A
     * request naming none of them is the plain item-1 list it always was.
     */
    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $query = self::query($request);
        $viewKey = self::stringParam($query, 'view') ?? CoreDocumentViews::ALL;

        // Null covers "no such key" AND "registered, but this installation
        // cannot compute it". Both are 404 — from outside, a folder whose facts
        // are not recorded here does not exist, and reporting it any other way
        // invites a client to render it as a real-but-unavailable folder, which
        // is the empty-inbox lie one step removed.
        $view = $this->views->get($viewKey);
        if ($view === null) {
            return Response::error('Unknown document view', 404);
        }

        foreach ($view->requiredParameters() as $required) {
            if (self::stringParam($query, $required) === null) {
                return Response::error("This view requires a '{$required}' parameter", 400);
            }
        }

        // Ownership is established HERE, before any view resolves, and a
        // collection that is not the caller's is reported missing rather than
        // forbidden. Without this check a caller could pass a colleague's
        // collection id and read back which of the documents they can already
        // see that colleague had filed — the row-visibility predicate would
        // still apply, so nothing new becomes readable, but WHO FILED WHAT is
        // itself private and would leak. Collection ids are enumerable
        // integers, so it would leak by walking them.
        $collectionId = self::intParam($query, 'collection_id');
        if ($collectionId !== null
            && $this->collections->findOwned($collectionId, $tenantId, $callerId) === null) {
            return Response::error('Collection not found', 404);
        }

        $viewContext = $this->viewContext($request, $tenantId, $callerId, $collectionId);
        if ($viewContext instanceof Response) {
            return $viewContext;
        }

        $resolution = $view->resolve($viewContext);
        if ($resolution->criteria === null) {
            // The view is real and this caller cannot anchor it. 422, not 404:
            // the folder exists, the client was right to ask, and the reason is
            // about the caller rather than about the view — a 404 here would be
            // indistinguishable from the unbuilt case above.
            return Response::error(
                $resolution->unavailableReason ?? 'This view is not available to you',
                422
            );
        }

        // Resolved ONCE and pushed into the query: see
        // DocumentVisibilityPolicy::restrictToCreator() for why this is a
        // predicate rather than a post-filter over the fetched page. Applied
        // AFTER the view resolves, so a view can never widen it.
        $criteria = $resolution->criteria->withRequestScope(
            $this->visibility->restrictToCreator($callerId, $this->permissionResolver($callerId, $tenantId)),
            self::stringParam($query, 'q')
        );

        $p = PaginationParams::fromPath($request->getPath());
        $total = $this->documents->countForCriteria($tenantId, $criteria);
        $rows = $this->documents->listForCriteria($tenantId, $criteria, $p->perPage, $p->offset);

        // The artifact list is fetched per document rather than in one join:
        // the join returns a document once per artifact and has to be
        // re-collapsed in PHP, and a page is at most PaginationParams::MAX_PER_PAGE
        // rows.
        //
        // The FILING, by contrast, is one query for the whole page — the star
        // and the "filed in" badge are on every row, so the per-row form is the
        // textbook N+1 that only bites once a tenant has volume.
        $filing = $this->collections->collectionIdsForDocuments(
            $tenantId,
            $callerId,
            array_map(static fn (array $row): int => (int) $row['id'], $rows)
        );
        $starredId = $viewContext->starredCollectionId;

        $data = array_map(
            fn (array $row): array => DocumentPresenter::document(
                $row,
                $this->artifacts->listForDocument((int) $row['id'], $tenantId),
                $filing[(int) $row['id']] ?? [],
                $starredId
            ),
            $rows
        );

        return Response::json([
            'data' => $data,
            'pagination' => $p->meta($total),
            // Echoed so a client rendering a rail can tell which entry is
            // selected without re-parsing its own URL, and so the anchor the
            // server actually used (the caller's own unit, when none was
            // supplied) is visible rather than guessed at.
            'view' => [
                'key' => $view->key,
                'ou_id' => $viewContext->effectiveOuId(),
                'collection_id' => $collectionId,
            ],
        ]);
    }

    /**
     * GET /api/documents/{id} — one document with its full artifact history.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        $documentId = (int) $document['id'];
        $filing = $this->collections->collectionIdsForDocuments($tenantId, $callerId, [$documentId]);
        $starred = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $callerId
        );

        return Response::json([
            'data' => DocumentPresenter::document(
                $document,
                $this->artifacts->listForDocument($documentId, $tenantId),
                $filing[$documentId] ?? [],
                $starred === null ? null : (int) $starred['id']
            ),
        ]);
    }

    /**
     * GET /api/documents/{id}/content — the CURRENT artifact's bytes.
     *
     * @param array<string, string> $params
     */
    public function content(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;

        $artifact = $this->artifacts->findLatestForDocument((int) $document['id'], $tenantId);
        if ($artifact === null) {
            // A record with no stored bytes is not a state the issuer can
            // produce (it rolls back rather than leave one), so this is the
            // "restored from a partial backup" case rather than a routine miss.
            return Response::error('Document has no stored content', 404);
        }

        return $this->streamArtifact($document, $artifact);
    }

    /**
     * GET /api/documents/{id}/artifacts/{artifactId}/content — ONE artifact's
     * bytes, superseded or not.
     *
     * This is the route that makes immutability checkable from the outside: a
     * URL handed out in March still resolves to the March bytes in December,
     * and the `checksum_sha256` on the record proves it.
     *
     * @param array<string, string> $params
     */
    public function artifactContent(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;

        $artifactId = (int) ($params['artifactId'] ?? 0);
        // Bound to the document as well as the tenant: an artifact id alone is
        // never a capability, so a guessed id cannot be walked across the
        // tenant's documents.
        $artifact = $this->artifacts->findById($artifactId, (int) $document['id'], $tenantId);
        if ($artifact === null) {
            return Response::error('Artifact not found', 404);
        }

        return $this->streamArtifact($document, $artifact);
    }

    /**
     * POST /api/documents/{id}/render — append a corrected artifact.
     *
     * Renders the document's ORIGINATING TEMPLATE again, with whatever data the
     * request supplies, and appends the result. The document row is untouched,
     * so its id — and everything #947 item 3 will hang off that id — keeps
     * pointing at the same record.
     *
     * Refused with 409 when the template has since been deleted: the foreign
     * key is ON DELETE SET NULL precisely so the existing artifacts survive
     * that, but there is nothing left to render FROM, and inventing a
     * substitute would produce a "correction" that is not a correction of
     * anything.
     *
     * The template's own visibility is re-checked, not assumed from the
     * document's: a caller who may read a document they raised does not
     * thereby gain the right to render a template that is gated away from them.
     *
     * @param array<string, string> $params
     */
    public function rerender(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') !== 'true') {
            return Response::error('Server-side document rendering is disabled on this instance', 503);
        }
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            return Response::error('Persisting rendered documents is disabled on this instance', 503);
        }

        $templateId = $document['document_template_id'];
        $template = is_int($templateId) ? $this->templates->findById($templateId, $tenantId) : null;
        if ($template === null
            || !$this->templatePolicy->canView(
                $template,
                $callerId,
                $this->permissionResolver($callerId, $tenantId),
                $this->ouReach->reachFor($tenantId, $callerId),
            )) {
            return Response::error('The template this document was issued from is no longer available', 409);
        }

        $body = JsonBody::parsed($request);
        $templateData = is_array($template['data']) ? $template['data'] : [];

        try {
            $pdf = $this->renderer->render($tenantId, $templateData, $body['dataRows'] ?? null, $body['sheet'] ?? null);
        } catch (DocumentRenderRejectedException $e) {
            // ->clientMessage, never ->getMessage(): see the exception's docblock.
            return Response::error($e->clientMessage, 422);
        } catch (RenderServiceUnavailableException $e) {
            error_log('[DocumentsApiHandler] re-render failed: ' . $e->getMessage());
            return Response::error('Document rendering is temporarily unavailable', 503);
        } catch (\Throwable $e) {
            error_log('[DocumentsApiHandler] unexpected re-render failure: ' . $e->getMessage());
            return Response::error('Document rendering is temporarily unavailable', 503);
        }

        try {
            $this->issuer->appendArtifact($tenantId, $callerId, $document, $pdf);
        } catch (StorageException $e) {
            error_log('[DocumentsApiHandler] storing the re-render failed: ' . $e->getMessage());
            return Response::error('Storing the rendered document is temporarily unavailable', 503);
        } catch (\Throwable $e) {
            error_log('[DocumentsApiHandler] unexpected persist failure: ' . $e->getMessage());
            return Response::error('Storing the rendered document is temporarily unavailable', 503);
        }

        // The whole document, so the caller sees the new artifact ALONGSIDE the
        // ones it supersedes rather than in place of them — the response is the
        // clearest statement this API makes about what a correction does.
        return Response::json([
            'data' => DocumentPresenter::document(
                $document,
                $this->artifacts->listForDocument((int) $document['id'], $tenantId)
            ),
        ], 201);
    }

    // ── helpers ─────────────────────────────────────────────────────────────

    /**
     * Stream an artifact's bytes.
     *
     * `inline` rather than `attachment`: this is what the viewer (#947 item 4)
     * embeds, and a browser told to download cannot render it in a frame. The
     * filename is still supplied, so an explicit "save as" names the file after
     * the document rather than after its numeric id.
     *
     * A storage read failure is a 503, not a 404: the record is real and the
     * bytes were written, so "it does not exist" would be a lie that sends the
     * caller looking in the wrong place.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $artifact
     */
    private function streamArtifact(array $document, array $artifact): Response
    {
        try {
            $bytes = $this->store->get((string) $artifact['storage_key']);
        } catch (StorageException $e) {
            error_log('[DocumentsApiHandler] reading a stored artifact failed: ' . $e->getMessage());
            return Response::error('Document content is temporarily unavailable', 503);
        }

        $filename = DocumentPresenter::slugify((string) $document['title']) . '.pdf';

        return new Response(200, $bytes, [
            'Content-Type' => (string) $artifact['content_type'],
            'Content-Disposition' => 'inline; filename="' . $filename . '"',
            // An artifact is immutable, so its ETag is its content hash and it
            // can be cached hard. `private` because the bytes are RBAC-gated —
            // a shared cache must never serve them to the next caller.
            'ETag' => '"' . $artifact['checksum_sha256'] . '"',
            'Cache-Control' => 'private, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Resolve the tenant, the caller, and a document the caller may see.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolveVisibleDocument(Request $request, array $params): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $document = $this->documents->findById((int) ($params['id'] ?? 0), $tenantId);
        if ($document === null
            || !$this->visibility->canView($document, $callerId, $this->permissionResolver($callerId, $tenantId))) {
            return Response::error('Document not found', 404);
        }

        return [$tenantId, $callerId, $document];
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response. Mirrors
     * {@see DocumentTemplatesApiHandler::context()}.
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
     * Build the context a view resolves against.
     *
     * The picked anchor is validated to be a unit IN THIS TENANT and otherwise
     * left alone: row visibility is enforced on every result, so anchoring at a
     * unit the caller has no standing in returns what they could already see and
     * nothing more. Refusing it as well would report "forbidden" for a query
     * whose real answer is "nothing", which tells an outsider the unit is busy.
     * An id that is not a unit here is a 400 rather than a silent fallback to
     * the caller's own unit — a folder quietly answering about a different unit
     * than the one on screen is worse than an error.
     *
     * @return DocumentViewContext|Response
     */
    private function viewContext(Request $request, int $tenantId, int $callerId, ?int $collectionId): DocumentViewContext|Response
    {
        $anchorOuId = self::intParam(self::query($request), 'ou_id');
        if ($anchorOuId !== null && !$this->ouExistsInTenant($tenantId, $anchorOuId)) {
            return Response::error('ou_id does not reference an organizational unit in this tenant', 400);
        }

        $starred = $this->collections->findBySystemKey(
            DocumentCollectionRepository::STARRED_KEY,
            $tenantId,
            $callerId
        );

        return new DocumentViewContext(
            $tenantId,
            $callerId,
            PrimaryMembershipOu::forProfile($this->db, $tenantId, $callerId),
            $anchorOuId,
            $collectionId,
            $starred === null ? null : (int) $starred['id'],
            // A NARROW, pre-bound subtree capability rather than the connection:
            // the tenant is closed over here, so a view — including a plugin's —
            // can ask what is beneath a unit and cannot ask anything else, and
            // cannot ask it of another tenant. See DocumentViewContext for why
            // handing views raw access would be the wrong shape.
            fn (int $anchor): array => OuSubtree::descendantIds($this->db, $tenantId, [$anchor]),
        );
    }

    /**
     * Whether a picked anchor is a unit in this tenant.
     *
     * A one-line literal read with its tenant predicate spelled out, mirroring
     * {@see \Whity\Api\TwoFactorPoliciesApiHandler}'s. {@see OuSubtree} would
     * answer it as a side effect — an unknown root contributes nothing — but
     * only as SILENCE, and an anchor that is quietly ignored means the folder
     * answers about a different unit than the one on screen. That is worse than
     * an error, so the error is explicit.
     */
    private function ouExistsInTenant(int $tenantId, int $ouId): bool
    {
        $stmt = $this->db->prepare(
            'SELECT 1 FROM organizational_units WHERE id = :id AND tenant_id = :tenant_id LIMIT 1'
        );
        $stmt->execute([':id' => $ouId, ':tenant_id' => $tenantId]);

        return $stmt->fetchColumn() !== false;
    }

    /**
     * The request's query parameters.
     *
     * Reads `$_GET` first and overlays anything embedded in the path, mirroring
     * {@see PaginationParams::fromPath()} — a handler test builds a Request with
     * the query string in the path and no superglobal, and a reader that only
     * consulted one of the two would work in exactly one of the two places.
     *
     * @return array<string, string>
     */
    private static function query(Request $request): array
    {
        $params = [];
        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $params[$key] = $value;
            }
        }

        $queryString = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $parsed);
            foreach ($parsed as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $params[$key] = $value;
                }
            }
        }

        return $params;
    }

    /**
     * A non-empty string parameter, or null. An empty value is null rather than
     * `''`: `?q=` is what a cleared search box sends, and treating it as a term
     * would filter every title down to the ones containing nothing.
     *
     * @param array<string, string> $query
     */
    private static function stringParam(array $query, string $name): ?string
    {
        $value = trim($query[$name] ?? '');

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, string> $query
     */
    private static function intParam(array $query, string $name): ?int
    {
        $value = $query[$name] ?? '';

        return ctype_digit($value) ? (int) $value : null;
    }

    /**
     * @return callable(string, int|null=): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }
}
