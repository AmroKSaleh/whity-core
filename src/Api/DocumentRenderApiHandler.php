<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\BlockReferenceScanner;
use Whity\Core\Document\DocumentAccessPolicy;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\DocumentTemplateRepository;
use Whity\Core\Document\Render\RenderServiceClientInterface;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Server-side document/label render endpoint (ADR 0012 / WC-docdesigner
 * Track 2):
 *
 *   POST /api/document-templates/{id}/render  (documents:render)
 *
 * Resolves the template TENANT-SCOPED + RBAC (same visibility policy as
 * {@see DocumentTemplatesApiHandler::show()} — a caller who may not see the
 * template gets a 404, never a 403 that would confirm its existence),
 * assembles `{template, dataRows, sheet, blocks}` (resolving any
 * `blockInstance` references to their live block elements — the render
 * service has no database access of its own) and calls the separate
 * `whity_render` Docker service over internal HTTP, streaming the returned
 * PDF back with `Content-Type: application/pdf`.
 *
 * Checks, in order (all four must pass; the flag check runs FIRST so a
 * disabled instance never attempts the internal HTTP call at all):
 *   1. `documents.render_enabled` (global setting) — 503 when off.
 *   2. RBAC — enforced by the route's `documents:render` permission gate.
 *   3. Tenant ownership + row visibility — via the repository + access policy.
 *   4. Template exists — 404 otherwise.
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
        private readonly DocumentBlockRepository $blocks,
        private readonly DocumentAccessPolicy $policy,
        private readonly RoleChecker $roleChecker,
        private readonly SettingsService $settings,
        private readonly RenderServiceClientInterface $renderService,
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
        if ($row === null || !$this->policy->canView($row, $callerId, $this->permissionResolver($callerId, $tenantId))) {
            return Response::error('Template not found', 404);
        }

        $body = JsonBody::parsed($request);

        $templateData = $row['data'];
        if (!is_array($templateData)) {
            $templateData = [];
        }
        $templateBytes = strlen((string) json_encode($templateData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $maxTemplateBytes = (int) ($effective[SettingsRegistry::DOCUMENTS_RENDER_MAX_TEMPLATE_BYTES] ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_RENDER_MAX_TEMPLATE_BYTES));
        if ($templateBytes > $maxTemplateBytes) {
            return Response::error("Template exceeds the maximum render size ({$maxTemplateBytes} bytes)", 422);
        }

        $dataRows = $this->normalizeDataRows($body['dataRows'] ?? null, $templateData);
        if ($dataRows === null) {
            return Response::error('dataRows must be a list of flat string maps', 422);
        }

        $maxRows = (int) ($effective[SettingsRegistry::DOCUMENTS_RENDER_MAX_ROWS] ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_RENDER_MAX_ROWS));
        if (count($dataRows) > $maxRows) {
            return Response::error("Too many dataset rows (max {$maxRows})", 422);
        }

        $pagesPerRow = max(1, count($templateData['pages'] ?? []));
        $totalUnits = count($dataRows) * $pagesPerRow;
        $maxUnits = (int) ($effective[SettingsRegistry::DOCUMENTS_RENDER_MAX_PAGES] ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_RENDER_MAX_PAGES));
        if ($totalUnits > $maxUnits) {
            return Response::error("Render would produce too many pages ({$totalUnits}, max {$maxUnits})", 422);
        }

        $sheet = $this->normalizeSheet($body['sheet'] ?? null);

        $payload = [
            'template'  => $templateData,
            // A row with no placeholders at all (e.g. a template that binds
            // none) normalises to an EMPTY PHP array, which json_encode()
            // serialises as `[]` — a JSON ARRAY, not the `{}` object the
            // render harness's `Record<string, string>` row shape needs.
            // Casting only the empty ones to stdClass keeps every non-empty
            // row (which already has string keys and encodes as an object
            // regardless) untouched.
            'dataRows'  => array_map(
                static fn (array $r): array|\stdClass => $r === [] ? new \stdClass() : $r,
                $dataRows
            ),
            'sheet'     => $sheet,
            'blocks'    => $this->resolveBlocks($templateData, $tenantId),
        ];

        try {
            $pdf = $this->renderService->render($payload);
        } catch (RenderServiceUnavailableException $e) {
            error_log('[DocumentRenderApiHandler] render failed: ' . $e->getMessage());
            return Response::error('Document rendering is temporarily unavailable', 503);
        } catch (\Throwable $e) {
            error_log('[DocumentRenderApiHandler] unexpected render failure: ' . $e->getMessage());
            return Response::error('Document rendering is temporarily unavailable', 503);
        }

        $filename = $this->slugify((string) $row['name']) . '.pdf';

        return new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }

    /**
     * Resolve every `blockInstance`-referenced block (anywhere in the template
     * tree) to its live elements, tenant-scoped. A reference to a deleted/
     * foreign-tenant block is simply omitted from the map — the render harness
     * (mirroring `BlockInstanceContent`'s client-side behaviour) renders a
     * missing block as empty rather than failing the whole render.
     *
     * @param array<string, mixed> $templateData
     * @return array<int|string, array{id: string, elements: mixed}> Keyed by
     *         the block id — PHP coerces an all-digit string array key to int.
     *         Harmless for the render harness either way: whether
     *         json_encode() serialises this as a JSON object or (only
     *         possible for the contiguous-from-zero edge case) an array, the
     *         harness only ever does a direct `blocks[el.blockId]` lookup,
     *         which resolves the same on a JS object OR array.
     */
    private function resolveBlocks(array $templateData, int $tenantId): array
    {
        $ids = BlockReferenceScanner::collectBlockIds($templateData);
        $out = [];
        foreach ($ids as $id) {
            if (!ctype_digit($id)) {
                continue;
            }
            $block = $this->blocks->findById((int) $id, $tenantId);
            if ($block !== null) {
                $out[$id] = ['id' => $id, 'elements' => $block['data']];
            }
        }

        return $out;
    }

    /**
     * Validate + normalise the request's `dataRows`: a list of flat
     * string=>string maps. Absent/empty defaults to a single row built from
     * the template's placeholder samples (mirrors the designer's own
     * `sampleDataOf()` preview default — a render with no explicit batch still
     * produces one sensible page rather than an empty one).
     *
     * @param array<string, mixed> $templateData
     * @return list<array<string, string>>|null Null on a validation failure.
     */
    private function normalizeDataRows(mixed $raw, array $templateData): ?array
    {
        if ($raw === null) {
            return [$this->sampleDataOf($templateData)];
        }
        if (!is_array($raw) || !array_is_list($raw)) {
            return null;
        }
        if ($raw === []) {
            return [$this->sampleDataOf($templateData)];
        }

        $rows = [];
        foreach ($raw as $row) {
            if (!is_array($row)) {
                return null;
            }
            $normalized = [];
            foreach ($row as $key => $value) {
                if (!is_string($key) || !is_scalar($value)) {
                    return null;
                }
                $normalized[$key] = (string) $value;
            }
            $rows[] = $normalized;
        }

        return $rows;
    }

    /**
     * The sample-data map built from a template's placeholders (key -> sample),
     * mirroring `web/lib/documents/storage.ts`'s `sampleDataOf()`.
     *
     * @param array<string, mixed> $templateData
     * @return array<string, string>
     */
    private function sampleDataOf(array $templateData): array
    {
        $out = [];
        $placeholders = $templateData['placeholders'] ?? [];
        if (is_array($placeholders)) {
            foreach ($placeholders as $p) {
                if (is_array($p) && is_string($p['key'] ?? null)) {
                    $out[$p['key']] = (string) ($p['sample'] ?? '');
                }
            }
        }

        return $out;
    }

    /**
     * Validate + coerce the optional `sheet` (N-up label-sheet layout) request
     * field to the shape the render harness expects (mirrors `SheetSpec` in
     * `packages/ui/src/documents/sheet.ts`). Returns null when absent/invalid —
     * an invalid sheet degrades to "no tiling" rather than failing the render.
     *
     * @return array<string, bool|float>|null
     */
    private function normalizeSheet(mixed $raw): ?array
    {
        if (!is_array($raw)) {
            return null;
        }
        $numeric = static fn (mixed $v): ?float => is_numeric($v) ? (float) $v : null;

        $cols = $numeric($raw['cols'] ?? null);
        $rows = $numeric($raw['rows'] ?? null);
        $sheetWidthMm = $numeric($raw['sheetWidthMm'] ?? null);
        $sheetHeightMm = $numeric($raw['sheetHeightMm'] ?? null);
        $marginXMm = $numeric($raw['marginXMm'] ?? null);
        $marginYMm = $numeric($raw['marginYMm'] ?? null);
        $gutterXMm = $numeric($raw['gutterXMm'] ?? null);
        $gutterYMm = $numeric($raw['gutterYMm'] ?? null);
        if ($cols === null || $rows === null || $sheetWidthMm === null || $sheetHeightMm === null
            || $marginXMm === null || $marginYMm === null || $gutterXMm === null || $gutterYMm === null) {
            return null;
        }

        return [
            'enabled' => (bool) ($raw['enabled'] ?? false),
            'cols' => $cols,
            'rows' => $rows,
            'sheetWidthMm' => $sheetWidthMm,
            'sheetHeightMm' => $sheetHeightMm,
            'marginXMm' => $marginXMm,
            'marginYMm' => $marginYMm,
            'gutterXMm' => $gutterXMm,
            'gutterYMm' => $gutterYMm,
        ];
    }

    private function slugify(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '-', $slug) ?? '';
        $slug = trim($slug, '-');

        return $slug !== '' ? $slug : 'document';
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
     * @return callable(string): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        $set = array_fill_keys($this->roleChecker->getEffectivePermissionsForProfile($callerId, $tenantId), true);

        return static fn (string $permission): bool => isset($set[$permission]);
    }
}
