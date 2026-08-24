<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\Render\DocumentRenderer;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Storage\StorageException;

/**
 * Server-side document/label render endpoint (ADR 0012 / WC-docdesigner
 * Track 2, extended by #947 item 1):
 *
 *   POST /api/document-templates/{id}/render  (documents:render)
 *
 * Resolves the template TENANT-SCOPED + RBAC (same visibility policy as
 * {@see DocumentTemplatesApiHandler::show()} — a caller who may not see the
 * template gets a 404, never a 403 that would confirm its existence), hands it
 * to {@see DocumentRenderer}, and either streams the PDF straight back or
 * persists it as a document record.
 *
 * TWO OUTCOMES, AND WHY THE DEFAULT IS THE EPHEMERAL ONE
 * ------------------------------------------------------
 *   `persist` absent or false (THE DEFAULT) — exactly the behaviour that
 *      shipped: `application/pdf` bytes, nothing written, no row, no id.
 *   `persist: true` — a `documents` record and an immutable artifact are
 *      created, and the response is JSON carrying the document, its artifact
 *      and a durable `content_url`.
 *
 * Persisting is opt-in rather than opt-out, and the reason is the caller mix
 * rather than caution. The overwhelmingly common render is the DESIGNER'S
 * PREVIEW — one per meaningful edit, dozens per session, none of them a
 * document anyone means to issue. Defaulting to persist would fill a tenant's
 * storage with drafts, give every one of them an id that a browser then has to
 * list, and do it as a silent behaviour change for every client already calling
 * this route. The cost of the opposite default is one boolean in the request
 * body of the calls that genuinely issue something, which is where the
 * intention actually lives.
 *
 * A persisted render also returns JSON rather than bytes-plus-a-header. An
 * `X-Document-Id` on a binary body is invisible to a browser download, absent
 * from the generated client's types, and undiscoverable in the spec; the
 * artifact is durably fetchable at `content_url` precisely so the response does
 * not have to be both things at once.
 *
 * Checks, in order (the flag check runs FIRST so a disabled instance never
 * attempts the internal HTTP call at all):
 *   1. `documents.render_enabled` (global setting) — 503 when off.
 *   2. RBAC — enforced by the route's `documents:render` permission gate.
 *   3. Tenant ownership + row visibility — via the repository + access policy.
 *   4. Template exists — 404 otherwise.
 *   5. When persisting: `documents.persist_enabled` (tenant-overridable) — 503
 *      when off, checked BEFORE the render so a refused persist does not first
 *      burn a Chromium render it is going to discard.
 *
 * Batch limits (max dataset rows / max total render units / max template
 * size) are tenant-overridable settings, not hardcoded (WC "no hardcoded
 * values" convention) — see {@see SettingsRegistry}. A breach is a 422, not a
 * 500 or a silent truncation.
 *
 * On any render-service failure (disabled, unreachable, timeout, bad
 * response) this returns a generic 503 — never a raw exception, and never a
 * downstream Node stack trace (WC-186).
 */
final class DocumentRenderApiHandler
{
    public function __construct(
        private readonly DocumentTemplateRepository $templates,
        private readonly DocumentAccessPolicy $policy,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
        private readonly DocumentRenderer $renderer,
        private readonly DocumentIssuer $issuer,
        // The WHERE half of TEMPLATE visibility (migration 117): a template
        // filed at an organizational unit is withheld from callers with no
        // standing there, so this path cannot become a way to reach a template
        // the designer's own list would not have shown.
        private readonly OuReachResolver $ouReach,
    ) {
    }

    /**
     * @param array<string, string> $params
     */
    public function render(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $effective = $this->settings->effective($tenantId);
        if (($effective[SettingsRegistry::DOCUMENTS_RENDER_ENABLED] ?? 'false') !== 'true') {
            return Response::error('Server-side document rendering is disabled on this instance', 503);
        }

        $id = (int) ($params['id'] ?? 0);
        $row = $this->templates->findById($id, $tenantId);
        if ($row === null || !$this->policy->canView(
            $row,
            $callerId,
            $this->permissionResolver($callerId, $tenantId),
            $this->ouReach->reachFor($tenantId, $callerId),
        )) {
            return Response::error('Template not found', 404);
        }

        $body = JsonBody::parsed($request);
        $persist = ($body['persist'] ?? false) === true;

        if ($persist) {
            // Before the render, not after: refusing a persist that has already
            // cost a headless-Chromium page is a wasted half-gigabyte and a
            // slow 503.
            if (($effective[SettingsRegistry::DOCUMENTS_PERSIST_ENABLED] ?? 'true') !== 'true') {
                return Response::error('Persisting rendered documents is disabled on this instance', 503);
            }
        }

        $templateData = $row['data'];
        if (!is_array($templateData)) {
            $templateData = [];
        }

        try {
            $pdf = $this->renderer->render($tenantId, $templateData, $body['dataRows'] ?? null, $body['sheet'] ?? null);
        } catch (DocumentRenderRejectedException $e) {
            // ->clientMessage, never ->getMessage(): see the exception's docblock.
            return Response::error($e->clientMessage, 422);
        } catch (RenderServiceUnavailableException $e) {
            error_log('[DocumentRenderApiHandler] render failed: ' . $e->getMessage());
            return Response::error('Document rendering is temporarily unavailable', 503);
        } catch (\Throwable $e) {
            error_log('[DocumentRenderApiHandler] unexpected render failure: ' . $e->getMessage());
            return Response::error('Document rendering is temporarily unavailable', 503);
        }

        if ($persist) {
            return $this->persist($tenantId, $callerId, $row, $body, $pdf);
        }

        $filename = DocumentPresenter::slugify((string) $row['name']) . '.pdf';

        return new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Turn the rendered bytes into a document record + a stored artifact.
     *
     * A storage failure is a 503 rather than a 500: the render succeeded and the
     * caller's request is not at fault, so this is the same "try again shortly"
     * shape the render-service failure above already has. Nothing is recorded
     * when it happens — {@see DocumentIssuer} rolls the record back rather than
     * leave one promising bytes that are not there.
     *
     * @param array<string, mixed> $template
     * @param array<string, mixed> $body
     */
    private function persist(int $tenantId, int $callerId, array $template, array $body, string $pdf): Response
    {
        // A caller-supplied title is what a routed document is recognised by in
        // an inbox; falling back to the template name keeps every record named
        // something, which is what the browser lists.
        $title = is_string($body['title'] ?? null) && trim($body['title']) !== ''
            ? trim((string) $body['title'])
            : (string) $template['name'];

        try {
            $issued = $this->issuer->issue($tenantId, $callerId, $template, mb_substr($title, 0, 255), $pdf);
        } catch (StorageException $e) {
            error_log('[DocumentRenderApiHandler] persisting the render failed: ' . $e->getMessage());
            return Response::error('Storing the rendered document is temporarily unavailable', 503);
        } catch (\Throwable $e) {
            error_log('[DocumentRenderApiHandler] unexpected persist failure: ' . $e->getMessage());
            return Response::error('Storing the rendered document is temporarily unavailable', 503);
        }

        return Response::json(
            ['data' => DocumentPresenter::document($issued['document'], [$issued['artifact']])],
            201
        );
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response. Mirrors
     * {@see DocumentTemplatesApiHandler::context()} exactly.
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
     * @return callable(string, int|null=): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }
}
