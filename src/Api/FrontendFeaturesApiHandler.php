<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Sdk\Frontend\Blocks\BlockValidator;

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
 *
 * Server-driven `screen:'blocks'` features (WC-226)
 * -------------------------------------------------
 * A plugin may expose a `screen:'blocks'` feature carrying a platform-neutral
 * `blocks` tree ({@see \Whity\Sdk\Frontend\Blocks\BlockContract}). The host is
 * the authoritative gate: every such tree is run through
 * {@see BlockValidator::validate()} before it can reach any renderer. The
 * validation is FAIL-CLOSED and applied IN ADDITION to (never instead of) the
 * per-caller permission filter:
 *  - a VALID tree → the feature is served WITH its `blocks` intact (still
 *    permission-filtered as every other feature);
 *  - an INVALID tree, or a `screen:'blocks'` feature whose `blocks` is missing
 *    or not an array → the feature is OMITTED and a structured, secret-free
 *    reason is logged (feature id + validator errors) via the optional logger.
 *    The raw validator errors are NEVER returned to the client; the endpoint
 *    still returns 200 with the OTHER valid features — never a 500.
 * Validation never throws (the SDK validator is pure and worker-safe), so a
 * malformed plugin tree can neither crash the request nor leak across workers.
 */
final class FrontendFeaturesApiHandler
{
    private PluginLoader $pluginLoader;
    private RoleChecker $roleChecker;
    private Router $router;
    private ?LoggerInterface $logger;

