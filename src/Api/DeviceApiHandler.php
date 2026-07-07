<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\DeviceCredentialService;
use Whity\Auth\TokenValidator;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\InputLimits;

/**
 * HTTP handler for device (native-client) enrollment management — WC-b-device-tokens.
 *
 *   POST   /api/v1/devices        — enroll a device, return its long-lived credential (once)
 *   GET    /api/v1/devices        — list the caller's active devices
 *   DELETE /api/v1/devices/{id}   — revoke a device (per-device server-side revocation)
 *
 * These management endpoints are performed by an AUTHENTICATED user acting on
 * their OWN devices (self-service, no special permission — mirrors 2FA
 * enrollment). The session is resolved from either the access-token cookie (web
 * UI) OR an Authorization: Bearer access token (a native client self-enrolling
 * after an interactive login), so both flows work. Everything is scoped to the
 * caller's own profile_id + active_tenant_id.
 *
 * The credential is later exchanged for a short-lived session at
 * POST /api/v1/devices/token (public; handled by AuthHandler::handleDeviceTokenExchange).
 */
final class DeviceApiHandler
{
    /** Accepted platform hint values (informational; not a security control). */
    private const PLATFORMS = ['windows', 'macos', 'linux', 'ios', 'android', 'other'];

    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly DeviceCredentialService $devices,
    ) {}

    /**
     * POST /api/v1/devices — enroll a device.
     *
     * Body: {"name": string, "platform": string, "fingerprint"?: string}
     * Returns 201 with {id, credential, name, platform, expires_at}. The
     * credential is returned ONCE and never stored in plaintext.
     *
     * @param array<string, mixed> $params
     */
    public function register(Request $request, array $params = []): Response
    {
        $identity = $this->resolveIdentity($request);
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId, $email] = $identity;

        $body = json_decode($request->getBody(), true);
        if (!is_array($body)) {
            return Response::error('Invalid JSON body', 400);
        }

        $name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
        $platform = isset($body['platform']) && is_string($body['platform']) ? strtolower(trim($body['platform'])) : '';
        $fingerprint = isset($body['fingerprint']) && is_string($body['fingerprint']) ? trim($body['fingerprint']) : null;

        if ($name === '') {
            return Response::error('name is required', 422);
        }
        if (!in_array($platform, self::PLATFORMS, true)) {
            return Response::error('platform must be one of: ' . implode(', ', self::PLATFORMS), 422);
        }
        if ($tooLong = InputLimits::firstViolation([
            'name' => [$name, InputLimits::NAME_MAX],
            'fingerprint' => [$fingerprint, InputLimits::NAME_MAX],
        ])) {
            return $tooLong;
        }

        $issued = $this->devices->issue($profileId, $tenantId, $email, $name, $platform, $fingerprint);

        return Response::json([
            'id'         => $issued['id'],
            'credential' => $issued['token'],
            'name'       => $name,
            'platform'   => $platform,
            'expires_at' => date('c', strtotime($issued['expires_at']) ?: 0),
        ], 201);
    }

    /**
     * GET /api/v1/devices — list the caller's active devices.
     *
     * @param array<string, mixed> $params
     */
    public function list(Request $request, array $params = []): Response
    {
        $identity = $this->resolveIdentity($request);
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $identity;

        return Response::json(['devices' => $this->devices->listForProfile($profileId, $tenantId)], 200);
    }

    /**
     * DELETE /api/v1/devices/{id} — revoke a device (ownership-gated).
     *
     * @param array<string, mixed> $params
     */
    public function revoke(Request $request, array $params = []): Response
    {
        $identity = $this->resolveIdentity($request);
        if ($identity === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $identity;

        $id = (int) ($params['id'] ?? 0);
        if ($id <= 0) {
            return Response::error('A valid device id is required', 422);
        }

        if (!$this->devices->revokeById($id, $profileId, $tenantId)) {
            return Response::error('Device not found', 404);
        }

        return new Response(204, '', ['Content-Type' => 'application/json']);
    }

    /**
     * Resolve the caller's (profileId, tenantId, email) from a session token —
     * cookie first, then Authorization: Bearer access token. Fail closed.
     *
     * @return array{0: int, 1: int, 2: string}|null
     */
    private function resolveIdentity(Request $request): ?array
    {
        $claims = $this->tokenValidator->validateAccessToken();
        if ($claims === null) {
            $bearer = $this->bearerToken($request);
            if ($bearer !== null) {
                $claims = $this->tokenValidator->validateAccessTokenFromBearer($bearer);
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
        $email = isset($claims['email']) && is_string($claims['email']) ? $claims['email'] : '';

        return [$profileId, $tenantId, $email];
    }

    /** Extract a Bearer token from the Authorization header, or null. */
    private function bearerToken(Request $request): ?string
    {
        $header = $request->getHeader('Authorization') ?? '';
        if (stripos($header, 'Bearer ') === 0) {
            $token = trim(substr($header, 7));
            return $token !== '' ? $token : null;
        }
        return null;
    }
}
