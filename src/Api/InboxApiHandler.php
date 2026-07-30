<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Notification\NotificationRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\PaginationParams;

/**
 * HTTP handler for the caller's in-app notification INBOX (WC-notifications).
 *
 *   GET  /api/v1/me/notifications                 — paginated inbox + unread count
 *   GET  /api/v1/me/notifications/unread-count     — just the unread badge count
 *   POST /api/v1/me/notifications/{id}/read        — mark one read (204)
 *   POST /api/v1/me/notifications/read-all          — mark all read ({marked})
 *
 * Session-gated (cookie OR Bearer access token) and strictly scoped to the
 * caller's OWN (tenant, profile): every repository call binds both the tenant
 * and the caller's recipient profile id, so a user can only ever read or mutate
 * their own notifications. No RBAC permission is required (self-service, like
 * /api/me/sessions); admin-facing management permissions are a separate task.
 */
final class InboxApiHandler
{
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly NotificationRepository $notifications,
    ) {}

    /**
     * GET /api/v1/me/notifications — the caller's inbox, newest first, with
     * pagination and the current unread count. `?unread=1` restricts to unread.
     *
     * @param array<string, mixed> $params
     */
    public function list(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        $query = self::queryParams($request);
        $unreadOnly = self::isTruthy($query['unread'] ?? null);
        $p = PaginationParams::fromQuery($query);

        $rows = $this->notifications->listForRecipient($tenantId, $profileId, $unreadOnly, $p->perPage, $p->offset);
        $total = $this->notifications->countForRecipient($tenantId, $profileId, $unreadOnly);

        return Response::json([
            'data'         => array_map(static fn (array $r): array => self::toPublic($r), $rows),
            'pagination'   => $p->meta($total),
            'unread_count' => $this->notifications->unreadCount($tenantId, $profileId),
        ], 200);
    }

    /**
     * GET /api/v1/me/notifications/unread-count — the badge count only (cheap poll).
     *
     * @param array<string, mixed> $params
     */
    public function unreadCount(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        return Response::json(['unread_count' => $this->notifications->unreadCount($tenantId, $profileId)], 200);
    }

    /**
     * POST /api/v1/me/notifications/{id}/read — mark one of the caller's
     * notifications read (idempotent). 404 when the id is missing or not owned.
     *
     * @param array<string, mixed> $params
     */
    public function markRead(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A valid notification id is required', 422);
        }

        if (!$this->notifications->markRead($tenantId, $profileId, $id)) {
            return Response::error('Notification not found', 404);
        }

        return new Response(204, '', ['Content-Type' => 'application/json']);
    }

    /**
     * POST /api/v1/me/notifications/read-all — mark all the caller's unread
     * notifications read; returns how many were flipped.
     *
     * @param array<string, mixed> $params
     */
    public function markAllRead(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        return Response::json(['marked' => $this->notifications->markAllRead($tenantId, $profileId)], 200);
    }

    /**
     * Shape a repository row for the API (internal recipient/tenant columns are
     * not exposed — the endpoint is already scoped to the caller).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function toPublic(array $row): array
    {
        return [
            'id'         => (int) $row['id'],
            'type'       => (string) $row['type'],
            'subject'    => (string) $row['subject'],
            'body'       => (string) $row['body'],
            'data'       => is_array($row['data'] ?? null) ? $row['data'] : [],
            'read'       => ($row['read_at'] ?? null) !== null,
            'read_at'    => isset($row['read_at']) && $row['read_at'] !== null ? (string) $row['read_at'] : null,
            'created_at' => isset($row['created_at']) && $row['created_at'] !== null ? (string) $row['created_at'] : null,
        ];
    }

    private static function isTruthy(?string $value): bool
    {
        return $value !== null && in_array($value, ['1', 'true', 'yes', 'on'], true);
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
     * Authorization: Bearer access token. Fail closed.
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
        $tenantId  = $claims['active_tenant_id'] ?? null;
        if (!is_int($profileId) || $profileId <= 0 || !is_int($tenantId) || $tenantId < 0) {
            return null;
        }

        return [$profileId, $tenantId];
    }
}
