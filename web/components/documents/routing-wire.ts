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
 * There is deliberately no `approved` here, and it is worth saying so out loud
 * because "approve" is the word everybody reaches for: the engine's terminal act
 * is `acknowledged` ("this goes no further along my chain"), and approval as a
 * DECISION is not something routing models at all. Spelling an "Approve" button
 * over `acknowledged` would put a word in the trail that the trail does not
 * contain.
 */
export const ROUTE_ACTIONS = ['issued', 'forwarded', 'acknowledged', 'returned', 'noted'] as const;

export type RouteActionName = (typeof ROUTE_ACTIONS)[number];

/**
 * What a RECIPIENT may do to their own open item, plus `noted`.
 *
 * `issued` is absent because it is the engine's own act, not a person's, and
 * accepting it from a recipient would mint a second beginning for a circulation
 * already under way. This list is the same one
 * `DocumentRoutingApiHandler::act()` validates against, in the same order.
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
    occurred_at: string;
  };
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
