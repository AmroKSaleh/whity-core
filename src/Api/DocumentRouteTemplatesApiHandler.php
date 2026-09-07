<?php

declare(strict_types=1);

namespace Whity\Api;

use Whity\Core\Document\Routing\RouteQuorum;
use Whity\Core\Document\RouteTemplate\RouteTemplateGraph;
use Whity\Core\Document\RouteTemplate\RouteTemplatePresenter;
use Whity\Core\Document\RouteTemplate\RouteTemplateRejectedException;
use Whity\Core\Document\RouteTemplate\RouteTemplateRepository;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Settings\SettingsRegistry;
use Whity\Core\Settings\SettingsService;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\JsonBody;
use Whity\Http\PaginationParams;

/**
 * Document ROUTE TEMPLATES (#1027) — the API the node-based flow editor speaks.
 *
 *   GET    /api/document-route-templates              (route_templates:read)
 *   POST   /api/document-route-templates              (route_templates:write)
 *   GET    /api/document-route-templates/{id}         (route_templates:read)
 *   PATCH  /api/document-route-templates/{id}         (route_templates:write)
 *   PUT    /api/document-route-templates/{id}/graph   (route_templates:write)
 *   DELETE /api/document-route-templates/{id}         (route_templates:write)
 *
 * THE GRAPH IS ITS OWN VERB, AND THAT IS THE WHOLE SHAPE OF THIS SURFACE
 * ----------------------------------------------------------------------
 * Renaming a template and redrawing it are different acts by different people at
 * different moments, so `PATCH /{id}` carries the name and `PUT /{id}/graph`
 * carries the canvas. Folding them into one PATCH would mean every rename sent
 * the whole graph back — and a client that omitted it would be indistinguishable
 * from one that meant to clear it.
 *
 * `PUT`, not `PATCH`, on the graph: it REPLACES. The editor's unit of work is the
 * whole canvas, and {@see \Whity\Core\Document\RouteTemplate\RouteTemplateRepository::replaceGraph()}
 * records why a diff would put half the answer on the side of the wire that
 * cannot verify it.
 *
 * THERE IS NO PREVIEW ENDPOINT HERE, DELIBERATELY
 * -----------------------------------------------
 * "How many people does this node reach?" is already answered, exactly, by
 * #1003's preview — a count plus a bounded sample, with
 * `groups.preview_sample_size` behind it. The editor calls it per node and this
 * surface adds nothing.
 *
 * It is TWO endpoints rather than one, and the split is #999's rather than a
 * client-side preference: `POST /api/v1/user-groups/preview` resolves a rule
 * that could DEFINE a group, so it refuses `rule_kind: "group"` — a group cannot
 * be defined as another group. A group STAGE is a perfectly ordinary thing and
 * is previewed through `GET /api/v1/user-groups/{id}/preview` instead, which is
 * also the looser gate (`groups:read`).
 *
 * A preview of my own would be a second implementation of the resolver's
 * semantics — active memberships only, direct membership role, resource-scoped
 * grants excluded — free to drift from the first in whichever direction was last
 * edited. #1003's endpoint is gated on `groups:write` because it resolves an
 * ARBITRARY rule the caller composed, which is exactly what a template author is
 * doing; on any ordinary install the same people hold both, because migration 116
 * grants `groups:write` and migration 120 grants `route_templates:write` to the
 * same audience (`roles:write`). Where they do not, the editor renders the node
 * WITHOUT a count and says so, rather than this class quietly re-deriving one.
 *
 * WHAT A TEMPLATE CANNOT DO YET, AND WHY THAT IS SAID OUT LOUD
 * ------------------------------------------------------------
 * Nothing here instantiates a template onto a document. That needs the engine to
 * follow verdict edges — #1014's `DocumentRouter` work — and a template that
 * could be "applied" today would either flatten its branches into a linear route
 * (silently doing less than it draws) or write edges no engine reads. Both are
 * the stored-intention failure migration 112 names. It is filed as a follow-up
 * with the seam migration 112 already specifies: a nullable `template_id` plus a
 * `template_name` snapshot on `document_routes`.
 *
 * A template id is an enumerable integer, so an id belonging to another tenant is
 * reported as ABSENT (404) rather than forbidden — the posture
 * {@see \Whity\Core\Group\UserGroupRepository} and
 * {@see \Whity\Core\Document\DocumentVisibilityPolicy} already take, because a
 * 403 would confirm which ids exist.
 */
final class DocumentRouteTemplatesApiHandler
{
    /** Matches `document_route_templates.name`, and `user_groups.name` before it. */
    private const NAME_MAX = 160;

    /** Bounded for the reason the routing note is: a description is a sentence. */
    private const DESCRIPTION_MAX = 2000;

    public function __construct(
        private readonly RouteTemplateRepository $templates,
        private readonly RouteTemplateGraph $graph,
        private readonly SettingsService $settings,
    ) {
    }

