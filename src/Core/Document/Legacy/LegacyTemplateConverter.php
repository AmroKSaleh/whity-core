<?php

declare(strict_types=1);

namespace Whity\Core\Document\Legacy;

/**
 * Turns one of the old Document designer's templates (DocTemplate v1/v2,
 * `document_templates.data`) into an editor document (editor M4b; the owner's
 * answer to Q1: the editor replaces both modes).
 *
 * Canvas templates place elements at fixed millimetre positions, and blocks
 * flow, so a canvas becomes blocks in READING ORDER: page by page, top to
 * bottom, start to end. What has a block becomes one (text, fields, images,
 * barcodes, QR codes, maths); a horizontal line becomes a divider. What has
 * none (rectangles, vertical lines, saved block instances) is left out and
 * counted, and the document starts with a callout naming it, for the author
 * to check and delete. A template printed on label stock, or on a page the
 * size of a label, becomes a label sheet (E.43). Flow templates map block for
 * block.
 *
 * Placeholders (`{{ship_to}}`) become the template's own fields
 * (`template.ship_to`), with their labels and samples.
 *
 * Pure: no database, no clock. Ids are derived from a counter so the same
 * input converts to the same output.
 */
final class LegacyTemplateConverter
{
    private const PLACEHOLDER = '/\{\{\s*([A-Za-z0-9_.\-]{1,64})\s*\}\}/';

    /** The old designer's verification-code keys; the editor's QR (6.7) is automatic. */
    private const RESERVED_KEYS = ['qr_url', 'qr_code', 'verify_url', 'document_id'];

    private const SHEETS = ['A4' => [210.0, 297.0], 'Letter' => [215.9, 279.4], 'A5' => [148.0, 210.0]];

    private int $ids = 0;

    /** @var array<string, array{key: string, label: string, sample?: string}> placeholder key => own field */
    private array $own = [];

    /** @var array<string, int> what was left out, by kind */
    private array $dropped = [];

    public function __construct(private readonly ConversionLabels $labels = new ConversionLabels())
    {
    }

    /**
     * @param array<string, mixed> $data The old template's `data`.
     * @return array{kind: string, title: string, lang: string, page: array<string, mixed>, blocks: list<array<string, mixed>>,
     *               own_fields: list<array{key: string, label: string, sample?: string}>, dropped: array<string, int>}
     */
    public function convert(string $name, array $data): array
    {
        $this->ids = 0;
        $this->own = [];
        $this->dropped = [];

        foreach (is_array($data['placeholders'] ?? null) ? $data['placeholders'] : [] as $p) {
            if (is_array($p) && is_string($p['key'] ?? null)) {
                $this->ownField($p['key'], is_string($p['label'] ?? null) ? $p['label'] : null, is_string($p['sample'] ?? null) ? $p['sample'] : null);
            }
        }

        $pageSpec = is_array($data['page'] ?? null) ? $data['page'] : [];
        $widthMm = (float) ($pageSpec['widthMm'] ?? 210);
        $heightMm = (float) ($pageSpec['heightMm'] ?? 297);
        $sheet = is_array($data['sheet'] ?? null) ? $data['sheet'] : null;

        $blocks = ($data['mode'] ?? 'canvas') === 'flow'
            ? $this->flowBlocks(is_array($data['flow'] ?? null) ? $data['flow'] : [])
            : $this->canvasBlocks(self::pages($data));

        if (($data['qr']['enabled'] ?? false) === true) {
            $this->dropped['qr'] = 1;
        }

        $lang = self::hasArabic($name . ' ' . json_encode($blocks, JSON_UNESCAPED_UNICODE)) ? 'ar' : 'en';

        if ($sheet !== null && ($sheet['enabled'] ?? false) === true) {
            [$kind, $page] = ['label', self::labelPage($sheet, $widthMm, $heightMm)];
        } elseif ($widthMm < 150 || $heightMm < 150) {
            // A page the size of a label (a shipping label, a badge) prints one per sheet.
            [$kind, $page] = ['label', [
                'size' => 'A4',
                'marginMm' => 16,
                'letterhead' => false,
                'labels' => ['columns' => 1, 'rows' => 1, 'size' => ['widthMm' => round($widthMm, 1), 'heightMm' => round($heightMm, 1)], 'gapMm' => 0],
            ]];
        } else {
            [$kind, $page] = ['template', ['size' => self::nearestSheet($widthMm, $heightMm), 'marginMm' => 16, 'letterhead' => false]];
        }

        if ($this->dropped !== []) {
            array_unshift($blocks, [
                'id' => $this->id(),
                'type' => 'callout',
                'kind' => 'important',
                'content' => [['type' => 'text', 'text' => $this->labels->note($this->dropped, $lang)]],
            ]);
        }
        if ($blocks === []) {
            $blocks[] = ['id' => $this->id(), 'type' => 'paragraph', 'content' => []];
        }

        return [
            'kind' => $kind,
            'title' => mb_substr(trim($name), 0, 255),
            'lang' => $lang,
            'page' => $page,
            'blocks' => $blocks,
            'own_fields' => array_values($this->own),
            'dropped' => $this->dropped,
        ];
    }

