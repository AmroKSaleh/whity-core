<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Mcp\Tools\AuthoredToolRegistry;
use Whity\Mcp\Tools\CoreToolsets;
use Whity\Mcp\Tools\Discovery\DiscoveryTools;
use Whity\Mcp\Tools\ToolDeriver;
use Whity\Mcp\Tools\ToolSurface;

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
 * WHIT-754: each tool also carries its toolset, visibility and agent policy,
 * plugin-authored tools are included, a plugin tool that asks to be always-on
 * says whether this workspace has turned it on (setting
 * mcp.always_on_plugin_tools, edited through the settings API), and `sizes`
 * estimates what each list mode costs a client before it does anything.
 *
 * Worker-safe: reads from ToolDeriver (static cache) and RoleChecker; no
 * instance-level mutable state.
 */
final class McpToolsAdminHandler
{
    /**
     * @param \Closure(int): list<string>|null $enabledPluginAlwaysOn the plugin tools this tenant turned on as always-on
     */
    public function __construct(
        private readonly ToolDeriver $toolDeriver,
        private readonly RoleChecker $roleChecker,
        private readonly ?AuthoredToolRegistry $authoredTools = null,
        private readonly ?\Closure $enabledPluginAlwaysOn = null,
    ) {}

    /**
     * GET /api/v1/admin/mcp/tools
     *
     * Returns `{ data: McpToolEntry[], sizes: {...} }` where each entry carries
     * the tool name, description, access requirements and its place on the
     * tool surface.
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

            $enabled   = $this->enabledPluginAlwaysOn !== null ? ($this->enabledPluginAlwaysOn)($tenantId) : [];
            $accessMap = $this->toolDeriver->buildAccessMap();
            $exposed   = [];
            foreach ($this->toolDeriver->deriveTools() as $tool) {
                // ADR-0006 §4 (WHIT-695): this page shows what an MCP client
                // can be offered, and tools/list no longer offers these.
                if ($this->toolDeriver->isExposed((string) ($tool['name'] ?? ''))) {
                    $exposed[] = [$tool, $accessMap[(string) $tool['name']] ?? [], null];
                }
            }
            if ($this->authoredTools !== null) {
                $authoredAccess = $this->authoredTools->accessMap();
                foreach ($this->authoredTools->toolObjects() as $tool) {
                    $name      = (string) ($tool['name'] ?? '');
                    $exposed[] = [$tool, $authoredAccess[$name] ?? [], $this->authoredTools->get($name)['plugin'] ?? null];
                }
            }

            $data       = [];
            $fullList   = [];
            $alwaysOn   = [];
            foreach ($exposed as [$tool, $access, $plugin]) {
                $name       = (string) ($tool['name'] ?? '');
                $meta       = is_array($tool['_meta'] ?? null) ? $tool['_meta'] : [];
                $visibility = (string) ($meta[ToolSurface::META_VISIBILITY] ?? ToolSurface::VISIBILITY_DISCOVERABLE);
                $isCore     = CoreToolsets::isAlwaysOn($name);
                $requested  = $visibility === ToolSurface::VISIBILITY_ALWAYS_ON && !$isCore;
                $data[] = [
                    'name'               => $name,
                    'description'        => (string) ($tool['description'] ?? ''),
                    'requiredPermission' => is_string($access['requiredPermission'] ?? null) ? $access['requiredPermission'] : null,
                    'requiredRole'       => is_string($access['requiredRole'] ?? null) ? $access['requiredRole'] : null,
                    'toolset'            => (string) ($meta[ToolSurface::META_TOOLSET] ?? ''),
                    'visibility'         => $visibility,
                    'agentPolicy'        => (string) ($meta[ToolSurface::META_AGENT_POLICY] ?? ToolSurface::AGENT_ALLOW),
                    'plugin'             => $plugin,
                    // A plugin tool asking to be always-on is listed only once
                    // this workspace turns it on (owner decision 5).
                    'alwaysOnRequested'  => $requested,
                    'alwaysOnEnabled'    => $isCore || ($requested && in_array($name, $enabled, true)),
                ];
                $fullList[] = $tool;
                if ($isCore || ($requested && in_array($name, $enabled, true))) {
                    $alwaysOn[] = $tool;
                }
            }

            $search  = DiscoveryTools::definitions();
            $default = [DiscoveryTools::initDefinition(), ...$alwaysOn, ...$search];

            return Response::json([
                'data'  => $data,
                // Bytes a client loads before doing anything, per list mode;
                // tokens are roughly bytes / 4. An administrator who holds
                // every permission; anyone else sees less.
                'sizes' => [
                    'default'  => ['tools' => count($default), 'bytes' => strlen((string) json_encode($default))],
                    'full'     => ['tools' => count($fullList), 'bytes' => strlen((string) json_encode($fullList))],
                    'discover' => ['tools' => count($search), 'bytes' => strlen((string) json_encode($search))],
                ],
            ], 200);
        } catch (\Exception $e) {
            error_log('[McpToolsAdminHandler] list failed: ' . $e->getMessage());
            return Response::error('Failed to fetch MCP tools', 500);
        }
    }
}
