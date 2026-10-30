<?php

declare(strict_types=1);

namespace Whity\Core\Document\Print;

/**
 * A published editor document as Whity Render's flowing payload
 * (`POST /render/flow`), with every field filled and every live block frozen
 * at this moment (E.16, IMPLEMENTATION-NOTES 3.7): no comments, no
 * suggestions, no field nodes, only values.
 *
 * The page rules are the page view's (E.7, stage 6): A4 at 16mm, the
 * letterhead on page 1, a running header from page 2, and "Page x of y" with
 * the reference in the footer. The title is the tenant's print colour.
 *
 * Every block the editor offers has a print view here; anything else (a
 * plugin's block, M7) is skipped, and the publish check refuses a template
 * that has one (E.18, E.37), so nothing printable is lost silently.
 */
final class WhityDocumentPrinter
{
    /** The block types with a print view (E.18). */
    public const PRINTABLE = [
        'paragraph', 'heading', 'quote', 'code', 'bullet', 'number', 'checklist', 'callout', 'toggle', 'columns',
        'divider', 'pageBreak', 'table', 'image', 'file', 'record', 'chart', 'signature', 'barcode', 'qr', 'math',
    ];

    private const MARGIN_MM = 16.0;

    /**
     * @param array<string, string|null> $values Field values by path; null is a missing value.
     * @param array<string, true> $required Paths the record type marks required (E.27).
     * @param callable(array{type: string, id: string}): ?array<string, mixed> $resolveRecord A Record block's view, or null.
     * @param callable(string): ?array<string, mixed> $loadChart A Chart block's data by source key, or null.
     * @param callable(string): ?string $imageData A file id's bytes as a data: URI, or null.
     */
    public function __construct(
        private readonly array $values,
        private readonly array $required,
        private readonly mixed $resolveRecord,
        private readonly mixed $loadChart,
        private readonly mixed $imageData,
        private readonly PrintLabels $labels,
    ) {
    }

    /**
     * Required fields this document uses that have no value (E.27): issuing is
     * refused while any is missing. Fiscal fields are required by their provider.
     *
     * @param list<mixed> $blocks
     * @return list<string>
     */
    public function missingRequired(array $blocks): array
    {
        $missing = [];
        foreach (self::fieldRefs($blocks) as $ref) {
            $path = (string) ($ref['path'] ?? '');
            $isRequired = ($ref['required'] ?? false) === true || isset($this->required[$path]);
            $value = $this->values[$path] ?? null;
            if ($isRequired && ($value === null || $value === '')) {
                $missing[$path] = true;
            }
        }

        return array_keys($missing);
    }

    /**
     * @param array{title: string, lang: string, blocks: list<mixed>, page?: array<string, mixed>|null} $doc
     * @param array{name: string, lines: list<string>, logo?: string|null}|null $letterhead
     * @param string|null $reference The reference field's value, beside "Page x of y".
     * @param string|null $date The date line under the reference.
     * @return array<string, mixed>
     */
    public function payload(array $doc, ?array $letterhead, ?string $reference, ?string $date, ?string $accent): array
    {
        $title = $doc['title'];
        $content = [];
        if ($letterhead !== null && (($doc['page']['letterhead'] ?? true) !== false)) {
            $start = ['name' => $letterhead['name'], 'lines' => array_slice($letterhead['lines'], 0, 6)];
            if (($letterhead['logo'] ?? null) !== null) {
                $start['image'] = ['src' => $letterhead['logo'], 'alt' => $letterhead['name'], 'heightMm' => 8.5];
            }
            $endLines = [];
            if ($reference !== null && $reference !== '') {
                $endLines[] = $this->labels->reference($reference);
            }
            if ($date !== null && $date !== '') {
                $endLines[] = $date;
            }
            $content[] = ['type' => 'letterhead', 'start' => $start, 'end' => ['title' => $title, 'lines' => $endLines]];
        } else {
            $content[] = ['type' => 'heading', 'level' => 1, 'text' => $title, 'unnumbered' => true, 'inContents' => false];
        }
        $this->blocks($doc['blocks'], $content);

        $pageOf = $this->labels->pageOf();
        $payload = [
            'title' => $title,
            'direction' => $doc['lang'] === 'ar' ? 'rtl' : 'ltr',
            'page' => [
                'widthMm' => 210.0,
                'heightMm' => 297.0,
                'margin' => ['topMm' => self::MARGIN_MM, 'rightMm' => self::MARGIN_MM, 'bottomMm' => self::MARGIN_MM, 'leftMm' => self::MARGIN_MM],
            ],
            'content' => $content,
            'header' => ['start' => $letterhead['name'] ?? '', 'end' => $this->labels->continued($title), 'fromPage' => 2],
            'footer' => [
                'start' => $reference !== null && $reference !== '' ? $pageOf . ' · ' . $reference : $pageOf,
                'end' => $letterhead['name'] ?? '',
            ],
        ];
        if ($accent !== null && preg_match('/^#[0-9A-Fa-f]{6}$/', $accent) === 1) {
            $payload['theme'] = ['accent' => $accent];
        }

        return $payload;
    }

