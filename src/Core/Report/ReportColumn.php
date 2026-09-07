<?php

declare(strict_types=1);

namespace Whity\Core\Report;

/**
 * One column of a report (#947 item 6).
 *
 * A key, a label, and how the value should read on a printed page. Three
 * things, and the third is the one worth having a type for: a report is
 * PRINTED, so a number that arrives as `1234.5` has to become `1,234.50` before
 * it is a column of figures a person can scan, and a date has to be rendered
 * in the reader's terms rather than the database's.
 *
 * WHY THE ALIGNMENT IS DERIVED AND NOT DECLARED
 * ----------------------------------------------
 * Numbers align to the trailing edge and everything else to the leading edge.
 * That is not a preference a source should be able to get wrong — a column of
 * right-aligned names or leading-aligned totals is simply a mis-set table — so
 * it follows from the type instead of being a fourth constructor argument
 * nobody would think about.
 *
 * "Trailing" and "leading" rather than "right" and "left" because these
 * documents are printed in Arabic as often as in English, and a column pinned
 * to the RIGHT is correct in one direction and wrong in the other.
 */
final class ReportColumn
{
    /** Printed as-is. */
    public const TYPE_TEXT = 'text';

    /** Grouped and decimal-aligned; trailing edge. */
    public const TYPE_NUMBER = 'number';

    /** Rendered as a date in the document's language. */
    public const TYPE_DATE = 'date';

    /** Rendered as a date and time. */
    public const TYPE_DATETIME = 'datetime';

    /** Yes/no, in the document's language. */
    public const TYPE_BOOLEAN = 'boolean';

    /**
     * @param string $key   The key this column reads from each row.
     * @param string $label The heading, already in the reader's language — a
     *                      source resolves its own wording, because only the
     *                      source knows which i18n domain its labels live in.
     * @param string $type  One of the TYPE_* constants.
     */
    private function __construct(
        public readonly string $key,
        public readonly string $label,
        public readonly string $type,
    ) {
    }

    public static function text(string $key, string $label): self
    {
        return new self($key, $label, self::TYPE_TEXT);
    }

    public static function number(string $key, string $label): self
    {
        return new self($key, $label, self::TYPE_NUMBER);
    }

    public static function date(string $key, string $label): self
    {
        return new self($key, $label, self::TYPE_DATE);
    }

    public static function dateTime(string $key, string $label): self
    {
        return new self($key, $label, self::TYPE_DATETIME);
    }

    public static function boolean(string $key, string $label): self
    {
        return new self($key, $label, self::TYPE_BOOLEAN);
    }

    /** Whether this column's values belong against the trailing edge. */
    public function isTrailingAligned(): bool
    {
        return $this->type === self::TYPE_NUMBER;
    }
}
