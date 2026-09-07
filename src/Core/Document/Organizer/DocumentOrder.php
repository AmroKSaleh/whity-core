<?php

declare(strict_types=1);

namespace Whity\Core\Document\Organizer;

/**
 * How a page of documents is ORDERED — a field from the closed
 * {@see DocumentSortField} vocabulary plus a direction.
 *
 * WHY THIS IS NOT A SLOT ON DocumentCriteria
 * ------------------------------------------
 * `DocumentCriteria` answers "which documents", and it is used by BOTH the list
 * and the count that produces the pagination total. An order belongs to the list
 * alone: `COUNT(*) … ORDER BY title` is the same number, so putting a sort on
 * the criteria would offer the count a field it must ignore, and the day
 * somebody honours it the total and the page start disagreeing for reasons
 * nobody can see. Two objects, because they are answers to two questions.
 *
 * WHY A DIRECTION DEFAULT PER FIELD, AND WHY IT IS ECHOED BACK
 * -----------------------------------------------------------
 * "Sort by title" means A→Z and "sort by date" means newest-first; a single
 * global default would make one of the two wrong on first click. So the default
 * is per field ({@see forField()}) — and because that is an implicit rule, the
 * list response echoes the order the server ACTUALLY applied rather than letting
 * a client assume its own. Same posture as the anchor unit the organizer already
 * echoes: the caller reads back what happened instead of guessing.
 *
 * Immutable — worker-safe.
 */
final class DocumentOrder
{
    public function __construct(
        public readonly DocumentSortField $field,
        public readonly bool $descending,
    ) {
    }

    /**
     * The order a caller means when they name a field and no direction.
     *
     * Text ascends (A→Z is what a file browser does); a date descends (the most
     * recent thing is the one being looked for).
     */
    public static function forField(DocumentSortField $field): self
    {
        return new self($field, $field === DocumentSortField::CreatedAt);
    }

    /** `asc` or `desc`, for echoing back to the caller. */
    public function direction(): string
    {
        return $this->descending ? 'desc' : 'asc';
    }
}
