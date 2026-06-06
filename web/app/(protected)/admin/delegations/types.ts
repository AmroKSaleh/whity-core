/**
 * Types for the permission delegations admin area (WC-34).
 *
 * Mirrors the API contract exposed by `DelegationsApiHandler`: camelCase keys,
 * a polymorphic grantee modelled as `granteeType` + `granteeId`, an optional OU
 * scope, and a revocable lifecycle (`revokedAt` is null while live).
 */

export type GranteeType = 'role' | 'user';

export interface Delegation {
  id: number;
  tenantId: number;
  grantorUserId: number;
  granteeType: GranteeType;
  granteeId: number;
  permission: string;
  ouId: number | null;
  grantedAt: string | null;
  revokedAt: string | null;
}

/** A permission as returned by `GET /api/permissions`. */
export interface Permission {
  id: number;
  name: string;
  description: string;
}

/** A role as returned by `GET /api/roles` (grantee picker). */
export interface RoleOption {
  id: number;
  name: string;
}

/** A user as returned by `GET /api/users` (grantee picker). */
export interface UserOption {
  id: number;
  name: string;
  email: string;
}

/** An organizational unit as returned by `GET /api/ous` (scope picker). */
export interface OuOption {
  id: number;
  name: string;
}
