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
use Whity\Core\Document\Render\VariableData;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\Ou\OuSubtree;
use Whity\Core\Ou\PrimaryMembershipOu;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\RecordSectionRequirement;
use Whity\Core\RBAC\RecordSectionResolver;
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
 *   POST /api/documents                                    (documents:render)
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
 * CREATING ONE (#947 item 1, the half that was missing)
 * ---------------------------------------------------
 * Until now nothing in this API could bring a document into existence. The list
 * was a list, the re-render corrected something that already existed, and the
 * only create path in the whole subsystem was `POST
 * /api/document-templates/{id}/render` with `persist: true` — a document as a
 * SIDE EFFECT of rendering a template, on the template's own resource, and
 * unreachable on a default install because `documents.render_enabled` is false.
 * Every document anybody had ever seen came from the demo seeder writing rows.
 *
 * {@see create()} is the front door: name a template, supply values for its
 * placeholders, get a document. Four decisions in it are worth stating here
 * because each had a plausible alternative:
 *
 *  1. IT IS GATED ON `documents:render`, and no new slug was minted. Migration
 *     113 already made this exact argument when it chose who may ROUTE a
 *     document: *"`documents:render` is what gates `persist: true` on the render
 *     routes, so a role holding it is precisely a role that can bring a document
 *     into existence"*. That sentence is either true, in which case this route
 *     belongs behind the same slug, or it was wrong then. A `documents:create`
 *     would be a second answer to one question — and, on every existing
 *     install, a slug NOBODY HOLDS, which is a lockout wearing the costume of a
 *     permission check.
 *
 *  2. THE RECORD IS THE DELIVERABLE; THE ARTIFACT IS OPPORTUNISTIC. Rendering is
 *     attempted when the instance can do it and skipped when it cannot, and
 *     either way the document exists. See {@see create()} for why the opposite
 *     (refuse to create anything without bytes) would make this route dead on
 *     every fresh install.
 *
 *  3. THE VALUES ARE PERSISTED, on the row, by migration 118. They are the only
 *     content an unrendered document has, and without them a correction months
 *     later would silently reissue the document with the template's SAMPLE text
 *     where the real reference number was.
 *
 *  4. THE TEMPLATE'S OWN VISIBILITY IS RE-CHECKED, through the same
 *     {@see DocumentAccessPolicy} + {@see OuReachResolver} pair the designer's
 *     list and the re-render already use. Creating from a template you cannot
 *     SEE would make this route a way to read a gated template's contents by
 *     rendering it.
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
    /**
     * How many unrecognised field names a 422 will list before it stops.
     *
     * A fixed ceiling rather than a setting, deliberately: it is not a limit on
     * what a caller may SEND (the render ceilings in
     * {@see \Whity\Core\Settings\SettingsRegistry} are, and those are
     * per-tenant overridable), it is how long one error message is allowed to
     * get. Nothing about a tenant makes a different answer right, and an
     * operator asked to tune it would have no basis to choose. Ten is past the
     * point where a reader is still reading and well short of a response bigger
     * than the request.
     */
    private const UNKNOWN_FIELDS_REPORTED = 10;

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
     * POST /api/documents — raise a document from a template.
     *
     * WHAT IT WRITES, AND IN WHICH ORDER
     * ----------------------------------
     *  1. The record, committed. Template pointer + `template_name` snapshot,
     *     title, origin unit, the values supplied. This is the deliverable.
     *  2. THEN, only if this instance can render and the caller did not opt out,
     *     the PDF, appended as an artifact.
     *
     * Step 2 failing does not undo step 1, and that is the decision this route
     * turns on. `documents.render_enabled` DEFAULTS TO FALSE: the render tier is
     * a separate headless-Chromium container that a sovereign deployment may
     * never run. If a document could not exist without one, this route would be
     * a 503 on every fresh install and the front door would still be missing.
     *
     * An unrendered document is not a broken one. It has an id, a title, the
     * values it was raised with, an origin unit, and a `content_url` of null —
     * which the read path has always handled. Everything the routing engine
     * needs is `documents.id`, so it can be circulated, acknowledged and
     * audited with no PDF in sight, and `POST /api/documents/{id}/render` mints
     * the artifact from the stored values whenever the tier is switched on.
     *
     * The response therefore reports the render OUTCOME as a sibling of `data`
     * — the same shape the routing create uses for `resolved`/`delivered` —
     * rather than encoding it in the status code. 201 means "the document
     * exists"; `render.stored` means "and here is whether it has bytes yet".
     * Folding those into one code would make a working create on a
     * render-less instance indistinguishable from a failure.
     *
     * THE ONE CASE THAT IS AN ERROR RATHER THAN AN OUTCOME is a caller who
     * EXPLICITLY asked to render (`"render": true`) on an instance that cannot.
     * Omitting the key means "render if you can", which is what a client that
     * does not care should send; passing `true` is a claim about the result, and
     * answering 201 to it would be a lie the client has no way to detect. That
     * is a 503, before anything is written.
     *
     * A CREATOR IN NO UNIT is not an error either. `origin_ou_id` is nullable,
     * the demo fixture deliberately includes a registry officer who belongs to
     * no unit, and the organizer already renders OU-anchored folders DISABLED
     * WITH A REASON rather than hiding them (#951). So the document is raised
     * with a null origin, it lists and routes normally, and the only thing it is
     * absent from is the unit-anchored folders — which is true of it, and
     * which those folders already say out loud.
     */
    public function create(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $body = JsonBody::parsed($request);

        $templateId = $body['document_template_id'] ?? null;
        // A string id is accepted: JSON from a form-driven client routinely
        // carries numbers as strings, and refusing it here would be a 422 whose
        // cause is invisible in the payload the developer is looking at. A
        // non-numeric value is still refused rather than coerced to 0, which
        // would report "template not found" for a request that named no
        // template at all.
        if (is_string($templateId) && ctype_digit($templateId)) {
            $templateId = (int) $templateId;
        }
        if (!is_int($templateId) || $templateId <= 0) {
            return Response::error("'document_template_id' must be the id of a template to raise this document from", 422);
        }

        // The SAME visibility pair the designer's own list applies
        // (DocumentAccessPolicy over the caller's scoped permissions and their
        // OU reach) — not a re-implementation, and not the document
        // visibility policy, which answers a different question about a
        // different row. 404 rather than 403 on a miss, for the reason this
        // class's docblock gives: a 403 would confirm the template exists.
        $template = $this->templates->findById($templateId, $tenantId);
        if ($template === null
            || !$this->templatePolicy->canView(
                $template,
                $callerId,
                $this->permissionResolver($callerId, $tenantId),
                $this->ouReach->reachFor($tenantId, $callerId),
            )) {
            return Response::error('Template not found', 404);
        }

        $templateData = is_array($template['data']) ? $template['data'] : [];

        // Validated by the SAME normaliser the render path uses, so a document
        // can never store values the renderer would later refuse. Note the
        // argument is the RAW body value including its absence: null means
        // "fall back to the template's placeholder samples", which is what a
        // client that offered no form should get, and `[]` means the same.
        $rows = VariableData::normalizeRows($body['dataRows'] ?? null, $templateData);
        if ($rows === null) {
            return Response::error('dataRows must be a list of flat string maps', 422);
        }

        $unknown = $this->unknownPlaceholders($rows, $templateData);
        if ($unknown !== []) {
            // Named, because the alternative is a developer comparing two JSON
            // blobs by eye. The keys came from the request, so echoing them
            // discloses nothing the caller did not send.
            return Response::error(
                'These fields are not placeholders on this template: ' . implode(', ', $unknown),
                422
            );
        }

        $title = $this->resolveTitle($body, $template);

        $effective = $this->settings->effective($tenantId);
        $renderable = ($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') === 'true';
        // The artifact half only. A record with no artifact writes nothing to
        // the tenant's storage, and this setting exists to cap storage that
        // grows without bound — see SettingsRegistry, which describes it as
        // asking "whether the output may be written". Reading it as a gate on
        // the RECORD would make an operator who capped storage unable to raise
        // a document at all, which is not what they turned off.
        $persistable = ($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') === 'true';

        // Tri-state, and the absent case is the common one. `true` = "I require
        // an artifact"; `false` = "record only, do not render even if you can";
        // absent = "render if this instance can".
        $requested = $body['render'] ?? null;
        if ($requested === true && !$renderable) {
            return Response::error('Server-side document rendering is disabled on this instance', 503);
        }
        if ($requested === true && !$persistable) {
            return Response::error('Persisting rendered documents is disabled on this instance', 503);
        }

        try {
            $document = $this->issuer->raise($tenantId, $callerId, $template, $title, $rows);
        } catch (\Throwable $e) {
            error_log('[DocumentsApiHandler] raising the document failed: ' . $e->getMessage());
            return Response::error('The document could not be created', 503);
        }

        $render = $this->attemptRender(
            $tenantId,
            $callerId,
            $document,
            $templateData,
            $rows,
            $body['sheet'] ?? null,
            $requested,
            $renderable,
            $persistable,
        );

        return Response::json([
            'data' => DocumentPresenter::document(
                $document,
                $this->artifacts->listForDocument((int) $document['id'], $tenantId)
            ),
            'render' => $render,
        ], 201);
    }

    /**
     * Render the freshly-raised document and append the artifact, reporting what
     * happened rather than throwing.
     *
     * EVERY RETURN IS A 201. This method runs AFTER the record is committed, so
     * there is no failure left that should take the document away — see
     * {@see create()}. What it owes the caller instead is an honest, machine
     * readable account, which is why `reason` is a CLOSED VOCABULARY rather than
     * a sentence: a client deciding whether to offer a "render now" button needs
     * to tell `disabled` (never going to work here, hide the button) from
     * `unavailable` (transient, offer the retry) from `declined` (the caller
     * asked for this). A prose message cannot be branched on, and a null reason
     * beside `stored: false` would be the shrug this route exists to avoid.
     *
     * @param array<string, mixed>        $document
     * @param array<string, mixed>        $templateData
     * @param list<array<string, string>> $rows
     * @return array{attempted: bool, stored: bool, reason: string|null}
     */
    private function attemptRender(
        int $tenantId,
        int $callerId,
        array $document,
        array $templateData,
        array $rows,
        mixed $sheet,
        mixed $requested,
        bool $renderable,
        bool $persistable,
    ): array {
        if ($requested === false) {
            return ['attempted' => false, 'stored' => false, 'reason' => 'declined'];
        }
        if (!$renderable) {
            return ['attempted' => false, 'stored' => false, 'reason' => 'disabled'];
        }
        if (!$persistable) {
            return ['attempted' => false, 'stored' => false, 'reason' => 'persist_disabled'];
        }

        try {
            $pdf = $this->renderer->render($tenantId, $templateData, $rows, $sheet);
        } catch (DocumentRenderRejectedException $e) {
            // Reachable only through `sheet`: `dataRows` was normalised and the
            // ceilings were not, so an oversized batch or template lands here.
            // The document keeps its values and can be rendered once the
            // operator raises the ceiling, which is why this is not a 422 that
            // discards the record.
            error_log('[DocumentsApiHandler] the new document was refused a render: ' . $e->clientMessage);
            return ['attempted' => true, 'stored' => false, 'reason' => 'rejected'];
        } catch (\Throwable $e) {
            error_log('[DocumentsApiHandler] rendering the new document failed: ' . $e->getMessage());
            return ['attempted' => true, 'stored' => false, 'reason' => 'unavailable'];
        }

        try {
            $this->issuer->appendArtifact($tenantId, $callerId, $document, $pdf);
        } catch (\Throwable $e) {
            error_log('[DocumentsApiHandler] storing the new document artifact failed: ' . $e->getMessage());
            return ['attempted' => true, 'stored' => false, 'reason' => 'storage_unavailable'];
        }

        return ['attempted' => true, 'stored' => true, 'reason' => null];
    }

    /**
     * The keys a request supplied that the template declares no placeholder for,
     * in the order they were sent, without duplicates, and CAPPED.
     *
     * HASH LOOKUPS, NOT `in_array`, AND A CEILING ON WHAT IS ECHOED. Both are
     * about the same request: a large batch (a label sheet can legitimately be
     * hundreds of rows) with a bad key on every row. Two linear scans per key
     * makes that quadratic, and naming every offender makes the error body
     * larger than the request that caused it. Neither is hypothetical for a
     * route whose input is a caller-supplied map of arbitrary keys.
     *
     * The names ARE echoed rather than replaced by a count, up to the cap: the
     * whole value of this refusal is that it says `refrence` instead of leaving
     * a developer to compare two JSON blobs by eye. They came from the request,
     * so echoing them discloses nothing the caller did not send.
     *
     * @param list<array<string, string>> $rows
     * @param array<string, mixed>        $templateData
     * @return list<string>
     */
    private function unknownPlaceholders(array $rows, array $templateData): array
    {
        $declared = array_fill_keys(VariableData::keysOf($templateData), true);
        $unknown = [];
        foreach ($rows as $row) {
            foreach ($row as $key => $_value) {
                if (!isset($declared[$key]) && !isset($unknown[$key])) {
                    $unknown[$key] = true;
                    if (count($unknown) >= self::UNKNOWN_FIELDS_REPORTED) {
                        return array_keys($unknown);
                    }
                }
            }
        }

        return array_keys($unknown);
    }

    /**
     * The document's title: what the caller sent, or the template's name.
     *
     * Falling back keeps every record named something, which is what the
     * organizer lists and what an inbox item is recognised by — the same
     * fallback {@see DocumentRenderApiHandler} already applies, spelled the same
     * way so two create paths cannot name the same document differently.
     *
     * @param array<string, mixed> $body
     * @param array<string, mixed> $template
     */
    private function resolveTitle(array $body, array $template): string
    {
        $title = $body['title'] ?? null;
        $resolved = is_string($title) && trim($title) !== ''
            ? trim($title)
            : (string) $template['name'];

        return mb_substr($resolved, 0, 255);
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

        // THE VALUES THE DOCUMENT WAS RAISED WITH, when the request supplies
        // none. Before migration 118 there was nothing to fall back to and the
        // renderer used the template's placeholder SAMPLES instead — so
        // correcting a six-week-old circular from a client that had not kept the
        // original values reissued it reading `Ref: DEMO-0001`, and the
        // correction looked like a success. A recorded value now wins over a
        // sample; a request that names its own `dataRows` still wins over both,
        // because an explicit correction of the values is exactly what that
        // field is for.
        $dataRows = $body['dataRows'] ?? null;
        if ($dataRows === null) {
            $dataRows = $document['variable_data'] ?? null;
        }

        try {
            $pdf = $this->renderer->render($tenantId, $templateData, $dataRows, $body['sheet'] ?? null);
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

        // #1004 made template visibility two-part: the permission gate AND the
        // OU reach predicate. Passing only the first here would let the record
        // page re-issue from a template the designer's own list withholds,
        // which is the one thing that scoping exists to prevent.
        return $template !== null
            && $this->templatePolicy->canView(
                $template,
                $callerId,
                $this->permissionResolver($callerId, $tenantId),
                $this->ouReach->reachFor($tenantId, $callerId),
            );
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
     * @return callable(string, int|null=): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }
}