    /**
     * One label of a label sheet (E.43): the document's blocks filled with this
     * printer's values, without what a label can't hold (page breaks). The
     * sheet itself, the grid and the other labels are the caller's.
     *
     * @param list<mixed> $blocks
     * @return list<array<string, mixed>>
     */
    public function labelContent(array $blocks): array
    {
        $content = [];
        $this->blocks($blocks, $content);

        return array_values(array_filter($content, static fn (array $b): bool => !in_array($b['type'] ?? '', ['pageBreak', 'letterhead', 'question'], true)));
    }

    /**
     * @param list<mixed> $blocks
     * @param list<array<string, mixed>> $out
     */
    private function blocks(array $blocks, array &$out): void
    {
        // Consecutive list items print as one list; a change of kind starts another.
        $ordered = null;
        $items = [];
        foreach ($blocks as $block) {
            if (!is_array($block)) {
                continue;
            }
            $type = (string) ($block['type'] ?? '');
            if ($type === 'checklist') {
                // Its box is its marker: a line of its own, never a bullet beside a box.
                $text = $this->text($block['content'] ?? []);
                if ($text !== '') {
                    if ($ordered !== null) {
                        self::list($items, $ordered, $out);
                        $items = [];
                        $ordered = null;
                    }
                    $out[] = ['type' => 'paragraph', 'text' => ((($block['checked'] ?? false) === true) ? '☑ ' : '☐ ') . $text];
                }
                continue;
            }
            if (in_array($type, ['bullet', 'number'], true)) {
                if ($ordered !== null && $ordered !== ($type === 'number')) {
                    self::list($items, $ordered, $out);
                    $items = [];
                }
                $ordered = $type === 'number';
                $indent = str_repeat('  ', max(0, min(2, (int) ($block['level'] ?? 0))));
                $text = $this->text($block['content'] ?? []);
                if ($text !== '') {
                    $items[] = $indent . $text;
                }
                continue;
            }
            if ($ordered !== null) {
                self::list($items, $ordered, $out);
                $items = [];
                $ordered = null;
            }
            $this->block($type, $block, $out);
        }
        if ($ordered !== null) {
            self::list($items, $ordered, $out);
        }
    }

