<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Frontend Features API Handler (WC-169).
 *
 * Exposes the validated plugin frontend feature descriptors (SDK 1.2,
 * {@see \Whity\Sdk\PluginFrontendInterface}) at `GET /api/frontend/features`
 * so a schema-driven admin UI can render plugin screens without hardcoding
 * them.
 *
 * Authorization
 * -------------
 * The route registers with NO required role/permission (any authenticated
 * caller may ask "what screens may I see?"), so this handler fails closed
 * itself, mirroring {@see AuditLogApiHandler}'s defence-in-depth pattern:
 * an unresolved {@see TenantContext} or a missing/invalid authenticated user
 * is refused with 403 before any descriptor is considered.
 *
 * Server-side filtering
 * ---------------------
 * Each descriptor is included ONLY when the caller actually holds its
 * `requiredPermission` per the authoritative {@see RoleChecker} (tenant
 * scoped, WC-54) — the client is never trusted to filter. Descriptors are UI
 * metadata only: they grant nothing, and data access remains enforced by the
 * route-level RBAC of the underlying plugin API routes.
 */
final class FrontendFeaturesApiHandler
{
    private PluginLoader $pluginLoader;
    private RoleChecker $roleChecker;

    /**
     * @param PluginLoader $pluginLoader The live loader carrying the validated descriptors.
     * @param RoleChecker  $roleChecker  Authoritative RBAC resolver for per-caller filtering.
     */
    public function __construct(PluginLoader $pluginLoader, RoleChecker $roleChecker)
    {
        $this->pluginLoader = $pluginLoader;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/frontend/features — list the features the caller may see.
     *
     * @param Request $request The incoming request.
     * @return Response JSON `{ data: [...] }` (200; empty data is valid) or a 403.
     */
    public function list(Request $request): Response
    {
        try {
            // Fail closed when the tenant context is unresolved.
            $tenantId = TenantContext::getTenantId();
            if ($tenantId === null) {
                return Response::error('Tenant context is required', 403);
            }

            // Fail closed without an authenticated, well-typed acting user.
            $actor = $request->user;
            $userId = is_object($actor) && isset($actor->user_id) && is_int($actor->user_id)
                ? $actor->user_id
                : null;
            if ($userId === null) {
                return Response::error('Authentication required', 403);
            }

            $data = [];
            foreach ($this->pluginLoader->getFrontendFeatures() as $feature) {
                // Defence in depth: a descriptor without a string permission
                // can never be exposed (the loader already guarantees one).
                $permission = $feature['requiredPermission'] ?? null;
                if (!is_string($permission)) {
                    continue;
                }

                // Server-side filtering against the authoritative store.
                if (!$this->roleChecker->hasPermission($userId, $permission, $tenantId)) {
                    continue;
                }

                $data[] = $this->toPublicFeature($feature, $permission);
            }

            return Response::json(['data' => $data], 200);
        } catch (\Throwable) {
            // Never leak internal exception details to clients.
            return Response::error('Failed to fetch frontend features', 500);
        }
    }

    /**
     * Shape a loader descriptor into the public API contract.
     *
     * Keys are emitted explicitly (never passed through blindly) so the
     * published FrontendFeature component stays the exhaustive contract.
     *
     * @param array<string, mixed> $feature The normalized loader descriptor.
     * @param string $permission The descriptor's required permission.
     * @return array<string, mixed> The public entry.
     */
    private function toPublicFeature(array $feature, string $permission): array
    {
        $resource = null;
        if (isset($feature['resource']) && is_array($feature['resource'])) {
            $resource = [
                'basePath' => (string) ($feature['resource']['basePath'] ?? ''),
                'titleField' => isset($feature['resource']['titleField']) && is_string($feature['resource']['titleField'])
                    ? $feature['resource']['titleField']
                    : null,
            ];
        }

        return [
            'id' => (string) ($feature['id'] ?? ''),
            'plugin' => (string) ($feature['plugin'] ?? ''),
            'label' => (string) ($feature['label'] ?? ''),
            'icon' => isset($feature['icon']) && is_string($feature['icon']) ? $feature['icon'] : null,
            'group' => (string) ($feature['group'] ?? 'plugins'),
            'order' => (int) ($feature['order'] ?? 100),
            'screen' => (string) ($feature['screen'] ?? 'custom'),
            'resource' => $resource,
            'requiredPermission' => $permission,
        ];
    }
}
