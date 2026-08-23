<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

use Whity\Core\Document\BlockReferenceScanner;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;

/**
 * Turns a stored template plus a request's data into PDF bytes (ADR 0012).
 *
 * WHY THIS WAS LIFTED OUT OF THE HANDLER
 * --------------------------------------
 * All of this — the ceilings, the `dataRows` normalisation, the sample-data
 * fallback, the `blockInstance` resolution, the call itself — lived inside
 * {@see \Whity\Api\DocumentRenderApiHandler} while there was exactly one way to
 * ask for a render. #947 item 1 adds a second (re-render an existing document
 * to append a corrected artifact), and a second copy of the ceiling checks is
 * how one endpoint ends up enforcing a limit the other does not. The handler
 * keeps the HTTP: the flag, the RBAC, the status codes, the response shape.
 *
 * The behaviour is unchanged and the error MESSAGES are preserved verbatim,
 * because they are the documented 422 bodies and a caller may be matching on
 * them.
 *
 * FAILURE SURFACE
 * ---------------
 *  - {@see DocumentRenderRejectedException} — the request will not be attempted
 *    (the caller's problem; a 422).
 *  - {@see RenderServiceUnavailableException} — the service could not do it
 *    (a 503, and never a downstream Node stack trace).
 *
 * Nothing here touches the database except to resolve referenced blocks, which
 * it does TENANT-SCOPED through the block repository — the render service has
 * no database of its own and must be handed everything it needs.
 */
final class DocumentRenderer
{
    public function __construct(
        private readonly DocumentBlockRepository $blocks,
        private readonly SettingsService $settings,
        private readonly RenderServiceClientInterface $renderService,
    ) {
    }

    /**
     * Render a template to PDF bytes.
     *
     * @param array<string, mixed> $templateData The verbatim client DocTemplate JSON.
     * @param mixed                $rawDataRows  The request's `dataRows`, unvalidated.
     * @param mixed                $rawSheet     The request's `sheet`, unvalidated.
     *
     * @throws DocumentRenderRejectedException   Bad input, or a ceiling exceeded.
     * @throws RenderServiceUnavailableException The render service failed.
     */
    public function render(int $tenantId, array $templateData, mixed $rawDataRows, mixed $rawSheet): string
    {
        $effective = $this->settings->effective($tenantId);

        $templateBytes = strlen((string) json_encode($templateData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $maxTemplateBytes = (int) ($effective[SettingsRegistry::DOCUMENTS_RENDER_MAX_TEMPLATE_BYTES]
            ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_RENDER_MAX_TEMPLATE_BYTES));
        if ($templateBytes > $maxTemplateBytes) {
            throw DocumentRenderRejectedException::because(
                "Template exceeds the maximum render size ({$maxTemplateBytes} bytes)"
            );
        }

        $dataRows = $this->normalizeDataRows($rawDataRows, $templateData);
        if ($dataRows === null) {
            throw DocumentRenderRejectedException::because('dataRows must be a list of flat string maps');
        }

        $maxRows = (int) ($effective[SettingsRegistry::DOCUMENTS_RENDER_MAX_ROWS]
            ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_RENDER_MAX_ROWS));
        if (count($dataRows) > $maxRows) {
            throw DocumentRenderRejectedException::because("Too many dataset rows (max {$maxRows})");
        }

        $pagesPerRow = max(1, count($templateData['pages'] ?? []));
        $totalUnits = count($dataRows) * $pagesPerRow;
        $maxUnits = (int) ($effective[SettingsRegistry::DOCUMENTS_RENDER_MAX_PAGES]
            ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_RENDER_MAX_PAGES));
        if ($totalUnits > $maxUnits) {
            throw DocumentRenderRejectedException::because(
                "Render would produce too many pages ({$totalUnits}, max {$maxUnits})"
            );
        }

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
            'sheet'     => $this->normalizeSheet($rawSheet),
            'blocks'    => $this->resolveBlocks($templateData, $tenantId),
        ];

        return $this->renderService->render($payload);
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
            // @db-bool-ignore: $raw is the render request's `sheet` object from the
            // JSON body, not a row — there is no `sheet` table and no BOOLEAN
            // column behind this.
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
}
