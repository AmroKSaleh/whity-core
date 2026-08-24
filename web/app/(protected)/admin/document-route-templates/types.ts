import type {
  RouteFlowGraph,
  RouteFlowQuorum,
  RouteFlowStep,
  RouteFlowVerdict,
} from '@amroksaleh/ui/route-flow/model';

/**
 * Wire types for the route-template surface (#1027).
 *
 * Mirrors `RouteTemplatePresenter` (`src/Core/Document/RouteTemplate/RouteTemplatePresenter.php`)
 * field for field.
 *
 * Hand-written rather than taken from `@/lib/api/schema`, matching the
 * convention its nearest neighbours state (`routing-wire.ts`,
 * `document-library/types.ts`): the generated client is built from the DYNAMIC
 * `/api/openapi.json`, which describes whatever plugin routes a given install
 * happens to have loaded, so it is not a stable description of a CORE resource.
 * Depending on it would make a core screen's types a function of the install
 * that last ran `npm run generate:api`.
 *
 * SNAKE CASE ON THE WIRE, CAMEL CASE IN THE KIT
 * ---------------------------------------------
 * The kit's model speaks camelCase because that is what a TypeScript component
 * library reads like to the three clients that consume it, and the API speaks
 * snake_case because that is what the rest of this backend speaks. The mapping
 * is {@link toFlowGraph} / {@link toGraphRequest}, in one place, so neither side
 * has to know about the other's spelling and a field added to one is a compile
 * error until it is added to the other.
 */

export interface RouteTemplateSummary {
  id: number;
  name: string;
  description: string | null;
  step_count: number;
  created_by: number | null;
  created_at: string;
  updated_at: string;
}

export interface RouteTemplateStepWire {
  position: number;
  rule_kind: string;
  rule_config: Record<string, unknown>;
  label: string | null;
  decision: boolean;
  /** `null` means "follow the tenant setting" — NOT "no quorum". */
  decision_quorum: RouteFlowQuorum | null;
  canvas_x: number;
  canvas_y: number;
}

export interface RouteTemplateEdgeWire {
  from: number;
  to: number;
  verdict: RouteFlowVerdict;
}

export interface RouteTemplateGraphWire extends RouteTemplateSummary {
  /** What a step with a null `decision_quorum` will actually do. */
  default_quorum: RouteFlowQuorum;
  /** The tenant's `documents.routing_max_steps` — the authoritative ceiling. */
  max_steps: number;
  steps: RouteTemplateStepWire[];
  edges: RouteTemplateEdgeWire[];
}

/** The wire graph, as the kit editor holds it. */
export function toFlowGraph(wire: RouteTemplateGraphWire): RouteFlowGraph {
  return {
    defaultQuorum: wire.default_quorum,
    steps: wire.steps.map(
      (s): RouteFlowStep => ({
        position: s.position,
        ruleKind: s.rule_kind,
        ruleConfig: s.rule_config,
        label: s.label,
        decision: s.decision,
        decisionQuorum: s.decision_quorum,
        canvasX: s.canvas_x,
        canvasY: s.canvas_y,
      })
    ),
    edges: wire.edges.map((e) => ({ from: e.from, to: e.to, verdict: e.verdict })),
  };
}

/**
 * The body for `PUT /api/v1/document-route-templates/{id}/graph`.
 *
 * `default_quorum` and `max_steps` are deliberately NOT sent back. They are
 * facts about the tenant that the server owns and re-derives on every read; a
 * client that echoed them would be offering to overwrite a setting from a canvas.
 */
export function toGraphRequest(graph: RouteFlowGraph): {
  steps: RouteTemplateStepWire[];
  edges: RouteTemplateEdgeWire[];
} {
  return {
    steps: graph.steps.map((s) => ({
      position: s.position,
      rule_kind: s.ruleKind,
      rule_config: s.ruleConfig,
      label: s.label,
      decision: s.decision,
      decision_quorum: s.decisionQuorum,
      canvas_x: s.canvasX,
      canvas_y: s.canvasY,
    })),
    edges: graph.edges.map((e) => ({ from: e.from, to: e.to, verdict: e.verdict })),
  };
}

/**
 * The shape both of #1003's preview endpoints answer with.
 *
 * The count field is `total`, NOT `count` — verified against a running server
 * rather than assumed. The first draft of this screen read `count`, typechecked,
 * linted, and would have rendered an empty audience line on every node while
 * looking entirely healthy: exactly the class of failure a screen full of
 * fallbacks hides.
 *
 * Only `total` is read. The `sample` of names it also returns is deliberately
 * ignored: this screen shows HOW MANY people a node reaches and never who they
 * are, because a surface that rendered 1,043 rows would have rebuilt the
 * thousand-node problem the whole design exists to avoid.
 */
export interface AudiencePreviewWire {
  data: {
    total: number;
  };
}

/**
 * Which preview endpoint answers for a given rule kind.
 *
 * Not one endpoint for everything, because #999 deliberately refuses one case:
 * `POST /user-groups/preview` resolves a rule that could DEFINE a group, and a
 * group cannot be defined as another group, so it rejects `rule_kind: "group"`
 * outright — with a message about defining groups, which would be baffling
 * beside a route stage that legitimately names one.
 *
 * A group STAGE is previewed by the stored group it names instead:
 * `GET /user-groups/{id}/preview`. Same resolver, same `preview_sample_size`,
 * same shape — and gated on `groups:read` rather than `groups:write`, so it is
 * the LOOSER of the two, which means a group node is the node most likely to be
 * able to show its size.
 *
 * Returns null when the config cannot address a preview at all (a stage whose
 * rule kind is not yet configured), so the caller shows no audience line rather
 * than a failed request.
 */
export function audiencePreviewRequest(
  ruleKind: string,
  ruleConfig: Record<string, unknown>
): { url: string; init?: RequestInit } | null {
  if (ruleKind === 'group') {
    const groupId = ruleConfig.group_id;
    if (typeof groupId !== 'number') {
      return null;
    }
    return { url: `/api/v1/user-groups/${groupId}/preview` };
  }

  if (Object.keys(ruleConfig).length === 0) {
    return null;
  }

  return {
    url: '/api/v1/user-groups/preview',
    init: {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ rule_kind: ruleKind, rule_config: ruleConfig }),
    },
  };
}
