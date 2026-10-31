<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentIssuer;
use Whity\Core\Document\DocumentPresenter;
use Whity\Core\Document\DocumentSourceFileStore;
use Whity\Core\Document\DocumentSourceVersionRepository;
use Whity\Core\Document\Issuing\ActingAs;
use Whity\Core\Document\Issuing\DocumentIssuing;
use Whity\Core\Document\Issuing\IssueRequest;
use Whity\Core\Document\Embed\DocumentEmbedRegistry;
use Whity\Core\Document\Fields\DocumentFieldRegistry;
use Whity\Core\Document\Print\DocumentPublishCheck;
use Whity\Core\Document\Print\LabelCsv;
use Whity\Core\Document\Print\LetterheadLogo;
use Whity\Core\Document\Print\PrintLabels;
use Whity\Core\Document\Print\SourceDocumentPrinter;
use Whity\Core\Document\Qr\DocumentQrService;
use Whity\Core\Document\Render\DocumentRenderRejectedException;
use Whity\Core\Document\Render\FlowDocumentRenderer;
use Whity\Core\Document\Render\RenderServiceUnavailableException;
use Whity\Core\Document\Theme\DocumentThemeRepository;
use Whity\Core\Entitlement\MeterService;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Sdk\Hooks\HookVetoException;

/**
 * Templates from the document editor, from draft to paper (editor M4;
 * IMPLEMENTATION-NOTES 3.2 and 3.7):
 *
 *   GET  /api/document-fields?recordType=            the field picker's groups,
 *                                                    the letterhead and print colour
 *   GET  /api/document-fields/records?recordType=&q= records to preview with
 *   GET  /api/document-sources/{id}/publish-check    what blocks publishing (E.37)
 *   POST /api/document-sources/{id}/publish          the draft becomes a version (E.9)
 *   GET  /api/document-sources/{id}/versions         the history
 *   GET  /api/document-sources/{id}/preview?record=  field values for one record (E.12)
 *   POST /api/document-sources/{id}/render           the PDF through Whity Render (E.16)
 *   POST /api/document-sources/{id}/print-labels     a label sheet, one label per record or CSV row (E.43)
 *
 * Every route first finds the document the way the editor's own routes do
 * (DocumentSourcesApiHandler::visibleRow), so a hidden document answers 404
 * here too, and every value is read for the person asking.
 */
final class DocumentPublishingApiHandler
{
    /** The limits one rendered document spends. */
    private const RENDER_METERS = DocumentIssuing::RENDER_METERS;

    private const MAX_RECORDS = 25;

    /** Label sheets (E.43): records read for one print, and CSV rows. */
    private const MAX_LABEL_RECORDS = 500;
    private const MAX_LABEL_ROWS = 5000;

    private SourceDocumentPrinter $print;

    private ?DocumentIssuing $issuing;

    public function __construct(
        private readonly DocumentSourcesApiHandler $sources,
        private readonly DocumentSourceVersionRepository $versions,
        private readonly DocumentFieldRegistry $fields,
        DocumentEmbedRegistry $embeds,
        private readonly RoleChecker $roleChecker,
        SettingsService $settings,
        private readonly ?FlowDocumentRenderer $renderer = null,
        ?DocumentIssuer $issuer = null,
        ?DocumentSourceFileStore $files = null,
        ?PrintLabels $printLabels = null,
        private readonly ?MeterService $meters = null,
        ?LetterheadLogo $logo = null,
        ?DocumentQrService $qr = null,
        ?DocumentIssuing $issuing = null,
        // The workspace's document themes (editor M4d).
        ?DocumentThemeRepository $themes = null,
    ) {
        $this->print = new SourceDocumentPrinter($fields, $embeds, $settings, $files, $printLabels, $logo, $themes);
        $this->issuing = $issuing ?? ($issuer !== null
            ? new DocumentIssuing($this->print, $versions, $issuer, $settings, $renderer, $qr, $meters)
            : null);
    }

