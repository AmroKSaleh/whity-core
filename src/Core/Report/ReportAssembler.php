<?php

declare(strict_types=1);

namespace Whity\Core\Report;

use Whity\Core\i18n\ServerLabels;
use Whity\Sdk\Render\DocumentRenderer as SdkRenderer;
use Whity\Sdk\Render\FlowDocument;
use Whity\Sdk\Render\IssuedDocument;
use Whity\Sdk\Render\PageSpec;
use Whity\Sdk\Render\RenderRejectedException;
use Whity\Sdk\Render\RenderUnavailableException;

/**
 * Turns a report source's rows into an issued document (#947 item 6).
 *
 * The last piece of #947, and the first production caller of the flowing
 * renderer — `FlowDocument::table()` was written for exactly this, and its own
 * docblock says so: "a real table is assembled from a query, not written as a
 * literal."
 *
 * WHAT THIS OWNS, WHICH IS LESS THAN IT LOOKS
 * -------------------------------------------
 * The source owns the query and its own tenant predicate. The renderer owns
 * pagination, numbering and the list of tables. The issuer owns the record, the
 * artifact and the verification code. What is left here is the part neither can
 * do: turning typed VALUES into printed text, and stating on the page what the
 * reader could not otherwise know about what they are holding.
 *
 * TRUNCATION IS PRINTED, NOT SWALLOWED
 * ------------------------------------
 * A report has a row ceiling, and a document that quietly stops at it is wrong
 * in the one way a printed page cannot be corrected: the reader has no way to
 * tell they are holding a subset. So when the ceiling bites, the count of what
 * MATCHED and the count of what is SHOWN both go on the page, in the reader's
 * language, above the table. That is also why {@see ReportSourceInterface} has
 * a `total()` separate from `rows()` — a total derived from the returned rows
 * could only ever agree with itself.
 *
 * NULL IS PRINTED AS EMPTY, NOT AS "null"
 * ---------------------------------------
 * Every formatter below returns `''` for null. That sounds obvious and is the
 * kind of thing that reaches paper: PHP interpolating a null into a string
 * gives `''`, but `var_export`, `json_encode` and a careless `?? 'null'` do not,
 * and a column of the word "null" is a document nobody can hand to anybody.
 *
 * The `{shown}` and `{total}` placeholders survive translation — a translator
 * moves them, and in Arabic will, but must not rename them. The summary line is
 * the one string here a reader relies on to know whether they are holding the
 * whole set.
 *
 * @i18n-keys admin
 *   report.summary = Showing all {total} matching records.
 *   report.summaryTruncated = Showing {shown} of {total} matching records. This report was limited; it is not the full set.
 *   report.empty = No records matched this report.
 *   report.yes = Yes
 *   report.no = No
 */
final class ReportAssembler
{
    /** The i18n domain the report furniture's own words live in. */
    private const DOMAIN = 'admin';

    public function __construct(
        private readonly SdkRenderer $renderer,
        private readonly ServerLabels $labels,
    ) {
    }

    /**
     * Build the document and issue it.
     *
     * @param list<array<string, mixed>> $rows    Already tenant-scoped and filtered.
     * @param list<ReportColumn>         $columns In print order.
     * @param int                        $total   How many MATCHED, not how many are here.
     *
     * @throws RenderRejectedException    The document will not be attempted.
     * @throws RenderUnavailableException The render tier could not do it.
     */
    public function issue(
        string $title,
        array $columns,
        array $rows,
        int $total,
        string $language,
        bool $rightToLeft,
    ): IssuedDocument {
        return $this->renderer->issue(
            $this->build($title, $columns, $rows, $total, $language, $rightToLeft),
            $title
        );
    }

    /**
     * The document itself, separated from issuing it so the shape is testable
     * without a render tier — the same split the renderer makes between its
     * verdict logic and its browser.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<ReportColumn>         $columns
     */
    public function build(
        string $title,
        array $columns,
        array $rows,
        int $total,
        string $language,
        bool $rightToLeft,
    ): FlowDocument {
        $document = ($rightToLeft ? FlowDocument::rightToLeft(PageSpec::a4()) : FlowDocument::create(PageSpec::a4()))
            ->withTitle($title)
            ->withLang($language)
            // The generated running footer carries the page numbers. A report is
            // the document class where "page 7 of 34" matters most: it is read
            // out of order, photocopied in parts, and argued over.
            ->heading(1, $title, unnumbered: true);

        $document->paragraph($this->summary(count($rows), $total, $language));

        if ($rows === []) {
            // An empty report is still a report, and it must SAY it is empty
            // rather than print a bare header row. A document that ends after
            // its title reads as a broken render.
            $document->paragraph(
                $this->labels->label(self::DOMAIN, 'report.empty', 'No records matched this report.')
            );

            return $document;
        }

        $document->table(
            array_map(static fn (ReportColumn $c): string => $c->label, $columns),
            array_map(fn (array $row): array => $this->formatRow($row, $columns, $language), $rows),
            caption: $title,
        );

        return $document;
    }

