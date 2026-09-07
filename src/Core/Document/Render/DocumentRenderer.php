<?php

declare(strict_types=1);

namespace Whity\Core\Document\Render;

use Whity\Core\Document\BlockReferenceScanner;
use Whity\Core\Document\DocumentBlockRepository;
use Whity\Core\Document\Qr\DocumentQrStamp;
use Whity\Core\Document\Qr\QrTemplateComposer;
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
        /**
         * The flowing renderer, for templates in document mode (#1186).
         *
         * NULLABLE so an existing construction of this class keeps working —
         * every test that builds one, and any host that wires it by hand. A
         * null one is not a silent downgrade: a flow template refuses with a
         * message naming the missing wiring, because printing its canvas pages
         * instead would produce the blank document this whole seam exists to
         * stop.
         */
        private readonly ?FlowDocumentRenderer $flow = null,
    ) {
    }

    /**
     * Render a template to PDF bytes.
     *
     * @param array<string, mixed> $templateData The verbatim client DocTemplate JSON.
     * @param mixed                $rawDataRows  The request's `dataRows`, unvalidated.
     * @param mixed                $rawSheet     The request's `sheet`, unvalidated.
     * @param DocumentQrStamp|null $qr           The document's verification code, or
     *        null when it carries none. See {@see QrTemplateComposer::compose()} — null is not
     *        merely "do nothing", it actively REMOVES an authored code, which is
     *        what stops a template with a QR element placed from printing an
     *        empty dashed box on every document of a tenant that has the feature
     *        switched off.
     *
     * @throws DocumentRenderRejectedException   Bad input, or a ceiling exceeded.
     * @throws RenderServiceUnavailableException The render service failed.
     */
    public function render(
        int $tenantId,
        array $templateData,
        mixed $rawDataRows,
        mixed $rawSheet,
        ?DocumentQrStamp $qr = null,
    ): string {
        // DOCUMENT MODE TAKES A DIFFERENT ROAD ENTIRELY (#1186).
        //
        // Everything below this branch is the fixed-canvas path: it measures a
        // `pages` tree, resolves block instances into it, and asks the service
        // to print one PDF page per template page. A flow template's content
        // does not live in `pages` — it lives in `flow` — so running it through
        // any of that would print the canvas the author never used, which for a
        // document built entirely in flow mode is a blank starting page.
        //
        // That is what happened before this branch existed: the document was
        // authored, saved, and printed as nothing, with no error anywhere.
        if (FlowTemplatePayload::isFlowMode($templateData)) {
            return $this->renderFlow($tenantId, $templateData);
        }

        $effective = $this->settings->effective($tenantId);

        // BEFORE the size ceiling, deliberately. Composition can ADD elements —
        // the supplied default placement is a QR plus its caption — so measuring
        // the template first would let a document sail past a limit the bytes
        // actually sent then exceed. The ceiling exists to bound what crosses
        // the wire, so it has to measure what crosses the wire.
        $templateData = QrTemplateComposer::compose($templateData, $qr !== null)['data'];

        $templateBytes = strlen((string) json_encode($templateData, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $maxTemplateBytes = (int) ($effective[SettingsRegistry::DOCUMENTS_RENDER_MAX_TEMPLATE_BYTES]
            ?? SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_RENDER_MAX_TEMPLATE_BYTES));
        if ($templateBytes > $maxTemplateBytes) {
            throw DocumentRenderRejectedException::because(
                "Template exceeds the maximum render size ({$maxTemplateBytes} bytes)"
            );
        }

        // Delegated to VariableData since `POST /api/documents` has to apply the
        // IDENTICAL reading to values it PERSISTS without rendering them
        // (migration 118). Two normalisers would let a document store values
        // this renderer would then refuse — a document that cannot be rendered,
        // discovered weeks later with nothing pointing at the two spellings.
        // Idempotent, so a caller that already normalised loses nothing here.
        $dataRows = VariableData::normalizeRows($rawDataRows, $templateData);
        if ($dataRows === null) {
            throw DocumentRenderRejectedException::because('dataRows must be a list of flat string maps');
        }

        // The reserved verification values are merged into every row AFTER
        // normalisation, so they cannot be refused by the flat-string-map check
        // and cannot be overwritten by a template that declares a placeholder of
        // the same dotted name. Every row, not the first: a label sheet is one
        // document of N physical things, and a code on only the top one would
        // make the rest unverifiable while looking exactly like the part that is.
        if ($qr !== null) {
            $dataRows = QrTemplateComposer::rowsWith($dataRows, $qr->url, $qr->reference);
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
     * Render a document-mode template through the flowing service.
     *
     * The ceilings, the enablement check and the call itself already belong to
     * {@see FlowDocumentRenderer} — it is what the SDK's `FlowDocument` path
     * uses — so this only has to build the payload and hand it over. Two
     * renderers applying two sets of limits to the same service is how one of
     * them ends up enforcing a bound the other does not.
     *
     * @param array<string, mixed> $templateData
     *
     * @throws DocumentRenderRejectedException   Empty content, or a ceiling.
     * @throws RenderServiceUnavailableException The service could not do it.
     */
    private function renderFlow(int $tenantId, array $templateData): string
    {
        if ($this->flow === null) {
            // Named rather than silently falling through to the canvas path.
            // Falling through would print a blank page and report success,
            // which is the failure this branch was added to remove.
            throw DocumentRenderRejectedException::because(
                'This document is in document mode, which this instance cannot render'
            );
        }

        return $this->flow->render($tenantId, FlowTemplatePayload::build($templateData))->bytes;
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
        // A WORKLIST, not a single pass over the template's own references
        // (#1186 slice 3). A block may now hold another block, and a nested
        // reference lives in the PARENT BLOCK'S data — somewhere this method
        // never looked, because it scanned the template tree alone.
        //
        // Scanning once was correct while nesting was forbidden and silently
        // wrong the moment it was not: the nested id never entered the map, the
        // harness looked it up, found nothing, and drew nothing. The document
        // would have printed with a hole in it and no error anywhere.
        $queue   = BlockReferenceScanner::collectBlockIds($templateData);
        $out     = [];
        $visited = [];

        while ($queue !== []) {
            $id = (string) array_shift($queue);

            // Also the cycle guard. A block that (transitively) contains itself
            // is resolved once and not re-entered, so a malformed library costs
            // a wrong-looking document rather than a render that never returns.
            if (isset($visited[$id])) {
                continue;
            }
            $visited[$id] = true;

            if (!ctype_digit($id)) {
                continue;
            }

            $block = $this->blocks->findById((int) $id, $tenantId);
            if ($block === null) {
                continue;
            }

            $out[$id] = ['id' => $id, 'elements' => $block['data']];

            // Tenant-scoped at every level: `findById` takes the tenant, so a
            // nested pointer cannot reach across tenants however deep it sits.
            foreach (BlockReferenceScanner::collectBlockIds($block['data']) as $childId) {
                if (!isset($visited[$childId])) {
                    $queue[] = $childId;
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
