<?php

declare(strict_types=1);

namespace Whity\Core\Search;

use Whity\Core\Text\SearchText;

/**
 * `GET /api/search` (WHIT-783): one search across every record type the caller
 * may list, for the "search records" MCP tool and the ⌘K palette.
 *
 * A source runs only when the caller holds its permission, so a type the caller
 * can't list contributes nothing, and not even its existence. Each source then
 * applies its own per-record rule. The query is folded once
 * ({@see SearchText::fold()}) and every source folds its columns the same way
 * in SQL.
 */
final class SearchService
{
    public const MIN_QUERY_LENGTH = 2;
    public const DEFAULT_LIMIT    = 5;
    public const MAX_LIMIT        = 20;

    /** @param \Closure(): list<SearchSource> $sources Read on each call, so plugin types registered after construction are included. */
    public function __construct(private readonly \Closure $sources)
    {
    }

    /**
     * Results grouped by type, in source order, at most $limit per type.
     *
     * @param list<string>|null $types Only these types; null for all.
     * @return list<array{type: string, label: array{en: string, ar: string}, results: list<array<string, mixed>>}>
     */
    public function search(SearchContext $context, string $query, ?array $types = null, int $limit = self::DEFAULT_LIMIT): array
    {
        $folded = SearchText::fold($query);
        if (mb_strlen($folded) < self::MIN_QUERY_LENGTH) {
            return [];
        }
        $pattern = SearchText::containsPattern($folded);
        $limit   = max(1, min(self::MAX_LIMIT, $limit));

        $groups = [];
        foreach (($this->sources)() as $source) {
            if ($types !== null && !in_array($source->type(), $types, true)) {
                continue;
            }
            if (!$context->can($source->permission())) {
                continue;
            }
            $hits = $source->search($context, $pattern, $limit);
            if ($hits === []) {
                continue;
            }
            $groups[] = [
                'type'    => $source->type(),
                'label'   => $source->label(),
                'results' => array_map(static fn (SearchHit $hit): array => $hit->toArray(), $hits),
            ];
        }

        return $groups;
    }

    /**
     * The types this caller could search, for clients that offer a filter.
     *
     * @return list<array{type: string, label: array{en: string, ar: string}}>
     */
    public function typesFor(SearchContext $context): array
    {
        $out = [];
        foreach (($this->sources)() as $source) {
            if ($context->can($source->permission())) {
                $out[] = ['type' => $source->type(), 'label' => $source->label()];
            }
        }
        return $out;
    }
}
