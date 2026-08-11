<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\DataType\DataTypeDefinition;
use Whity\Core\DataType\DataTypeLifecycleService;
use Whity\Core\DataType\DataTypeRegistry;
use Whity\Core\DataType\LifecycleAction;
use Whity\Core\DataType\LifecycleResult;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;

/**
 * The generated lifecycle surface for plugin-declared data types (WC-723,
 * Door 2).
 *
 * One handler serves every registered type. That is the point: the shape of a
 * trash view, a restore, a retire and a delete-that-refuses is identical across
 * plugins, and only the field definitions and domain rules differ — so the
 * shape is generated once here and the differences arrive as declarations.
 *
 * Permissions vary PER TYPE, so these routes carry no route-level
 * `requiredPermission` and the handler gates itself. It fails closed in three
 * ways that matter:
 *
 *  - no resolved tenant → 403, before anything is read;
 *  - a type that does not OFFER the action (its lifecycle or its permission was
 *    never declared) → 405, never a silent success;
 *  - an action whose declared permission the caller lacks → 403 naming the
 *    permission, resolved through the same {@see RoleChecker} the middleware
 *    uses so the two can never give different answers.
 *
 * Honest degradation is enforced here as well as rendered: `GET /api/data-types`
 * publishes only the actions a type actually offers AND the caller may actually
 * use, so a generated screen that renders exactly what it is told can never
 * present a control the endpoint will refuse.
 */
final class DataTypesApiHandler
{
    private DataTypeRegistry $registry;

    private DataTypeLifecycleService $lifecycle;

    private RoleChecker $roleChecker;

    /**
     * @param DataTypeRegistry         $registry    Catalogue of declared types.
     * @param DataTypeLifecycleService $lifecycle   The single enforcement point.
     * @param RoleChecker              $roleChecker Authoritative permission resolution.
     */
    public function __construct(
        DataTypeRegistry $registry,
        DataTypeLifecycleService $lifecycle,
        RoleChecker $roleChecker
    ) {
        $this->registry = $registry;
        $this->lifecycle = $lifecycle;
        $this->roleChecker = $roleChecker;
    }

    /**
     * GET /api/data-types — the contract a generated admin surface renders from.
     *
     * Types the caller cannot read are omitted entirely rather than listed and
     * disabled: a type whose existence the caller may not learn should not be
     * advertised by its absence of a button.
     *
     * @param Request $request The incoming request.
     * @return Response The declared types, filtered per caller.
     */
    public function list(Request $request): Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        [$tenantId, $profileId] = $context;

        $data = [];
        foreach ($this->registry->all() as $definition) {
            if (!$this->may($definition, LifecycleAction::READ, $profileId, $tenantId)) {
                continue;
            }

            $payload = $definition->toArray();
            $payload['actions'] = array_values(array_filter(
                $definition->offeredActions(),
                fn (string $action): bool => $this->may($definition, $action, $profileId, $tenantId)
            ));
            $data[] = $payload;
        }

