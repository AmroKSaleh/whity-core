<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Auth\RoleChecker;
use Whity\Core\Document\DocumentRepository;
use Whity\Core\Document\DocumentVisibilityPolicy;
use Whity\Core\Document\RouteTemplate\RouteTemplateInstantiation;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Document\Routing\DocumentRouter;
use Whity\Core\Document\Routing\RouteAction;
use Whity\Core\Document\Routing\RouteEdgeRepository;
use Whity\Core\Document\Routing\RouteEventRepository;
use Whity\Core\Document\Routing\RouteRecipientRepository;
use Whity\Core\Document\Routing\RouteRepository;
use Whity\Core\Document\Routing\RouteStepRepository;
use Whity\Core\Document\Routing\RouteVerdict;
use Whity\Core\Document\Routing\RoutingPresenter;
use Whity\Core\Document\Routing\RoutingRejectedException;
use Whity\Core\Document\Routing\RoutingRuleLabels;
use Whity\Core\Document\Routing\RoutingRuleRegistry;
use Whity\Core\i18n\ServerLabels;
use Whity\Core\RBAC\CorePermissions;
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
 *   POST /api/documents/{id}/routes/from-template  (documents:route + route_templates:read)
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
 * A DECISION STEP CHANGES WHAT `/actions` ACCEPTS (#1014)
 * ------------------------------------------------------
 * On a step marked `decision`, the only answer is `acknowledged` carrying a
 * `verdict` of `approved` or `rejected`; `forwarded` is refused, and a verdict
 * on a circulation step is refused too. Both refusals are 422s that say which
 * kind of step the caller is standing on, because the alternative — accepting
 * and ignoring — writes an approval nobody asked for onto a trail that cannot be
 * corrected.
 *
 * The response's `decided` is NOT the caller's own verdict. It is what the STEP
 * concluded, which stays null while a quorum is still short: under the default
 * `all`, two of three approvals conclude nothing, and a client that rendered the
 * caller's verdict as the outcome would tell two people a document was approved
 * before it was.
 *
 * The step is not gated by a permission any more than acting is. Whether a
 * verdict is available to you is decided by the route that reached you, not by a
 * tenant-wide grant — see above.
 *
 * For the same reason the route READ publishes `default_quorum` (#1041): a step
 * whose `decision_quorum` is null defers to the tenant's setting, and the person
 * being asked to approve is the least likely person in the tenant to be able to
 * read that setting. Sending it with the route is what lets a screen say "all
 * three of you must approve" instead of naming a rule it had to guess.
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
        private readonly RouteEdgeRepository $edges,
        private readonly DocumentRouter $router,
        private readonly RoutingRuleRegistry $rules,
        private readonly DocumentVisibilityPolicy $visibility,
        private readonly RoleChecker $roleChecker,
        private readonly RouteTemplateRepository $templates,
        private readonly ServerLabels $labels,
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

        // Localised at SERVING time, not at declaration time (#1044): the wording
        // depends on who is asking, and the registry is a process-wide singleton
        // shared by every tenant and every language on the instance.
        return Response::json([
            'data' => RoutingRuleLabels::localise($this->rules->catalogue(), $this->labels),
        ]);
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
            'data' => RoutingPresenter::route(
                $issued['route'],
                $issued['steps'],
                $issued['edges'],
                $this->router->defaultQuorum($tenantId),
            ),
            'resolved' => $issued['resolved'],
            'delivered' => $issued['delivered'],
        ], 201);
    }

    /**
     * POST /api/documents/{id}/routes/from-template — apply a design (#1031).
     *
     * WHY THIS IS ITS OWN ENDPOINT AND NOT A FIELD ON `POST .../routes`
     * -----------------------------------------------------------------
     * The two requests have disjoint bodies: one carries a `steps` array the
     * caller composed, the other carries a `template_id` and nothing else. Folded
     * into one endpoint they would need an "exactly one of" rule, and a caller
     * that sent both would be answered by whichever check happened to run first.
     * More importantly the two differ in what the SERVER may assert: a route
     * issued here carries provenance the server derived, and one issued there
     * carries none — a distinction that would evaporate the moment a client could
     * send `template_id` alongside its own hand-written steps and have the pair
     * stored as though the design produced them.
     *
     * TWO PERMISSIONS, AND THE SECOND IS CHECKED HERE RATHER THAN AT THE ROUTE
     * ------------------------------------------------------------------------
     * The route gates on `documents:route`, because issuing a circulation is the
     * act. Reading somebody's DESIGN is a second question — the 201 body contains
     * every stage of it — so `route_templates:read` is required too, and the
     * router carries one permission per route. Migration 120 grants that slug to
     * `documents:route` holders precisely because "the people who will pick one
     * when routing a document" are an audience for it, so on an ordinary install
     * the check never fires; on a deployment that revoked it deliberately, it
     * does, and it says which slug is missing rather than reporting the template
     * as absent.
     *
     * THE STEP CEILING IS THE ENGINE'S, DELIBERATELY
     * -----------------------------------------------
     * #1031 asks that a template exceeding `documents.routing_max_steps` be
     * refused AT THIS MOMENT rather than only when it was authored, because the
     * setting can move in between. It is —
     * {@see \Whity\Core\Document\Routing\DocumentRouter::validateSteps()} resolves
     * the tenant's effective value on every issue and refuses with a message
     * naming both numbers and the setting to raise. A second check here would be
     * a second reading of one tenant-configurable number.
     *
     * @param array<string, string> $params
     */
    public function createFromTemplate(Request $request, array $params): Response
    {
        $resolved = $this->resolveVisibleDocument($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, $callerId, $document] = $resolved;

        if (!$this->permissionResolver($callerId, $tenantId)(CorePermissions::ROUTE_TEMPLATES_READ)) {
            return Response::error(
                'Applying a route template requires ' . CorePermissions::ROUTE_TEMPLATES_READ,
                403
            );
        }

        $body = JsonBody::parsed($request);

        $templateId = $body['template_id'] ?? null;
        if (!is_int($templateId) || $templateId < 1) {
            return Response::error("'template_id' must be the id of a route template", 422);
        }

        $template = $this->templates->findById($templateId, $tenantId);
        if ($template === null) {
            // Absent, never forbidden: a template id is an enumerable integer and
            // a 403 would confirm which ids exist. Same posture as the templates
            // surface itself.
            return Response::error('Route template not found', 404);
        }

        $title = $body['title'] ?? null;
        // Falls back to the DESIGN's name rather than the document's, which is
        // the one place this endpoint differs from its hand-composed sibling: an
        // author who applied "Purchase approval" is naming the circulation after
        // the flow it follows, and a list of routes on a document reads better
        // for it. `template_name` is stored separately regardless, so the two do
        // not become one fact.
        $title = is_string($title) && trim($title) !== ''
            ? trim($title)
            : (string) $template['name'];
        if (mb_strlen($title) > 255) {
            return Response::error("'title' must be 255 characters or fewer", 422);
        }

        try {
            $steps = RouteTemplateInstantiation::toRouteSteps(
                $this->templates->stepsFor($templateId, $tenantId),
                $this->templates->edgesFor($templateId, $tenantId),
            );
        } catch (RouteTemplateRejectedException $e) {
            // ->clientMessage, never ->getMessage(): the same rule the routing
            // exception below follows, and ExceptionLeakageTest enforces it
            // statically over this directory.
            return Response::error($e->clientMessage, 422);
        }

        try {
            $issued = $this->router->issue(
                $tenantId,
                $callerId,
                $document,
                $title,
                $steps,
                $templateId,
                (string) $template['name'],
            );
        } catch (RoutingRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        }

        return Response::json([
            'data' => RoutingPresenter::route(
                $issued['route'],
                $issued['steps'],
                $issued['edges'],
                // #1041's `default_quorum`, resolved through the ENGINE's own
                // reader rather than re-derived here. An applied design is the
                // route most likely to carry a gate with no explicit quorum —
                // the canvas leaves it NULL by default — so omitting it would
                // publish `all` to the one reader who most needs the tenant's
                // real answer, on the response that first shows them the gate.
                $this->router->defaultQuorum($tenantId),
            ),
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
        // Resolved ONCE for the whole page rather than per route: it is a fact
        // about the tenant, and asking the settings chain once per route would
        // make a document with forty circulations forty reads of the same row.
        $defaultQuorum = $this->router->defaultQuorum($tenantId);
        $data = array_map(
            fn (array $route): array => RoutingPresenter::route(
                $route,
                $this->steps->listForRoute((int) $route['id'], $tenantId),
                $this->edges->listForRoute((int) $route['id'], $tenantId),
                $defaultQuorum,
                // #1037: how many times each step has sent the document back.
                // Only here — the two issuing paths above publish a route that
                // was created moments ago, where zero is not a default but the
                // fact.
                $this->events->rejectionCountsByStep((int) $route['id'], $tenantId),
                // #1140: how many cohorts each step has opened, which is how
                // many times it SETTLED. Read here for the same reason and with
                // the same caveat — a route published at issue time has opened
                // exactly the cohorts its first act opened, and reporting that
                // as history would be reporting the present as the past.
                $this->recipients->cohortCountsByStep((int) $route['id'], $tenantId),
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

        // #1014. Validated for SHAPE here and for FITNESS in the engine, which is
        // the same split the action vocabulary already has: this check can name
        // the two verdicts, while only the engine knows whether the step the
        // caller is standing on is a gate at all.
        $verdict = $body['verdict'] ?? null;
        if ($verdict !== null && (!is_string($verdict) || !RouteVerdict::isValid($verdict))) {
            return Response::error(
                "'verdict' must be one of: " . implode(', ', RouteVerdict::all()),
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
            $outcome = $this->router->act(
                $tenantId,
                $callerId,
                $route,
                $action,
                $note,
                is_string($verdict) ? $verdict : null,
            );
        } catch (RoutingRejectedException $e) {
            return Response::error($e->clientMessage, 422);
        }

        return Response::json([
            'data' => RoutingPresenter::event($outcome['event']),
            'resolved' => $outcome['resolved'],
            'delivered' => $outcome['delivered'],
            // What the STEP concluded, which is not what the caller said: under a
            // quorum of `all`, two of three approvals conclude nothing. Null
            // while the step is still open, and the reason it is on the envelope
            // rather than on the event is the reason `resolved`/`delivered` are —
            // it describes what THIS request did, not a property of the record.
            'decided' => $outcome['decided'],
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
