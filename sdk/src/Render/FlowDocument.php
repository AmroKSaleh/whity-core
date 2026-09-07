<?php

declare(strict_types=1);

namespace Whity\Sdk\Render;

/**
 * A flowing document, assembled block by block (SDK 1.41).
 *
 * This is what a plugin hands {@see DocumentRenderer}. It carries CONTENT and
 * no positions: headings, paragraphs, tables, figures. How many pages that
 * becomes, which page each table lands on, and therefore every page number in
 * the generated contents list are properties of the RENDERED result, decided by
 * the renderer — see {@see RenderedDocument}.
 *
 * WHY A BUILDER RATHER THAN AN ARRAY
 * ----------------------------------
 * The wire format is a JSON tree, and the honest alternative was to publish its
 * shape and let plugins build it. The reason not to is where the mistakes go. A
 * misspelled `paragrahp`, a heading level of 0, a `rows` that is a map instead
 * of a list — every one of those is accepted by PHP, travels a network hop, and
 * comes back as a 422 from a service the plugin author has never heard of,
 * naming a field index in a tree they did not write by hand. Here each of them
 * is a method that does not exist or an argument that is refused at the call
 * site, with the plugin's own stack still on it.
 *
 * It does NOT re-implement the renderer's validator, and that is a deliberate
 * line. Two copies of those rules would drift, and the one that drifted would
 * be the one nobody exercised. What is checked here is only what this class
 * must know to build a correct tree at all — a heading level indexes the
 * numbering counters, a figure source is a security rule rather than a shape
 * rule — and everything else is the renderer's to judge, reported back as a
 * {@see RenderRejectedException} naming the field.
 *
 * MUTABLE, ON PURPOSE
 * -------------------
 * Every other value object in this SDK is immutable and this one is not. A
 * compliance submission of the kind this seam exists for is tens of thousands
 * of blocks; copy-on-append would make assembling it quadratic in both time and
 * memory, and the object is a BUILDER whose whole life is one local variable in
 * one method. The fluent methods return `$this`, not a clone. If you need two
 * variants of a document, build two.
 *
 * NUMBERING IS NOT YOURS TO SET
 * -----------------------------
 * Tables and figures are numbered in document order by the renderer, and
 * headings get their `2.3.1` the same way. There is deliberately no way to
 * assign one here: a caller that numbered its own would have to renumber every
 * time it inserted something, and any disagreement between its numbers and the
 * generated lists would be invisible until somebody read the printed document.
 * You supply content and captions; the renderer supplies numbers.
 */
final class FlowDocument
{
    public const DIRECTION_LTR = 'ltr';
    public const DIRECTION_RTL = 'rtl';

    /** Generated front-matter lists, in the renderer's vocabulary. */
    public const FRONT_MATTER_CONTENTS = 'contents';
    public const FRONT_MATTER_TABLES = 'tables';
    public const FRONT_MATTER_FIGURES = 'figures';

    private const MIN_HEADING_LEVEL = 1;
    private const MAX_HEADING_LEVEL = 6;

    /** @var list<array<string, mixed>> */
    private array $content = [];

    /** @var list<array<string, mixed>> */
    private array $frontMatter = [];

    /** @var array<string, string> */
    private array $labels = [];

    /** @var array<string, mixed>|null */
    private ?array $header = null;

    /** @var array<string, mixed>|null */
    private ?array $footer = null;

    private string $title = '';

    private ?string $lang = null;

    private function __construct(
        private readonly PageSpec $page,
        private readonly string $direction,
    ) {
    }

    /**
     * Start a document.
     *
     * The direction is settled HERE and cannot be changed afterwards, because
     * it is not decoration: it picks the default caption words, the running
     * footer wording, and the base direction every mixed Arabic/Latin run in
     * the document is resolved against. A document whose direction changed
     * halfway through assembly would have paragraphs resolved two different
     * ways with nothing recording which.
     *
     * @throws RenderRejectedException On a direction that is neither ltr nor rtl.
     */
    public static function create(
        ?PageSpec $page = null,
        string $direction = self::DIRECTION_LTR,
    ): self {
        if ($direction !== self::DIRECTION_LTR && $direction !== self::DIRECTION_RTL) {
            throw RenderRejectedException::because(
                'Document direction must be "' . self::DIRECTION_LTR . '" or "' . self::DIRECTION_RTL . '"'
            );
        }

        return new self($page ?? PageSpec::a4(), $direction);
    }

    /** A right-to-left document on the given page. Arabic unless told otherwise. */
    public static function rightToLeft(?PageSpec $page = null): self
    {
        return self::create($page, self::DIRECTION_RTL);
    }

    /**
     * The document's title. Shown by the renderer where a title belongs; it is
     * not a heading and does not enter the contents list. Add a heading for
     * that.
     */
    public function withTitle(string $title): self
    {
        $this->title = $title;

        return $this;
    }