    /**
     * @param PluginLoader        $pluginLoader The live loader carrying the validated descriptors.
     * @param RoleChecker         $roleChecker  Authoritative RBAC resolver for per-caller filtering.
     * @param Router              $router       The live router whose routes back each feature's capabilities.
     * @param LoggerInterface|null $logger      Optional PSR-3 sink for fail-closed omit reasons (WC-226).
     */
    public function __construct(
        PluginLoader $pluginLoader,
        RoleChecker $roleChecker,
        Router $router,
        ?LoggerInterface $logger = null
    ) {
        $this->pluginLoader = $pluginLoader;
        $this->roleChecker = $roleChecker;
        $this->router = $router;
        $this->logger = $logger;
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
            $userId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
                ? $actor->profile_id
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
                if (!$this->roleChecker->hasPermissionForProfile($userId, $permission, $tenantId)) {
                    continue;
                }

                // WC-226: fail-closed block-tree validation for `screen:'blocks'`
                // features. Applied IN ADDITION to the permission filter above —
                // a permitted feature with an invalid (or missing/!array) tree is
                // still omitted. Returns the validated tree to pass through, or
                // null when the feature must be dropped (already logged).
                $validatedBlocks = null;
                if (($feature['screen'] ?? null) === 'blocks') {
                    $validatedBlocks = $this->validateBlocksOrNull($feature);
                    if ($validatedBlocks === null) {
                        continue;
                    }
                }

                $data[] = $this->toPublicFeature($feature, $permission, $userId, $tenantId, $validatedBlocks);
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
     * @param list<mixed>|null $validatedBlocks The already-validated block tree for a
     *        `screen:'blocks'` feature (emitted verbatim under `blocks`), or null
     *        for every other screen (no `blocks` key is added).
     * @return array<string, mixed> The public entry.
     */
    private function toPublicFeature(
        array $feature,
        string $permission,
        int $userId,
        int $tenantId,
        ?array $validatedBlocks = null
    ): array {
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

        $action = null;
        if (isset($feature['action']) && is_array($feature['action'])) {
            $rawFields = isset($feature['action']['fields']) && is_array($feature['action']['fields'])
                ? $feature['action']['fields']
                : [];
            $fields = [];
            foreach ($rawFields as $rawField) {
                if (!is_array($rawField)) {
                    continue;
                }
                $fields[] = [
                    'name' => (string) ($rawField['name'] ?? ''),
                    'label' => (string) ($rawField['label'] ?? ''),
                    'kind' => (string) ($rawField['kind'] ?? 'text'),
                    'accept' => isset($rawField['accept']) && is_string($rawField['accept']) ? $rawField['accept'] : null,
                    'required' => (bool) ($rawField['required'] ?? false),
                ];
            }
            $action = [
                'method' => (string) ($feature['action']['method'] ?? 'POST'),
                'path' => (string) ($feature['action']['path'] ?? ''),
                'submitLabel' => isset($feature['action']['submitLabel']) && is_string($feature['action']['submitLabel'])
                    ? $feature['action']['submitLabel']
                    : null,
                'fields' => $fields,
            ];
        }

        $public = [
            'id' => (string) ($feature['id'] ?? ''),
            'plugin' => (string) ($feature['plugin'] ?? ''),
            'label' => (string) ($feature['label'] ?? ''),
            'icon' => isset($feature['icon']) && is_string($feature['icon']) ? $feature['icon'] : null,
            'group' => (string) ($feature['group'] ?? 'plugins'),
            'order' => (int) ($feature['order'] ?? 100),
            'screen' => (string) ($feature['screen'] ?? 'custom'),
            'resource' => $resource,
            'action' => $action,
            'requiredPermission' => $permission,
            'capabilities' => $this->resolveCapabilities($basePath, $userId, $tenantId),
        ];

        // WC-226: a `screen:'blocks'` feature carries its host-validated block
        // tree verbatim. The key is added ONLY for blocks features — every other
        // screen keeps the existing exhaustive contract unchanged.
        if ($validatedBlocks !== null) {
            $public['blocks'] = $validatedBlocks;
        }

        return $public;
    }

    /**
     * Validate a `screen:'blocks'` feature's tree, fail-closed (WC-226).
     *
     * Returns the block tree to pass through when it is present, an array, AND
     * structurally valid per {@see BlockValidator::validate()}. Otherwise returns
     * null (the feature must be omitted) after logging a structured, secret-free
     * reason naming the feature id and carrying the validator errors. The raw
     * errors are for operators only and never surface to the client.
     *
     * @param array<string, mixed> $feature The normalized loader descriptor.
     * @return list<mixed>|null The validated tree, or null when the feature must be dropped.
     */
    private function validateBlocksOrNull(array $feature): ?array
    {
        $featureId = isset($feature['id']) && is_string($feature['id']) ? $feature['id'] : '(no id)';
        $pluginName = isset($feature['plugin']) && is_string($feature['plugin']) ? $feature['plugin'] : '(unknown)';

        $blocks = $feature['blocks'] ?? null;
        if (!is_array($blocks)) {
            $this->logBlocksDropped($pluginName, $featureId, ["'blocks' must be an array, got " . get_debug_type($blocks)]);

            return null;
        }

        $result = BlockValidator::validate($blocks);
        if ($result['ok'] !== true) {
            $this->logBlocksDropped($pluginName, $featureId, $result['errors']);

            return null;
        }

        /** @var list<mixed> $blocks */
        return $blocks;
    }

    /**
     * Log the fail-closed omission of a `screen:'blocks'` feature (WC-226).
     *
     * Structured + secret-free: the validator errors are path-qualified contract
     * diagnostics (block type/prop names), never request data or secrets, and are
     * passed as PSR-3 context for operator triage — they are NEVER returned to the
     * client. A no-op when no logger is wired.
     *
     * @param list<string> $errors The validator errors (path-qualified contract diagnostics).
     */
    private function logBlocksDropped(string $pluginName, string $featureId, array $errors): void
    {
        $this->logger?->warning(
            'Frontend feature with screen:blocks dropped: invalid block tree',
            [
                'plugin' => $pluginName,
                'feature_id' => $featureId,
                'errors' => $errors,
            ]
        );
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
        if (is_string($requiredRole) && !$this->roleChecker->hasRoleForProfile($userId, $requiredRole, $tenantId)) {
            return false;
        }

        $requiredPermission = $route['requiredPermission'] ?? null;
        if (is_string($requiredPermission) && !$this->roleChecker->hasPermissionForProfile($userId, $requiredPermission, $tenantId)) {
            return false;
        }

        return true;
    }
}
