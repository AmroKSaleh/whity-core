/**
 * Wire types for the document routing surface (#978, over #989's engine).
 *
 * Mirrors `RoutingPresenter` (`src/Core/Document/Routing/RoutingPresenter.php`)
 * and `InboxItem` (`src/Core/Inbox/InboxItem.php`) field for field.
 *
 * Hand-written rather than taken from `@/lib/api/schema`, matching the
 * convention its nearest neighbour states — see
 * `web/app/(protected)/admin/document-library/types.ts`: the generated client is
 * built from the DYNAMIC `/api/openapi.json`, which describes whatever plugin
 * routes a given install happens to have loaded, so it is not a stable
 * description of a CORE resource. The routing paths do appear in `schema.d.ts`
 * today; depending on that would make these screens' types a function of the
 * install that last ran `npm run generate:api`.
 *
 * The rejected alternative was importing `components['schemas']['...']`. It
 * would have cost nothing today and made a core screen break on an unrelated
 * regeneration later.
 */

/**
 * The closed action vocabulary, from `RouteAction` and CHECK-constrained in
 * migration 112. Five verbs and the database refuses a sixth.
 *
 * There is still no `approved` here, and the REASON changed in #1030 — which is
 * worth spelling out, because the paragraph that stood in this place said
 * routing does not model approval at all, and that has been false since
 * migration 119. It was true when it was written. Left alone it would have gone
 * on telling the next reader not to look, which is the one thing a confident
 * comment is reliably good at.
 *
 * What is true now: approval is a VERDICT on the act, not a sixth verb.
 * `document_route_events` carries a nullable `verdict` column beside `action`,
 * because under a quorum the same verdict has different routing effects — the
 * first approval of three closes one inbox row and opens nothing, the third
 * opens the next step — and this vocabulary's defining property is that the verb
 * DETERMINES the effect. See {@link ROUTE_VERDICTS}.
 *
 * So an "Approve" button is legitimate now, and it posts `acknowledged` carrying
 * `verdict: 'approved'`. The trail keeps the two facts apart: what the person
 * did, and what they decided.
 */
export const ROUTE_ACTIONS = ['issued', 'forwarded', 'acknowledged', 'returned', 'noted'] as const;

export type RouteActionName = (typeof ROUTE_ACTIONS)[number];

/**
 * The closed VERDICT vocabulary, from `RouteVerdict` and CHECK-constrained in
 * migration 119. Two values, and NULL is not a third.
 *
 * `verdict === null` means "this act said nothing about approval". That is every
 * act on a circulation step, every `noted`, and every event written before
 * migration 119. It NEVER means "not approved" — a reader that treated absence
 * as refusal would invent a rejection for every document ever circulated.
 */
export const ROUTE_VERDICTS = ['approved', 'rejected'] as const;

export type RouteVerdictName = (typeof ROUTE_VERDICTS)[number];

/**
 * What "this step approved" MEANS when its rule resolves to more than one
 * person, from `RouteQuorum` and CHECK-constrained on
 * `document_route_steps.decision_quorum`.
 *
 * All three are the SAME RULE for a cohort of one, which is the overwhelmingly
 * common approval step ("the dean signs off"). They differ exactly where a rule
 * fans out to hundreds — which is why this client shows the quorum only there,
 * and why `all` is the engine's default: identical in the safe case,
 * conservative in the risky one.
 *
 * There is deliberately no rejection quorum to render. Rejection is DERIVED —
 * the reject edge fires when the approval quorum has become arithmetically
 * unreachable — so a second control here would offer a rule that does not exist.
 */
export const ROUTE_QUORUMS = ['all', 'any', 'majority'] as const;

export type RouteQuorumName = (typeof ROUTE_QUORUMS)[number];

/**
 * What a RECIPIENT may do to their own open item, plus `noted`.
 *
 * `issued` is absent because it is the engine's own act, not a person's, and
 * accepting it from a recipient would mint a second beginning for a circulation
 * already under way. This list is the same one
 * `DocumentRoutingApiHandler::act()` validates against, in the same order.
 *
 * On a DECISION step the engine narrows it (#1030): `forwarded` is refused,
 * because choosing the destination is the one thing a gate exists to take away
 * from the person answering. `acknowledged` is the act a verdict is carried by,
 * and it is REFUSED there without one. `returned` stays available and carries no
 * verdict. See {@link RouteStep.decision}.
 */
