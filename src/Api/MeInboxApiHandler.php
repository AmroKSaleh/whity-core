<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Inbox\InboxItem;
use Whity\Core\Inbox\InboxSourceRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\PaginationParams;

/**
 * The caller's INBOX — items awaiting them, read one registered source at a
 * time (#881, first source contributed by #947 item 3).
 *
 *   GET /api/me/inbox/sources          — the registered sources
 *   GET /api/me/inbox?source=<key>     — that source's items, paginated
 *
 * Session-gated (cookie OR Bearer) and strictly scoped to the caller's own
 * (tenant, profile): every source is handed both and can answer for nobody else.
 * No RBAC permission, matching `/api/me/notifications` and `/api/me/sessions` —
 * an inbox row already names exactly one person, so a tenant-wide permission has
 * no work left to do.
 *
 * WHY `source` IS REQUIRED
 * ------------------------
 * A request with no `source` is a 422 naming the registered keys, not a
 * default-to-the-only-source. #881 lists three questions that arise only when
 * sources are aggregated — ordering across heterogeneous sources, per-source
 * failure isolation, and pagination across sources — and states they need
 * deciding before an aggregate ships. Answering an unsourced request would be
 * deciding all three by accident, and the answer would silently become wrong the
 * day a second source registers, with nothing to alert the caller.
 *
 * So the refusal is the placeholder. When the aggregate lands it is a new
 * behaviour for the unsourced case, reading this same registry, and every
 * existing sourced call keeps working unchanged.
 *
 * WHY THIS IS NOT `/api/me/document-inbox`
 * ---------------------------------------
 * Because routing's recipients are a SOURCE, not a surface. #947: "two inbox
 * surfaces would be the same mistake as two audit trails." The endpoint belongs
 * to the registry; routing contributes to it.
 *
 * ITEMS ARE SHAPED FOR THE `inbox` BLOCK
 * --------------------------------------
 * The wire fields are `id`, `title`, `subtitle`, `timestamp`, `status`,
 * `resource_type`, `resource_id` — the props the block type declares (#868), so
 * a screen can point an `inbox` block straight at this endpoint. The source
 * catalogue publishes the mapping rather than leaving each client to hardcode
 * it.
 */
final class MeInboxApiHandler
{
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly InboxSourceRegistry $sources,
    ) {
    }

    /**
     * GET /api/me/inbox/sources — what the caller may ask for, with each
     * source's current count for them.
     *
     * The count is included because it is what a navigation badge needs, and the
     * alternative is N requests to discover N zeroes. It is the caller's own
     * count, from the same predicate the list uses, so the badge cannot disagree
     * with the page.
     */
    public function sources(Request $request): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        $data = [];
        foreach ($this->sources->catalogue() as $entry) {
            $source = $this->sources->get($entry['key']);
            if ($source === null) {
                continue;
            }
            $data[] = $entry + ['open_count' => $source->count($tenantId, $profileId, true)];
        }

        return Response::json(['data' => $data]);
    }

    /**
     * GET /api/me/inbox?source=<key> — a page of one source's items.
     *
     * `?open=0` includes the caller's history as well as what is still awaiting
     * them. Open-only is the default because that is what an inbox IS: the
     * question is "what needs me", not "what has ever reached me".
     */
    public function list(Request $request): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        $query = self::queryParams($request);

        $key = $query['source'] ?? null;
        if (!is_string($key) || $key === '') {
            return Response::error(
                "'source' is required — this endpoint reads one inbox source at a time. Registered sources: "
                . implode(', ', $this->sources->keys()),
                422
            );
        }

        $source = $this->sources->get($key);
        if ($source === null) {
            // 422 rather than 404: the path exists and the caller's request is
            // malformed, and naming the registered keys is the one thing that
            // makes it fixable. An empty 200 would read as "you have no items",
            // which is the wrong answer to a typo.
            return Response::error(
                "Unknown inbox source '{$key}'. Registered sources: " . implode(', ', $this->sources->keys()),
                422
            );
        }

        // Defaults to true, and only an explicit falsey value turns it off, so a
        // malformed `?open=maybe` narrows to the safe reading rather than
        // silently widening to the caller's whole history.
        $openOnly = !self::isFalsey($query['open'] ?? null);

        $p = PaginationParams::fromQuery($query);
        $total = $source->count($tenantId, $profileId, $openOnly);
        $items = $source->list($tenantId, $profileId, $openOnly, $p->perPage, $p->offset);

        return Response::json([
            'data' => array_map(static fn (InboxItem $i): array => $i->toArray(), $items),
            'pagination' => $p->meta($total),
            // Echoed so a client rendering several sources can tell which
            // response it is holding without tracking the request.
            'source' => $key,
        ]);
    }

    private static function isFalsey(?string $value): bool
    {
        return $value !== null && in_array($value, ['0', 'false', 'no', 'off'], true);
    }

    /**
     * Merge query params from $_GET (production) and the path query string
     * (tests), mirroring the other list handlers.
     *
     * @return array<string, string>
     */
    private static function queryParams(Request $request): array
    {
        $query = [];
        foreach ($_GET as $k => $v) {
            if (is_string($k) && is_string($v)) {
                $query[$k] = $v;
            }
        }
        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            foreach ($parsed as $k => $v) {
                if (is_string($k) && is_string($v)) {
                    $query[$k] = $v;
                }
            }
        }

        return $query;
    }

    /**
     * Resolve (profileId, tenantId) from a session token — cookie first, then
     * Authorization: Bearer. Fail closed. Mirrors {@see InboxApiHandler}.
     *
     * @return array{0: int, 1: int}|null
     */
    private function resolveClaims(Request $request): ?array
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            $header = $request->getHeader('Authorization') ?? '';
            if (stripos($header, 'Bearer ') === 0) {
                $token = trim(substr($header, 7));
                if ($token !== '') {
                    $claims = $this->tokenValidator->validateAccessTokenFromBearer($token);
                }
            }
        }
        if ($claims === null) {
            return null;
        }

        $profileId = $claims['profile_id'] ?? null;
        $tenantId = $claims['active_tenant_id'] ?? null;
        if (!is_int($profileId) || $profileId <= 0 || !is_int($tenantId) || $tenantId < 0) {
            return null;
        }

        return [$profileId, $tenantId];
    }
}
