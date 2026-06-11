/**
 * Types for the permission delegations admin area (WC-34).
 *
 * Since WC-168 these are DERIVED from the OpenAPI schema generated off
 * `public/openapi.json` (`npm run generate:api`), so they track the published
 * API contract instead of hand-mirroring it. The aliases keep the feature's
 * import surface stable.
 */

import type { components } from '@/lib/api/schema';

export type Delegation = components['schemas']['Delegation'];

export type GranteeType = Delegation['granteeType'];

/**
 * A permission catalogue entry as returned by `GET /api/permissions`. Note:
 * registry-only permissions carry `id: null` and a `source` tag — use `name`
 * as the stable identity.
 */
export type Permission = components['schemas']['PermissionCatalogueEntry'];

/** A role as returned by `GET /api/roles` (grantee picker). */
export type RoleOption = components['schemas']['Role'];

/** A user as returned by `GET /api/users` (grantee picker). */
export type UserOption = components['schemas']['User'];

/** An organizational unit as returned by `GET /api/ous` (scope picker). */
export type OuOption = components['schemas']['OrganizationalUnit'];
