<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\MethodHandler;

/**
 * MCP tools/list handler (WC-001754c6, filtered WC-e8c4d228).
 *
 * Returns only the tools the caller is permitted to use. Open tools (no
 * requiredRole / requiredPermission) are visible to all callers including
 * unauthenticated ones. Protected tools are hidden when the bearer token is
 * absent or invalid, or when RoleChecker denies the required grant.
 *
 * Filtering is soft-auth: a bad or missing token never throws — it simply
 * limits the visible set to open tools. RBAC is still hard-enforced in
 * ToolsCallHandler when the caller tries to invoke a protected tool.
 */
final class ToolsListHandler implements MethodHandler
{
    public function __construct(
        private readonly ToolDeriver    $toolDeriver,
        private readonly RoleChecker    $roleChecker,
        private readonly TokenValidator $tokenValidator,
    ) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $principal = $bearerToken !== null
            ? $this->tokenValidator->validateBearerForMcp($bearerToken)
            : null;
        $tenantId = TenantContext::getTenantId();

        $accessMap = $this->toolDeriver->buildAccessMap();
        $tools     = [];
        foreach ($this->toolDeriver->deriveTools() as $tool) {
            $name   = (string) ($tool['name'] ?? '');
            $access = $accessMap[$name] ?? ['requiredRole' => null, 'requiredPermission' => null];
            if ($this->callerCanUse($access, $principal, $tenantId)) {
                $tools[] = $tool;
            }
        }

        return ['tools' => $tools];
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
            return $this->roleChecker->hasPermission($principal->userId, $requiredPermission, $tenantId);
        }
        return $this->roleChecker->hasRole($principal->userId, $requiredRole, $tenantId);
    }
}
