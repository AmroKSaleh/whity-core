<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\CoreVersion;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Update\LatestReleaseCheck;
use Whity\Sdk\Sdk;

/**
 * Platform version state over HTTP (WHIT-587).
 *
 * "What version am I running, and is there a newer one?" was answerable only
 * from a shell (`update:check`). On a white-label platform the operator is the
 * CUSTOMER, who may well have no shell on their own deployment — so this is a
 * product surface, not a sysadmin task.
 *
 * OPERATOR-ONLY, gated on `settings:manage` AND the system tenant, mirroring
 * ErrorsApiHandler: version state describes the whole DEPLOYMENT, so on a
 * shared install a tenant admin has no business reading it (and no way to act
 * on it — updating is a host-level operation).
 *
 * READ-ONLY by design. There is deliberately no "apply update" action here: a
 * one-click apply cannot mean the same thing for a source checkout (fetch,
 * check out, migrate, rebuild the frontend) as for an image deployment (whose
 * running container cannot replace its own immutable image), and an apply
 * button in front of an unreliable pre-flight is just a one-click outage. The
 * runbook stays docs/wiki/Core-Update.md.
 *
 * The FRONTEND's build identity is deliberately NOT reported here — the web
 * tier serves it itself (`GET /web-build`), because only the process that
 * loaded a bundle knows which bundle it loaded. See that route for why.
 */
final class PlatformVersionApiHandler
{
    private const PERMISSION = CorePermissions::SETTINGS_MANAGE;

    public function __construct(
        private readonly RoleChecker $roleChecker,
        private readonly LatestReleaseCheck $releaseCheck,
    ) {
    }

    /**
     * GET /api/v1/platform/version — what this deployment is running.
     *
     * Local state only: no network call, so it stays answerable on an
     * air-gapped deployment and cheap enough to poll from an admin screen.
     */
    public function version(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        return Response::json([
            'core_version' => CoreVersion::VERSION,
            // The plugin SDK contract version: what installed plugins declare
            // their sdk-constraint against. A plugin refusing to load after a
            // core update is nearly always this number moving, and until now
            // it appeared in no endpoint at all.
            'sdk_version' => Sdk::VERSION,
            'php_version' => PHP_VERSION,
        ], 200);
    }

    /**
     * GET /api/v1/platform/version/latest — is there a newer release?
     *
     * Reaches out to the release stream, so it is a separate route from the
     * local snapshot above: an admin screen can render instantly and let this
     * one resolve (or fail) on its own.
     *
     * Always 200 on a reachable verdict INCLUDING `check_failed`. The endpoint
     * answered truthfully — "I could not tell" is information, and an HTTP
     * error would collapse it into "up to date" for every naive caller.
     */
    public function latest(Request $request): Response
    {
        if (($denied = $this->authorize($request)) !== null) {
            return $denied;
        }

        return Response::json($this->releaseCheck->run()->toArray(), 200);
    }

    /**
     * Operator-only: `settings:manage` AND the system tenant.
     *
     * The route registration already demands the permission; re-checking here
     * keeps the handler honest when it is called directly (tests, CLI wiring)
     * and is where the system-tenant half — which the router cannot express —
     * is enforced.
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
            return Response::error('Platform version state is readable by the system tenant only', 403);
        }

        return null;
    }
}
