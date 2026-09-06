<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Feature\FeatureService;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Request;
use Whity\Core\Response;

/**
 * Which major subsystems this instance has, and which are available here.
 *
 * READS THE CALLER'S OWN TENANT, from the request's tenant context rather than
 * a path parameter. There is no target-tenant form on purpose: "what can this
 * tenant do" is a question an admin asks about their own tenant, and the
 * operator surface that answers it about somebody else's is the entitlements
 * endpoint, which already exists and is already gated on the system tenant.
 * Adding a second cross-tenant reader here would be a second thing to keep
 * correct for no new answer.
 *
 * Gated on `settings:read` because that is what these are: the switches already
 * live in settings, and this endpoint reports the same values composed with the
 * tenant's plan. A separate permission would let the two disagree about who may
 * see the same fact.
 *
 * WHY IT REPORTS THREE BOOLEANS PER FEATURE. "Off" is not one condition — an
 * operator can have the subsystem switched off, or the tenant's plan can fail to
 * include it — and the two need different actions from different people. A
 * single flag would send whoever is looking to the wrong place.
 */
final class FeaturesApiHandler
{
    public function __construct(private readonly FeatureService $features)
    {
    }

    public function list(Request $request): Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            // The middleware resolves this for every authenticated request, so
            // its absence is a wiring fault rather than a caller mistake. Said
            // plainly instead of defaulting to the system tenant, which would
            // answer for the wrong tenant and look like a working endpoint.
            return Response::error('No tenant context for this request', 400);
        }

        return Response::json(['data' => $this->features->all($tenantId)]);
    }
}