        return Response::json(['data' => $data]);
    }

    /**
     * GET /api/data-types/{type}/{id} — one record's lifecycle state, the
     * references that currently block deleting it, and why any unavailable
     * action is unavailable.
     *
     * This is what lets a generated screen refuse honestly BEFORE the user
     * clicks: the blockers arrive with the plugin's own labels attached, and
     * `refusals` carries a stable reason key per action so a disabled control
     * can explain itself rather than sitting there greyed out.
     *
     * Every ACTION-shaped boolean is exactly `!isset($refusals[$action])` —
     * `restorable` and `deletable` alike — so a `false` on an action is never
     * unexplained, whether the cause is a reference, the record's state, or the
     * type not offering the action at all. `referenceable` and `pending_removal`
     * are not actions: they are properties of the state that is published beside
     * them, so they carry no refusal and none is invented for them.
     *
     * ONE thing this cannot promise: a plugin veto
     * --------------------------------------------
     * `refusals` is the complete account of what CORE will refuse. It is NOT the
     * complete account of what will happen, because a plugin listening on
     * `datatype.lifecycle.changing` may refuse a transition for a domain reason
     * core cannot derive, and this endpoint does not dispatch that hook to find
     * out — a `GET` must not run plugin code (see
     * {@see DataTypeLifecycleService::describe()} for the full reasoning).
     *
     * So an action published here as available can still answer `409` with
     * `reason: "blocked_by_plugin"` when it is attempted. A client should render
     * its controls from `refusals` and stay able to surface that 409; treating
     * it as an unexpected error is the failure this note exists to prevent.
     *
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response The record's lifecycle description, or 404.
     */
    public function show(Request $request, array $params = []): Response
    {
        $resolved = $this->resolve($request, $params, LifecycleAction::READ);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$definition, $tenantId, , $id] = $resolved;

        $description = $this->lifecycle->describe($definition->key(), $tenantId, $id);
        if ($description === null) {
            return Response::error('Record not found', 404);
        }

        return Response::json(['data' => ['key' => $definition->key()] + $description]);
    }

    /**
     * POST /api/data-types/{type}/{id}/trash
     *
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response The transition outcome.
     */
    public function trash(Request $request, array $params = []): Response
    {
        return $this->transition($request, $params, LifecycleAction::TRASH);
    }

    /**
     * POST /api/data-types/{type}/{id}/restore
     *
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response The transition outcome.
     */
    public function restore(Request $request, array $params = []): Response
    {
        return $this->transition($request, $params, LifecycleAction::RESTORE);
    }

    /**
     * POST /api/data-types/{type}/{id}/retire
     *
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response The transition outcome.
     */
    public function retire(Request $request, array $params = []): Response
    {
        return $this->transition($request, $params, LifecycleAction::RETIRE);
    }

    /**
     * DELETE /api/data-types/{type}/{id} — remove the row for real, if every
     * declared guard permits it.
     *
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Captured path parameters.
     * @return Response The transition outcome; 409 with blockers when refused.
     */
    public function delete(Request $request, array $params = []): Response
    {
        return $this->transition($request, $params, LifecycleAction::DELETE);
    }

    /**
     * Resolve, authorize and perform one transition.
     *
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Captured path parameters.
     * @param string                $action  A {@see LifecycleAction} constant.
     */
    private function transition(Request $request, array $params, string $action): Response
    {
        $resolved = $this->resolve($request, $params, $action);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$definition, $tenantId, $profileId, $id] = $resolved;

        $result = match ($action) {
            LifecycleAction::TRASH => $this->lifecycle->trash($definition->key(), $tenantId, $id, $profileId),
            LifecycleAction::RESTORE => $this->lifecycle->restore($definition->key(), $tenantId, $id, $profileId),
            LifecycleAction::RETIRE => $this->lifecycle->retire($definition->key(), $tenantId, $id, $profileId),
            default => $this->lifecycle->delete($definition->key(), $tenantId, $id, $profileId),
        };

        return $this->respond($definition, $result);
    }

    /**
     * Turn a {@see LifecycleResult} into an HTTP response.
     *
     * A refusal keeps its blockers and its stable reason key, so a client can
     * both show the sentence and branch on the cause without parsing prose.
     *
     * A plugin veto arrives here as an ordinary refusal and needs no special
     * case: `409`, `reason: "blocked_by_plugin"`, and the plugin's own
     * client-safe sentence as the message. That uniformity is the point — one
     * envelope for "this transition did not happen, and here is why", whether
     * the reason was core's rule or a plugin's.
     */
    private function respond(DataTypeDefinition $definition, LifecycleResult $result): Response
    {
        if ($result->isOk()) {
            return Response::json([
                'data' => ['key' => $definition->key()] + $result->toArray(),
            ]);
        }

        return Response::error(
            $result->message(),
            $result->httpStatus(),
            [
                'reason' => $result->reason(),
                'state' => $result->state(),
                'blockers' => $result->blockers(),
            ]
        );
    }

    /**
     * Resolve the type, the tenant, the caller and the record id — refusing
     * before any data is touched when any of them is missing.
     *
     * An UNREGISTERED type is a 404, and so is a type the caller may not read:
     * whether a plugin declared `acme:record` is not something an unauthorized
     * caller should be able to probe by status code.
     *
     * @param Request               $request The incoming request.
     * @param array<string, string> $params  Captured path parameters.
     * @param string                $action  A {@see LifecycleAction} constant.
     * @return array{0: DataTypeDefinition, 1: int, 2: int, 3: string}|Response
     */
    private function resolve(Request $request, array $params, string $action): array|Response
    {
        $context = $this->context($request);
        if ($context instanceof Response) {
            return $context;
        }
        [$tenantId, $profileId] = $context;

        $definition = $this->registry->get((string) ($params['type'] ?? ''));
        if ($definition === null) {
            return Response::error('Unknown data type', 404);
        }
        if (!$this->may($definition, LifecycleAction::READ, $profileId, $tenantId)) {
            return Response::error('Unknown data type', 404);
        }

        if (!$definition->offers($action)) {
            return Response::error(
                "This data type does not offer the '{$action}' action",
                405,
                ['reason' => $action . '_not_offered']
            );
        }
        if (!$this->may($definition, $action, $profileId, $tenantId)) {
            return Response::error(
                'Insufficient permissions',
                403,
                ['required' => $definition->permissionFor($action)]
            );
        }

        $id = trim((string) ($params['id'] ?? ''));
        if ($id === '') {
            return Response::error('A record id is required', 400);
        }

        return [$definition, $tenantId, $profileId, $id];
    }

    /**
     * Whether the caller holds the permission a type declares for an action.
     *
     * An action with no declared permission is NOT open — it was never offered,
     * so nobody holds it. Fail closed.
     */
    private function may(DataTypeDefinition $definition, string $action, int $profileId, int $tenantId): bool
    {
        $permission = $definition->permissionFor($action);
        if ($permission === null) {
            return false;
        }

        return $this->roleChecker->hasPermissionForProfile($profileId, $permission, $tenantId);
    }

    /**
     * Resolve (tenantId, callerProfileId) or return an early error response.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 403);
        }

        $actor = $request->user;
        $profileId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($profileId === null) {
            return Response::error('Authentication is required', 403);
        }

        return [$tenantId, $profileId];
    }
}
