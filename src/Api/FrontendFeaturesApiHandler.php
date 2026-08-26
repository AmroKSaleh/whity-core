<?php

declare(strict_types=1);

namespace Whity\Api;

use Psr\Log\LoggerInterface;
use Whity\Auth\RoleChecker;
use Whity\Core\PluginLoader;
use Whity\Core\RBAC\CorePermissions;
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
 * Why a false capability also carries a reason (#951)
 * ---------------------------------------------------
 * A capability comes back false for three unrelated reasons: the resource has
 * no such write route at all, the plugin registered the wrong method (the
 * classic being PUT where editability is derived from PATCH), or the caller
 * simply lacks the RBAC for the route that does exist. The renderer used to
 * answer all three by omitting the control, which made a correct screen the
 * viewer has no rights on pixel-identical to a broken one — a plugin once
 * shipped seven screens with no Edit control at all and it read as a design
 * decision. So every false capability now also ships a `capabilityReasons`
 * entry, and the renderer disables the control instead of dropping it. The
 * BOOLEANS are computed and enforced exactly as before; only the discarded
 * explanation is new.
 *
 * That reason serves TWO audiences, which is why it has two fields:
 *  - `reason` is written for the person looking at the screen and is safe for
 *    any caller who could already see the feature. It says what happened to
 *    THEM ("You do not have permission to edit records here") and never names
 *    an internal identifier.
 *  - `detail` is written for whoever can fix it, and names the exact route the
 *    platform looked for, or the exact role/permission the matched route
 *    demands. It is emitted ONLY to callers holding
 *    {@see CorePermissions::PLUGINS_READ} — the same permission that gates the
 *    plugin console, i.e. exactly the people who can act on it.
 * The `detail` gate is uniform across every reason code rather than decided
 * per code. Only `forbidden` details leak authorization surface today, but a
 * per-code exemption is a rule that whoever adds the NEXT code has to remember,
 * and a uniform one cannot be got wrong. An ordinary caller loses nothing by
 * it: a route path they cannot call is of no use to them.
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
 *
 * Reporting what was dropped (#953)
 * ---------------------------------
 * Both of the above refuse features on purpose and the rules are right, but a
 * refused feature simply was not in the navigation, which looks exactly like a
 * permission problem, a caching problem, or a typo in the screen id. Finding
 * the real cause meant reading container logs.
 *
 * So the response carries a `dropped` array naming each refused descriptor and
 * why. It covers BOTH refusal points — the loader's, at plugin load, and this
 * handler's block-tree validation, per request — because they are one question
 * to an administrator ("which screens should be here and are not?") and putting
 * the answer on the response that BUILDS the navigation is what makes the
 * question and the answer adjacent.
 *
 * The key is emitted only to a caller holding
 * {@see CorePermissions::PLUGINS_READ}: the reasons quote route paths and
 * permission names, and every authenticated caller fetches this endpoint. Its
 * ABSENCE is meaningful — an empty array means "nothing was refused", a missing
 * key means "not yours to read" — so a client is never left guessing which it
 * is looking at. The list is NOT permission-filtered per descriptor the way
 * `data` is: a dropped feature is a defect in a plugin's declaration, not
 * something the caller could hold rights over, and several of the refusals are
 * precisely that its declared permission was invalid.
 *
 * HOST-DECLARED FEATURES (convening, #convening)
 * ----------------------------------------------
 * Until now every descriptor came from a plugin, and a CORE subsystem that
 * wanted a schema-driven screen had no way to declare one: the loader's
 * validator refuses any descriptor whose `requiredPermission` is a core
 * permission ("core names are not plugin-ownable"), which is exactly right for a
 * plugin and leaves core with nowhere to stand.
 *
 * So the constructor takes an optional list of HOST descriptors, supplied by
 * `public/index.php` from the subsystem that owns them
 * ({@see \Whity\Core\Convening\ConveningFeatures}). They are appended to the
 * plugin list and then go through THE SAME PIPELINE, not a parallel one: the
 * per-caller permission filter, the fail-closed block-tree validation, the same
 * capability resolution, the same `dropped` reporting. A second code path would
 * be a second place the fail-closed rules could be forgotten.
 *
 * Two differences, both narrow and both deliberate:
 *
 *  - Their permissions are CORE permissions, which is the whole point, and they
 *    are not run through the plugin ownership check (there is no plugin to own
 *    them). The permission is still enforced per caller by the same RoleChecker,
 *    and the routes behind the screen still enforce their own RBAC.
 *  - Their API paths are already versioned, because the subsystem emits them
 *    through {@see Router::versionedPath()} rather than relying on the loader's
 *    rewrite of plugin-declared paths.
 */
final class FrontendFeaturesApiHandler
{
    /** #951: the feature declares no resource, so no write route is derivable. */
    private const DENIED_NO_RESOURCE = 'no-resource';

    /** #951: a resource exists but nothing is registered to satisfy this action. */
    private const DENIED_NO_ROUTE = 'no-route';

    /** #951: the route exists and the caller does not satisfy its RBAC. */
    private const DENIED_FORBIDDEN = 'forbidden';

    /**
     * The user-facing text for a capability no route can satisfy (#951).
     *
     * `no-resource` and `no-route` are one and the same sentence here on
     * purpose. They are different bugs to an author and the SAME fact to a
     * reader — the action is not offered on this screen — and the reader is who
     * this string is for. What separates them lives in `detail`.
     *
     * @var array<string, string>
     */
    private const UNAVAILABLE_TEXT = [
        'canCreate' => 'Creating records is not available on this screen.',
        'canEdit' => 'Editing records is not available on this screen.',
        'canDelete' => 'Deleting records is not available on this screen.',
    ];

    /**
     * The user-facing text for a capability the caller's RBAC denies (#951).
     *
     * Says only that the caller lacks it, never which permission would grant
     * it: the subject is the reader, which they are entitled to be told, while
     * the identifier is RBAC surface they are not.
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_TEXT = [
        'canCreate' => 'You do not have permission to create records here.',
        'canEdit' => 'You do not have permission to edit records here.',
        'canDelete' => 'You do not have permission to delete records here.',
    ];

    private PluginLoader $pluginLoader;
    private RoleChecker $roleChecker;
    private Router $router;
    private ?LoggerInterface $logger;

    /** @var list<array<string, mixed>> */
    private array $hostFeatures;

    /**
     * @param PluginLoader        $pluginLoader The live loader carrying the validated descriptors.
     * @param RoleChecker         $roleChecker  Authoritative RBAC resolver for per-caller filtering.
     * @param Router              $router       The live router whose routes back each feature's capabilities.
     * @param LoggerInterface|null $logger      Optional PSR-3 sink for fail-closed omit reasons (WC-226).
     * @param list<array<string, mixed>> $hostFeatures Descriptors declared by CORE
     *        subsystems rather than by a plugin. Served through the same filter,
     *        the same block validation and the same capability resolution as
     *        every plugin descriptor — see the class docblock.
     */
    public function __construct(
        PluginLoader $pluginLoader,
        RoleChecker $roleChecker,
        Router $router,
        ?LoggerInterface $logger = null,
        array $hostFeatures = []
    ) {
        $this->pluginLoader = $pluginLoader;
        $this->roleChecker = $roleChecker;
        $this->router = $router;
        $this->logger = $logger;
        $this->hostFeatures = array_values($hostFeatures);
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

            // Whether this caller gets the operator-grade `detail` on a denied
            // capability (#951). Resolved ONCE per request rather than per
            // feature: it is a property of the caller, not of the descriptor,
            // and asking the RoleChecker again for every feature would be the
            // same answer at N times the cost.
            $includeDetail = $this->roleChecker->hasPermissionForProfile(
                $userId,
                CorePermissions::PLUGINS_READ,
                $tenantId
            );

            // Refusals made while serving THIS request (#953). The loader's own
            // refusals are read back separately below; they happened at load
            // time and are not re-derived per request.
            $droppedHere = [];

            $data = [];
            // Plugin descriptors first, then the host's own. Order matters only
            // for reading the response; the client sorts by `group`/`order`.
            $declared = array_merge($this->pluginLoader->getFrontendFeatures(), $this->hostFeatures);

            foreach ($declared as $feature) {
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
                    $validatedBlocks = $this->validateBlocksOrNull($feature, $droppedHere);
                    if ($validatedBlocks === null) {
                        continue;
                    }
                }

                $data[] = $this->toPublicFeature(
                    $feature,
                    $permission,
                    $userId,
                    $tenantId,
                    $validatedBlocks,
                    $includeDetail
                );
            }

            $body = ['data' => $data];

            // #953. Same audience as a capability's `detail`, for the same
            // reason: these reasons are for whoever can repair the plugin.
            if ($includeDetail) {
                $body['dropped'] = array_merge(
                    $this->pluginLoader->getDroppedFrontendFeatures(),
                    $droppedHere
                );
            }

            return Response::json($body, 200);
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
     * @param bool $includeDetail Whether the caller may see the operator-grade
     *        `detail` on a denied capability (#951).
     * @return array<string, mixed> The public entry.
     */
    private function toPublicFeature(
        array $feature,
        string $permission,
        int $userId,
        int $tenantId,
        ?array $validatedBlocks = null,
        bool $includeDetail = false
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

        $embed = null;
        if (isset($feature['embed']) && is_array($feature['embed'])) {
            $embed = [
                'path' => (string) ($feature['embed']['path'] ?? ''),
            ];
        }

        $resolved = $this->resolveCapabilities($basePath, $userId, $tenantId);

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
            'embed' => $embed,
            'requiredPermission' => $permission,
            'capabilities' => $resolved['capabilities'],
            // #951: one entry per FALSE capability — a true one needs no
            // explanation, and emitting an empty reason for it would only
            // invite the renderer to test the wrong thing. Always present (even
            // when empty) so the published contract stays exhaustive.
            'capabilityReasons' => $this->publicReasons($resolved['reasons'], $includeDetail),
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
     * @param list<array{plugin: string, featureId: string|null, reason: string}> $dropped
     *        Collects this refusal for the response's `dropped` array (#953).
     * @return list<mixed>|null The validated tree, or null when the feature must be dropped.
     */
    private function validateBlocksOrNull(array $feature, array &$dropped): ?array
    {
        $featureId = isset($feature['id']) && is_string($feature['id']) ? $feature['id'] : '(no id)';
        $pluginName = isset($feature['plugin']) && is_string($feature['plugin']) ? $feature['plugin'] : '(unknown)';

        $blocks = $feature['blocks'] ?? null;
        if (!is_array($blocks)) {
            $this->logBlocksDropped($pluginName, $featureId, ["'blocks' must be an array, got " . get_debug_type($blocks)], $dropped);

            return null;
        }

        $result = BlockValidator::validate($blocks);
        if ($result['ok'] !== true) {
            $this->logBlocksDropped($pluginName, $featureId, $result['errors'], $dropped);

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
     * passed as PSR-3 context for operator triage. A no-op when no logger is
     * wired — which is exactly why the refusal is ALSO collected into `$dropped`
     * (#953): an unwired logger, or a log nobody reads, used to mean the answer
     * existed nowhere at all.
     *
     * The errors reach the client only through the `dropped` array, which is
     * served solely to a plugin administrator.
     *
     * @param list<string> $errors The validator errors (path-qualified contract diagnostics).
     * @param list<array{plugin: string, featureId: string|null, reason: string}> $dropped
     */
    private function logBlocksDropped(string $pluginName, string $featureId, array $errors, array &$dropped): void
    {
        $this->logger?->warning(
            'Frontend feature with screen:blocks dropped: invalid block tree',
            [
                'plugin' => $pluginName,
                'feature_id' => $featureId,
                'errors' => $errors,
            ]
        );

        $dropped[] = [
            'plugin' => $pluginName,
            'featureId' => $featureId,
            'reason' => 'invalid block tree: ' . implode('; ', $errors),
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
     * Alongside each FALSE capability this also returns why it is false (#951).
     * The boolean answers are computed exactly as before — the scan, its order,
     * and its last-match-wins behaviour are untouched — and the reason is read
     * off the same pass rather than recomputed, so the two can never disagree.
     *
     * @param string|null $basePath The resource base path, or null when absent.
     * @param int $userId The resolved caller user id.
     * @param int $tenantId The resolved tenant id.
     * @return array{
     *     capabilities: array{canCreate: bool, canEdit: bool, canDelete: bool},
     *     reasons: array<string, array{code: string, reason: string, detail: string}>
     * }
     */
    private function resolveCapabilities(?string $basePath, int $userId, int $tenantId): array
    {
        $capabilities = ['canCreate' => false, 'canEdit' => false, 'canDelete' => false];

        if ($basePath === null || $basePath === '') {
            $reasons = [];
            foreach (array_keys($capabilities) as $capability) {
                $reasons[$capability] = [
                    'code' => self::DENIED_NO_RESOURCE,
                    'reason' => self::UNAVAILABLE_TEXT[$capability],
                    'detail' => 'the feature declares no resource, so the platform can derive no write route for it',
                ];
            }

            return ['capabilities' => $capabilities, 'reasons' => $reasons];
        }

        // Matches `${basePath}/{param}` precisely: the remainder after the base
        // path is a single brace-param segment with NO nested slash. This binds
        // edit/delete to the resource's own item route and excludes deeper
        // sub-resource routes (whose remainder contains a `/`).
        $itemPattern = '#^' . preg_quote($basePath . '/', '#') . '\{[^/]+\}$#';

        // The route each capability was decided BY, or null when the scan never
        // matched one. This is the whole diagnosis: a false with no matched
        // route means nothing is registered to satisfy it (the PUT-instead-of-
        // PATCH case), and a false WITH one means the route exists and the
        // caller failed its RBAC. The two are indistinguishable from the
        // boolean alone, which is exactly why #951 was hard to see.
        $matched = ['canCreate' => null, 'canEdit' => null, 'canDelete' => null];

        foreach ($this->router->getRoutes() as $route) {
            $method = $route['method'];
            $path = $route['path'];

            if ($method === 'POST' && $path === $basePath) {
                $capabilities['canCreate'] = $this->callerSatisfies($route, $userId, $tenantId);
                $matched['canCreate'] = $route;
            } elseif ($method === 'PATCH' && preg_match($itemPattern, $path) === 1) {
                $capabilities['canEdit'] = $this->callerSatisfies($route, $userId, $tenantId);
                $matched['canEdit'] = $route;
            } elseif ($method === 'DELETE' && preg_match($itemPattern, $path) === 1) {
                $capabilities['canDelete'] = $this->callerSatisfies($route, $userId, $tenantId);
                $matched['canDelete'] = $route;
            }
        }

        // The path each capability is derived FROM, quoted back to the author so
        // a wrong-method registration names itself.
        $itemPath = $basePath . '/{id}';
        $targets = [
            'canCreate' => ['POST', $basePath],
            'canEdit' => ['PATCH', $itemPath],
            'canDelete' => ['DELETE', $itemPath],
        ];

        $reasons = [];
        foreach ($capabilities as $capability => $granted) {
            if ($granted) {
                continue;
            }

            [$method, $path] = $targets[$capability];
            $route = $matched[$capability];

            $reasons[$capability] = $route === null
                ? [
                    'code' => self::DENIED_NO_ROUTE,
                    'reason' => self::UNAVAILABLE_TEXT[$capability],
                    'detail' => "no {$method} route is registered at '{$path}'"
                        // The single most common way to arrive here, and
                        // invisible from the screen: PUT is a perfectly valid
                        // registration that this derivation simply does not read.
                        . ($capability === 'canEdit' ? ' — editability is derived from PATCH, never PUT' : ''),
                ]
                : [
                    'code' => self::DENIED_FORBIDDEN,
                    'reason' => self::FORBIDDEN_TEXT[$capability],
                    'detail' => "{$method} {$path} requires " . self::describeRouteRbac($route),
                ];
        }

        return ['capabilities' => $capabilities, 'reasons' => $reasons];
    }

    /**
     * Name what a route demands, for the operator-grade `detail` (#951).
     *
     * Both requirements are named rather than only the one that failed: telling
     * them apart would mean asking the RoleChecker a second time for an answer
     * the reader can see for themselves, and an author fixing a screen wants the
     * route's whole gate anyway.
     *
     * @param array{requiredRole: ?string, requiredPermission: ?string} $route The route descriptor.
     */
    private static function describeRouteRbac(array $route): string
    {
        $demands = [];

        $requiredRole = $route['requiredRole'] ?? null;
        if (is_string($requiredRole)) {
            $demands[] = "role '{$requiredRole}'";
        }

        $requiredPermission = $route['requiredPermission'] ?? null;
        if (is_string($requiredPermission)) {
            $demands[] = "permission '{$requiredPermission}'";
        }

        // Unreachable while the caller failed the check — a route demanding
        // nothing is satisfied by everyone — but stated rather than assumed, so
        // the string is never the empty tail of a sentence.
        return $demands === [] ? 'no role or permission' : implode(' and ', $demands);
    }

    /**
     * Shape resolved denial reasons for the wire, applying the audience gate (#951).
     *
     * `reason` goes to everyone who could already see the feature; `detail` only
     * to a caller holding {@see CorePermissions::PLUGINS_READ}. The key is always
     * emitted so a client never has to distinguish "no detail for you" from "the
     * server forgot" — it is null in both the ungated and the not-applicable case,
     * and a client that renders it renders nothing.
     *
     * @param array<string, array{code: string, reason: string, detail: string}> $reasons
     * @param bool $includeDetail Whether this caller may see the operator detail.
     * @return array<string, array{code: string, reason: string, detail: string|null}>
     */
    private function publicReasons(array $reasons, bool $includeDetail): array
    {
        $public = [];
        foreach ($reasons as $capability => $denial) {
            $public[$capability] = [
                'code' => $denial['code'],
                'reason' => $denial['reason'],
                'detail' => $includeDetail ? $denial['detail'] : null,
            ];
        }

        return $public;
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
