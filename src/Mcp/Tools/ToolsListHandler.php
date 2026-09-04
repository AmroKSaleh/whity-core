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
        // SDK 1.43. Optional so every existing construction site keeps working:
        // an installation with no tool-authoring plugin behaves exactly as it
        // did, rather than every caller having to pass an empty registry.
        private readonly ?AuthoredToolRegistry $authoredTools = null,
    ) {}

    /** @param array<string, mixed>|null $params */
    public function __invoke(?array $params, ?string $bearerToken): mixed
    {
        $principal = $bearerToken !== null
            ? $this->tokenValidator->validateBearerForMcp($bearerToken)
            : null;
        $tenantId = TenantContext::getTenantId();

        $accessMap = $this->toolDeriver->buildAccessMap();
        $derived   = $this->toolDeriver->deriveTools();
        $tools     = [];
        foreach ($derived as $tool) {
            $name   = (string) ($tool['name'] ?? '');
            $access = $accessMap[$name] ?? ['requiredRole' => null, 'requiredPermission' => null];
            if ($this->callerCanUse($access, $principal, $tenantId)) {
                $tools[] = $tool;
            }
        }

        // Authored tools are filtered through the SAME callerCanUse() as
        // derived ones, deliberately: two filters would be two chances to let
        // a tool through that the other would have hidden, and tools/list is
        // the surface a model reads to decide what it may attempt.
        if ($this->authoredTools !== null) {
            // Drop anything a derived tool already named. Done here rather than
            // at registration because derivation happens after plugins load —
            // there was nothing to collide with at the time they registered.
            $this->authoredTools->dropCollisionsWith(
                array_map(static fn (array $t): string => (string) ($t['name'] ?? ''), $derived)
            );

            $authoredAccess = $this->authoredTools->accessMap();
            foreach ($this->authoredTools->toolObjects() as $tool) {
                $name   = (string) ($tool['name'] ?? '');
                $access = $authoredAccess[$name] ?? ['requiredRole' => null, 'requiredPermission' => null];
                if ($this->callerCanUse($access, $principal, $tenantId)) {
                    $tools[] = $tool;
                }
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
            return $this->roleChecker->hasPermissionForProfile($principal->userId, $requiredPermission, $tenantId);
        }
        return $this->roleChecker->hasRoleForProfile($principal->userId, $requiredRole, $tenantId);
    }
}