    /**
     * The line above the table: how many rows are shown, out of how many
     * matched, and when it was produced.
     *
     * The counts are always printed, not only when they differ. A reader who
     * has learned that the line appears when something was cut cannot conclude
     * anything from its absence in a document they were handed a page of.
     */
    private function summary(int $shown, int $total, string $language): string
    {
        $template = $shown < $total
            ? $this->labels->label(
                self::DOMAIN,
                'report.summaryTruncated',
                'Showing {shown} of {total} matching records. This report was limited; it is not the full set.'
            )
            : $this->labels->label(
                self::DOMAIN,
                'report.summary',
                'Showing all {total} matching records.'
            );

        return strtr($template, [
            '{shown}' => $this->number((float) $shown, $language),
            '{total}' => $this->number((float) $total, $language),
        ]);
    }

    /**
     * One row, in declared column order, every value a string.
     *
     * Order comes from `$columns` rather than from the row's own key order: a
     * source that built its rows with a different `SELECT` ordering would
     * otherwise print its values under the wrong headings — a table that is
     * entirely plausible and entirely wrong.
     *
     * @param array<string, mixed> $row
     * @param list<ReportColumn>   $columns
     * @return list<string>
     */
    private function formatRow(array $row, array $columns, string $language): array
    {
        return array_map(
            fn (ReportColumn $column): string => $this->formatValue($row[$column->key] ?? null, $column, $language),
            $columns
        );
    }

    private function formatValue(mixed $value, ReportColumn $column, string $language): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        return match ($column->type) {
            ReportColumn::TYPE_NUMBER => is_numeric($value) ? $this->number((float) $value, $language) : (string) $value,
            ReportColumn::TYPE_DATE => $this->timestamp($value, 'Y-m-d'),
            ReportColumn::TYPE_DATETIME => $this->timestamp($value, 'Y-m-d H:i'),
            ReportColumn::TYPE_BOOLEAN => $this->boolean($value, $language),
            default => is_scalar($value) ? (string) $value : '',
        };
    }

    /**
     * A number, grouped for reading.
     *
     * Arabic-Indic digits are NOT substituted, even for an Arabic document.
     * They are a live typographic preference rather than a settled one — much
     * Arabic administrative and financial print uses Western digits — and a
     * report is a document people compare against a screen, where the platform
     * shows Western digits throughout. Two representations of one figure across
     * two surfaces is worse than one that is merely not the reader's first
     * choice.
     */
    private function number(float $value, string $language): string
    {
        $decimals = $value == (int) $value ? 0 : 2;

        // Deliberately not NumberFormatter: `intl` is not a required extension
        // for this platform, so a locale-aware path here would produce grouped
        // numbers on some deployments and ungrouped ones on others, from the
        // same code and with nothing reporting the difference.
        unset($language);

        return number_format($value, $decimals, '.', ',');
    }

    /**
     * A stored timestamp, printed.
     *
     * The wire value is what the driver returned — PostgreSQL gives
     * `2026-08-25 14:02:11` with no offset — so it is read as the UTC instant
     * the server meant rather than in whatever zone the PHP process happens to
     * be in. An unparseable value is returned verbatim: printing the raw string
     * tells a reader something is wrong with that field, while printing nothing
     * hides it.
     */
    private function timestamp(mixed $value, string $format): string
    {
        if (!is_string($value) && !is_int($value)) {
            return '';
        }

        try {
            $when = is_int($value)
                ? (new \DateTimeImmutable('@' . $value))
                : new \DateTimeImmutable((string) $value, new \DateTimeZone('UTC'));
        } catch (\Exception) {
            return (string) $value;
        }

        return $when->format($format);
    }

    private function boolean(mixed $value, string $language): string
    {
        unset($language);

        $true = in_array($value, [true, 1, '1', 't', 'true', 'TRUE'], true);

        return $true
            ? $this->labels->label(self::DOMAIN, 'report.yes', 'Yes')
            : $this->labels->label(self::DOMAIN, 'report.no', 'No');
    }
}