export const RECIPIENT_ACTIONS = ['forwarded', 'acknowledged', 'returned'] as const;

export type RecipientActionName = (typeof RECIPIENT_ACTIONS)[number];

/** One entry from `GET /api/v1/routing-rules`. */
export interface RoutingRule {
  /** The canonical key stored in `document_route_steps.rule_kind`. */
  kind: string;
  /** Human label from the resolver, e.g. "Everyone holding a role". */
  label: string;
  /** `core`, or the plugin slug that contributed the kind. */
  source: string;
}

export interface RoutingRulesResponse {
  data: RoutingRule[];
}

/**
 * One step of a route.
 *
 * `position` is a 1-based AUTHORING ORDINAL, unique within a route — NOT a
 * depth and NOT a progress marker. #989 redefined it that way on purpose, and
 * the distinction is the whole reason this UI has no progress bar: two people
 * can be standing on different positions of the same route at the same moment.
 *
 * `rule_config` is the rule's own vocabulary, passed through unchanged by the
 * server. Core's two kinds both take `{ role_id: number }`; a plugin's kind can
 * carry anything, which is why this is an opaque record and not a union.
 */
export interface RouteStep {
  id: number;
  position: number;
  rule_kind: string;
  rule_config: Record<string, unknown>;
  label: string | null;
  /**
   * Whether this step is a GATE — answered with a verdict rather than a forward
   * (#1030).
   *
   * Published by the server rather than inferred from the edges, and this client
   * must not infer it either: a gate at the END of a route has no outgoing edge
   * and still demands a verdict, so reading the edges would render the last
   * sign-off in a route as an ordinary circulation.
   */
  decision: boolean;
  /**
   * This step's OWN override of what "this step approved" means.
   *
   * `null` is not "no quorum". It means "follow the tenant's setting", which is
   * what {@link DocumentRoute.default_quorum} carries — resolve the pair with
   * {@link effectiveQuorum} rather than reading either alone.
   */
  decision_quorum: RouteQuorumName | null;
  /**
   * Whether this stage ASKS anybody to answer, or only tells them (#1054).
   *
   * `'act'` — recipients are asked, and their rows stay open until they answer.
   * `'delivery'` — recipients are told. Their rows are closed at the instant
   * they are created and nobody does anything.
   *
   * The server has published this since #1061 and this type did not declare it,
   * so the routing screen could not tell the two apart and rendered every closed
   * row as "Acted" — including rows nobody ever acted on. #1061 put
   * `satisfied_by` on the STEP precisely so a person's act and a system's
   * delivery would stay distinguishable, and then the screen said it anyway, in
   * the second person, to the people it was untrue about.
   */
  satisfied_by: RouteSatisfactionName;
}

/**
 * What settles a stage. Closed on the server by a CHECK constraint (migration
 * 125), so a third value is not a case this client has to consider.
 */
export type RouteSatisfactionName = 'act' | 'delivery';

/**
 * One verdict edge: where a SETTLED verdict sends this step's document.
 *
 * A flat list on the route rather than fields on a step, mirroring the server:
 * an edge is a relationship between two steps and belongs to neither.
 *
 * The two verdicts are deliberately not symmetric when no edge was drawn. An
 * approval with no `approved` edge continues to the next authoring ordinal; a
 * rejection with no `rejected` edge goes NOWHERE and the chain ends. No code
 * path lets a rejection inherit the approval's destination, so no client may
 * describe one as though it had.
 */
export interface RouteEdge {
  id: number;
  route_id: number;
  from_step_id: number;
  to_step_id: number;
  verdict: RouteVerdictName;
}