    public function fields(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;
        $type = self::query($request)['recordType'] ?? null;
        $type = is_string($type) && $type !== '' ? $type : null;

        return Response::json(['data' => [
            'record_types' => $this->fields->recordTypes($tenantId),
            'groups' => $this->fields->groups($tenantId, $type),
            // The name and default lines, and every line by its theme name, so
            // the editor shows the lines the document's theme lists. The print
            // colour comes with the document (resolvedTheme, editor M4d).
            'letterhead' => $this->print->letterhead($tenantId),
            'letterhead_parts' => $this->print->letterheadParts($tenantId)['parts'],
        ]]);
    }

    public function records(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        $q = self::query($request);
        $type = (string) ($q['recordType'] ?? '');
        if (!$this->fields->has($type, $tenantId)) {
            return Response::error('Unknown record type', 404);
        }

        return Response::json(['data' => $this->fields->records(
            $tenantId,
            $callerId,
            $this->permissions($callerId, $tenantId),
            $type,
            mb_substr((string) ($q['q'] ?? ''), 0, 100),
            self::MAX_RECORDS,
        )]);
    }

    /** @param array<string, string> $params */
    public function publishCheck(Request $request, array $params): Response
    {
        $found = $this->template($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , $row] = $found;

        return Response::json(['data' => $this->blockers($tenantId, $row)])
            ->withHeaders(['ETag' => '"' . (int) $row['draft_version'] . '"']);
    }

    /**
     * Runs the check again and publishes exactly the draft version it checked
     * (If-Match), so a save landing in between can't be published unchecked.
     *
     * @param array<string, string> $params
     */
    public function publish(Request $request, array $params): Response
    {
        $found = $this->template($request, $params);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;

        $base = DocumentSourcesApiHandler::parseIfMatch($request->getHeader('If-Match'));
        if ($base === null) {
            return Response::error('If-Match with the draft version is required', 428);
        }
        if ($base !== (int) $row['draft_version']) {
            return Response::error('The document changed since it was checked', 409, ['draft_version' => (int) $row['draft_version']]);
        }
        $blockers = $this->blockers($tenantId, $row);
        if ($blockers !== []) {
            return Response::error('The template has something that blocks publishing', 409, ['blockers' => $blockers]);
        }

        // The version keeps the theme it is published with (E.44): printing it
        // never resolves again, so a theme changed later reaches it only when
        // a person publishes it again.
        $theme = $this->print->theme($tenantId, $row['theme'] ?? null, (string) $row['kind']);
        $version = $this->versions->publish((int) $row['id'], $tenantId, $base, $callerId, $theme);
        if ($version === null) {
            return Response::error('The document changed since it was checked', 409);
        }

        return Response::json(['data' => ['published_version' => $version]], 201);
    }

    /** @param array<string, string> $params */
    public function history(Request $request, array $params): Response
    {
        $found = $this->template($request, $params, false);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , $row] = $found;

