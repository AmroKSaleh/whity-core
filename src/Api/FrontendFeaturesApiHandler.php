<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;

/**
 * Frontend Features API Handler (WC-169 / WC-175).
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
 *
 * Per-feature write capabilities (WC-175, #199)
 * ---------------------------------------------
 * The schema-driven CRUD renderer derives Create/Edit/Delete controls from
 * OpenAPI operation PRESENCE, so a read-only delegated caller would see enabled
 * controls that 403 on submit. To let the renderer hide them, every feature
 * carries a `capabilities` object `{ canCreate, canEdit, canDelete }` computed
 * SERVER-SIDE from the resource's registered routes' RBAC — exactly what
 * RbacMiddleware will enforce on submit. A feature without a resource gets all
 * false. The {@see RoleChecker} is the only authority; no direct DB access.
 */
final class FrontendFeaturesApiHandler
{
    private PluginLoader $pluginLoader;
    private RoleChecker $roleChecker;
    private Router $router;

    /**
     * @param PluginLoader $pluginLoader The live loader carrying the validated descriptors.
     * @param RoleChecker  $roleChecker  Authoritative RBAC resolver for per-caller filtering.
     * @param Router       $router       The live router whose routes back each feature's capabilities.
     */
    public function __construct(PluginLoader $pluginLoader, RoleChecker $roleChecker, Router $router)
    {
        $this->pluginLoader = $pluginLoader;
        $this->roleChecker = $roleChecker;
        $this->router = $router;
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

                $data[] = $this->toPublicFeature($feature, $permission, $userId, $tenantId);
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
     * @param int $userId The resolved caller user id (for capability resolution).
     * @param int $tenantId The resolved tenant id (for capability resolution).
     * @return array<string, mixed> The public entry.
     */
    private function toPublicFeature(array $feature, string $permission, int $userId, int $tenantId): array
    {
        $resource = null;
        $basePath = null;
        if (isset($feature['resource']) && is_array($feature['resource'])) {
            $basePath = (string) ($feature['resource']['basePath'] ?? '');
            $resource = [
                'basePath' => $basePath,
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
            'capabilities' => $this->resolveCapabilities($basePath, $userId, $tenantId),
        ];
    }

    /**
     * Resolve the caller's effective write capabilities for a feature's resource.
     *
     * Mirrors exactly what RbacMiddleware enforces on submit: for the resource's
     * `basePath`, `canCreate` requires a satisfiable POST at EXACTLY the base
     * path, while `canEdit`/`canDelete` require a satisfiable PATCH/DELETE at the
     * resource's single item route — `basePath` followed by EXACTLY one
     * `{param}` segment and nothing further. That is the only write target the
     * schema-driven renderer ever submits to (`${basePath}/{id}`, see
     * web/components/plugin/crud-screen.tsx handleEdit/handleDelete).
     *
     * The item route MUST be matched precisely rather than by an item-prefix
     * test: a prefix match would also capture NESTED sub-resource write routes
     * under the same base path (e.g. `PATCH /api/foo/{id}/notes/{nid}`) that are
     * gated on a DIFFERENT permission. Whichever such route iterated last would
     * then decide `canEdit`/`canDelete` purely by route-registration order,
     * over-granting (or over-denying) a capability the renderer would never
     * even exercise. Requiring a single brace-param segment with no further
     * slash binds the capability to the resource's own item route alone.
     *
     * A feature without a resource (or an empty base path) has no derivable
     * write routes, so every capability is false.
     *
     * @param string|null $basePath The resource base path, or null when absent.
     * @param int $userId The resolved caller user id.
     * @param int $tenantId The resolved tenant id.
     * @return array{canCreate: bool, canEdit: bool, canDelete: bool}
     */
    private function resolveCapabilities(?string $basePath, int $userId, int $tenantId): array
    {
        $capabilities = ['canCreate' => false, 'canEdit' => false, 'canDelete' => false];

        if ($basePath === null || $basePath === '') {
            return $capabilities;
        }

        // Matches `${basePath}/{param}` precisely: the remainder after the base
        // path is a single brace-param segment with NO nested slash. This binds
        // edit/delete to the resource's own item route and excludes deeper
        // sub-resource routes (whose remainder contains a `/`).
        $itemPattern = '#^' . preg_quote($basePath . '/', '#') . '\{[^/]+\}$#';

        foreach ($this->router->getRoutes() as $route) {
            $method = $route['method'];
            $path = $route['path'];

            if ($method === 'POST' && $path === $basePath) {
                $capabilities['canCreate'] = $this->callerSatisfies($route, $userId, $tenantId);
            } elseif ($method === 'PATCH' && preg_match($itemPattern, $path) === 1) {
                $capabilities['canEdit'] = $this->callerSatisfies($route, $userId, $tenantId);
            } elseif ($method === 'DELETE' && preg_match($itemPattern, $path) === 1) {
                $capabilities['canDelete'] = $this->callerSatisfies($route, $userId, $tenantId);
            }
        }

        return $capabilities;
    }

    /**
     * Whether the caller satisfies a route's RBAC — the same check RbacMiddleware
     * applies on submit.
     *
     * @param array{requiredRole: ?string, requiredPermission: ?string} $route The route descriptor.
     * @param int $userId The resolved caller user id.
     * @param int $tenantId The resolved tenant id.
     * @return bool True when the caller would pass the route's RBAC.
     */
    private function callerSatisfies(array $route, int $userId, int $tenantId): bool
    {
        $requiredRole = $route['requiredRole'] ?? null;
        if (is_string($requiredRole) && !$this->roleChecker->hasRole($userId, $requiredRole, $tenantId)) {
            return false;
        }

        $requiredPermission = $route['requiredPermission'] ?? null;
        if (is_string($requiredPermission) && !$this->roleChecker->hasPermission($userId, $requiredPermission, $tenantId)) {
            return false;
        }

        return true;
    }
}