/** A route: one circulation of one document. */
export interface DocumentRoute {
  id: number;
  document_id: number;
  title: string;
  /** Profile id of whoever raised it; null once that person is deleted. */
  created_by: number | null;
  created_at: string;
  steps: RouteStep[];
  edges: RouteEdge[];
  /**
   * What a step whose `decision_quorum` is null actually does in this tenant,
   * already resolved through the settings chain by the server (#1041).
   *
   * It rides along with the route because the answer lives behind
   * `settings:read`, and the person standing on a decision step is the least
   * likely person in the tenant to hold it. Without it this client could not
   * tell an approver whether their single approval carries the gate or is one of
   * four hundred required — and guessing is the one thing it must not do.
   *
   * Typed as required because the spec requires it. It is still read through
   * {@link effectiveQuorum}, which validates at RUNTIME: an install serving a
   * build older than #1041 sends nothing here, and the safe reading of a missing
   * approval rule is the strictest one, never the most permissive.
   */
  default_quorum: RouteQuorumName;
}

export interface RoutesResponse {
  data: DocumentRoute[];
}

/**
 * One recipient row — an assignment addressed to one person, at one step.
 *
 * `open` is the server's derivation of `closed_by_event_id IS NULL`, published
 * beside the pointer it came from so a reader can follow the claim back into the
 * trail. This client renders `open` and never re-derives it.
 *
 * `parent_recipient_id` is the fan-out edge: the recipient whose act produced
 * this one. A null parent means step 1, where nothing preceded it. Following
 * these gives the CHAINS, which is the only honest shape for a fan-out.
 */
export interface RouteRecipient {
  id: number;
  document_id: number;
  route_id: number;
  step_id: number;
  profile_id: number;
  ou_id: number | null;
  parent_recipient_id: number | null;
  created_by_event_id: number;
  closed_by_event_id: number | null;
  open: boolean;
  /**
   * Closed because the document was DELIVERED to this person, not because they
   * acted (#1061).
   *
   * The distinction `open` cannot carry: both a delivered row and an answered
   * row are closed, and rendering them the same way tells somebody they acted
   * when they were only told. Published by the server since #1061 and, like
   * {@link RouteStep.satisfied_by}, not declared here until the screen was
   * caught making that claim.
   */
  closed_by_delivery: boolean;
  created_at: string;
}

export interface RecipientsResponse {
  data: RouteRecipient[];
}

/**
 * The outcome of issuing a route or acting on one.
 *
 * BOTH counts, because the server sends both and they answer different
 * questions: `resolved` is what the rule found, `delivered` is how many inbox
 * rows that became after de-duplication against chains that already reached
 * those people. Showing only `delivered` would make an author think the rule
 * found fewer people than it did; showing only `resolved` would hide that some
 * of them already had it.
 *
 * This pair is also the entire rule-preview story — see `RouteComposer`.
 */
export interface RoutingOutcome {
  resolved: number;
  delivered: number;
}

/** `POST /api/v1/documents/{id}/routes` — 201. */
export interface IssueRouteResponse extends RoutingOutcome {
  data: DocumentRoute;
}

/** `POST /api/v1/documents/{id}/routes/{routeId}/actions` — 201. */
export interface ActOnRouteResponse extends RoutingOutcome {
  data: {
    id: number;
    document_id: number;
    route_id: number;
    step_id: number | null;
    actor_profile_id: number | null;
    action: RouteActionName;
    from_ou_id: number | null;
    to_ou_id: number | null;
    note: string | null;
    /**
     * What THIS PERSON decided — the verdict on their own event, echoed back.
     *
     * Not the outcome. See {@link ActOnRouteResponse.decided}, and do not render
     * this field as one.
     */
    verdict: RouteVerdictName | null;
    occurred_at: string;
  };
  /**
   * What the STEP concluded, which is not what the caller said.
   *
   * This is the single most important field on this wire and the only one it is
   * possible to get wrong while everything typechecks. Under the default quorum
   * of `all`, two of three approvals conclude NOTHING and this stays null; the
   * third settles the step and this becomes `approved`.
   *
   * A client that rendered `data.verdict` here would tell the first two of three
   * approvers that the document was approved — confidently, in the exact place a
   * person goes to find out, and to the two people least able to check. Read it
   * through {@link readDecided}, which cannot fall back to the caller's own
   * verdict because it never sees it.
   */
  decided: RouteVerdictName | null;
}

