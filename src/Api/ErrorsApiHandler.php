<?php

declare(strict_types=1);

namespace Whity\Api;

use Throwable;
use Whity\Core\Observability\ErrorGroupRepository;
use Whity\Core\Observability\ErrorTrackerConfig;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Settings\GlobalSettingsRepository;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * The built-in error inbox and its DSN credential (WC-error-tracking).
 *
 * OPERATOR-ONLY, gated on `settings:manage` — the same permission that governs
 * the global platform settings this belongs to. Error tracking is configured
 * deployment-wide and captures errors from every tenant plus errors that belong
 * to no tenant at all (boot, queue, cron), so exposing it to a tenant admin
 * would leak across the isolation boundary this codebase enforces everywhere
 * else.
 *
 * The DSN is handled exactly like the SMTP password: WRITE-ONLY. It is a
 * credential (it embeds a project key), so it is stored encrypted under a
 * reserved key outside the settings registry, and reads report only whether one
 * exists. It is never echoed back — not to the admin UI, not to anyone.
 */
final class ErrorsApiHandler
{
    private const PERMISSION = CorePermissions::SETTINGS_MANAGE;
    private const MAX_PAGE = 100;

    public function __construct(
        private readonly ErrorGroupRepository $groups,
        private readonly GlobalSettingsRepository $globals,
        private readonly EncryptedSecretStore $secrets,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    /** GET /api/v1/errors?status=unresolved&limit=&offset= */
    public function list(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        $status = $this->query($request, 'status');
        if ($status !== null && !in_array($status, ['unresolved', 'resolved', 'ignored'], true)) {
            return Response::error('status must be unresolved, resolved or ignored', 422);
        }

        $limit = (int) ($this->query($request, 'limit') ?? '50');
        $limit = max(1, min(self::MAX_PAGE, $limit));
        $offset = max(0, (int) ($this->query($request, 'offset') ?? '0'));

        try {
            return Response::json([
                'data' => $this->groups->list($status, $limit, $offset),
                'pagination' => [
                    'total' => $this->groups->countByStatus($status),
                    'limit' => $limit,
                    'offset' => $offset,
                ],
                'counts' => [
                    'unresolved' => $this->groups->countByStatus('unresolved'),
                    'resolved' => $this->groups->countByStatus('resolved'),
                    'ignored' => $this->groups->countByStatus('ignored'),
                ],
            ], 200);
        } catch (Throwable $e) {
            error_log('[ErrorsApiHandler] list failed: ' . $e->getMessage());

            return Response::error('Failed to list errors', 500);
        }
    }

    /**
     * GET /api/v1/errors/{id} — the full record, including the stack.
     *
     * @param array<string, mixed> $params
     */
    public function get(Request $request, array $params = []): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        $group = $this->groups->find((int) ($params['id'] ?? 0));
        if ($group === null) {
            return Response::error('Error not found', 404);
        }

        return Response::json(['data' => $group], 200);
    }

    /**
     * PATCH /api/v1/errors/{id} — resolve / ignore / reopen.
     *
     * @param array<string, mixed> $params
     */
    public function update(Request $request, array $params = []): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        $body = JsonBody::parsed($request);
        $status = $body['status'] ?? null;
        if (!is_string($status) || !in_array($status, ['unresolved', 'resolved', 'ignored'], true)) {
            return Response::error('Validation failed', 422, [
                'status' => 'status must be unresolved, resolved or ignored.',
            ]);
        }

        $id = (int) ($params['id'] ?? 0);
        if (!$this->groups->setStatus($id, $status)) {
            return Response::error('Error not found', 404);
        }

        return Response::json(['data' => $this->groups->find($id)], 200);
    }

    /**
     * GET /api/v1/settings/error-tracking — the non-secret configuration plus
     * whether a DSN is stored. Mirrors GET /mail/status.
     */
    public function status(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        $stored = $this->globals->get(ErrorTrackerConfig::DSN_SETTING_KEY);

        return Response::json([
            // Never the DSN itself — only its presence.
            'has_dsn' => is_string($stored) && $stored !== '',
            'counts' => [
                'unresolved' => $this->groups->countByStatus('unresolved'),
                'total' => $this->groups->countByStatus(null),
            ],
        ], 200);
    }

    /**
     * PUT /api/v1/settings/error-tracking/dsn — set or clear the DSN.
     *
     * Write-only: 204 with nothing to return. Clients re-read `has_dsn`.
     */
    public function setDsn(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        $body = JsonBody::parsed($request);
        if (!array_key_exists('dsn', $body)) {
            return Response::error('Request body must include a "dsn" field (null to clear)', 400);
        }

        $dsn = $body['dsn'];
        if ($dsn !== null && !is_string($dsn)) {
            return Response::error('Validation failed', 422, ['dsn' => 'dsn must be a string or null.']);
        }

        try {
            if ($dsn === null || trim($dsn) === '') {
                $this->globals->delete(ErrorTrackerConfig::DSN_SETTING_KEY);
            } else {
                $this->globals->set(
                    ErrorTrackerConfig::DSN_SETTING_KEY,
                    $this->secrets->encrypt(trim($dsn))
                );
            }

            return Response::json([], 204);
        } catch (Throwable $e) {
            // Never log the DSN or any crypto detail.
            error_log('[ErrorsApiHandler] setDsn failed: ' . $e->getMessage());

            return Response::error('Failed to store the DSN', 500);
        }
    }

    /**
     * Operator-only: `settings:manage` AND the system tenant, mirroring
     * MailSettingsApiHandler. Error tracking is configured deployment-wide and
     * captures every tenant's errors, so a tenant admin must never reach it.
     */
    private function authorize(Request $request): ?Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;

        if ($userId === null
            || !$this->roleChecker->hasPermissionForProfile($userId, self::PERMISSION, $tenantId)
        ) {
            return Response::error('Insufficient permissions', 403, ['required' => self::PERMISSION]);
        }

        if ($tenantId !== SettingsService::SYSTEM_TENANT_ID) {
            return Response::error('Error tracking is managed by the system tenant only', 403);
        }

        return null;
    }

    /** $_GET in production, the path query string in tests (as AuditLogApiHandler does). */
    private function query(Request $request, string $name): ?string
    {
        $value = $_GET[$name] ?? null;
        if (is_string($value) && $value !== '') {
            return $value;
        }

        $qs = parse_url($request->getPath(), PHP_URL_QUERY);
        if (is_string($qs) && $qs !== '') {
            parse_str($qs, $parsed);
            $fromPath = $parsed[$name] ?? null;
            if (is_string($fromPath) && $fromPath !== '') {
                return $fromPath;
            }
        }

        return null;
    }
}
