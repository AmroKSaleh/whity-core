<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Tools\ToolDeriver;

/**
 * Admin read-only view of MCP tools available in this tenant (WC-0208ce4d).
 *
 * Endpoint
 * --------
 *   GET /api/v1/admin/mcp/tools
 *
 * Returns the same tool catalogue that tools/list exposes to an MCP client,
 * augmented with the permission / role requirement so an admin can understand
 * what each tool requires from an AI principal.
 *
 * The response is NOT user-filtered (unlike ToolsListHandler, which hides
 * tools the caller lacks): the intent here is to let the admin see the FULL
 * set of tools and their requirements for audit/planning purposes. The admin's
 * own permissions are still verified via mcp:tokens:manage — the page is
 * admin-gated, not public.
 *
 * Worker-safe: reads from ToolDeriver (static cache) and RoleChecker; no
 * instance-level mutable state.
 */
final class McpToolsAdminHandler
{
    public function __construct(
        private readonly ToolDeriver $toolDeriver,
        private readonly RoleChecker $roleChecker,
    ) {}

    /**
     * GET /api/v1/admin/mcp/tools
     *
     * Returns `{ data: McpToolEntry[] }` where each entry carries the tool
     * name, description, and access requirements.
     *
     * @param array<string, mixed> $params Route path parameters (unused).
     */
    public function list(Request $request, array $params = []): Response
    {
        try {
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            $actor  = $request->user;
            $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
                ? $actor->profile_id
                : null;
            if ($userId === null || !$this->roleChecker->hasPermissionForProfile($userId, CorePermissions::MCP_TOKENS_MANAGE, $tenantId)) {
                return Response::error('Insufficient permissions', 403, ['required' => CorePermissions::MCP_TOKENS_MANAGE]);
            }

            $tools     = $this->toolDeriver->deriveTools();
            $accessMap = $this->toolDeriver->buildAccessMap();

            $data = [];
            foreach ($tools as $tool) {
                $name   = (string) ($tool['name'] ?? '');
                $access = $accessMap[$name] ?? ['requiredRole' => null, 'requiredPermission' => null];
                $data[] = [
                    'name'               => $name,
                    'description'        => (string) ($tool['description'] ?? ''),
                    'requiredPermission' => is_string($access['requiredPermission'] ?? null) ? $access['requiredPermission'] : null,
                    'requiredRole'       => is_string($access['requiredRole'] ?? null) ? $access['requiredRole'] : null,
                ];
            }

            return Response::json(['data' => $data], 200);
        } catch (\Exception $e) {
            error_log('[McpToolsAdminHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch MCP tools', 500);
        }
    }
}