    // ── canvas ────────────────────────────────────────────────────────────────

    /**
     * Every page's elements (v2 `pages`, or v1's single `elements` list).
     *
     * @param array<string, mixed> $data
     * @return list<list<array<string, mixed>>>
     */
    private static function pages(array $data): array
    {
        if (is_array($data['pages'] ?? null)) {
            $pages = [];
            foreach ($data['pages'] as $page) {
                $pages[] = is_array($page['elements'] ?? null) ? array_values(array_filter($page['elements'], 'is_array')) : [];
            }

            return $pages;
        }

        return [is_array($data['elements'] ?? null) ? array_values(array_filter($data['elements'], 'is_array')) : []];
    }

    /**
     * @param list<list<array<string, mixed>>> $pages
     * @return list<array<string, mixed>>
     */
    private function canvasBlocks(array $pages): array
    {
        $blocks = [];
        foreach ($pages as $n => $elements) {
            if ($n > 0 && $blocks !== []) {
                $blocks[] = ['id' => $this->id(), 'type' => 'pageBreak'];
            }
            $visible = array_values(array_filter($elements, static fn (array $e): bool => ($e['hidden'] ?? false) !== true));
            // Reading order: rows top to bottom (within 2 mm is one row), then start to end.
            usort($visible, static function (array $a, array $b): int {
                $dy = (float) ($a['y'] ?? 0) - (float) ($b['y'] ?? 0);
                if (abs($dy) > 2.0) {
                    return $dy <=> 0.0;
                }

                return (float) ($a['x'] ?? 0) <=> (float) ($b['x'] ?? 0);
            });
            foreach ($visible as $element) {
                $block = $this->element($element);
                if ($block !== null) {
                    $blocks[] = $block;
                }
            }
        }

        return $blocks;
    }

