<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\RBAC\ScopedPermissionSet;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;

/**
 * Document routing (#947 item 3):
 *
 *   GET  /api/routing-rules                        (documents:read)
 *   POST /api/documents/{id}/routes                (documents:route)
 *   GET  /api/documents/{id}/routes                (documents:read)
 *   GET  /api/documents/{id}/trail                 (documents:read)
 *   GET  /api/documents/{id}/recipients            (documents:read)
 *   POST /api/documents/{id}/routes/{routeId}/actions   (session only)
 *
 * These are the three views #978 needs: the composer reads `/routing-rules` and
 * writes a route, the trail view reads `/trail`, and acting posts `/actions`.
 *
 * TWO DIFFERENT KINDS OF GATE, AND THE ONE THAT IS NOT A PERMISSION
 * ----------------------------------------------------------------
 * Issuing a route is `documents:route` (migration 113). ACTING on an item that
 * reached you is session-gated with NO permission, because BEING A RECIPIENT IS
 * THE AUTHORIZATION: the route named a rule, the rule resolved to you, and the
 * engine wrote the row. A second permission on top would let a route resolve to
 * somebody who then cannot answer it — the item sits open forever, the chain
 * never advances, and the person holding it cannot discover why.
 *
 * That mirrors `/api/me/notifications` and `/api/me/sessions`, which are
 * unpermissioned for the same structural reason: the row already names exactly
 * one person, so the tenant-wide question has no work left to do.
 *
 * `noted` is the exception among the acts — it needs no open item, only
 * visibility of the document, because the person best placed to correct the
 * record is often one who has already acted and whose row is closed.
 *
 * NO 403s ON A MISS
 * -----------------
 * A document the caller may not see is reported as missing. A 403 confirms the
 * id exists, which for an enumerable integer id leaks the shape of the tenant's
 * activity — the same posture {@see DocumentsApiHandler} already takes.
 *
 * WHY THE ROUTE-CREATION RESPONSE REPORTS COUNTS
 * ----------------------------------------------
 * `resolved` and `delivered` are in the 201 body. A rule that matched nobody is
 * a legal answer (the role exists and nobody holds it), and the whole argument
 * for rules over stored lists is that failures are VISIBLE rather than silent —
 * so the author finds out in the response that step 1 reached zero people,
 * rather than six weeks later in a complaint. Reporting both distinguishes "the
 * rule found nobody" from "the rule found people who already had it".
 */
final class DocumentRoutingApiHandler
{
    public function __construct(
        private readonly DocumentRepository $documents,
        private readonly RouteRepository $routes,
        private readonly RouteStepRepository $steps,
        private readonly RouteEventRepository $events,
        private readonly RouteRecipientRepository $recipients,
        private readonly DocumentRouter $router,
        private readonly RoutingRuleRegistry $rules,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly RoleChecker $roleChecker,
    ) {
    }

    /**
     * GET /api/routing-rules — what a route step may name on this instance.
     *
     * Instance-wide rather than per-tenant: the catalogue is CODE (core's two
     * plus whatever the installed plugins registered), so it is the same for
     * every tenant on the install. Gated on `documents:read` because it is only
     * useful to somebody composing a route, and an unauthenticated reader would
     * learn which plugins are installed.
     */
    public function rules(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }

