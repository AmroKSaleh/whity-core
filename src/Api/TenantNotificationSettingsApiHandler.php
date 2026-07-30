<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\Notification\TenantNotificationSettingsRepository;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Security\EncryptedSecretStore;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;

/**
 * Admin API for a tenant's per-channel SENDER configuration (WC-notifications).
 *
 *   GET    /api/v1/notification-settings                        list channels (redacted)
 *   PUT    /api/v1/notification-settings/{channel}              upsert config
 *   PUT    /api/v1/notification-settings/{channel}/credentials  set/clear creds (write-only)
 *   DELETE /api/v1/notification-settings/{channel}              remove a channel's config
 *
 * PER-TENANT: every operation is scoped to the caller's tenant (from
 * TenantContext) and requires `settings:manage` — a tenant admin manages their
 * OWN tenant's sender config (unlike the system-tenant-only global mail
 * settings). Provider credentials are WRITE-ONLY: they are encrypted at rest via
 * {@see EncryptedSecretStore} and NEVER returned — reads expose only a
 * `has_credentials` flag. Raw exceptions are never surfaced to the client.
 */
final class TenantNotificationSettingsApiHandler
{
    public function __construct(
        private readonly RoleChecker $roleChecker,
        private readonly TenantNotificationSettingsRepository $repo,
        private readonly EncryptedSecretStore $secrets,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * GET /api/v1/notification-settings — the caller tenant's channels (redacted).
     *
     * @param array<string, mixed> $params
     */
    public function list(Request $request, array $params = []): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }

        return Response::json(['data' => $this->repo->listForTenant($auth['tenantId'])], 200);
    }

    /**
     * PUT /api/v1/notification-settings/{channel} — upsert a channel's non-secret
     * sender config (transport, from/reply-to, provider config, enabled).
     *
     * @param array<string, mixed> $params
     */
    public function updateChannel(Request $request, array $params = []): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $channel = self::channelParam($params);
        if ($channel === null) {
            return Response::error('A valid channel is required', 422);
        }

        $body = JsonBody::parsed($request);
        foreach (['from_address', 'reply_to'] as $emailField) {
            $value = $body[$emailField] ?? null;
            if (is_string($value) && $value !== '' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                return Response::error('Validation failed', 422, [$emailField => 'must be a valid email address']);
            }
        }
        $config = $body['config'] ?? [];
        if (!is_array($config)) {
            return Response::error('Validation failed', 422, ['config' => 'must be an object']);
        }

        try {
            $this->repo->upsertConfig($auth['tenantId'], $channel, [
                'transport'    => self::str($body['transport'] ?? null),
                'from_address' => self::str($body['from_address'] ?? null),
                'from_name'    => self::str($body['from_name'] ?? null),
                'reply_to'     => self::str($body['reply_to'] ?? null),
                'config'       => $config,
                'enabled'      => !array_key_exists('enabled', $body) || filter_var($body['enabled'], FILTER_VALIDATE_BOOL),
            ]);

            return Response::json(['data' => $this->repo->findForChannel($auth['tenantId'], $channel)], 200);
        } catch (\Throwable $e) {
            $this->logger->error('[TenantNotificationSettings] update failed: ' . $e->getMessage());
            return Response::error('Failed to save sender configuration', 500);
        }
    }

    /**
     * PUT /api/v1/notification-settings/{channel}/credentials — set or clear a
     * channel's provider credentials (write-only, encrypted at rest). Body
     * `{ "credentials": "<secret>" | null }`; null/empty clears. Returns 204.
     *
     * @param array<string, mixed> $params
     */
    public function setCredentials(Request $request, array $params = []): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $channel = self::channelParam($params);
        if ($channel === null) {
            return Response::error('A valid channel is required', 422);
        }

        $body = JsonBody::parsed($request);
        if (!array_key_exists('credentials', $body)) {
            return Response::error('Request body must include a "credentials" field (null to clear)', 400);
        }
        $credentials = $body['credentials'];
        if ($credentials !== null && !is_string($credentials)) {
            return Response::error('Validation failed', 422, ['credentials' => 'must be a string or null']);
        }

        try {
            $encrypted = ($credentials === null || $credentials === '')
                ? null
                : $this->secrets->encrypt($credentials);
            $this->repo->setCredentials($auth['tenantId'], $channel, $encrypted);

            // 204: credentials are write-only; clients re-read has_credentials from GET.
            return new Response(204, '', ['Content-Type' => 'application/json']);
        } catch (\Throwable $e) {
            // Never log the secret or crypto detail.
            $this->logger->error('[TenantNotificationSettings] setCredentials failed: ' . $e->getMessage());
            return Response::error('Failed to store credentials', 500);
        }
    }

    /**
     * DELETE /api/v1/notification-settings/{channel} — remove a channel's config.
     *
     * @param array<string, mixed> $params
     */
    public function deleteChannel(Request $request, array $params = []): Response
    {
        $auth = $this->authorize($request);
        if ($auth instanceof Response) {
            return $auth;
        }
        $channel = self::channelParam($params);
        if ($channel === null) {
            return Response::error('A valid channel is required', 422);
        }

        if (!$this->repo->delete($auth['tenantId'], $channel)) {
            return Response::error('Channel configuration not found', 404);
        }

        return new Response(204, '', ['Content-Type' => 'application/json']);
    }

    /**
     * Resolve the caller's tenant + require settings:manage for it.
     *
     * @return array{tenantId: int, userId: int}|Response
     */
    private function authorize(Request $request): array|Response
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
            || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::SETTINGS_MANAGE, $tenantId)
        ) {
            return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::SETTINGS_MANAGE]);
        }

        return ['tenantId' => $tenantId, 'userId' => $userId];
    }

    /**
     * @param array<string, mixed> $params
     */
    private static function channelParam(array $params): ?string
    {
        $channel = isset($params['channel']) && is_string($params['channel']) ? trim($params['channel']) : '';

        return preg_match('/^[a-z0-9_]{1,64}$/i', $channel) === 1 ? $channel : null;
    }

    private static function str(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
