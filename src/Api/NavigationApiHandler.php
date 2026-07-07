<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Hooks\HookManager;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * Navigation API Handler.
 *
 * Exposes the registered navigation items at `GET /api/navigation` so a
 * schema-driven admin UI can render the menu without hardcoding it. Items are
 * contributed by core and plugins via the `navigation.register` hook.
 *
 * Authorization
 * -------------
 * The route registers with NO required role/permission (any authenticated
 * caller may ask "which menu items may I see?"), so this handler fails closed
 * itself, mirroring {@see FrontendFeaturesApiHandler}: an unresolved
 * {@see TenantContext} or a missing/invalid authenticated user is refused with
 * 403 before any item is considered.
 *
 * Server-side filtering (WC-175, #191)
 * ------------------------------------
 * Each item is included ONLY when the caller actually satisfies any RBAC gate
 * it declares, checked against the authoritative {@see RoleChecker} (tenant
 * scoped). An item may carry `requiredRole` (string) and/or `requiredPermission`
 * (string); when both are present BOTH must pass, mirroring RbacMiddleware. An
 * item with neither gate is always included (a public item). The client is
 * never trusted to filter — nav items are UI metadata only and grant nothing;
 * data access remains enforced by the route-level RBAC of the linked page's
 * API.
 */
final class NavigationApiHandler
{
    private HookManager $hookManager;
    private RoleChecker $roleChecker;

    /**
     * @param HookManager $hookManager Collects items via the navigation.register hook.
     * @param RoleChecker $roleChecker Authoritative RBAC resolver for per-caller filtering.
     */
    public function __construct(HookManager $hookManager, RoleChecker $roleChecker)
    {
        $this->hookManager = $hookManager;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/navigation — list the navigation items the caller may see.
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
            $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
                ? $actor->profile_id
                : null;
            if ($userId === null) {
                return Response::error('Authentication required', 403);
            }

            // Dispatch hook for core/plugins to register navigation items.
            $result = $this->hookManager->dispatch('navigation.register', [
                'items' => [],
            ]);
            $items = $result['items'] ?? [];

            // Server-side filtering against the authoritative store: keep an item
            // unless a declared RBAC gate excludes the caller.
            $items = array_values(array_filter(
                $items,
                fn (array $item): bool => $this->isVisibleTo($item, $userId, $tenantId)
            ));

            // Sort items by group then by order.
            usort($items, function ($a, $b) {
                $groupCompare = ($a['group'] ?? 'default') <=> ($b['group'] ?? 'default');
                if ($groupCompare !== 0) {
                    return $groupCompare;
                }
                return ($a['order'] ?? 999) <=> ($b['order'] ?? 999);
            });

            return Response::json(['data' => $items], 200);
        } catch (\Throwable) {
            // Never leak internal exception details to clients.
            return Response::error('Failed to fetch navigation', 500);
        }
    }

    /**
     * Whether the caller satisfies every RBAC gate the item declares.
     *
     * An item with neither gate is public. When multiple gates are present ALL
     * must pass, mirroring RbacMiddleware. A `systemTenantOnly` item is
     * additionally hidden outside the system tenant (id 0).
     *
     * @param array<string, mixed> $item     The navigation item descriptor.
     * @param int                  $userId   The resolved caller user id.
     * @param int                  $tenantId The resolved tenant id.
     * @return bool True when the item is visible to the caller.
     */
    private function isVisibleTo(array $item, int $userId, int $tenantId): bool
    {
        $requiredRole = $item['requiredRole'] ?? null;
        if (is_string($requiredRole) && $requiredRole !== ''
            && !$this->roleChecker->hasRoleForProfile($userId, $requiredRole, $tenantId)) {
            return false;
        }

        $requiredPermission = $item['requiredPermission'] ?? null;
        if (is_string($requiredPermission) && $requiredPermission !== ''
            && !$this->roleChecker->hasPermissionForProfile($userId, $requiredPermission, $tenantId)) {
            return false;
        }

        // System-tenant-only items (e.g. platform governance surfaces) are
        // hidden for any caller not acting in the system tenant (id 0), even if
        // they hold the declared permission within their own tenant. Mirrors the
        // handler-side systemTenantOnly gates (WC-235).
        if (($item['systemTenantOnly'] ?? false) === true && $tenantId !== 0) {
            return false;
        }

        return true;
    }
}