/**
 * The step's conclusion from an action response, or null if there is not one.
 *
 * Deliberately takes the WHOLE body and nothing else: the caller's own verdict
 * is not a parameter, so no amount of editing inside this function can make it
 * substitute one for the other. That is the entire design — the mistake this
 * guards against is not a typo, it is the perfectly reasonable-looking
 * `decided ?? verdict`, and a signature that cannot express it is a stronger
 * guarantee than a comment asking nobody to write it.
 *
 * `undefined` and `null` are both "the step did not conclude". A server older
 * than #1030 sends no field at all, and the safe reading of a missing conclusion
 * is that nothing was concluded — never that the caller's wish was granted.
 */
export function readDecided(body: unknown): RouteVerdictName | null {
  if (typeof body !== 'object' || body === null) return null;
  const decided = (body as Record<string, unknown>)['decided'];
  if (typeof decided !== 'string') return null;
  return (ROUTE_VERDICTS as readonly string[]).includes(decided)
    ? (decided as RouteVerdictName)
    : null;
}

/**
 * The quorum actually in force for a step: its own override, else the tenant's.
 *
 * Mirrors the top two layers of `DocumentRouter::approvalQuorum()`. The lower
 * two (global, then the registry default) are already resolved into
 * {@link DocumentRoute.default_quorum} by the server, so this client resolves a
 * pair rather than a ladder.
 *
 * An unrecognised value on either side falls back to `all`, exactly as the
 * engine does and for the engine's reason: the value has been through a CHECK
 * constraint and a settings validator to get this far, so a foreign string means
 * something upstream is broken — and the safe reading of a broken approval rule
 * is the strictest one, never the most permissive. Naming a laxer rule than the
 * engine will apply is how a screen tells somebody their approval is enough when
 * it is not.
 */
export function effectiveQuorum(step: RouteStep, route: DocumentRoute): RouteQuorumName {
  const onStep = step.decision_quorum;
  if (typeof onStep === 'string' && (ROUTE_QUORUMS as readonly string[]).includes(onStep)) {
    return onStep;
  }

  const onTenant: unknown = route.default_quorum;
  if (typeof onTenant === 'string' && (ROUTE_QUORUMS as readonly string[]).includes(onTenant)) {
    return onTenant as RouteQuorumName;
  }

  return 'all';
}

/**
 * The COHORT one open item belongs to: the rows a single act opened at a step.
 *
 * `created_by_event_id` is the whole definition, and it is the server's own —
 * `RouteRecipientRepository::listCohort()` groups by exactly this. It is what
 * keeps chains independent: two chains that reach the same decision step each
 * decide for themselves, so "how many people is this step waiting on" is a
 * question about one act's rows, never about the step.
 *
 * WHAT THIS COUNT IS, AND WHAT IT IS NOT. It is how many people the step was PUT
 * TO. It is not a live tally of who has answered and it deliberately does not
 * try to be: the recipients endpoint publishes no closing verdict, and the
 * engine also drops from its own denominator anybody who is no longer an active
 * member. A client arithmetic that looked like a tally would be wrong in both
 * directions and wrong invisibly. So this number is only ever rendered as the
 * size of the audience a rule resolved to — which is a fact the rows do carry.
 */
export function stepCohort(
  recipients: RouteRecipient[],
  item: RouteRecipient
): RouteRecipient[] {
  return recipients.filter(
    (r) =>
      r.route_id === item.route_id &&
      r.step_id === item.step_id &&
      r.created_by_event_id === item.created_by_event_id
  );
}

/** A step as the composer holds it, before the server has ever seen it. */
export interface DraftStep {
  /**
   * A client-only key for React and for reordering. Deliberately not an index:
   * reordering by index makes every row below a move remount and lose focus.
   */
  key: string;
  rule_kind: string;
  rule_config: Record<string, unknown>;
  label: string;
}

// ---------------------------------------------------------------------------
// #881 inbox aggregate
// ---------------------------------------------------------------------------

/**
 * One registered inbox source, from `GET /api/v1/me/inbox/sources`.
 *
 * `open_count` is the source's own count for the caller, from the same predicate
 * its list uses — so a tab badge cannot disagree with the page it opens.
 */
export interface InboxSource {
  key: string;
  label: string;
  /** `core`, or the plugin that registered the source. */
  origin: string;
  item_fields: string[];
  open_count: number;
}

export interface InboxSourcesResponse {
  data: InboxSource[];
}

