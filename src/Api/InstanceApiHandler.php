<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\CoreVersion;
use Whity\Core\Instance\InstanceService;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;

/**
 * Instance metadata + first-run setup (WC-instance-first-run).
 *
 * Two endpoints drive the guided first-run experience:
 *
 *  - GET  /api/v1/instance/status         → { configured, version }. Registered
 *    with NO required permission (any authenticated caller): the web app reads it
 *    right after sign-in to decide whether to route an eligible operator into the
 *    `/onboarding` wizard. It exposes only whether setup is done and the running
 *    core version — no sensitive data.
 *
 *  - POST /api/v1/instance/complete-setup → marks first-run setup complete and
 *    returns { configured: true }. Gated on `settings:manage` at the route AND,
 *    here, on the SYSTEM TENANT (id 0): the first-run flag is an operator/global
 *    concern (mirroring how global settings are written), so a regular tenant —
 *    even one whose role happens to carry settings:manage — must never flip
 *    instance-wide state. Idempotent.
 *
 * Never leaks internal errors to the client (WC-186): the only non-2xx path is a
 * typed 422 with a generic, caller-actionable message.
 */
final class InstanceApiHandler
{
    public function __construct(private readonly InstanceService $instance)
    {
    }

    /**
     * GET /api/v1/instance/status — first-run + version probe.
     */
    public function status(Request $request): Response
    {
        return Response::json([
            'configured' => $this->instance->isConfigured(),
            'version' => CoreVersion::VERSION,
        ], 200);
    }

    /**
     * POST /api/v1/instance/complete-setup — mark the guided first-run complete.
     */
    public function completeSetup(Request $request): Response
    {
        // The route requires settings:manage; additionally require the system
        // tenant. First-run completion is instance-wide operator state, so it is
        // written only from the system tenant (id 0) — the same asymmetry the
        // global-settings surface enforces (a regular tenant has no global layer).
        if (TenantContext::getTenantId() !== SettingsService::SYSTEM_TENANT_ID) {
            return Response::error(
                'First-run setup can only be completed from the system tenant.',
                422
            );
        }

        $this->instance->markConfigured();

        return Response::json(['configured' => true], 200);
    }
}