    /**
     * @param array<string, mixed> $e
     * @return array<string, mixed>|null
     */
    private function element(array $e): ?array
    {
        switch ($e['type'] ?? null) {
            case 'text':
            case 'dynamicText':
                $text = (string) ($e['type'] === 'text' ? ($e['text'] ?? '') : ($e['template'] ?? ''));
                $content = is_array($e['runs'] ?? null) && $e['runs'] !== [] ? $this->runs($e['runs']) : $this->inline($text);
                if ($content === []) {
                    return null;
                }
                $style = is_array($e['style'] ?? null) ? $e['style'] : [];
                $size = (float) ($style['fontSize'] ?? 12);
                $bold = ($style['fontWeight'] ?? 'normal') === 'bold';
                $level = $size >= 20 ? 1 : ($size >= 16 ? 2 : ($bold && $size >= 13 ? 3 : 0));

                return $level > 0
                    ? ['id' => $this->id(), 'type' => 'heading', 'level' => $level, 'content' => self::unmarked($content)]
                    : ['id' => $this->id(), 'type' => 'paragraph', 'content' => $bold ? self::bolded($content) : $content];

            case 'image':
                $src = (string) ($e['src'] ?? '');
                if (($e['binding'] ?? null) === 'logo_url' || $src === '' || !str_starts_with($src, 'data:image/')) {
                    // The letterhead carries the logo; a remote or bound image has no file here.
                    $this->drop('image');

                    return null;
                }
                $width = (float) ($e['w'] ?? 0);

                return ['id' => $this->id(), 'type' => 'image', 'src' => $src, 'alt' => '', 'width' => $width >= 120 ? 'full' : ($width >= 70 ? 'wide' : 'half')];

            case 'barcode':
                $symbology = in_array($e['symbology'] ?? null, ['code128', 'ean13', 'code39'], true) ? (string) $e['symbology'] : 'code128';
                if ($symbology !== ($e['symbology'] ?? null)) {
                    $this->drop('symbology');
                }

                return [
                    'id' => $this->id(),
                    'type' => 'barcode',
                    'symbology' => $symbology,
                    'value' => $this->valueOf($e),
                    'showText' => ($e['showText'] ?? true) !== false,
                    'widthMm' => round((float) ($e['w'] ?? 60), 1),
                    'heightMm' => round((float) ($e['h'] ?? 20), 1),
                ];

            case 'qr':
                return ['id' => $this->id(), 'type' => 'qr', 'value' => $this->valueOf($e), 'sizeMm' => round(min((float) ($e['w'] ?? 25), (float) ($e['h'] ?? 25)), 1)];

            case 'math':
                $tex = trim((string) ($e['expression'] ?? ''));

                return $tex === '' ? null : ['id' => $this->id(), 'type' => 'math', 'tex' => $tex];

            case 'line':
                if ((float) ($e['h'] ?? 0) <= 2.0) {
                    return ['id' => $this->id(), 'type' => 'divider'];
                }
                $this->drop('shape');

                return null;

            case 'rect':
                $this->drop('shape');

                return null;

            case 'blockInstance':
                $this->drop('savedBlock');

                return null;

            default:
                $this->drop('other');

                return null;
        }
    }

    /**
     * A barcode's or QR code's value: its text with fields, or its binding as a field.
     *
     * @param array<string, mixed> $e
     * @return list<array<string, mixed>>
     */
    private function valueOf(array $e): array
    {
        if (is_string($e['binding'] ?? null) && $e['binding'] !== '') {
            return [$this->fieldRef($e['binding'])];
        }
        $value = array_values(array_filter($this->inline((string) ($e['value'] ?? '')), static fn (array $i): bool => $i['type'] !== 'break'));

        return $value !== [] ? $value : [['type' => 'text', 'text' => '0']];
    }