        return Response::json(['data' => $this->rules->catalogue()]);
    }

    /**
     * POST /api/documents/{id}/routes — issue a route.
     *
     * @param array<string, string> $params
     */
    public function create(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        $body = JsonBody::parsed($request);

        $steps = $body['steps'] ?? null;
        if (!is_array($steps)) {
            return Response::error("'steps' must be an array of route steps", 422);
        }
        // A JSON object with numeric-ish keys decodes to an associative array,
        // and the engine indexes positions from the ORDER of the list. Refusing
        // a non-list here rather than silently re-indexing means a client that
        // sent `{"1": …, "0": …}` is told, instead of getting its steps in an
        // order it did not choose.
        if (array_values($steps) !== $steps) {
            return Response::error("'steps' must be a JSON array, not an object", 422);
        }

        $title = $body['title'] ?? null;
        // Falls back to the document's own title: a route is a circulation OF
        // something, and making the author name it twice buys nothing.
        $title = is_string($title) && trim($title) !== ''
            ? trim($title)
            : (string) $document['title'];
        if (mb_strlen($title) > 255) {
            return Response::error("'title' must be 255 characters or fewer", 422);
        }

        /** @var list<array<string, mixed>> $declared */
        $declared = [];
        foreach ($steps as $step) {
            if (!is_array($step)) {
                return Response::error('Each entry in \'steps\' must be an object', 422);
            }
            $declared[] = $step;
        }

        try {
            $issued = $this->router->issue($tenantId, $callerId, $document, $title, $declared);
        } catch (RoutingRejectedException $e) {
            // ->clientMessage, never ->getMessage(): the exception wraps text a
            // PLUGIN wrote, so the two strings have to stay distinguishable. See
            // the exception's docblock.
            return Response::error($e->clientMessage, 422);
        }

        return Response::json([
            'data' => RoutingPresenter::route($issued['route'], $issued['steps']),
            'resolved' => $issued['resolved'],
            'delivered' => $issued['delivered'],
        ], 201);
    }

    /**
     * GET /api/documents/{id}/routes — the circulations of a document.
     *
     * @param array<string, string> $params
     */
    public function list(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;

        $documentId = (int) $document['id'];
        $data = array_map(
            fn (array $route): array => RoutingPresenter::route(
                $route,
                $this->steps->listForRoute((int) $route['id'], $tenantId)
            ),
            $this->routes->listForDocument($documentId, $tenantId)
        );

        return Response::json(['data' => $data]);
    }

    /**
     * GET /api/documents/{id}/trail — the append-only trail, oldest first.
     *
     * Paginated, because a widely circulated document accumulates events without
     * bound and a trail view that fetches all of them is a view that stops
     * loading at some point nobody predicted. Oldest first because a trail is
     * read as a story.
     *
     * @param array<string, string> $params
     */
    public function trail(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;

        $documentId = (int) $document['id'];
        $p = PaginationParams::fromPath($request->getPath());
        $total = $this->events->countForDocument($documentId, $tenantId);
        $rows = $this->events->listForDocument($documentId, $tenantId, $p->perPage, $p->offset);

        return Response::json([
            'data' => array_map(RoutingPresenter::event(...), $rows),
            'pagination' => $p->meta($total),
        ]);
    }

    /**
     * GET /api/documents/{id}/recipients — who the document's routes reached.
     *
     * Separate from the trail rather than folded into it: the trail says what
     * happened, this says where the document currently IS. Both are needed and
     * neither is derivable from the other in one query — which is the same
     * reason the two tables exist.
     *
     * @param array<string, string> $params
     */
    public function recipients(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $document] = $resolved;

        $rows = $this->recipients->listForDocument((int) $document['id'], $tenantId);

        return Response::json(['data' => array_map(RoutingPresenter::recipient(...), $rows)]);
    }

    /**
     * POST /api/documents/{id}/routes/{routeId}/actions — act on the route.
     *
     * Session-gated, no permission: see the class docblock. The route is bound
     * to the document in the path as well as to the tenant, so a guessed route
     * id cannot be walked onto another document.
     *
     * @param array<string, string> $params
     */
    public function act(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        $routeId = (int) ($params['routeId'] ?? 0);
        $route = $routeId > 0 ? $this->routes->findById($routeId, $tenantId) : null;
        if ($route === null || (int) $route['document_id'] !== (int) $document['id']) {
            return Response::error('Route not found', 404);
        }

        $body = JsonBody::parsed($request);
        $action = $body['action'] ?? null;
        if (!is_string($action) || $action === '') {
            return Response::error("'action' is required", 422);
        }

        // The vocabulary is validated at the boundary AND CHECK-constrained by
        // the schema. Not redundancy: the boundary check produces a message
        // naming the four available verbs, where the constraint would produce a
        // driver error the caller cannot act on.
        $allowed = array_merge(RouteAction::recipientActions(), [RouteAction::NOTED]);
        if (!in_array($action, $allowed, true)) {
            return Response::error(
                "'action' must be one of: " . implode(', ', $allowed),
                422
            );
        }

        $note = $body['note'] ?? null;
        if ($note !== null && !is_string($note)) {
            return Response::error("'note' must be a string when present", 422);
        }
        if (is_string($note) && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            return Response::error(
                "'note' must be " . self::MAX_NOTE_LENGTH . ' characters or fewer',
                422
            );
        }

        try {
            $outcome = $this->router->act($tenantId, $callerId, $route, $action, $note);
        } catch (RoutingRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        }

        return Response::json([
            'data' => RoutingPresenter::event($outcome['event']),
            'resolved' => $outcome['resolved'],
            'delivered' => $outcome['delivered'],
        ], 201);
    }

    /**
     * The ceiling on a note's length.
     *
     * `note` is TEXT, so the database imposes none. A bound exists because the
     * trail is append-only: a megabyte pasted into a note cannot be edited down
     * afterwards, and every reader of that document's trail pays for it forever.
     * Not a tenant setting, deliberately — it is a structural property of an
     * immutable record rather than a capacity an operator would tune, and
     * per-tenant limits on an append-only table would make the same trail legal
     * to write and illegal to re-write after a tenant move.
     */
    private const MAX_NOTE_LENGTH = 4000;

    // -- helpers -------------------------------------------------------------

    /**
     * Resolve the tenant, the caller, and a document the caller may see.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolveVisibleDocument(Request $request, array $params): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $document = $this->documents->findById((int) ($params['id'] ?? 0), $tenantId);
        if ($document === null
            || !$this->visibility->canView($document, $callerId, $this->permissionResolver($callerId, $tenantId))) {
            return Response::error('Document not found', 404);
        }

        return [$tenantId, $callerId, $document];
    }

    /**
     * Resolve (tenantId, callerProfileId) or an early error Response. Mirrors
     * {@see DocumentsApiHandler::context()}.
     *
     * @return array{0: int, 1: int}|Response
     */
    private function context(Request $request): array|Response
    {
        $tenantId = TenantContext::getTenantId();
        if ($tenantId === null) {
            return Response::error('Tenant context is required', 400);
        }
        $actor = $request->user;
        $callerId = is_object($actor) && isset($actor->profile_id) && is_int($actor->profile_id)
            ? $actor->profile_id
            : null;
        if ($callerId === null) {
            return Response::error('Authentication required', 401);
        }

        return [$tenantId, $callerId];
    }

    /**
     * @return callable(string, int|null=): bool
     */
    private function permissionResolver(int $callerId, int $tenantId): callable
    {
        return ScopedPermissionSet::forProfile($this->roleChecker, $callerId, $tenantId);
    }
}
