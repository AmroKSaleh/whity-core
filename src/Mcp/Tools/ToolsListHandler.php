<?php

declare(strict_types=1);

namespace Whity\Mcp\Tools;

use Whity\Auth\RoleChecker;
use Whity\Auth\TokenValidator;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Auth\McpPrincipal;
use Whity\Mcp\JsonRpc\MethodHandler;
use Whity\Mcp\Auth\McpScope;

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

        // WHIT-748: `?toolsets=` narrows what is shown. Presentation only;
        // the token's own toolsets (applied in listFor) are what restrict.
        $surface = ToolSurfaceRequest::fromParams($params);
        $tools   = array_values(array_filter(
            $this->listFor($principal, TenantContext::getTenantId()),
            static fn (array $tool): bool => $surface->selectsTool($tool),
        ));

        return ['tools' => $tools];
    }

    /**
     * The tools a principal would see, by the exact filter tools/list applies.
     *
     * Public so the connect wizard's scope preview (WHIT-697) answers "what
     * could an app with this scope do?" from this code rather than from a
     * second copy of it, which would agree today and drift later.
     *
     * @return list<array<string, mixed>>
     */
    public function listFor(?McpPrincipal $principal, ?int $tenantId): array
    {
        $accessMap = $this->toolDeriver->buildAccessMap();
        $derived   = $this->toolDeriver->deriveTools();
        $tools     = [];
        foreach ($derived as $tool) {
            $name   = (string) ($tool['name'] ?? '');
            // ADR-0006 §4 (WHIT-695): ungated routes stay off the list unless
            // their schema opts in.
            if (!$this->toolDeriver->isExposed($name)) {
                continue;
            }
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

        return self::inStableOrder($tools);
    }

    /**
     * Always-on operations first, in {@see CoreToolsets::ALWAYS_ON} order, then
     * everything else by toolset and name (WHIT-747). The order depends only on
     * the catalogue, never on declaration or plugin-load order, so it is the
     * same on every worker and stays put while nothing changes: the
     * deterministic order MCP 2026-07-28 asks for, which keeps clients' caches
     * and prompt caches valid.
     *
     * @param list<array<string, mixed>> $tools
     * @return list<array<string, mixed>>
     */
    private static function inStableOrder(array $tools): array
    {
        $rank = array_flip(CoreToolsets::ALWAYS_ON);
        $key  = static function (array $tool) use ($rank): array {
            $meta       = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
            $name       = (string) ($tool['name'] ?? '');
            $alwaysOn   = ($meta[ToolSurface::META_VISIBILITY] ?? null) === ToolSurface::VISIBILITY_ALWAYS_ON;
            return [
                $alwaysOn ? 0 : 1,
                $alwaysOn ? ($rank[$name] ?? PHP_INT_MAX) : 0,
                (string) ($meta[ToolSurface::META_TOOLSET] ?? ''),
                $name,
            ];
        };
        usort($tools, static fn (array $a, array $b): int => $key($a) <=> $key($b));

        return $tools;
    }

    /** @param array<string, mixed> $access */
    private function callerCanUse(array $access, ?McpPrincipal $principal, ?int $tenantId): bool
    {
        $requiredPermission = is_string($access['requiredPermission'] ?? null) ? $access['requiredPermission'] : null;
        $requiredRole       = is_string($access['requiredRole'] ?? null) ? $access['requiredRole'] : null;

        // WHIT-695: a token that names permissions reaches only items gated by
        // one of them. Applied before the open-item shortcut, which is exactly
        // what narrowing removes.
        if ($principal !== null && !McpScope::of($principal)->allowsPermission($requiredPermission)) {
            return false;
        }
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
