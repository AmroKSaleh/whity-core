<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\TokenValidator;
use Whity\Core\Notification\NotificationPreferenceRepository;
use Whity\Core\Notification\NotificationPreferenceResolver;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * HTTP handler for the caller's own notification PREFERENCES (WC-notifications).
 *
 *   GET /api/v1/me/notification-preferences  — the caller's toggles + which
 *                                              types are transactional (locked)
 *   PUT /api/v1/me/notification-preferences  — upsert a batch of toggles
 *
 * Session-gated and strictly self-scoped to the caller's (tenant, profile): the
 * repository binds both, so a caller only ever reads or writes their OWN
 * preferences. No RBAC permission (self-service, like /api/me/notifications).
 * Transactional types (security/account/auth/…) cannot be disabled — the API
 * rejects a disable on such a type, and the resolver always delivers them anyway.
 */
final class NotificationPreferencesApiHandler
{
    /** Cap a single PUT so a caller cannot flood the table. */
    private const MAX_PREFERENCES_PER_REQUEST = 200;

    public function __construct(
        private readonly TokenValidator $tokenValidator,
        private readonly NotificationPreferenceRepository $repo,
        private readonly NotificationPreferenceResolver $resolver,
    ) {}

    /**
     * GET /api/v1/me/notification-preferences.
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

        return Response::json([
            'data'                   => $this->repo->listForProfile($tenantId, $profileId),
            'transactional_prefixes' => $this->resolver->transactionalPrefixes(),
        ], 200);
    }

    /**
     * PUT /api/v1/me/notification-preferences — upsert a batch of (type, channel,
     * enabled) toggles. All entries are validated first (no partial writes); a
     * disable on a transactional type is rejected.
     *
     * @param array<string, mixed> $params
     */
    public function update(Request $request, array $params = []): Response
    {
        $ctx = $this->resolveClaims($request);
        if ($ctx === null) {
            return Response::error('Unauthenticated', 401);
        }
        [$profileId, $tenantId] = $ctx;

        $body = json_decode($request->getBody(), true);
        if (!is_array($body) || !isset($body['preferences']) || !is_array($body['preferences'])) {
            return Response::error('A "preferences" array is required', 422);
        }
        if (count($body['preferences']) > self::MAX_PREFERENCES_PER_REQUEST) {
            return Response::error('Too many preferences in one request', 422);
        }

        // Validate everything before writing anything.
        $entries = [];
        foreach ($body['preferences'] as $raw) {
            if (!is_array($raw)) {
                return Response::error('Each preference must be an object', 422);
            }
            $type = isset($raw['type']) && is_string($raw['type']) ? trim($raw['type']) : '';
            $channel = isset($raw['channel']) && is_string($raw['channel']) ? trim($raw['channel']) : '';
            if ($type === '' || $channel === '') {
                return Response::error('Each preference needs a non-empty type and channel', 422);
            }
            $enabled = filter_var($raw['enabled'] ?? true, FILTER_VALIDATE_BOOL);
            if (!$enabled && $this->resolver->isTransactional($type)) {
                return Response::error('Transactional notifications cannot be disabled: ' . $type, 422);
            }
            $entries[] = ['type' => $type, 'channel' => $channel, 'enabled' => $enabled];
        }

        foreach ($entries as $entry) {
            $this->repo->set($tenantId, $profileId, $entry['type'], $entry['channel'], $entry['enabled']);
        }

        return Response::json([
            'data'                   => $this->repo->listForProfile($tenantId, $profileId),
            'transactional_prefixes' => $this->resolver->transactionalPrefixes(),
        ], 200);
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