    /**
     * @param list<string> $items
     * @param list<array<string, mixed>> $out
     */
    private static function list(array $items, bool $ordered, array &$out): void
    {
        foreach (array_chunk($items, 30) as $chunk) {
            $out[] = ['type' => 'list', 'ordered' => $ordered, 'tone' => 'plain', 'items' => $chunk];
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $out
     */
    private function block(string $type, array $block, array &$out): void
    {
        switch ($type) {
            case 'paragraph':
            case 'quote':
            case 'callout':
                $p = $this->paragraph($block['content'] ?? [], $type === 'quote');
                if ($p !== null) {
                    $out[] = $p;
                }
                return;
            case 'code':
                $text = trim((string) ($block['text'] ?? ''));
                if ($text !== '') {
                    $out[] = ['type' => 'paragraph', 'text' => $text];
                }
                return;
            case 'heading':
                $text = $this->text($block['content'] ?? []);
                if ($text !== '') {
                    // The editor's H1-H3 sit under the document title, which is the page's own heading.
                    $level = max(1, min(3, (int) ($block['level'] ?? 1))) + 1;
                    $out[] = ['type' => 'heading', 'level' => $level, 'text' => $text, 'unnumbered' => true, 'inContents' => false];
                }
                return;
            case 'toggle':
                $p = $this->paragraph($block['summary'] ?? [], false, true);
                if ($p !== null) {
                    $out[] = $p;
                }
                $this->blocks(is_array($block['children'] ?? null) ? $block['children'] : [], $out);
                return;
            case 'columns':
                foreach ((is_array($block['columns'] ?? null) ? $block['columns'] : []) as $column) {
                    $this->blocks(is_array($column) ? $column : [], $out);
                }
                return;
            case 'divider':
                $out[] = ['type' => 'divider'];
                return;
            case 'pageBreak':
                $out[] = ['type' => 'pageBreak'];
                return;
            case 'table':
                $this->table($block, $out);
                return;
            case 'image':
                $src = $this->imageSrc((string) ($block['src'] ?? ''));
                if ($src !== null) {
                    $figure = ['type' => 'figure', 'src' => $src, 'alt' => (string) ($block['alt'] ?? '')];
                    $caption = $this->text($block['caption'] ?? []);
                    if ($caption !== '') {
                        $figure['caption'] = $caption;
                    }
                    $out[] = $figure;
                }
                return;
            case 'file':
                $name = trim((string) ($block['name'] ?? ''));
                if ($name !== '') {
                    $out[] = ['type' => 'paragraph', 'text' => $this->labels->attachment($name)];
                }
                return;
            case 'record':
                $this->record($block, $out);
                return;
            case 'chart':
                $this->chart($block, $out);
                return;
            case 'signature':
                $this->signature($block, $out);
                return;
            case 'barcode':
                $value = $this->text($block['value'] ?? []);
                if ($value !== '') {
                    $symbology = in_array($block['symbology'] ?? '', ['code128', 'ean13', 'code39'], true) ? $block['symbology'] : 'code128';
                    $out[] = ['type' => 'barcode', 'symbology' => $symbology, 'value' => mb_substr($value, 0, 80), 'showText' => ($block['showText'] ?? true) !== false];
                }
                return;
            case 'qr':
                $value = $this->text($block['value'] ?? []);
                if ($value !== '') {
                    $qr = ['type' => 'qr', 'value' => mb_substr($value, 0, 512)];
                    $caption = $this->text($block['caption'] ?? []);
                    if ($caption !== '') {
                        $qr['reference'] = $caption;
                    }
                    $out[] = $qr;
                }
                return;
            case 'math':
                $tex = trim((string) ($block['tex'] ?? ''));
                if ($tex !== '') {
                    $out[] = ['type' => 'math', 'tex' => mb_substr($tex, 0, 2000)];
                }
                return;
            default:
                // No print view: refused at publish (E.18), so never silently dropped from a published template.
                return;
        }
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $out
     */
    private function table(array $block, array &$out): void
    {
        $rows = [];
        foreach ((is_array($block['rows'] ?? null) ? $block['rows'] : []) as $row) {
            $cells = [];
            foreach (is_array($row) ? $row : [] as $cell) {
                $cells[] = $this->text(is_array($cell) ? $cell : []);
            }
            $rows[] = $cells;
        }
        if ($rows === []) {
            return;
        }
        $columns = [];
        if (($block['headerRow'] ?? false) === true) {
            $columns = array_shift($rows);
        }
        $out[] = ['type' => 'table', 'columns' => $columns, 'rows' => $rows];
    }

    /**
     * E.16: the record as the person printing may see it, at this moment.
     *
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $out
     */
    private function record(array $block, array &$out): void
    {
        $ref = $block['ref'] ?? null;
        if (!is_array($ref) || !is_string($ref['type'] ?? null) || !is_string($ref['id'] ?? null)) {
            return;
        }
        $view = ($this->resolveRecord)(['type' => $ref['type'], 'id' => $ref['id']]);
        if ($view === null) {
            $out[] = ['type' => 'paragraph', 'text' => $this->labels->recordHidden()];
            return;
        }
        $items = [['label' => (string) ($view['type_label'] ?? ''), 'value' => (string) ($view['title'] ?? '')]];
        foreach (['amount', 'subtitle'] as $key) {
            if (is_string($view[$key] ?? null) && $view[$key] !== '') {
                $items[] = ['label' => $this->labels->recordFact($key), 'value' => $view[$key]];
            }
        }
        if (is_array($view['status'] ?? null) && is_string($view['status']['label'] ?? null)) {
            $items[] = ['label' => $this->labels->recordFact('status'), 'value' => $view['status']['label']];
        }
        $out[] = ['type' => 'facts', 'items' => array_slice($items, 0, 6)];
    }

    /**
     * E.16: a chart prints as its values at this moment, a table any reader of
     * the paper can check.
     *
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $out
     */
    private function chart(array $block, array &$out): void
    {
        $source = $block['source'] ?? null;
        $key = is_array($source) && is_string($source['query'] ?? null) ? $source['query'] : '';
        $data = $key !== '' ? ($this->loadChart)($key) : null;
        if (!is_array($data) || !is_array($data['points'] ?? null) || $data['points'] === []) {
            return;
        }
        $rows = [];
        foreach ($data['points'] as $point) {
            if (is_array($point)) {
                $rows[] = [(string) ($point['label'] ?? ''), (string) ($point['value'] ?? '')];
            }
        }
        $table = ['type' => 'table', 'columns' => ['', (string) ($data['value_label'] ?? '')], 'rows' => $rows];
        $caption = trim((string) ($block['title'] ?? ''));
        if ($caption !== '') {
            $table['caption'] = $caption;
        }
        $out[] = $table;
    }

    /**
     * @param array<string, mixed> $block
     * @param list<array<string, mixed>> $out
     */
    private function signature(array $block, array &$out): void
    {
        $fields = [];
        foreach ((is_array($block['signers'] ?? null) ? $block['signers'] : []) as $signer) {
            if (!is_array($signer)) {
                continue;
            }
            $name = $this->text($signer['name'] ?? []);
            $role = $this->text($signer['role'] ?? []);
            $label = trim($name . ($role !== '' ? ' · ' . $role : ''));
            $fields[] = ['label' => $label !== '' ? $label : $this->labels->signature(), 'kind' => 'line', 'span' => 'half'];
        }
        if ($fields === []) {
            $fields[] = ['label' => $this->labels->signature(), 'kind' => 'line'];
        }
        $date = $this->text($block['date'] ?? []);
        if ($date !== '') {
            $fields[] = ['label' => $this->labels->date() . ': ' . $date, 'kind' => 'line', 'span' => 'half'];
        }
        $out[] = ['type' => 'fields', 'fields' => array_slice($fields, 0, 24)];
    }

    /**
     * @param list<mixed>|mixed $content
     * @return array<string, mixed>|null
     */
    private function paragraph(mixed $content, bool $italic = false, bool $bold = false): ?array
    {
        $runs = [];
        foreach (is_array($content) ? $content : [] as $run) {
            if (!is_array($run)) {
                continue;
            }
            // A hard break is a line break on paper too: an address, a recipient's lines.
            if (($run['type'] ?? '') === 'break') {
                $runs[] = ['text' => "\n", 'break' => true];
                continue;
            }
            $text = $this->runText($run);
            if ($text === '') {
                continue;
            }
            $r = ['text' => $text];
            // A field can be bold or italic like the text around it.
            $marks = in_array($run['type'] ?? '', ['text', 'field'], true) && is_array($run['marks'] ?? null) ? $run['marks'] : [];
            $types = array_map(static fn ($m) => is_array($m) ? ($m['type'] ?? '') : '', $marks);
            if ($bold || in_array('bold', $types, true)) {
                $r['bold'] = true;
            }
            if ($italic || in_array('italic', $types, true)) {
                $r['italic'] = true;
            }
            foreach ($marks as $mark) {
                if (is_array($mark) && ($mark['type'] ?? '') === 'link' && is_string($mark['href'] ?? null)
                    && preg_match('#^(https?://|mailto:)#i', $mark['href']) === 1) {
                    $r['href'] = $mark['href'];
                }
            }
            $runs[] = $r;
        }
        if ($runs === []) {
            return null;
        }
        $text = implode('', array_map(static fn (array $r) => $r['text'], $runs));
        $plain = count($runs) === 1 && count($runs[0]) === 1;

        return $plain ? ['type' => 'paragraph', 'text' => $text] : ['type' => 'paragraph', 'text' => $text, 'runs' => $runs];
    }

    /** @param list<mixed>|mixed $content */
    private function text(mixed $content): string
    {
        $out = '';
        foreach (is_array($content) ? $content : [] as $run) {
            if (is_array($run)) {
                $out .= $this->runText($run);
            }
        }

        return trim($out);
    }

    /** @param array<string, mixed> $run */
    private function runText(array $run): string
    {
        return match ($run['type'] ?? '') {
            'text' => (string) ($run['text'] ?? ''),
            // E.27: an optional field with no value prints blank.
            'field' => (string) ($this->values[(string) ($run['path'] ?? '')] ?? ''),
            'break' => ' ',
            'math' => (string) ($run['tex'] ?? ''),
            default => '',
        };
    }

    private function imageSrc(string $src): ?string
    {
        if (preg_match('#/files/(\d+)/content$#', $src, $m) === 1) {
            return ($this->imageData)($m[1]);
        }

        return str_starts_with($src, 'data:image/') ? $src : null;
    }

    /**
     * Every field reference in the blocks, nested ones included.
     *
     * @param list<mixed>|mixed $node
     * @return list<array<string, mixed>>
     */
    public static function fieldRefs(mixed $node): array
    {
        $found = [];
        $walk = static function (mixed $n) use (&$walk, &$found): void {
            if (!is_array($n)) {
                return;
            }
            if (($n['type'] ?? null) === 'field' && is_string($n['path'] ?? null)) {
                $found[] = $n;
                return;
            }
            foreach ($n as $child) {
                if (is_array($child)) {
                    $walk($child);
                }
            }
        };
        $walk($node);

        return $found;
    }
}
