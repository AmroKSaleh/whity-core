/**
 * Wire types for the document RECORD page (#993).
 *
 * Hand-written rather than taken from the generated client, for the reason the
 * organizer's own `types.ts` gives one directory up: the typed client is
 * generated from the DYNAMIC `/api/openapi.json`, which describes plugin routes,
 * so it cannot type a core resource. These mirror `DocumentPresenter`,
 * `RoutingPresenter` and `RecordSectionResolver` — the three places the server
 * decides what this page is allowed to see.
 *
 * The `DocumentArtifact`/`DocumentRow` shapes are deliberately NOT re-declared
 * here: the record page reuses the viewer's own `DocumentRecord` for the part it
 * hands to the viewer, and imports the organizer's row type for the rest, so a
 * field renamed on the wire breaks one declaration rather than three.
 */

import type { RecordSectionVerdicts } from '@amroksaleh/features/record';

import type { DocumentArtifact } from '../types';

/**
 * One issued document as `GET /api/v1/documents/{id}` returns it.
 *
 * Extends the organizer's row with the two things only the RECORD route sends.
 *
 * `sections` is OPTIONAL and its absence is not "everything allowed" — it means
 * the host resolves no regions, and `sectionAccessFrom` reads that as every
 * region hidden. Fail-closed, matching the way `can()` answers while
 * capabilities are in flight, and matching what the server test pins down: a
 * handler built without a resolver omits the key entirely.
 */
export interface DocumentRecord {
  id: number;
  template_name: string;
  title: string;
  origin_ou_id: number | null;
  created_by: number | null;
  created_at: string;
  content_url: string | null;
  artifacts: DocumentArtifact[];
  collection_ids?: number[];
  starred?: boolean;
  sections?: RecordSectionVerdicts;
}

/**
 * The five things that can happen to a document, as `document_route_events`
 * CHECK-constrains them (migration 112).
 *
 * A closed union rather than `string`, so a server that grows a sixth verb
 * produces a compile error at the one place this page turns a verb into a
 * sentence — rather than silently rendering the raw enum to a reader.
 */
export type TrailAction = 'issued' | 'forwarded' | 'acknowledged' | 'returned' | 'noted';

/**
 * One entry in the append-only trail, as `RoutingPresenter::event()` sends it.
 *
 * Every id here is an id and not a name. Resolving them is this client's job and
 * needs `users:read` / `ous:read`, which are separate gates — see the screen for
 * what it does when it does not hold them.
 */
export interface TrailEvent {
  id: number;
  document_id: number;
  route_id: number;
  step_id: number | null;
  actor_profile_id: number | null;
  /** Typed loosely on arrival: the server is the authority, and an unknown verb must render as itself. */
  action: string;
  from_ou_id: number | null;
  to_ou_id: number | null;
  note: string | null;
  occurred_at: string;
}

/** The trail's pagination envelope — camelCase, unlike the rest of the wire. */
export interface TrailPagination {
  page: number;
  perPage: number;
  total: number;
  totalPages: number;
}

export interface TrailPage {
  data: TrailEvent[];
  pagination: TrailPagination;
}

/**
 * One recipient row, as `RoutingPresenter::recipient()` sends it.
 *
 * `open` is DERIVED by the server from `closed_by_event_id` and published
 * alongside the pointer rather than instead of it. This page renders the boolean
 * and keeps the pointer, because the two say different things: `open` is "still
 * awaiting", and `closed_by_event_id` names the trail entry that settled it.
 *
 * `parent_recipient_id` is the fan-out edge — distribution branches, and a row
 * whose parent is another row arrived by being forwarded from it.
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

/**
 * Names for the ids the trail and the recipient list are made of, and whether
 * this caller was allowed to look them up.
 *
 * The two `...Available` flags exist because a MISSING NAME and a WITHHELD
 * DIRECTORY are different facts and must read differently on screen. Listing
 * people needs `users:read` and listing units needs `ous:read`; migration 101
 * removed `ous:read` from the plain user role, so a reader with no directory is
 * an ordinary state rather than a fault. What is never acceptable is printing
 * `#42` with no explanation, which leaves the reader unable to tell a permission
 * boundary from a data bug (#951).
 */
export interface Directory {
  people: ReadonlyMap<number, string>;
  units: ReadonlyMap<number, string>;
  peopleAvailable: boolean;
  unitsAvailable: boolean;
}