    // ── flow ──────────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $flow
     * @return list<array<string, mixed>>
     */
    private function flowBlocks(array $flow): array
    {
        if (isset($flow['header']) || isset($flow['footer'])) {
            // The page view has its own running header and footer (E.7).
            $this->drop('running');
        }
        $blocks = [];
        foreach (is_array($flow['blocks'] ?? null) ? $flow['blocks'] : [] as $b) {
            if (!is_array($b)) {
                continue;
            }
            $content = is_array($b['runs'] ?? null) && $b['runs'] !== [] ? $this->runs($b['runs']) : $this->inline((string) ($b['text'] ?? ''));
            switch ($b['type'] ?? null) {
                case 'heading':
                    $blocks[] = ['id' => $this->id(), 'type' => 'heading', 'level' => max(1, min(3, (int) ($b['level'] ?? 1))), 'content' => self::unmarked($content)];
                    break;
                case 'paragraph':
                    $blocks[] = ['id' => $this->id(), 'type' => 'paragraph', 'content' => $content];
                    break;
                case 'table':
                    $columns = is_array($b['columns'] ?? null) ? array_values($b['columns']) : [];
                    $rows = [];
                    if ($columns !== []) {
                        $rows[] = array_map(fn (mixed $c): array => $this->inline(is_array($c) ? (string) ($c['label'] ?? '') : (string) $c), $columns);
                    }
                    foreach (is_array($b['rows'] ?? null) ? $b['rows'] : [] as $row) {
                        $rows[] = array_map(fn (mixed $cell): array => $this->inline((string) (is_scalar($cell) ? $cell : '')), is_array($row) ? array_values($row) : []);
                    }
                    if ($rows === []) {
                        break;
                    }
                    $width = max(array_map('count', $rows));
                    $rows = array_map(static fn (array $r): array => array_pad($r, $width, []), $rows);
                    $blocks[] = [
                        'id' => $this->id(),
                        'type' => 'table',
                        'headerRow' => $columns !== [],
                        'columns' => array_fill(0, $width, ['align' => 'start']),
                        'rows' => $rows,
                    ];
                    break;
                case 'figure':
                    $src = (string) ($b['src'] ?? '');
                    if (str_starts_with($src, 'data:image/')) {
                        $figure = ['id' => $this->id(), 'type' => 'image', 'src' => $src, 'alt' => (string) ($b['alt'] ?? ''), 'width' => 'wide'];
                        if (is_string($b['caption'] ?? null) && $b['caption'] !== '') {
                            $figure['caption'] = $this->inline($b['caption']);
                        }
                        $blocks[] = $figure;
                    } else {
                        $this->drop('image');
                    }
                    break;
                case 'pageBreak':
                    $blocks[] = ['id' => $this->id(), 'type' => 'pageBreak'];
                    break;
                case 'spacer':
                    break;
                default:
                    $this->drop('other');
            }
        }

        return $blocks;
    }

    // ── inline content ────────────────────────────────────────────────────────

