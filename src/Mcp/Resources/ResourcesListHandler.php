<?php

declare(strict_types=1);

namespace Whity\Mcp\Resources;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP resources/list handler (WC-30513809, filtered WC-e8c4d228).
 *
 * Returns only the resources and resourceTemplates the caller is permitted to
 * read. Open resources (no requiredRole / requiredPermission) are visible to
 * all callers including unauthenticated ones. Protected resources are hidden
 * when the bearer token is absent or invalid, or when RoleChecker denies the
 * required grant.
 *
 * Filtering is soft-auth: a bad or missing token never throws — it simply
 * limits the visible set to open resources. RBAC is still hard-enforced in
 * ResourcesReadHandler when the caller tries to read a protected resource.
 */
final class ResourcesListHandler implements MethodHandler
{
    public function __construct(
        private readonly ResourceDeriver $resourceDeriver,
        private readonly RoleChecker     $roleChecker,
        private readonly TokenValidator  $tokenValidator,
    ) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $principal = $bearerToken !== null
            ? $this->tokenValidator->validateMcpToken($bearerToken)
            : null;
        $tenantId = TenantContext::getTenantId();

        $accessMap = $this->resourceDeriver->buildAccessMap();
        $all       = $this->resourceDeriver->deriveResources();

        $resources = array_values(array_filter(
            $all['resources'],
            fn (array $r): bool => $this->callerCanUse(
                $accessMap[(string) ($r['uri'] ?? '')] ?? [],
                $principal,
                $tenantId,
            ),
        ));

        $resourceTemplates = array_values(array_filter(
            $all['resourceTemplates'],
            fn (array $r): bool => $this->callerCanUse(
                $accessMap[(string) ($r['uriTemplate'] ?? '')] ?? [],
                $principal,
                $tenantId,
            ),
        ));

        return ['resources' => $resources, 'resourceTemplates' => $resourceTemplates];
    }

    /** @param array<string, mixed> $access */
    private function callerCanUse(array $access, ?McpPrincipal $principal, ?int $tenantId): bool
    {
        $requiredPermission = is_string($access['requiredPermission'] ?? null) ? $access['requiredPermission'] : null;
        $requiredRole       = is_string($access['requiredRole'] ?? null) ? $access['requiredRole'] : null;

        if ($requiredPermission === null && $requiredRole === null) {
            return true;
        }
        if ($principal === null || $tenantId === null) {
            return false;
        }
        if ($requiredPermission !== null) {
            return $this->roleChecker->hasPermissionForProfile($principal->userId, $requiredPermission, $tenantId);
        }
        return $this->roleChecker->hasRoleForProfile($principal->userId, $requiredRole, $tenantId);
    }
}
