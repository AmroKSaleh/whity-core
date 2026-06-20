/**
 * Client-side upload/clear helpers for branding assets (WC-233 Slice 5).
 *
 * The branding upload endpoints use `multipart/form-data`, which the
 * openapi-typescript generated client cannot express via its typed path
 * (requestBody is `never` in the schema because the spec declares the route
 * without a typed body). We apply the same narrow-cast pattern used in
 * `plugin-upload.ts`: rather than weakening the whole client, we recast
 * `api.POST` to a precise function type for the upload paths only.
 *
 * `clearBrandingAsset` and `setBrandingHost` use the same narrow-cast idiom
 * (through `unknown`) to avoid `as never` on the call site, which would make
 * the return type `never` and prevent destructuring.
 */

import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

/** The effective branding shape returned by every branding mutation. */
export type Branding = components['schemas']['Branding'];

/** Uniform error envelope. */
export type ApiError = components['schemas']['Error'];

// ---------------------------------------------------------------------------
// Shared result type for branding endpoints
// ---------------------------------------------------------------------------

interface BrandingResult {
  data?: components['schemas']['BrandingResponse'];
  error?: ApiError;
  response?: Response;
}

interface VoidResult {
  data?: unknown;
  error?: ApiError;
  response?: Response;
}

// ---------------------------------------------------------------------------
// Narrow cast types (mirror plugin-upload.ts pattern)
// ---------------------------------------------------------------------------

/**
 * api.POST narrowed for multipart branding uploads.
 * Accepts any string path + { body: FormData }; returns a BrandingResult.
 */
type MultipartPost = (
  url: string,
  options: { body: FormData }
) => Promise<BrandingResult>;

/**
 * api.DELETE narrowed for branding clear endpoints.
 * Accepts any string path; returns a BrandingResult.
 */
type BrandingDelete = (url: string) => Promise<BrandingResult>;

/**
 * api.PUT narrowed for the branding-host endpoint.
 * Accepts any string path + { body: BrandingHostRequest }; returns a VoidResult.
 */
type BrandingHostPut = (
  url: string,
  options: { body: components['schemas']['BrandingHostRequest'] }
) => Promise<VoidResult>;

// ---------------------------------------------------------------------------
// Helper: extract a readable message from an API error object
// ---------------------------------------------------------------------------

function apiErrorMessage(error: ApiError | undefined, fallback: string): string {
  if (!error) return fallback;
  const msg = (error as { error?: unknown }).error;
  return typeof msg === 'string' && msg !== '' ? msg : fallback;
}

// ---------------------------------------------------------------------------
// Public helpers
// ---------------------------------------------------------------------------

/**
 * Upload a branding asset for the given scope.
 *
 * - `scope === 'tenant'` → `POST /api/v1/branding/assets/{key}` (settings:write)
 * - `scope === 'global'` → `POST /api/v1/branding/global/assets/{key}` (settings:manage)
 *
 * The file is sent under field name `file` in a FormData body; the browser
 * sets the multipart boundary automatically. Throws on error.
 */
export async function uploadBrandingAsset(
  scope: 'tenant' | 'global',
  key: 'logo_wide' | 'logo_square' | 'favicon',
  file: File,
): Promise<Branding> {
  const body = new FormData();
  body.append('file', file);

  const path =
    scope === 'global'
      ? `/api/v1/branding/global/assets/${key}`
      : `/api/v1/branding/assets/${key}`;

  const postMultipart = api.POST as unknown as MultipartPost;
  const { data, error } = await postMultipart(path, { body });

  if (!data) throw new Error(apiErrorMessage(error, 'Upload failed'));
  return data.data;
}

/**
 * Clear a branding asset for the given scope (reverts to the next-level default).
 *
 * - `scope === 'tenant'` → `DELETE /api/v1/branding/assets/{key}` (settings:write)
 * - `scope === 'global'` → `DELETE /api/v1/branding/global/assets/{key}` (settings:manage)
 */
export async function clearBrandingAsset(
  scope: 'tenant' | 'global',
  key: 'logo_wide' | 'logo_square' | 'favicon',
): Promise<Branding> {
  const path =
    scope === 'global'
      ? `/api/v1/branding/global/assets/${key}`
      : `/api/v1/branding/assets/${key}`;

  const del = api.DELETE as unknown as BrandingDelete;
  const { data, error } = await del(path);

  if (!data) throw new Error(apiErrorMessage(error, 'Clear failed'));
  return data.data;
}

/**
 * Set or clear a tenant's custom branding hostname.
 *
 * Requires `settings:manage`. Pass `null` or `''` to clear the hostname.
 *
 * `PUT /api/v1/tenants/{id}/branding-host`
 */
export async function setBrandingHost(tenantId: number, host: string | null): Promise<void> {
  const put = api.PUT as unknown as BrandingHostPut;
  const { error } = await put(`/api/v1/tenants/${tenantId}/branding-host`, {
    body: { host },
  });

  if (error) throw new Error(apiErrorMessage(error, 'Failed to set branding host'));
}
