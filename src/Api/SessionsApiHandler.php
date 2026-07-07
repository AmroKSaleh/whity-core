<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\SessionService;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * HTTP handler for interactive session management (WC-f-sessions-table).
 *
 *   GET    /api/v1/me/sessions        — list the caller's active sessions
 *   DELETE /api/v1/me/sessions/{id}   — revoke one session
 *   DELETE /api/v1/me/sessions        — revoke all OTHER sessions (keep current)
 *
 * Session-gated (cookie OR Bearer access token) and strictly scoped to the
 * caller's OWN profile+tenant. Revocation blacklists the target session's
 * current jti(s), so the live access token dies immediately (via the existing
 * revoked_tokens check) and the refresh can no longer rotate. Native-device
 * credentials are managed separately (see DeviceApiHandler); this surface is
 * interactive logins only.
 */
final class SessionsApiHandler
{
    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly SessionService $sessions,
    ) {}

    /**
     * GET /api/v1/me/sessions — list the caller's active sessions (current flagged).
     *
     * @param array<string, mixed> $params
     */
    public function list(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId, $jti] = $ctx;

        return Response::json([
            'sessions' => $this->sessions->listForProfile($profileId, $tenantId, $jti !== '' ? $jti : null),
        ], 200);
    }

    /**
     * DELETE /api/v1/me/sessions/{id} — revoke one of the caller's sessions.
     *
     * @param array<string, mixed> $params
     */
    public function revoke(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A valid session id is required', 422);
        }

        if (!$this->sessions->revokeById($id, $profileId, $tenantId)) {
            return Response::error('Session not found', 404);
        }

        return new Response(204, '', ['Content-Type' => 'application/json']);
    }

    /**
     * DELETE /api/v1/me/sessions — revoke every OTHER session, keeping the
     * caller's current one. Returns the number revoked.
     *
     * @param array<string, mixed> $params
     */
    public function revokeOthers(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId, $jti] = $ctx;
        if ($jti === '') {
            // Cannot identify the current session to keep — refuse rather than
            // risk revoking the caller's own session.
            return Response::error('Unauthenticated', 401);
        }

        return Response::json(['revoked' => $this->sessions->revokeAllExcept($profileId, $tenantId, $jti)], 200);
    }

    /**
     * Resolve (profileId, tenantId, currentAccessJti) from a session token —
     * cookie first, then Authorization: Bearer access token. Fail closed.
     *
     * @return array{0: int, 1: int, 2: string}|null
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
        $jti = isset($claims['jti']) && is_string($claims['jti']) ? $claims['jti'] : '';

        return [$profileId, $tenantId, $jti];
    }
}
