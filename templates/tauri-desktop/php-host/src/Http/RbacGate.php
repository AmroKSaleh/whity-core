<?php

declare(strict_types=1);

namespace Whity\Http;

use Whity\PluginHost\OfflineIdentity;
use Whity\Sdk\Http\Response;
use Whity\Sdk\Rbac\PermissionResolver;

/**
 * The offline host's request-dispatch RBAC gate — mirrors production's
 * RbacMiddleware::handle() exactly in check order and 403 JSON shape (a role
 * failure omits the `required` key; a permission failure adds it — this
 * asymmetry is production's actual behavior, matched here rather than
 * "improved"), but skips JWT extraction entirely: there is one fixed
 * OfflineIdentity, not a per-request authenticated caller.
 *
 * A route with neither requiredRole nor requiredPermission stays fail-open,
 * exactly like production — a plugin route declaring no requirement is
 * intentionally public even offline.
 */
final class RbacGate
{
    public function __construct(
        private readonly PermissionResolver $resolver,
        private readonly OfflineIdentity $identity,
    ) {
    }

    public function authorize(?string $requiredRole, ?string $requiredPermission): ?Response
    {
        if ($requiredRole === null && $requiredPermission === null) {
            return null;
        }

        if ($requiredRole !== null
            && !$this->resolver->hasRole($this->identity->profileId, $this->identity->tenantId, $requiredRole)
        ) {
            return $this->forbidden();
        }

        if ($requiredPermission !== null
            && !$this->resolver->hasPermission($this->identity->profileId, $this->identity->tenantId, $requiredPermission)
        ) {
            return $this->forbidden($requiredPermission);
        }

        return null;
    }

    private function forbidden(?string $requiredPermission = null): Response
    {
        $body = ['error' => 'Insufficient permissions'];
        if ($requiredPermission !== null) {
            $body['required'] = $requiredPermission;
        }

        return Response::json($body, 403);
    }
}