    /**
     * GET /api/document-route-templates — this tenant's designs, by name.
     */
    public function index(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId] = $ctx;

        $p = PaginationParams::fromPath($request->getPath());
        $total = $this->templates->countForTenant($tenantId);
        $rows = $this->templates->listForTenant($tenantId, $p->perPage, $p->offset);

        return Response::json([
            'data' => array_map(RouteTemplatePresenter::template(...), $rows),
            'pagination' => $p->meta($total),
        ]);
    }

    /**
     * GET /api/document-route-templates/{id} — one design, with its graph.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $resolved = $this->resolveTemplate($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $template] = $resolved;

        $id = (int) $template['id'];

        return Response::json([
            'data' => RouteTemplatePresenter::graph(
                $template,
                $this->templates->stepsFor($id, $tenantId),
                $this->templates->edgesFor($id, $tenantId),
                $this->defaultQuorum($tenantId),
                $this->maxSteps($tenantId),
            ),
        ]);
    }

    /**
     * POST /api/document-route-templates — start a design.
     *
     * Creates it EMPTY. A template with no steps is what the editor opens onto,
     * and requiring a graph up front would make "new template" a call nobody can
     * make until they have already drawn one.
     */
    public function create(Request $request): Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $body = JsonBody::parsed($request);

        $name = $this->validName($body);
        if ($name instanceof Response) {
            return $name;
        }
        $description = $this->validDescription($body);
        if ($description instanceof Response) {
            return $description;
        }

        if ($this->templates->findByName($name, $tenantId) !== null) {
            return Response::error('A route template named "' . $name . '" already exists in this tenant.', 409);
        }

        $id = $this->templates->create($tenantId, $name, $description, $callerId);
        $created = $this->templates->findById($id, $tenantId);
        if ($created === null) {
            return Response::error('The route template could not be read back after creation', 500);
        }

        return Response::json(['data' => RouteTemplatePresenter::template($created)], 201);
    }

    /**
     * PATCH /api/document-route-templates/{id} — rename or re-describe.
     *
     * @param array<string, string> $params
     */
    public function update(Request $request, array $params): Response
    {
        $resolved = $this->resolveTemplate($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $template] = $resolved;

        $body = JsonBody::parsed($request);

        // Absent means unchanged; present means replace. Spelled with
        // array_key_exists rather than `??` so an explicit null on `description`
        // clears it instead of reading as "not sent".
        $name = (string) $template['name'];
        if (array_key_exists('name', $body)) {
            $valid = $this->validName($body);
            if ($valid instanceof Response) {
                return $valid;
            }
            $name = $valid;
        }

        $description = $template['description'] !== null ? (string) $template['description'] : null;
        if (array_key_exists('description', $body)) {
            $valid = $this->validDescription($body);
            if ($valid instanceof Response) {
                return $valid;
            }
            $description = $valid;
        }

        $existing = $this->templates->findByName($name, $tenantId);
        if ($existing !== null && (int) $existing['id'] !== (int) $template['id']) {
            return Response::error('A route template named "' . $name . '" already exists in this tenant.', 409);
        }

        $this->templates->update((int) $template['id'], $tenantId, $name, $description);

        $updated = $this->templates->findById((int) $template['id'], $tenantId);
        if ($updated === null) {
            return Response::error('The route template could not be read back after the update', 500);
        }

        return Response::json(['data' => RouteTemplatePresenter::template($updated)]);
    }

    /**
     * PUT /api/document-route-templates/{id}/graph — replace the whole canvas.
     *
     * @param array<string, string> $params
     */
    public function replaceGraph(Request $request, array $params): Response
    {
        $resolved = $this->resolveTemplate($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $template] = $resolved;

        $body = JsonBody::parsed($request);

        // `steps` absent is NOT the same as `steps: []`. The first is a caller
        // that forgot the field and would be told it cleared the design; the
        // second is an author who really did delete every node, which is a
        // legitimate thing to save.
        if (!array_key_exists('steps', $body)) {
            return Response::error(
                "'steps' is required. Send an empty array to clear the template's graph.",
                422
            );
        }

        try {
            $validated = $this->graph->validate(
                $body['steps'],
                $body['edges'] ?? [],
                $this->maxSteps($tenantId),
            );
        } catch (RouteTemplateRejectedException $e) {
            // The message is the one this class's own validator wrote for this
            // author, or a rule resolver's own text about its own config — never
            // an arbitrary throwable's. See the exception's docblock for why that
            // distinction has its own field.
            return Response::error($e->clientMessage, 422);
        }

        $id = (int) $template['id'];
        $this->templates->replaceGraph($id, $tenantId, $validated['steps'], $validated['edges']);

        $saved = $this->templates->findById($id, $tenantId);
        if ($saved === null) {
            return Response::error('The route template could not be read back after the save', 500);
        }

        return Response::json([
            'data' => RouteTemplatePresenter::graph(
                $saved,
                $this->templates->stepsFor($id, $tenantId),
                $this->templates->edgesFor($id, $tenantId),
                $this->defaultQuorum($tenantId),
                $this->maxSteps($tenantId),
            ),
        ]);
    }

    /**
     * DELETE /api/document-route-templates/{id} — discard a design.
     *
     * Nothing is checked first. Routes already issued from this template carry
     * their own step rows, written when the document was issued, and no running
     * circulation reads back through a template — so deleting a design cannot
     * change the history of anything that followed it.
     *
     * @param array<string, string> $params
     */
    public function destroy(Request $request, array $params): Response
    {
        $resolved = $this->resolveTemplate($request, $params);
        if ($resolved instanceof Response) {
            return $resolved;
        }
        [$tenantId, , $template] = $resolved;

        $id = (int) $template['id'];
        $this->templates->delete($id, $tenantId);

        return Response::json(['data' => ['id' => $id, 'deleted' => true]]);
    }

    // -- helpers -------------------------------------------------------------

    /**
     * Tenant + caller, or the Response that says which is missing.
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
     * Context plus the addressed template, or the Response that refuses.
     *
     * @param array<string, string> $params
     * @return array{0: int, 1: int, 2: array<string, mixed>}|Response
     */
    private function resolveTemplate(Request $request, array $params): array|Response
    {
        $ctx = $this->context($request);
        if ($ctx instanceof Response) {
            return $ctx;
        }
        [$tenantId, $callerId] = $ctx;

        $id = (int) ($params['id'] ?? 0);
        $template = $id > 0 ? $this->templates->findById($id, $tenantId) : null;
        if ($template === null) {
            return Response::error('Route template not found', 404);
        }

        return [$tenantId, $callerId, $template];
    }

    /**
     * A usable `name`, or the Response that refuses it.
     *
     * @param array<string, mixed> $body
     */
    private function validName(array $body): string|Response
    {
        $name = $body['name'] ?? null;
        if (!is_string($name)) {
            return Response::error("'name' is required and must be text", 422);
        }
        $name = trim($name);
        if ($name === '') {
            return Response::error("'name' cannot be empty", 422);
        }
        if (mb_strlen($name) > self::NAME_MAX) {
            return Response::error("'name' must be " . self::NAME_MAX . ' characters or fewer', 422);
        }

        return $name;
    }

    /**
     * A usable `description`, or the Response that refuses it.
     *
     * @param array<string, mixed> $body
     */
    private function validDescription(array $body): string|null|Response
    {
        $description = $body['description'] ?? null;
        if ($description === null) {
            return null;
        }
        if (!is_string($description)) {
            return Response::error("'description' must be text or null", 422);
        }
        $description = trim($description);
        if ($description === '') {
            return null;
        }
        if (mb_strlen($description) > self::DESCRIPTION_MAX) {
            return Response::error(
                "'description' must be " . self::DESCRIPTION_MAX . ' characters or fewer',
                422
            );
        }

        return $description;
    }

    /**
     * Per-tenant, then global, then the registry default. Never hardcoded.
     *
     * The same chain and the same treatment of a bad stored value as
     * {@see \Whity\Core\Document\Routing\DocumentRouter}: a non-numeric or
     * non-positive value falls back to the default rather than disabling the
     * ceiling, because a "0" typed into a settings field must not silently mean
     * "no limit".
     */
    private function maxSteps(int $tenantId): int
    {
        $effective = $this->settings->effective($tenantId);
        $raw = $effective[SettingsRegistry::DOCUMENTS_ROUTING_MAX_STEPS] ?? null;
        if (is_string($raw) && preg_match('/^\d+$/', $raw) === 1 && (int) $raw > 0) {
            return (int) $raw;
        }

        return max(1, (int) SettingsRegistry::defaultFor(SettingsRegistry::DOCUMENTS_ROUTING_MAX_STEPS));
    }

    /**
     * What a step with no explicit quorum will actually do.
     *
     * Read by KEY rather than through a registry constant, because the key is
     * #1014's and is not in `SettingsRegistry` on this branch — see
     * {@see SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM}. Until it lands the
     * lookup finds nothing and every tenant gets #1014's own default; the day it
     * lands, this starts honouring per-tenant overrides with no change here.
     *
     * A stored value outside the vocabulary falls back to the default rather than
     * being echoed. The editor DRAWS this string beside every node without an
     * explicit quorum, and drawing a word the engine does not recognise would tell
     * an author their flow behaves in a way it does not.
     */
    private function defaultQuorum(int $tenantId): string
    {
        $effective = $this->settings->effective($tenantId);
        $raw = $effective[SettingsRegistry::DOCUMENTS_ROUTING_APPROVAL_QUORUM] ?? null;

        return is_string($raw) && RouteQuorum::isValid($raw)
            ? $raw
            : RouteQuorum::ALL;
    }
}
