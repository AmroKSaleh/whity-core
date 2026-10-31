<?php

declare(strict_types=1);

namespace Whity\Core\Search;

/**
 * One kind of record `GET /api/search` looks through (WHIT-783).
 *
 * A source answers for its own records only, and with exactly the visibility
 * its list route applies: the route permission ({@see self::permission()}),
 * checked by {@see SearchService} before the source runs, and any per-record
 * rule inside {@see self::search()}. Every query is scoped to the tenant.
 */
interface SearchSource
{
    /** The `type` on each hit and in `?types=`: `document`, `form`, `person`, `ou`, or a plugin data-type key. */
    public function type(): string;

    /** @return array{en: string, ar: string} */
    public function label(): array;

    /** The permission the record type's list route needs. Null is not allowed: a source no one gates is a source everyone reads. */
    public function permission(): string;

    /**
     * Records whose folded text contains $pattern (a `LIKE` pattern from
     * {@see \Whity\Core\Text\SearchText::containsPattern()}), newest first.
     *
     * @return list<SearchHit>
     */
    public function search(SearchContext $context, string $pattern, int $limit): array;
}
