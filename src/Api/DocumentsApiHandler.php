<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentArtifactRepository;
use Whity\Core\Document\DocumentArtifactStore;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;
use Whity\Storage\StorageException;

/**
 * Issued documents (#947 item 1):
 *
 *   GET  /api/documents                                    (documents:read)
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
 * WHY THE READ SURFACE IS THIS SMALL
 * ----------------------------------
 * #947 item 5 builds the document BROWSER, and its central argument is that
 * every useful folder is a QUERY over what routing already holds rather than
 * a stored tree. Anticipating those queries here — a `?folder=awaiting-me`,
 * an OU-subtree filter — would mean inventing them before the routing facts
 * they read exist, and item 5 would inherit a half-shaped surface it has to
 * unpick. So this list is deliberately the plain one: the tenant's documents,
 * newest first, paginated, filtered to what the caller may see. The columns
 * item 5 needs are indexed (migration 106) and the filter it will add is one
 * predicate in {@see DocumentRepository}.
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
 * already apply.
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
    ) {
    }

    /**
     * GET /api/documents — the caller's visible documents, newest first.
     */
    public function list(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        // Resolved ONCE and pushed into the query: see
        // DocumentVisibilityPolicy::restrictToCreator() for why this is a
        // predicate rather than a post-filter over the fetched page.
        $onlyMine = $this->visibility->restrictToCreator($callerId, $this->permissionResolver($callerId, $tenantId));

        $p = PaginationParams::fromPath($request->getPath());
        $total = $this->documents->countForTenant($tenantId, $onlyMine);
        $rows = $this->documents->listForTenant($tenantId, $onlyMine, $p->perPage, $p->offset);

        // The artifact list is fetched per document rather than in one join:
        // the join returns a document once per artifact and has to be
        // re-collapsed in PHP, and a page is at most PaginationParams::MAX_PER_PAGE
        // rows. When the browser (item 5) makes this a hot path, the fix is a
        // single `WHERE document_id IN (...)` fetch collapsed once — not a
        // wider row shape here.
        $data = array_map(
            fn (array $row): array => DocumentPresenter::document(
                $row,
                $this->artifacts->listForDocument((int) $row['id'], $tenantId)
            ),
            $rows
        );

        return Response::json(['data' => $data, 'pagination' => $p->meta($total)]);
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
        [$tenantId, , $document] = $resolved;

        return Response::json([
            'data' => DocumentPresenter::document(
                $document,
                $this->artifacts->listForDocument((int) $document['id'], $tenantId)
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
            || !$this->templatePolicy->canView($template, $callerId, $this->permissionResolver($callerId, $tenantId))) {
            return Response::error('The template this document was issued from is no longer available', 409);
        }

        $body = JsonBody::parsed($request);
        $templateData = is_array($template['data']) ? $template['data'] : [];

        try {
            $pdf = $this->renderer->render($tenantId, $templateData, $body['dataRows'] ?? null, $body['sheet'] ?? null);
        } catch (DocumentRenderRejectedException $e) {
            return Response::error($e->getMessage(), 422);
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
     * @return callable(string): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        $set = array_fill_keys($this->roleChecker->getEffectivePermissionsForProfile($callerId, $tenantId), true);

        return static fn (string $permission): bool => isset($set[$permission]);
    }
}