    /**
     * Text with `{{key}}` placeholders as text and fields; line breaks as breaks.
     *
     * @param list<array<string, mixed>> $marks
     * @return list<array<string, mixed>>
     */
    private function inline(string $text, array $marks = []): array
    {
        $out = [];
        $lines = preg_split('/\r\n|\r|\n/', $text) ?: [];
        foreach ($lines as $i => $line) {
            if ($i > 0) {
                $out[] = ['type' => 'break'];
            }
            $parts = preg_split(self::PLACEHOLDER, $line, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [];
            foreach ($parts as $j => $part) {
                if ($j % 2 === 1) {
                    if (!in_array(strtolower($part), self::RESERVED_KEYS, true)) {
                        $out[] = $this->fieldRef($part) + ($marks !== [] ? ['marks' => $marks] : []);
                    }
                } elseif ($part !== '') {
                    $out[] = ['type' => 'text', 'text' => $part] + ($marks !== [] ? ['marks' => $marks] : []);
                }
            }
        }

        return $out;
    }

    /**
     * Rich text runs (bold, italic, underline, links).
     *
     * @param array<mixed> $runs
     * @return list<array<string, mixed>>
     */
    private function runs(array $runs): array
    {
        $out = [];
        foreach ($runs as $run) {
            if (!is_array($run) || !is_string($run['text'] ?? null)) {
                continue;
            }
            $marks = [];
            foreach (['bold', 'italic', 'underline'] as $mark) {
                if (($run[$mark] ?? false) === true) {
                    $marks[] = ['type' => $mark];
                }
            }
            if (is_string($run['href'] ?? null) && preg_match('#^(https?:|mailto:)#i', $run['href']) === 1) {
                $marks[] = ['type' => 'link', 'href' => $run['href']];
            }
            array_push($out, ...$this->inline($run['text'], $marks));
        }

        return $out;
    }

    /** @return array<string, mixed> */
    private function fieldRef(string $placeholder): array
    {
        $field = $this->ownField($placeholder, null, null);

        return ['type' => 'field', 'path' => 'template.' . $field['key'], 'label' => $field['label']];
    }

    /** @return array{key: string, label: string, sample?: string} */
    private function ownField(string $placeholder, ?string $label, ?string $sample): array
    {
        if (isset($this->own[$placeholder])) {
            return $this->own[$placeholder];
        }
        $key = substr(trim((string) preg_replace('/[^a-z0-9]+/', '_', strtolower($placeholder)), '_'), 0, 40);
        $key = $key === '' ? 'field' : $key;
        $taken = array_column($this->own, 'key');
        for ($n = 2, $base = $key; in_array($key, $taken, true); $n++) {
            $key = substr($base, 0, 36) . '_' . $n;
        }
        $label = trim((string) $label) !== '' ? mb_substr(trim((string) $label), 0, 80) : ucfirst(str_replace(['_', '.', '-'], ' ', $placeholder));
        $field = ['key' => $key, 'label' => $label];
        if ($sample !== null && $sample !== '') {
            $field['sample'] = mb_substr($sample, 0, 200);
        }

        return $this->own[$placeholder] = $field;
    }

    /**
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private static function bolded(array $content): array
    {
        return array_map(static function (array $i): array {
            if ($i['type'] === 'break') {
                return $i;
            }
            $marks = array_values(array_filter($i['marks'] ?? [], static fn (array $m): bool => $m['type'] !== 'bold'));
            $i['marks'] = [['type' => 'bold'], ...$marks];

            return $i;
        }, $content);
    }

    /**
     * A heading is its own weight; it carries no bold mark.
     *
     * @param list<array<string, mixed>> $content
     * @return list<array<string, mixed>>
     */
    private static function unmarked(array $content): array
    {
        return array_map(static function (array $i): array {
            if (isset($i['marks'])) {
                $i['marks'] = array_values(array_filter($i['marks'], static fn (array $m): bool => $m['type'] !== 'bold'));
                if ($i['marks'] === []) {
                    unset($i['marks']);
                }
            }

            return $i;
        }, $content);
    }

    // ── page ──────────────────────────────────────────────────────────────────

    /**
     * A label sheet from the old designer's sheet setup. The template's page
     * was the label, so it is the cell; the new grid is centred, so the old
     * margins are not kept.
     *
     * @param array<string, mixed> $sheet
     * @return array<string, mixed>
     */
    private static function labelPage(array $sheet, float $labelW, float $labelH): array
    {
        $cols = max(1, min(10, (int) ($sheet['cols'] ?? 1)));
        $rows = max(1, min(30, (int) ($sheet['rows'] ?? 1)));
        $w = (float) ($sheet['sheetWidthMm'] ?? 210);
        $h = (float) ($sheet['sheetHeightMm'] ?? 297);
        $gx = max(0.0, (float) ($sheet['gutterXMm'] ?? 0));
        $gy = max(0.0, (float) ($sheet['gutterYMm'] ?? 0));
        // Never wider than the sheet allows, so the grid always fits.
        $cellW = min($labelW, ($w - ($cols - 1) * $gx) / $cols);
        $cellH = min($labelH, ($h - ($rows - 1) * $gy) / $rows);

        return [
            'size' => self::nearestSheet($w, $h) === 'Letter' ? 'Letter' : 'A4',
            'marginMm' => 16,
            'letterhead' => false,
            'labels' => [
                'columns' => $cols,
                'rows' => $rows,
                'size' => ['widthMm' => round(max(10.0, $cellW), 1), 'heightMm' => round(max(10.0, $cellH), 1)],
                'gapMm' => round($gx, 1),
                'gapYMm' => round($gy, 1),
            ],
        ];
    }

    private static function nearestSheet(float $w, float $h): string
    {
        $best = 'A4';
        $distance = INF;
        foreach (self::SHEETS as $name => [$sw, $sh]) {
            $d = abs(min($w, $h) - $sw) + abs(max($w, $h) - $sh);
            if ($d < $distance) {
                [$best, $distance] = [$name, $d];
            }
        }

        return $best;
    }

    private static function hasArabic(string $text): bool
    {
        return preg_match('/\p{Arabic}/u', $text) === 1;
    }

    private function drop(string $kind): void
    {
        $this->dropped[$kind] = ($this->dropped[$kind] ?? 0) + 1;
    }

    private function id(): string
    {
        return 'c' . (++$this->ids);
    }
}
