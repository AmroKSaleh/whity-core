<?php

declare(strict_types=1);

namespace Whity\Http;

use InvalidArgumentException;

/**
 * What ONE list endpoint allows a caller to sort and search by.
 *
 * WHY A DECLARATION RATHER THAN PARAMETERS. `sort` and `q` arrive from the query
 * string, and a sort column reaches SQL as an `ORDER BY` fragment that cannot be
 * a bound parameter. So the only safe design is one where the untrusted value
 * never becomes SQL at all: it is a KEY, looked up in a map the endpoint wrote,
 * and a key that is not in the map is not an error to report — it is simply not
 * a sort. {@see ListQuery} does the lookup; this holds the map.
 *
 * THE TIEBREAKER IS REQUIRED, and it is the part most likely to be dismissed as
 * ceremony. `LIMIT/OFFSET` over an `ORDER BY` with ties has no defined order
 * WITHIN a tie, so two requests for two different pages can return the same row
 * twice and never return another. On a users table sorted by role — where
 * hundreds of rows share a value — that is not a rare edge: it is the normal
 * case. The symptom is a row that "disappears" and a duplicate three pages
 * later, which reads as a data bug and is a query bug. Passing a unique column
 * (usually the primary key) makes the order total and the paging exact.
 *
 * COLUMN EXPRESSIONS ARE TRUSTED, KEYS ARE NOT. The values in `sortable` and the
 * entries in `searchable` are written by the handler author and interpolated
 * into SQL as-is; they are code, not input. Anything derived from the request is
 * bound. That split is the whole security model here, so a handler must never
 * build these from anything a caller sent.
 */
final class ListSpec
{
    /**
     * @param array<string, string> $sortable   Caller-facing key => SQL column expression
     *        (`['email' => 'pe.email']`). The key is what a client sends as `sort`.
     * @param string $tiebreaker A UNIQUE column expression appended to every ORDER BY,
     *        so paging cannot repeat or skip rows. See the class docblock.
     * @param list<string> $searchable SQL column expressions matched against `q`.
     *        Empty means the endpoint offers no search, and `q` is ignored.
     * @param string|null $defaultSort A key from `$sortable`, used when the caller
     *        sends none. Null means "order by the tiebreaker alone".
     * @param string $defaultDirection `asc` or `desc`.
     */
    public function __construct(
        public readonly array $sortable,
        public readonly string $tiebreaker,
        public readonly array $searchable = [],
        public readonly ?string $defaultSort = null,
        public readonly string $defaultDirection = 'asc',
    ) {
        if (trim($this->tiebreaker) === '') {
            throw new InvalidArgumentException(
                'A ListSpec needs a unique tiebreaker column, or LIMIT/OFFSET paging '
                . 'over tied rows can repeat and skip rows between pages.'
            );
        }

        if ($this->defaultSort !== null && !array_key_exists($this->defaultSort, $this->sortable)) {
            throw new InvalidArgumentException(sprintf(
                'defaultSort "%s" is not one of this endpoint\'s sortable keys (%s).',
                $this->defaultSort,
                implode(', ', array_keys($this->sortable)) ?: 'none'
            ));
        }

        if (!in_array(strtolower($this->defaultDirection), ['asc', 'desc'], true)) {
            throw new InvalidArgumentException('defaultDirection must be "asc" or "desc".');
        }
    }

    /** The SQL expression for a caller-supplied sort key, or null if it is not offered. */
    public function columnFor(string $key): ?string
    {
        return $this->sortable[$key] ?? null;
    }
}