/**
 * One inbox item.
 *
 * The named fields are #881's aggregate contract — every source emits these,
 * whatever it is about. `meta` is the SOURCE's own detail: routing puts
 * `route_id` / `step_id` / `document_id` / `open` / `arrived_by` in there, and
 * #881 is explicit that the aggregate must not come to depend on it. So this
 * screen reads `meta` only through {@link routingMeta}, which is allowed to
 * return null — an item from a source that is not routing has none of it, and
 * that is not an error.
 */
export interface InboxItem {
  id: string;
  title: string;
  subtitle: string | null;
  timestamp: string | null;
  status: string | null;
  resource_type: string | null;
  resource_id: string | null;
  meta?: Record<string, unknown> | null;
}

export interface InboxListResponse {
  data: InboxItem[];
  /**
   * camelCase keys, matching `PaginationParams::meta()` — `perPage` /
   * `totalPages`, NOT the snake_case the rest of these payloads use. The
   * inconsistency is the server's and is mirrored rather than corrected here,
   * because a client that "tidied" it would read `undefined` as a page count and
   * render a single page of a long list with no way to reach the rest.
   */
  pagination: {
    page: number;
    perPage: number;
    total: number;
    totalPages: number;
  };
  source: string;
}

/** The routing detail on an inbox item, when the item came from routing. */
export interface RoutingItemMeta {
  route_id: number;
  step_id: number;
  document_id: number;
  open: boolean;
  arrived_by: RouteActionName;
  /**
   * Whether this item is a DECISION (#1030).
   *
   * Published so a client knows before it renders anything, because the acts
   * available differ — a gate takes a verdict and refuses a forward. Without it
   * the inbox offers the wrong affordances and the person discovers the
   * difference from a 422 after clicking, which is the worst possible place to
   * learn what kind of thing you are holding.
   */
  decision: boolean;
}

/**
 * Read routing's `meta` off an inbox item, or null if it is not there.
 *
 * Every field is checked rather than cast. An item from another source has a
 * different `meta` (or none), and a cast would turn that into `document_id:
 * undefined` flowing into a URL — a link to `/admin/document-routing/undefined`
 * that looks like a bug in routing rather than an item this screen should have
 * rendered without a routing link at all.
 */
export function routingMeta(item: InboxItem): RoutingItemMeta | null {
  const meta = item.meta;
  if (typeof meta !== 'object' || meta === null) return null;

  const routeId = meta['route_id'];
  const stepId = meta['step_id'];
  const documentId = meta['document_id'];
  const open = meta['open'];
  const arrivedBy = meta['arrived_by'];

  if (
    typeof routeId !== 'number' ||
    typeof stepId !== 'number' ||
    typeof documentId !== 'number' ||
    typeof open !== 'boolean' ||
    typeof arrivedBy !== 'string' ||
    !(ROUTE_ACTIONS as readonly string[]).includes(arrivedBy)
  ) {
    return null;
  }

  return {
    route_id: routeId,
    step_id: stepId,
    document_id: documentId,
    open,
    arrived_by: arrivedBy as RouteActionName,
    // The ONE field here that does not join the guard above, and on purpose. A
    // server older than #1030 sends no `decision` key, and requiring it would
    // make every routing item on such an install fail the whole check — which
    // this screen renders as "an item from a source I have no link for", so the
    // document would silently lose its link rather than lose a chip. Absent is
    // read as false, which is also what it MEANT before decision steps existed:
    // absence of a verdict has never meant a verdict.
    decision: meta['decision'] === true,
  };
}

/**
 * One positive whole number out of a rule config, or null.
 *
 * Both spellings of the same number are accepted, because the SERVER accepts
 * both and for the reason it records: a JSON body decodes `7` as a number and
 * `"7"` as a string, and a config round-tripped through JSONB comes back either
 * way depending on the driver. A client that insisted on one would render a step
 * the engine can resolve perfectly well as unconfigured — and would do it on
 * PostgreSQL or on the offline SQLite engine but not both, which is the worst
 * place for a dialect difference to surface.
 *
 * Shared by every config reader here so the four kinds cannot drift into four
 * ideas of what a number is.
 */
