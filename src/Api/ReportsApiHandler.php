<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\i18n\LanguageRegistry;
use Whity\Core\Ou\OuReachResolver;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Report\ReportAssembler;
use Whity\Core\Report\ReportSourceRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Render\RenderRejectedException;
use Whity\Sdk\Render\RenderUnavailableException;

/**
 * Tabular reports, emitted as documents (#947 item 6):
 *
 *   GET  /api/reports                     — what may be run (documents:render)
 *   POST /api/reports/{source}/document   — run one, issue the document
 *
 * TWO GATES, AND BOTH MEAN SOMETHING DIFFERENT
 * ---------------------------------------------
 * The ROUTE is gated on `documents:render`: producing a document spends a
 * headless-browser page and writes to the tenant's storage, so it is the same
 * capability the render endpoint already requires, and a population that may
 * not render should not be able to make the platform render on their behalf by
 * calling it a report.
 *
 * The SOURCE is gated on its own existing permission, checked here — reporting
 * on documents needs `documents:read`, reporting on anything else needs
 * whatever already governs reading that. There is deliberately no `reports:run`
 * permission: a report is a READ, and a second vocabulary for it would be a
 * second answer to "may this person see these rows", drifting from the first
 * the moment either moved.
 *
 * The two compose as AND. Holding `documents:render` does not let anyone report
 * over data they cannot read, and holding `documents:read` does not let them
 * spend a render.
 *
 * A SOURCE THE CALLER MAY NOT READ IS A 404, NOT A 403
 * -----------------------------------------------------
 * Same rule the template and document handlers already follow: a 403 confirms
 * the source exists, and the catalogue is filtered to what the caller may run,
 * so a 403 here would leak the existence of a report they were not shown.
 *
 * ROW CEILING. Bounded by `documents.flow_max_table_rows`, the same tenant
 * setting the renderer enforces — asking for more would produce a payload the
 * render service refuses, so the ceiling is applied where the reason can still
 * be explained. When it bites, the DOCUMENT says so on its own first page; see
 * {@see ReportAssembler}. A caller is never handed a silent truncation.
 */
final class ReportsApiHandler
{
    public function __construct(
        private readonly ReportSourceRegistry $sources,
        private readonly ReportAssembler $assembler,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
        private readonly OuReachResolver $ouReach,
        private readonly LanguageRegistry $languages,
    ) {
    }

    /**
     * The reports this caller may actually run.
     *
     * FILTERED, not annotated. Returning every source with a `permitted: false`
     * beside it would publish the existence of reports over data the caller
     * cannot see, and would leave every client to implement the same filter
     * again — differently.
     */
    public function index(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $can = $this->permissionResolver($callerId, $tenantId);
        $language = $this->language();

        $catalogue = array_values(array_filter(
            $this->sources->catalogue($language),
            static fn (array $entry): bool => $can($entry['required_permission'])
        ));

        return Response::json(['data' => $catalogue]);
    }

    /**
     * Run a report and issue the document.
     *
     * @param array<string, string> $params
     */
    public function document(Request $request, array $params): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $source = $this->sources->get((string) ($params['source'] ?? ''));
        $can = $this->permissionResolver($callerId, $tenantId);

        if ($source === null || !$can($source->requiredPermission())) {
            // One branch for both, so the response cannot distinguish "no such
            // report" from "not yours" by timing or by wording.
            return Response::error('Report not found', 404);
        }

        $effective = $this->settings->effective($tenantId);
        $limit = (int) ($effective[SettingsRegistry::DOCUMENTS_FLOW_MAX_TABLE_ROWS]
            ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_FLOW_MAX_TABLE_ROWS));

        $language = $this->language();
        $reach = $this->ouReach->reachFor($tenantId, $callerId);

        try {
            $rows = $source->rows($tenantId, $callerId, $can, $reach, $limit);
            $total = $source->total($tenantId, $callerId, $can, $reach);
        } catch (\Throwable $e) {
            error_log('[ReportsApiHandler] running report ' . $source->key() . ' failed: ' . $e->getMessage());

            return Response::error('The report could not be run', 503);
        }

        try {
            $issued = $this->assembler->issue(
                $source->label($language),
                $source->columns($language),
                $rows,
                $total,
                $language,
                $this->isRightToLeft($language),
            );
        } catch (RenderRejectedException $e) {
            // ->clientMessage, never ->getMessage(): the first is written to be
            // shown, the second is whatever the nearest throw site put there.
            return Response::error($e->clientMessage, 422);
        } catch (RenderUnavailableException $e) {
            error_log('[ReportsApiHandler] rendering report ' . $source->key() . ' failed: ' . $e->getMessage());

            return Response::error('Report rendering is temporarily unavailable', 503);
        }

        return Response::json([
            'data' => [
                'document_id' => $issued->documentId,
                'title' => $issued->title,
                'page_count' => $issued->pageCount,
                // The reader needs BOTH numbers, for the same reason the printed
                // page carries both: `rows` alone cannot say whether it is the
                // whole set.
                'row_count' => count($rows),
                'total_rows' => $total,
                'truncated' => count($rows) < $total,
                'content_url' => $issued->contentUrl,
            ],
        ], $issued->hasArtifact() ? 201 : 202);
    }

    /**
     * The reader's language, as the request already resolved it.
     *
     * Read from the registry rather than from a request field, because the
     * registry is what `ResolveLanguage` sets and what every translator in the
     * process is already answering against — a report that picked its language
     * a second way could print its own furniture in one language and its
     * source's column labels in another.
     */
    private function language(): string
    {
        return $this->languages->getCurrentLanguageCode();
    }

    /**
     * Whether the document is laid out right-to-left.
     *
     * Derived from the language rather than from a caller-supplied flag: a
     * caller that could ask for Arabic text in a left-to-right document would
     * be able to produce one, and it would look almost right — which is the
     * hardest kind of wrong to notice in a printed report.
     */
    private function isRightToLeft(string $language): bool
    {
        $base = strtolower(explode('-', $language)[0]);

        return in_array($base, ['ar', 'fa', 'he', 'ur'], true);
    }

    /**
     * @return array{0: int, 1: int}|Response
     */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }

        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
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
