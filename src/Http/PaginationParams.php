<?php

declare(strict_types=1);

namespace Whity\Http;

/**
 * Parsed, clamped pagination parameters extracted from a query string.
 *
 * Centralises the page / per_page resolution that was previously duplicated
 * inline in AuditLogApiHandler so every list endpoint uses the same defaults,
 * clamping rules, and envelope shape.
 *
 * Usage:
 *   $p = PaginationParams::fromQuery($this->parseQuery($request->getPath()));
 *   // ... COUNT query → $total
 *   // ... SELECT ... LIMIT :limit OFFSET :offset, bind $p->perPage / $p->offset
 *   return Response::json(['data' => $rows, 'pagination' => $p->meta($total)]);
 */
final class PaginationParams
{
    public const DEFAULT_PER_PAGE = 25;
    public const MAX_PER_PAGE     = 100;

    /** 1-indexed current page (>= 1). */
    public readonly int $page;

    /** Effective page size, clamped to [1, MAX_PER_PAGE]. */
    public readonly int $perPage;

    /** Zero-based row offset for LIMIT/OFFSET queries. */
    public readonly int $offset;

    private function __construct(int $page, int $perPage)
    {
        $this->page    = $page;
        $this->perPage = $perPage;
        $this->offset  = ($page - 1) * $perPage;
    }

    /**
     * Build from a raw request path (e.g. `/api/users?page=2&per_page=10`).
     *
     * Reads `$_GET` first (runtime source), then overlays any parameters in the
     * path query string so test requests that embed params in the path are handled
     * without a real superglobal. This matches the parseQuery() pattern used by
     * AuditLogApiHandler.
     *
     * @param string $rawPath The full request path, optionally including a query string.
     * @return self
     */
    public static function fromPath(string $rawPath): self
    {
        $params = [];

        foreach ($_GET as $key => $value) {
            if (is_string($key) && is_string($value)) {
                $params[$key] = $value;
            }
        }

        $queryString = parse_url($rawPath, PHP_URL_QUERY);
        if (is_string($queryString) && $queryString !== '') {
            parse_str($queryString, $parsed);
            foreach ($parsed as $key => $value) {
                if (is_string($key) && is_string($value)) {
                    $params[$key] = $value;
                }
            }
        }

        return self::fromQuery($params);
    }

    /**
     * Build from a parsed query-parameter map.
     *
     * Accepts the same `page` / `per_page` keys (digit-string values) used by
     * AuditLogApiHandler; invalid or absent values fall back to the defaults.
     *
     * @param array<string, string> $query Parsed query parameters.
     * @return self
     */
    public static function fromQuery(array $query): self
    {
        $rawPage    = $query['page']     ?? null;
        $rawPerPage = $query['per_page'] ?? null;

        $page = 1;
        if (is_string($rawPage) && ctype_digit($rawPage)) {
            $page = max(1, (int) $rawPage);
        }

        $perPage = self::DEFAULT_PER_PAGE;
        if (is_string($rawPerPage) && ctype_digit($rawPerPage)) {
            $v = (int) $rawPerPage;
            if ($v >= 1) {
                $perPage = min($v, self::MAX_PER_PAGE);
            }
        }

        return new self($page, $perPage);
    }

    /**
     * Build the `pagination` block for a JSON response.
     *
     * @param int $total The total row count (across all pages).
     * @return array{page: int, perPage: int, total: int, totalPages: int}
     */
    public function meta(int $total): array
    {
        return [
            'page'       => $this->page,
            'perPage'    => $this->perPage,
            'total'      => $total,
            'totalPages' => $this->perPage > 0 ? (int) ceil($total / $this->perPage) : 0,
        ];
    }
}
