/**
 * Shared types for the Relations hub (WC-65).
 *
 * These mirror the public API contracts emitted by PersonsApiHandler /
 * RelationsApiHandler (camelCase). One `persons` row is the only graph node; a
 * relation edge is always person -> person, and the reciprocal is derived at
 * read time by the backend (so a `RelationView` already carries the type from
 * the viewing person's perspective).
 */

/** A reciprocal-derived relation entry as seen from one person's perspective. */
export interface RelationView {
  /** The underlying stored edge id (used to delete the edge). */
  relationId: number;
  /** The OTHER person in this relation. */
  otherPersonId: number;
  otherPersonName: string;
  /** Whether the other person is linked to a user account. */
  otherPersonHasAccount: boolean;
  /** The relationship type id from the viewing person's perspective. */
  typeId: number;
  /** The relationship type name from the viewing person's perspective. */
  typeName: string;
  /** 'outgoing' (viewer is the stored `from`) or 'incoming' (viewer is the `to`). */
  direction: 'outgoing' | 'incoming' | string;
}

/** A person graph node. */
export interface Person {
  id: number;
  tenantId: number;
  displayName: string;
  /** The linked user id, or null for a non-user relative. */
  userId: number | null;
  /** True when the person is linked to a user account. */
  hasAccount: boolean;
  birthDate: string | null;
  deceased: boolean;
  notes: string | null;
  createdAt: string | null;
  /** Number of relations touching this person. */
  relationCount: number;
  /** The person's relations (reciprocal-derived). */
  relations: RelationView[];
}

/** A relationship type from the seeded vocabulary. */
export interface RelationshipType {
  id: number;
  name: string;
  inverseTypeId: number | null;
  symmetric: boolean;
}

/** A stored relation edge (canonical direction), for the graph view. */
export interface RelationEdge {
  id: number;
  fromPersonId: number;
  toPersonId: number;
  typeId: number;
  typeName: string;
  /** The inverse type name (what the edge reads as from the `to` side), or null. */
  inverseTypeName: string | null;
}
