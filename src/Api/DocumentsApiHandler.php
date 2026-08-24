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
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Ou\OuSubtree;
use Whity\Core\Ou\PrimaryMembershipOu;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\RecordSectionRequirement;
use Whity\Core\RBAC\RecordSectionResolver;
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
        // ── #993: the record page's per-region authorization ─────────────────
        //
        // All three OPTIONAL, and all three defaulting to "absent" rather than
        // to a stub, following {@see RolesApiHandler}'s own optional resolver.
        // A host built without them makes no region claims at all — `show()`
        // omits `sections` entirely and the response is byte-identical to what
        // it was before this PR — which is what keeps the two existing handler
        // tests, and any embedder that wires documents without routing, working
        // unchanged. It also means the client's fail-closed rule (no verdicts ⇒
        // every region hidden) is the ONLY reading of an absent key, so a
        // half-wired deployment degrades to "nothing to show" rather than to
        // "everything editable".
        private readonly ?RecordSectionResolver $sectionResolver = null,
        // The two routing reads the record page's record-scoped predicates need.
        // Documents already depend on routing for visibility (a recipient may
        // read what was sent to them — see DocumentVisibilityPolicy), so this is
        // an existing edge, not a new one. Null fails the predicates CLOSED:
        // "you may not act" and "there is no trail" are the safe answers when
        // the host cannot tell.
        private readonly ?RouteEventRepository $routeEvents = null,
        private readonly ?RouteRecipientRepository $routeRecipients = null,
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

        $data = DocumentPresenter::document(
            $document,
            $this->artifacts->listForDocument($documentId, $tenantId),
            $filing[$documentId] ?? [],
            $starred === null ? null : (int) $starred['id']
        );

        // #993: the record page's per-region verdicts. Only `show()` carries
        // them — the LIST deliberately does not, and that asymmetry is the same
        // one `collection_ids` already has: a verdict is an answer about ONE
        // record and a caller, and computing 25 of them per page to render a
        // table that gates nothing would be work nobody reads.
        $verdicts = $this->resolveRecordSections($tenantId, $callerId, $document);
        if ($verdicts !== null) {
            $data['sections'] = $verdicts;
        }

        return Response::json(['data' => $data]);
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
            || !$this->templatePolicy->canView($template, $callerId, $this->permissionResolver($callerId, $tenantId))) {
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

    // ── #993: the record page's regions ─────────────────────────────────────

    /**
     * The three regions of the document record page, declared once (#910/#975).
     *
     * A document record answers three different questions and the operator's
     * requirement — *"some parts have permissions, not always everything is
     * allowed"* — is the reason they are three declarations rather than one:
     * reading the document, reading what has happened to it, and being the
     * person it is currently with are separate facts with separate answers.
     *
     * WHY ALL THREE `readPermission`s ARE NULL, which is the decision most worth
     * arguing. It is tempting to gate the trail on `audit:read` and the pending
     * queue on `documents:route`, and both would read as tighter. Both would
     * also be gates with a BYPASS ONE PATH SEGMENT AWAY: `GET /{id}/trail` and
     * `GET /{id}/recipients` are registered on `documents:read`, so a caller
     * refused the region would simply call the route and be served. The
     * resolver's own {@see RecordSectionResolver::mayRead()} docblock names that
     * failure exactly, and #975's fifth invariant is that a region's dedicated
     * route must carry the same gate as the region. Narrowing those two routes
     * is a real policy change — it would take the trail away from a recipient
     * who was sent the document, which is precisely the audience that most needs
     * to see where it has been — and it belongs to whoever owns routing, not to
     * a record page. So `null` here is the honest, documented statement the
     * field is for: *the route that served the record is the only read gate*,
     * and that route is `documents:read` plus {@see DocumentVisibilityPolicy}.
     *
     * The consequence is that a caller who gets a payload at all sees all three
     * regions, and the mechanism does its work on the WRITE side, where the
     * three answers genuinely differ:
     *
     *  - `document`   — `documents:render`, plus a record predicate. Correcting
     *                   an issued document is the one write it HAS.
     *  - `trail`      — no permission (appending a note is open to anyone who
     *                   can read the document — see DocumentRouter::act, where
     *                   `noted` is handled before the recipient check), plus a
     *                   record predicate: there must be a route to append to.
     *  - `recipients` — no permission either. Being a recipient IS the
     *                   authorization, which is a fact about the RECORD and the
     *                   caller, so it is a record predicate and not a slug. This
     *                   is the one region whose verdict a grant cannot change,
     *                   and telling it apart from a permission refusal is what
     *                   {@see RecordSectionResolver::CODE_RECORD} exists for.
     *
     * @return list<RecordSectionRequirement>
     */
    private static function recordSections(): array
    {
        return [
            new RecordSectionRequirement(
                key: 'document',
                readPermission: null,
                writePermission: CorePermissions::DOCUMENTS_RENDER,
                recordScoped: true,
                deniedReason: 'You can read this document. Issuing a corrected version of it '
                    . 'is not something your account can do.',
            ),
            new RecordSectionRequirement(
                key: 'trail',
                readPermission: null,
                writePermission: null,
                recordScoped: true,
                // No `deniedReason`: with no write permission declared, a
                // PERMISSION refusal is unreachable for this region, and copy
                // for a branch that cannot be taken is copy nobody can check.
                // The record refusal supplies the sentence instead.
            ),
            new RecordSectionRequirement(
                key: 'recipients',
                readPermission: null,
                writePermission: null,
                recordScoped: true,
            ),
        ];
    }

    /**
     * The audience-safe sentence for each region's RECORD refusal.
     *
     * One per region because the three refusals are three different facts, and
     * {@see RecordSectionResolver::resolve()} takes one sentence per call — see
     * {@see self::resolveRecordSections()} for why that is called three times.
     *
     * Written for the person reading the screen, naming no identifier and no
     * slug: a `record` refusal cannot be fixed by a grant, so there is nothing
     * operator-grade to add (which is why the resolver sends `detail: null` for
     * this code).
     */
    private const RECORD_DENIED_REASONS = [
        'document' => 'The template this document was issued from is no longer available, '
            . 'so no corrected version can be issued. The versions already issued are unaffected.',
        'trail' => 'This document has not been put into circulation, so there is no trail to add to.',
        'recipients' => 'This document is not awaiting you. You are reading it as a record '
            . 'rather than as something to act on.',
    ];

    /**
     * The per-region verdicts for this caller and this document, or null when
     * this host does not resolve regions at all (#910/#975).
     *
     * WHY THIS CALLS `resolve()` ONCE PER REGION. The resolver takes ONE record
     * predicate and ONE record sentence per call, which is right for a role —
     * `roleManageableByTenant()` is a single fact about the row. A document has
     * three independent ones: whether it can be re-issued at all, whether it
     * has a trail, and whether it is with this caller. The alternative was
     * widening `resolve()` to take a predicate MAP, which would have changed a
     * class shared with the roles record page to suit one caller and forced
     * every existing site to pass a map to say the one thing it means. Calling a
     * pure function three times costs three cheap reads and leaves the shared
     * contract alone; the requirement LIST is still declared once, in
     * {@see self::recordSections()}, so "which regions does this record have"
     * remains answerable in one place.
     *
     * The two absences are different and both are meaningful, exactly as in
     * {@see RolesApiHandler}: `null` means this host makes no region claims, so
     * the response carries no `sections` key and behaves as it did before. An
     * empty ARRAY means regions WERE resolved and this caller was granted none —
     * a record with a header and no body.
     *
     * @param array<string, mixed> $document
     * @return array<string, array{state: string, denial: array{code: string, reason: string,
     *         detail: string|null}|null}>|null
     */
    private function resolveRecordSections(
        int $tenantId,
        int $callerId,
        array $document
    ): ?array {
        if ($this->sectionResolver === null) {
            return null;
        }

        // The operator-grade half of a denial names a permission SLUG, and
        // `permissions:read` is the permission that governs seeing permission
        // slugs at all — the same gate {@see RolesApiHandler} chose, for the
        // same reason rather than by analogy. A caller without it being told
        // "this needs 'documents:render'" would be reading the RBAC catalogue
        // through a denial message.
        $includeDetail = $this->roleChecker->hasPermissionForProfile(
            $callerId,
            CorePermissions::PERMISSIONS_READ,
            $tenantId
        );

        $predicates = [
            'document' => $this->documentIsReissuable($tenantId, $callerId, $document),
            'trail' => $this->documentHasTrail($tenantId, $document),
            'recipients' => $this->documentIsAwaiting($tenantId, $callerId, $document),
        ];

        $verdicts = [];
        foreach (self::recordSections() as $requirement) {
            $verdicts += $this->sectionResolver->resolve(
                [$requirement],
                $callerId,
                $tenantId,
                $predicates[$requirement->key] ?? false,
                self::RECORD_DENIED_REASONS[$requirement->key] ?? null,
                $includeDetail
            );
        }

        return $verdicts;
    }

    /**
     * Whether a corrected version of this document could be issued at all.
     *
     * The same three conditions {@see self::rerender()} enforces, asked in
     * advance rather than restated: rendering enabled, persisting enabled, and
     * a template still readable by this caller. Deliberately the same three, so
     * the page cannot offer a control the route would refuse with a 409 or a
     * 503 — which is the whole point of a record predicate, and the reason this
     * is not simply `true`.
     *
     * It is NOT a permission question. A caller holding `documents:render` on a
     * document whose template was deleted is refused by the record, and #975
     * separates the two codes precisely because an operator told "you lack a
     * permission" would go looking for a grant that could not have helped.
     *
     * @param array<string, mixed> $document
     */
    private function documentIsReissuable(int $tenantId, int $callerId, array $document): bool
    {
        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') !== 'true') {
            return false;
        }
        if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
            return false;
        }

        $templateId = $document['document_template_id'];
        $template = is_int($templateId) ? $this->templates->findById($templateId, $tenantId) : null;

        return $template !== null
            && $this->templatePolicy->canView($template, $callerId, $this->permissionResolver($callerId, $tenantId));
    }

    /**
     * Whether this document has a trail to append to.
     *
     * Asked of the EVENTS rather than of the routes, because an event is what a
     * note becomes and `issue()` always appends one — so "has a route" and "has
     * at least one event" cannot disagree, and the events table is the one the
     * region actually renders.
     *
     * This is the predicate behind the empty state the page must not fake. A
     * document nobody has circulated has no trail, and a region that showed an
     * empty list would be stating *"nothing has happened to this"* — true only
     * by accident, and indistinguishable from a trail that failed to load.
     *
     * @param array<string, mixed> $document
     */
    private function documentHasTrail(int $tenantId, array $document): bool
    {
        // Fail closed on an unwired host: "there is no trail" is the safe answer
        // when this deployment cannot tell, and it renders as a region that
        // explains itself rather than as one offering a write that would fail.
        return $this->routeEvents !== null
            && $this->routeEvents->countForDocument((int) $document['id'], $tenantId) > 0;
    }

    /**
     * Whether this document is currently awaiting THIS caller.
     *
     * An OPEN recipient row — `closed_by_event_id IS NULL`, migration 112's
     * partial unique index — for this profile on this document. A closed row is
     * something already done, so counting it would tell a reader the document is
     * with them when they have already dealt with it.
     *
     * Filtered here rather than through a new repository method on purpose: a
     * document's recipient list is bounded by its own fan-out, the region
     * renders the same rows anyway, and adding a query to a routing repository
     * for a documents-side question is how two nearly-identical "is it open"
     * predicates end up in the codebase disagreeing about the same index.
     *
     * @param array<string, mixed> $document
     */
    private function documentIsAwaiting(int $tenantId, int $callerId, array $document): bool
    {
        if ($this->routeRecipients === null) {
            return false;
        }

        foreach ($this->routeRecipients->listForDocument((int) $document['id'], $tenantId) as $recipient) {
            if ($recipient['closed_by_event_id'] === null
                && (int) $recipient['profile_id'] === $callerId) {
                return true;
            }
        }

        return false;
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
     * @return callable(string): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        $set = array_fill_keys($this->roleChecker->getEffectivePermissionsForProfile($callerId, $tenantId), true);

        return static fn (string $permission): bool => isset($set[$permission]);
    }
}
