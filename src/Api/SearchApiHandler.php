<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Search\SearchContext;
use Whity\Core\Search\SearchService;
use Whity\Core\Tenant\TenantContext;

/**
 * `GET /api/search?q=&types=&limit=` (WHIT-783, docs/design/MCP-TOOL-SURFACE.md §5.13).
 *
 * One search across documents, forms, people, organisational units and the
 * plugin records that opted in. It serves the always-on "search records" MCP
 * tool and the ⌘K command palette.
 *
 * The route itself has no permission gate: any signed-in member may search.
 * Each record type is searched only when the caller holds that type's list
 * permission, and with that type's per-record rule, so a member sees exactly
 * what the list routes would show them, and nothing about types they can't
 * list. The route schema opts into MCP for that reason (`'mcp' => true`): the
 * handler authorises per type.
 *
 * `GET /api/search/types` lists the types this caller could search, for a
 * client that offers a filter.
 */
final class SearchApiHandler
{
    public function __construct(
        private readonly SearchService $search,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    /** @param array<string, string> $params */
    public function search(Request $request, array $params = []): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }

        $query = self::query($request);
        $q     = is_string($query['q'] ?? null) ? trim($query['q']) : '';
        if ($q === '') {
            return Response::error('q is required', 422);
        }
        if (mb_strlen($q) > 200) {
            return Response::error('q must be at most 200 characters', 422);
        }

        $types = null;
        if (is_string($query['types'] ?? null) && trim($query['types']) !== '') {
            $types = array_values(array_filter(array_map('trim', explode(',', $query['types'])), static fn (string $t): bool => $t !== ''));
        }
        $limit = isset($query['limit']) && is_numeric($query['limit']) ? (int) $query['limit'] : SearchService::DEFAULT_LIMIT;

        try {
            return Response::json(['data' => $this->search->search($context, $q, $types, $limit)], 200);
        } catch (\Throwable $e) {
            error_log('[search] ' . $e->getMessage());
            return Response::error('Search failed', 500);
        }
    }

    /** @param array<string, string> $params */
    public function types(Request $request, array $params = []): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }

        return Response::json(['data' => $this->search->typesFor($context)], 200);
    }

    private function context(Request $request): SearchContext|Response
    {
        $tenantId = TenantContext::getTenantId();
        $actor    = $request->user;
        $profile  = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id) ? $actor->profile_id : null;
        if ($tenantId === null || $profile === null) {
            return Response::error('Unauthenticated', 401);
        }

        $checker = $this->roleChecker;
        return new SearchContext(
            $tenantId,
            $profile,
            static fn (string $permission): bool => $checker->hasPermissionForProfile($profile, $permission, $tenantId),
        );
    }

    /**
     * The query string, from the request path (tests, internal callers) or
     * `$_GET` (production: Request::fromGlobals() keeps only the path).
     *
     * @return array<string, mixed>
     */
    private static function query(Request $request): array
    {
        parse_str((string) parse_url($request->getPath(), PHP_URL_QUERY), $fromPath);
        $query = [];
        foreach ($fromPath + $_GET as $key => $value) {
            $query[(string) $key] = $value;
        }
        return $query;
    }
}