function positiveInteger(raw: unknown): number | null {
  if (typeof raw === 'number' && Number.isInteger(raw) && raw > 0) return raw;
  if (typeof raw === 'string' && /^\d+$/.test(raw) && Number(raw) > 0) return Number(raw);
  return null;
}

/**
 * The role id a core routing rule is configured with, or null.
 *
 * Both core kinds (`role`, `role_below_actor`) take `{ role_id }`, and the
 * server accepts the number or its decimal string — a config round-tripped
 * through JSONB can come back either way depending on the driver, and
 * `RoleRuleResolver::requireRoleId()` reads both for exactly that reason. This
 * mirrors it rather than picking one, so a step that the engine can resolve is
 * never one this screen renders as unconfigured.
 */
export function configuredRoleId(config: Record<string, unknown>): number | null {
  return positiveInteger(config['role_id']);
}

/** Core's two rule kinds, both of which are configured by naming a role. */
export const ROLE_CONFIGURED_KINDS = ['role', 'role_below_actor'] as const;

/** Whether this kind is one whose config this client knows how to author. */
export function isRoleConfiguredKind(kind: string): boolean {
  return (ROLE_CONFIGURED_KINDS as readonly string[]).includes(kind);
}

/**
 * The kind that names a stored USER GROUP: `{ group_id }` (#999).
 *
 * A step's whole point — one row that keeps meaning what the institution
 * currently means by "instructors" — so this is the kind #1015 exists to make
 * authorable.
 */
export const GROUP_KIND = 'group';

/** The kind that names PEOPLE, one by one: `{ profile_ids: [...] }` (#999). */
export const EXPLICIT_KIND = 'explicit';

/**
 * The group id a `group` step names, or null.
 *
 * Mirrors `GroupRuleResolver::requireGroupId()` — the ID, never the name:
 * renaming a group is an ordinary administrative act and must not silently
 * re-point, or un-point, every step that named it.
 */
export function configuredGroupId(config: Record<string, unknown>): number | null {
  return positiveInteger(config['group_id']);
}

/**
 * The profile ids an `explicit` step names, in order, de-duplicated.
 *
 * Mirrors `ExplicitRuleResolver::requireProfileIds()`, including its
 * de-duplication: an author who listed somebody twice must not read a count of
 * three for two people. A malformed entry is DROPPED rather than failing the
 * whole read — the server is the authority on whether the config is acceptable,
 * and its refusal names what is wrong; a client that rendered nothing at all
 * would hide the two ids that were fine and leave the author with no way to see
 * what they had written.
 */
export function configuredProfileIds(config: Record<string, unknown>): number[] {
  const raw = config['profile_ids'];
  if (!Array.isArray(raw)) return [];

  const seen = new Set<number>();
  const ids: number[] = [];
  for (const entry of raw) {
    const id = positiveInteger(entry);
    if (id === null || seen.has(id)) continue;
    seen.add(id);
    ids.push(id);
  }
  return ids;
}

/**
 * Whether this client knows how to author the kind's config.
 *
 * Four kinds, all core's. A PLUGIN's kind is deliberately not here and is
 * deliberately still offered: refusing to show it would hide a rule the install
 * genuinely has, and the plugin's own validator — not a guess made here — is what
 * says whether an empty config is enough.
 */
export function isCoreConfiguredKind(kind: string): boolean {
  return isRoleConfiguredKind(kind) || kind === GROUP_KIND || kind === EXPLICIT_KIND;
}

/**
 * Whether a step of this kind carries the config its rule requires.
 *
 * ONLY core's four kinds are judged here. Everything else — the tenant's step
 * ceiling, the per-step recipient ceiling, a plugin rule's own required config —
 * stays the engine's to judge, because guessing at it here would block routes the
 * engine would have accepted. What changed with #1015 is only which kinds this
 * client can speak for: `group` and `explicit` are core's, their configs are
 * authored by controls on this screen, and letting them through empty produced a
 * 422 the author could have been spared.
 */
export function isStepConfigured(kind: string, config: Record<string, unknown>): boolean {
  if (isRoleConfiguredKind(kind)) return configuredRoleId(config) !== null;
  if (kind === GROUP_KIND) return configuredGroupId(config) !== null;
  if (kind === EXPLICIT_KIND) return configuredProfileIds(config).length > 0;
  return true;
}
