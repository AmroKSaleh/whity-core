/**
 * Wire types for APPLYING a route template to a document (#1031).
 *
 * A separate module from `routing-wire.ts` rather than an addition to it, and
 * the split follows the surfaces: `routing-wire` describes a route that
 * HAPPENED — steps, events, recipients — while these describe a DESIGN that has
 * not happened to anything yet. The composer is the one screen that holds both,
 * which is precisely why keeping them apart is worth doing: the fields have
 * similar names and different lifetimes, and a `RouteStep` and a
 * `RouteTemplateStep` are not interchangeable however alike they read.
 *
 * Hand-written rather than taken from `@/lib/api/schema`, for the reason
 * `routing-wire.ts` records in full: the generated client is built from the
 * DYNAMIC `/api/openapi.json`, which describes whatever plugin routes a given
 * install happens to have loaded, so depending on it would make a core screen's
 * types a function of the install that last ran `npm run generate:api`.
 *
 * A STEP IS ADDRESSED BY `position`, IN BOTH DIRECTIONS
 * -----------------------------------------------------
 * There are no step ids here, and that is the server's contract rather than an
 * omission: saving a graph REPLACES rather than diffs, so template step ids
 * churn on every save, and a client holding one across a save would be wrong the
 * first time somebody dragged a node. `edges` therefore names positions at both
 * ends. See `RouteTemplatePresenter`.
 */

/** One row of `GET /api/v1/document-route-templates`. */
export interface RouteTemplateSummary {
  id: number;
  name: string;
  description: string | null;
  /**
   * How many stages the design has.
   *
   * Rendered beside the name because "apply this" is a decision about a thing
   * the author may not have drawn, and a design with none cannot be applied at
   * all — the server refuses it. There is deliberately no resolved-PEOPLE count
   * here: that would mean resolving every rule of every stage on every render of
   * the picker, and would report a number already stale by the time it was drawn.
   */
  step_count: number;
}

export interface RouteTemplateListResponse {
  data: RouteTemplateSummary[];
}

/** One stage of a design. */
export interface RouteTemplateStep {
  position: number;
  rule_kind: string;
  rule_config: Record<string, unknown>;
  label: string | null;
  /** Whether this stage is a GATE — answered with a verdict rather than forwarded. */
  decision: boolean;
  /**
   * NULL is not "no quorum": it means "follow the tenant's setting, whatever it
   * becomes". The graph response carries `default_quorum` so a reader can show
   * what that currently resolves to without holding `settings:read`.
   */
  decision_quorum: 'all' | 'any' | 'majority' | null;
}

/** Where a settled verdict sends the document. Both ends are POSITIONS. */
export interface RouteTemplateEdge {
  from: number;
  to: number;
  verdict: 'approved' | 'rejected';
}

/** `GET /api/v1/document-route-templates/{id}` — the design with its graph. */
export interface RouteTemplateGraph extends RouteTemplateSummary {
  default_quorum: 'all' | 'any' | 'majority';
  max_steps: number;
  steps: RouteTemplateStep[];
  edges: RouteTemplateEdge[];
}

export interface RouteTemplateGraphResponse {
  data: RouteTemplateGraph;
}

/**
 * What a stage does when its verdict settles, in one sentence per verdict.
 *
 * The three rules are #1014's and are stated in exactly one place on the server
 * (`DocumentRouter::nextForVerdict()`) and one on the canvas
 * (`resolveTransitions()` in `@amroksaleh/ui/route-flow/model`). This is a THIRD
 * reader of them, and it is deliberately the smallest one that can answer the
 * question the composer asks — "where does this go?" — for a single stage:
 *
 *   - a drawn edge for the verdict wins;
 *   - an APPROVAL with no edge falls through to the next stage in order;
 *   - a REJECTION with no edge ENDS the chain. It never falls through to where
 *     an approval would have gone.
 *
 * Returned as a position or `null` (meaning "ends here") rather than as text, so
 * the caller does the wording and this stays a fact.
 */
export function destinationFor(
  graph: RouteTemplateGraph,
  step: RouteTemplateStep,
  verdict: 'approved' | 'rejected'
): number | null {
  const drawn = graph.edges.find((e) => e.from === step.position && e.verdict === verdict);
  if (drawn !== undefined) {
    return drawn.to;
  }
  if (verdict === 'rejected') {
    return null;
  }

  const ordered = [...graph.steps].sort((a, b) => a.position - b.position);
  const index = ordered.findIndex((s) => s.position === step.position);

  return index >= 0 && index + 1 < ordered.length ? ordered[index + 1].position : null;
}
