/**
 * Multipart plugin-upload wrapper (WC-221).
 *
 * `POST /api/v1/plugins/upload` accepts a `multipart/form-data` body with the
 * package file under field name `package` (WC-220). openapi-typescript types
 * that requestBody as `{ package: string }`, which cannot express a real
 * `FormData`/`File` body — so the generated typed `api.POST` signature rejects
 * the FormData we must actually send.
 *
 * Rather than weaken the whole client, this module narrowly re-types
 * `api.POST` for THIS one endpoint: it accepts `{ body: FormData }` and returns
 * the same `{ data, error, response }` envelope every other typed call returns.
 * No `any` is used — the cast goes through `unknown` and a precise function
 * type.
 *
 * Transport details that make the multipart request correct:
 *   - openapi-fetch's defaultBodySerializer passes a `FormData` body through
 *     UNTOUCHED and, crucially, does NOT set `Content-Type` for it, so the
 *     browser sets `multipart/form-data` WITH the boundary itself. We therefore
 *     never set Content-Type manually.
 *   - The client's auth middleware already adds the `X-Requested-With:
 *     XMLHttpRequest` CSRF header to every request, including this one.
 */

import { api } from '@/lib/api/client';
import type { components } from '@/lib/api/schema';

/** The success envelope: the staged plugin (lands `disabled` for review). */
export type PluginUploadResponse =
  components['schemas']['PluginUploadResponse'];

/** The uniform error envelope ({ error: string }). */
export type ApiError = components['schemas']['Error'];

/** The narrowed result of the upload call. */
export interface PluginUploadResult {
  data?: PluginUploadResponse;
  error?: ApiError;
  response?: Response;
}

/**
 * The precise shape we need from `api.POST` for the multipart upload endpoint:
 * a FormData body in, the typed `{ data, error, response }` envelope out.
 */
type MultipartPost = (
  url: '/api/v1/plugins/upload',
  options: { body: FormData }
) => Promise<PluginUploadResult>;

/**
 * Upload a plugin package (`.zip` or single `.php`) for staged install.
 *
 * The file is sent under field name `package` in a `FormData` body so the
 * browser owns the `multipart/form-data` boundary. Resolves to the typed
 * `{ data, error, response }` envelope — callers branch on `error`.
 */
export function uploadPluginPackage(file: File): Promise<PluginUploadResult> {
  const body = new FormData();
  body.append('package', file);

  const postMultipart = api.POST as unknown as MultipartPost;
  return postMultipart('/api/v1/plugins/upload', { body });
}