    /**
     * The content language tag, e.g. `ar`, `en`, `ar-SA`.
     *
     * Defaults from the direction (`ar` for rtl, `en` for ltr), which is right
     * often enough to leave alone and wrong for exactly the cases worth naming:
     * Hebrew, Urdu, or English content laid out right-to-left. It reaches the
     * page as a `lang` attribute, so it drives hyphenation and font selection
     * rather than anything cosmetic.
     */
    public function withLang(string $lang): self
    {
        $this->lang = $lang !== '' ? $lang : null;

        return $this;
    }

    /**
     * Override the renderer's caption and furniture words.
     *
     * The renderer ships defaults in both directions ("Table"/"جدول",
     * "Contents"/"المحتويات", "Page {{page}} of {{pages}}"). Supply only the
     * keys you want to change; anything omitted keeps the renderer's wording,
     * so a tenant that renames "Figure" to "Exhibit" does not thereby have to
     * restate every other label.
     *
     * Typed as loosely as it is checked, deliberately. These strings routinely
     * come from a tenant setting or a translation catalogue rather than from a
     * literal in the plugin's source, so the values arriving here are as
     * untrusted as any other stored data — and a signature promising
     * `array<string, string>` would let the analyser conclude the guard below
     * is dead code that could be removed.
     *
     * @param array<array-key, mixed> $labels Keys: contents, tables, figures,
     *        table, figure, continued, pageOf. Anything that is not a
     *        string-to-string pair is ignored rather than refused: a label is
     *        cosmetic, and failing a hundred-page render over one is the wrong
     *        trade — the renderer's own wording is a working fallback.
     */
    public function withLabels(array $labels): self
    {
        foreach ($labels as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $this->labels[$key] = $value;
            }
        }