        return Response::json(['data' => $this->versions->listForSource((int) $row['id'], $tenantId)]);
    }

    /**
     * GET /api/document-sources/{id}/versions/{version}: what a published
     * version says (title, page, blocks, own fields), for the editor's issuing
     * mode to show exactly what will print.
     *
     * @param array<string, string> $params
     */
    public function version(Request $request, array $params): Response
    {
        $found = $this->template($request, $params, false);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, , $row] = $found;
        $version = $this->versions->find((int) $row['id'], $tenantId, (int) ($params['version'] ?? 0));
        if ($version === null) {
            return Response::error('Version not found', 404);
        }

        return Response::json(['data' => $version]);
    }

    /**
     * E.12: each field's value for one record as the caller may see it. A
     * record the caller can't see answers the same 404 as one that doesn't exist.
     *
     * @param array<string, string> $params
     */
    public function preview(Request $request, array $params): Response
    {
        $found = $this->template($request, $params, false);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;

        $record = self::parseRecord(self::query($request)['record'] ?? null);
        $type = $row['record_type'] !== null ? (string) $row['record_type'] : null;
        if ($type !== null && ($record === null || $record[0] !== $type)) {
            return Response::error('record must be "' . $type . ':<id>"', 422);
        }
        $resolved = $this->fields->resolve($tenantId, $callerId, $this->permissions($callerId, $tenantId), $type, $record[1] ?? null);
        if ($resolved === null) {
            return Response::error('Record not found', 404);
        }

        return Response::json(['data' => $resolved]);
    }

    /**
     * The PDF of the published version (or the draft, for a preview) filled
     * for one record. Field values, records and charts are read now, for the
     * caller, and frozen into the PDF (E.16). With `persist`, the published
     * version is issued as a document (Issuing\DocumentIssuing), as
     * `POST /api/documents` does: 201 with the document and what happened to
     * its PDF.
     *
     * @param array<string, string> $params
     */
    public function render(Request $request, array $params): Response
    {
        $found = $this->template($request, $params, false);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        if ($this->renderer === null || !$this->renderer->isEnabled($tenantId)) {
            return Response::error('Document rendering is not enabled on this instance', 503);
        }

        $body = JsonBody::parsed($request);
        $draft = ($body['draft'] ?? false) === true;
        $persist = ($body['persist'] ?? false) === true;
        if ($draft && $persist) {
            return Response::error('A draft can be previewed as a PDF but not issued', 422);
        }
        if ($persist && $this->issuing === null) {
            return Response::error('Issuing documents is not available on this instance', 503);
        }
        // The route needs documents:render (a preview); issuing needs documents:issue as well.
        if ($persist && !$this->permissions($callerId, $tenantId)(CorePermissions::DOCUMENTS_ISSUE)) {
            return Response::error('Issuing a document requires documents:issue', 403);
        }

        $version = null;
        if ($draft) {
            $doc = ['title' => (string) $row['title'], 'page' => $row['page'], 'blocks' => $row['blocks'], 'version' => null];
        } else {
            $published = $row['published_version'] !== null
                ? $this->versions->find((int) $row['id'], $tenantId, (int) $row['published_version'])
                : null;
            if ($published === null) {
                return Response::error('This template has no published version yet', 409);
            }
            $doc = ['title' => $published['title'], 'page' => $published['page'], 'blocks' => $published['blocks'], 'version' => $published['version']];
            $version = $published;
        }

        $hasPermission = $this->permissions($callerId, $tenantId);
        $type = $row['record_type'] !== null ? (string) $row['record_type'] : null;
        $record = self::parseRecord($body['record'] ?? null);
        if ($type !== null && ($record === null || $record[0] !== $type)) {
            return Response::error('record must be "' . $type . ':<id>"', 422);
        }
        $resolved = $this->fields->resolve($tenantId, $callerId, $hasPermission, $type, $record[1] ?? null);
        if ($resolved === null) {
            return Response::error('Record not found', 404);
        }
        $values = $resolved['values'];

        $title = $resolved['label'] !== '' ? $doc['title'] . ' · ' . $resolved['label'] : $doc['title'];
        if ($persist && $this->issuing !== null && $version !== null) {
            // E.27: refuse rather than issue a document with a required field missing.
            $actor = new ActingAs($tenantId, $callerId, $hasPermission);
            $missing = $this->issuing->missing(new IssueRequest($row, $version, $values, $title), $actor);
            if ($missing !== []) {
                return Response::error('Required fields have no value for this record', 422, ['missing' => $missing]);
            }
            try {
                $issued = $this->issuing->issue(new IssueRequest(
                    source: $row,
                    version: $version,
                    values: $values,
                    title: $title,
                    recordLabel: $resolved['label'],
                    render: true,
                    record: $record !== null ? $record[0] . ':' . $record[1] : null,
                ), $actor);
            } catch (HookVetoException $e) {
                return Response::error($e->reason(), 409, ['code' => 'vetoed', 'reason' => 'blocked_by_plugin']);
            } catch (\Throwable $e) {
                error_log('[DocumentPublishingApiHandler] issuing failed: ' . $e->getMessage());

                return Response::error('The document could not be created', 503);
            }

            return Response::json([
                'data' => DocumentPresenter::document($issued['document'], $issued['artifacts']),
                'render' => $issued['render'],
            ], 201);
        }

        $printer = $this->print->printer($tenantId, $callerId, $hasPermission, (int) $row['id'], $values, $type);

        // E.27: refuse rather than print a document with a required field missing.
        $missing = $printer->missingRequired($doc['blocks']);
        if ($missing !== []) {
            return Response::error('Required fields have no value for this record', 422, ['missing' => $missing]);
        }

        // A draft previews with the theme as it resolves now; a published
        // version prints with the one frozen when it was published.
        $theme = $version !== null
            ? $this->print->versionTheme($tenantId, $version, (string) $row['kind'])
            : $this->print->theme($tenantId, $row['theme'] ?? null, (string) $row['kind']);
        $payload = $this->print->payload(
            $printer,
            $tenantId,
            ['title' => (string) $doc['title'], 'lang' => (string) $row['lang'], 'page' => $doc['page'], 'blocks' => $doc['blocks']],
            $resolved['label'],
            $values,
            $theme,
        );

        $metered = false;
        if ($this->meters !== null) {
            $decision = $this->meters->consume($tenantId, self::RENDER_METERS);
            if (!$decision->allowed) {
                return Response::error('The document render limit has been reached', 429);
            }
            $metered = true;
        }
        try {
            $pdf = $this->renderer->render($tenantId, $payload)->bytes;
        } catch (DocumentRenderRejectedException $e) {
            $this->refund($tenantId, $metered);

            return Response::error($e->clientMessage, 422);
        } catch (RenderServiceUnavailableException $e) {
            $this->refund($tenantId, $metered);
            error_log('[DocumentPublishingApiHandler] render failed: ' . $e->getMessage());

            return Response::error('Document rendering is temporarily unavailable', 503);
        }

        return new Response(200, $pdf, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . DocumentPresenter::slugify($title) . '.pdf"',
        ]);
    }

    /**
     * POST /api/document-sources/{id}/print-labels: a label sheet's PDF, one
     * label per record or per CSV row (E.43). The body is either
     * `{records: ["type:id", …]}` or `{csv: "<text>", columns: {"CSV column": "field.path"}}`,
     * with `draft: true` to print the draft instead of the published version.
     *
     * The CSV is read here and never stored: a mailing list is personal data,
     * and documents' file store holds only PDFs and images. The shared fields
     * (Company, Today) fill every label; a row's mapped columns fill the rest.
     * A required field with no value on any label refuses the print, naming
     * the labels (E.27). Labels aren't issued as documents; one print is one
     * render against the tenant's limits.
     *
     * @param array<string, string> $params
     */
    public function printLabels(Request $request, array $params): Response
    {
        $found = $this->template($request, $params, false);
        if ($found instanceof Response) {
            return $found;
        }
        [$tenantId, $callerId, $row] = $found;
        if ($row['kind'] !== 'label') {
            return Response::error('Only label sheets print labels', 409);
        }
        if ($this->renderer === null || !$this->renderer->isEnabled($tenantId)) {
            return Response::error('Document rendering is not enabled on this instance', 503);
        }

        $body = JsonBody::parsed($request);
        if (($body['draft'] ?? false) === true) {
            $doc = ['page' => $row['page'], 'blocks' => $row['blocks'], 'own_fields' => $row['own_fields'] ?? []];
            $theme = $this->print->theme($tenantId, $row['theme'] ?? null, (string) $row['kind']);
        } else {
            $published = $row['published_version'] !== null
                ? $this->versions->find((int) $row['id'], $tenantId, (int) $row['published_version'])
                : null;
            if ($published === null) {
                return Response::error('This label sheet has no published version yet', 409);
            }
            $doc = ['page' => $published['page'], 'blocks' => $published['blocks'], 'own_fields' => $published['own_fields']];
            $theme = $this->print->versionTheme($tenantId, $published, (string) $row['kind']);
        }
        $page = is_array($doc['page']) ? $doc['page'] : [];
        $grid = is_array($page['labels'] ?? null) ? $page['labels'] : null;
        if ($grid === null) {
            return Response::error('This label sheet has no label grid; choose the label stock first', 422);
        }

        $hasPermission = $this->permissions($callerId, $tenantId);
        $type = $row['record_type'] !== null ? (string) $row['record_type'] : null;

        // Each label's field values: from its record, or from its CSV row over the shared fields.
        $rows = [];
        if (isset($body['records'])) {
            $refs = $body['records'];
            if (!is_array($refs) || $refs === [] || !array_is_list($refs)) {
                return Response::error('records must be a non-empty list of "type:id"', 422);
            }
            if (count($refs) > self::MAX_LABEL_RECORDS) {
                return Response::error('At most ' . self::MAX_LABEL_RECORDS . ' records can be printed at once', 422);
            }
            foreach ($refs as $i => $ref) {
                $record = self::parseRecord($ref);
                if ($record === null || ($type !== null && $record[0] !== $type)) {
                    return Response::error('records[' . $i . '] must be "' . ($type ?? '<type>') . ':<id>"', 422);
                }
                $resolved = $this->fields->resolve($tenantId, $callerId, $hasPermission, $record[0], $record[1]);
                if ($resolved === null) {
                    return Response::error('Record not found: ' . $ref, 404);
                }
                $rows[] = $resolved['values'];
            }
        } elseif (isset($body['csv'])) {
            $shared = $this->fields->resolve($tenantId, $callerId, $hasPermission, null, null);
            $paths = $this->fields->paths($tenantId, $type) + self::ownPaths($doc['own_fields']);
            $parsed = LabelCsv::rows($body['csv'], $body['columns'] ?? null, $paths, self::MAX_LABEL_ROWS);
            if (is_string($parsed)) {
                return Response::error($parsed, 422);
            }
            foreach ($parsed as $values) {
                $rows[] = $values + ($shared['values'] ?? []);
            }
        } else {
            return Response::error('Send records or a csv with its columns', 422);
        }

        $sourceId = (int) $row['id'];
        $labels = [];
        $missing = [];
        foreach ($rows as $n => $values) {
            $printer = $this->print->printer($tenantId, $callerId, $hasPermission, $sourceId, $values, $type);
            $gaps = $printer->missingRequired($doc['blocks']);
            if ($gaps !== []) {
                $missing[] = ['label' => $n + 1, 'fields' => $gaps];
                continue;
            }
            $labels[] = $printer->labelContent($doc['blocks']);
        }
        if ($missing !== []) {
            return Response::error('Required fields have no value on some labels', 422, ['missing' => array_slice($missing, 0, 50)]);
        }

        $payload = [
            'sheet' => ['preset' => ($page['size'] ?? 'A4') === 'Letter' ? 'letter' : 'a4'],
            'grid' => [
                'columns' => (int) $grid['columns'],
                'rows' => (int) $grid['rows'],
                'widthMm' => (float) $grid['size']['widthMm'],
                'heightMm' => (float) $grid['size']['heightMm'],
                'gapXMm' => (float) $grid['gapMm'],
                'gapYMm' => (float) ($grid['gapYMm'] ?? $grid['gapMm']),
            ],
            'labels' => $labels,
            'direction' => $row['lang'] === 'ar' ? 'rtl' : 'ltr',
            'title' => (string) $row['title'],
        ];
        $accent = $theme['tokens']['accent'] ?? null;
        if (is_string($accent) && $accent !== '') {
            $payload['theme'] = ['accent' => $accent];
        }

        $metered = false;
        if ($this->meters !== null) {
            $decision = $this->meters->consume($tenantId, self::RENDER_METERS);
            if (!$decision->allowed) {
                return Response::error('The document render limit has been reached', 429);
            }
            $metered = true;
        }
        try {
            $rendered = $this->renderer->renderLabels($tenantId, $payload);
        } catch (DocumentRenderRejectedException $e) {
            $this->refund($tenantId, $metered);

            return Response::error($e->clientMessage, 422);
        } catch (RenderServiceUnavailableException $e) {
            $this->refund($tenantId, $metered);
            error_log('[DocumentPublishingApiHandler] label render failed: ' . $e->getMessage());

            return Response::error('Document rendering is temporarily unavailable', 503);
        }

        return new Response(200, $rendered->bytes, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . DocumentPresenter::slugify((string) $row['title']) . '.pdf"',
            'X-Labels-Count' => (string) count($labels),
            'X-Labels-Clipped' => (string) $rendered->labelsClipped,
        ]);
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function blockers(int $tenantId, array $row): array
    {
        $type = $row['record_type'] !== null ? (string) $row['record_type'] : null;
        $blockers = DocumentPublishCheck::blockers(
            is_array($row['blocks'] ?? null) ? $row['blocks'] : [],
            $this->fields->paths($tenantId, $type) + self::ownPaths($row['own_fields'] ?? []),
        );

        // A theme the document names that the workspace doesn't have (deleted,
        // or a plugin's that is off) would publish in another look than the
        // one the author chose, so it blocks like an unknown field (E.44).
        $ref = is_array($row['theme'] ?? null) ? ($row['theme']['ref'] ?? null) : null;
        if (is_string($ref) && $ref !== '') {
            $theme = $this->print->theme($tenantId, $row['theme'], (string) $row['kind']);
            if ($theme['ref'] !== $ref) {
                $blockers[] = ['kind' => 'theme-unavailable', 'ref' => $ref];
            }
        }

        return $blockers;
    }

    /**
     * A template's own fields as paths (`template.<key>`). They have no value
     * unless a print fills them (a CSV column); with a record they print blank.
     *
     * @return array<string, true>
     */
    private static function ownPaths(mixed $ownFields): array
    {
        $paths = [];
        foreach (is_array($ownFields) ? $ownFields : [] as $field) {
            if (is_array($field) && is_string($field['key'] ?? null)) {
                $paths['template.' . $field['key']] = true;
            }
        }

        return $paths;
    }

    /**
     * The visible template, or an early error.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function template(Request $request, array $params, bool $templatesOnly = true): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;
        $row = $this->sources->visibleRow((int) ($params['id'] ?? 0), $tenantId, $callerId);
        if ($row === null) {
            return Response::error('Document not found', 404);
        }
        // E.9: only templates have a published version; notes and wiki pages save live.
        if ($templatesOnly && !in_array($row['kind'], ['template', 'letter', 'report', 'label'], true)) {
            return Response::error('Only documents that print are published', 409);
        }

        return [$tenantId, $callerId, $row];
    }

    /** @return array{0: string, 1: string}|null */
    private static function parseRecord(mixed $value): ?array
    {
        if (!is_string($value) || preg_match('/^([a-z0-9._-]{1,64}):([A-Za-z0-9._-]{1,64})$/', $value, $m) !== 1) {
            return null;
        }

        return [$m[1], $m[2]];
    }

    /** @return array<int|string, mixed> */
    private static function query(Request $request): array
    {
        $query = parse_url($request->getPath(), PHP_URL_QUERY);
        $params = [];
        if (is_string($query) && $query !== '') {
            parse_str($query, $params);
        }

        return $params;
    }

    private function refund(int $tenantId, bool $metered): void
    {
        if ($metered && $this->meters !== null) {
            $this->meters->refund($tenantId, self::RENDER_METERS);
        }
    }

    /** @return array{0: int, 1: int}|Response */
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

    /** @return callable(string, int|null=): bool */
    private function permissions(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }
}