        return $this;
    }

    /**
     * The running header: up to three slots across the top of every page.
     *
     * `{{page}}` and `{{pages}}` are substituted per page by the renderer. The
     * slots are named by POSITION rather than by side, so a right-to-left
     * document does not need its header rewritten — `start` is the leading
     * edge in whichever direction the document runs.
     */
    public function withHeader(?string $start = null, ?string $center = null, ?string $end = null): self
    {
        $this->header = self::furniture($start, $center, $end);

        return $this;
    }

    /**
     * The running footer. Same slots as {@see withHeader()}.
     *
     * Calling this with nothing REMOVES the footer, including the renderer's
     * default page-number line. That is the only way to get a document with no
     * page numbers at all, and it is a real requirement for a single-sided
     * certificate, so it has to be expressible.
     */
    public function withFooter(?string $start = null, ?string $center = null, ?string $end = null): self
    {
        $this->footer = self::furniture($start, $center, $end) ?? [];

        return $this;
    }

    /**
     * Add a generated contents list to the front matter.
     *
     * @param string|null $title    Overrides the direction's default wording.
     * @param int         $maxLevel Deepest heading level listed. Three by
     *                              default: below that a heading is structure
     *                              rather than navigation.
     */
    public function withContents(?string $title = null, int $maxLevel = 3): self
    {
        return $this->addFrontMatter(self::FRONT_MATTER_CONTENTS, $title, $maxLevel);
    }

    /**
     * Add a generated list of tables. Only tables WITH a caption appear —
     * an uncaptioned table has nothing to list.
     */
    public function withListOfTables(?string $title = null): self
    {
        return $this->addFrontMatter(self::FRONT_MATTER_TABLES, $title, null);
    }

    /** Add a generated list of figures. Captioned figures only, as above. */
    public function withListOfFigures(?string $title = null): self
    {
        return $this->addFrontMatter(self::FRONT_MATTER_FIGURES, $title, null);
    }

    /**
     * A heading.
     *
     * @param int  $level      1-6.
     * @param bool $inContents Set false for a running sub-head you do not want
     *                         listed. Ignored when $unnumbered is true.
     * @param bool $unnumbered A front-matter-style title: no `2.3.1`, and never
     *                         in the contents list.
     *
     * @throws RenderRejectedException On a level outside 1-6. Refused rather
     *         than clamped: a 0 or a 7 is a caller's arithmetic being wrong
     *         about its own structure, and silently promoting it to 1 would
     *         produce a plausible document with the wrong outline.
     */
    public function heading(int $level, string $text, bool $inContents = true, bool $unnumbered = false): self
    {
        if ($level < self::MIN_HEADING_LEVEL || $level > self::MAX_HEADING_LEVEL) {
            throw RenderRejectedException::because(
                'Heading level must be between ' . self::MIN_HEADING_LEVEL
                . ' and ' . self::MAX_HEADING_LEVEL . ', got ' . $level
            );
        }

        $block = ['type' => 'heading', 'level' => $level, 'text' => $text];
        if (!$inContents) {
            $block['inContents'] = false;
        }
        if ($unnumbered) {
            $block['unnumbered'] = true;
        }

        $this->content[] = $block;

        return $this;
    }

    /** A paragraph of body text. */
    public function paragraph(string $text): self
    {
        $this->content[] = ['type' => 'paragraph', 'text' => $text];

        return $this;
    }

    /**
     * A table.
     *
     * Rows are typed as loosely as they are CHECKED, and the reason is where
     * they come from. A real table is assembled from a query, not written as a
     * literal — and `fetchAll()` without `FETCH_NUM` returns exactly the map
     * rows this refuses. A signature promising a list of lists would let the
     * analyser prove the check below unreachable on the one shape that never
     * occurs (a literal) while saying nothing about the shape that does.
     *
     * @param list<string>       $columns Header cells; pass `[]` for a table
     *                                    with no header row.
     * @param array<int, mixed>  $rows    Body rows: each must be a LIST of cell
     *                                    values (strings, numbers or null).
     * @param string|null        $caption Captioned tables are numbered and can
     *                                    appear in the list of tables;
     *                                    uncaptioned ones are still numbered
     *                                    but have nothing to list.
     *
     * @throws RenderRejectedException When a row is not a list. A map row is
     *         the mistake worth catching here: `['name' => 'x']` encodes as a
     *         JSON object, the renderer refuses the whole document, and the
     *         only clue is a block index.
     */
    public function table(array $columns, array $rows, ?string $caption = null): self
    {
        foreach ($rows as $index => $row) {
            if (!is_array($row) || !array_is_list($row)) {
                throw RenderRejectedException::because(
                    'Table row ' . $index . ' must be a list of cell values, not a map'
                );
            }
        }

        $block = ['type' => 'table', 'columns' => array_values($columns), 'rows' => array_values($rows)];
        if ($caption !== null && $caption !== '') {
            $block['caption'] = $caption;
        }

        $this->content[] = $block;

        return $this;
    }

    /**
     * A figure, embedded.
     *
     * @param string $dataUri The image as a `data:` URI.
     *
     * @throws RenderRejectedException On anything that is not a `data:` URI.
     *         Not a style preference: an `http(s)` source would make every
     *         render an outbound fetch from inside the render tier, which is
     *         both a request-forgery surface and a source of documents that
     *         differ between two renders of the same content. The renderer
     *         refuses them too; this refuses them before the round trip, where
     *         the caller can still see which figure it was.
     */
    public function figure(string $dataUri, ?string $caption = null): self
    {
        if (!str_starts_with($dataUri, 'data:')) {
            throw RenderRejectedException::because(
                'A figure source must be a data: URI — remote images are not fetched by the renderer'
            );
        }

        $block = ['type' => 'figure', 'src' => $dataUri];
        if ($caption !== null && $caption !== '') {
            $block['caption'] = $caption;
        }

        $this->content[] = $block;

        return $this;
    }

    /** Start the next block on a new page. */
    public function pageBreak(): self
    {
        $this->content[] = ['type' => 'pageBreak'];

        return $this;
    }

    /** Vertical space, in millimetres. */
    public function spacer(float $heightMm): self
    {
        $this->content[] = ['type' => 'spacer', 'heightMm' => max(0.0, $heightMm)];

        return $this;
    }

    /** How many blocks this document carries. What a host's ceiling counts. */
    public function blockCount(): int
    {
        return count($this->content);
    }

    /** Whether anything has been added yet. */
    public function isEmpty(): bool
    {
        return $this->content === [];
    }

    /**
     * The wire payload.
     *
     * @throws RenderRejectedException When the document has no content. An
     *         empty tree is refused here rather than sent, because the renderer
     *         answers the same refusal a network hop later and this is the one
     *         case where the caller's own mistake needs no service to diagnose.
     *
     * @return array<string, mixed>
     */
    public function toPayload(): array
    {
        if ($this->content === []) {
            throw RenderRejectedException::because('A document must have at least one block of content');
        }

        $payload = [
            'page' => $this->page->toArray(),
            'direction' => $this->direction,
            'content' => $this->content,
        ];

        if ($this->title !== '') {
            $payload['title'] = $this->title;
        }
        if ($this->lang !== null) {
            $payload['lang'] = $this->lang;
        }
        if ($this->labels !== []) {
            $payload['labels'] = $this->labels;
        }
        if ($this->header !== null) {
            $payload['header'] = $this->header;
        }
        if ($this->footer !== null) {
            $payload['footer'] = $this->footer;
        }
        if ($this->frontMatter !== []) {
            $payload['frontMatter'] = $this->frontMatter;
        }

        return $payload;
    }

    /**
     * @return array<string, mixed>|null Null when every slot was empty, which
     *         is how {@see withHeader()} expresses "no header" and is NOT the
     *         same as the renderer's default.
     */
    private static function furniture(?string $start, ?string $center, ?string $end): ?array
    {
        $slots = array_filter(
            ['start' => $start, 'center' => $center, 'end' => $end],
            static fn (?string $value): bool => $value !== null && $value !== '',
        );

        return $slots === [] ? null : $slots;
    }

    private function addFrontMatter(string $kind, ?string $title, ?int $maxLevel): self
    {
        $entry = ['kind' => $kind];
        if ($title !== null && $title !== '') {
            $entry['title'] = $title;
        }
        if ($maxLevel !== null) {
            $entry['maxLevel'] = $maxLevel;
        }

        $this->frontMatter[] = $entry;

        return $this;
    }
}
