/**
 * Typed API client (WC-168).
 *
 * An openapi-fetch client generated from `public/openapi.json` (see
 * `npm run generate:api` → `lib/api/schema.d.ts`) that preserves the auth
 * behavior of the legacy `lib/api-client.ts` wrapper as middleware:
 *
 * 1. Every request carries `credentials: 'include'` (httpOnly cookies) and the
 *    `X-Requested-With: XMLHttpRequest` CSRF defense header (WC-160) —
 *    caller-supplied headers win on clash.
 * 2. On a 401, it POSTs `/api/v1/auth/refresh` once and replays the original
 *    request once. The replay bypasses the middleware entirely, so a 401 on
 *    the retry (or on the refresh itself) is returned as-is — refresh loops
 *    are structurally impossible.
 * 3. Responses are never thrown; callers branch on `{ data, error, response }`
 *    with types derived from the OpenAPI spec.
 *
 * The characterization tests in `__tests__/typed-api-client.test.ts` pin this
 * contract — change them only when the auth flow itself changes.
 */

import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from './schema';

export interface CreateApiClientOptions {
  /**
   * Base URL prepended to the spec paths. The default empty string keeps
   * `/api/*` requests relative so they go through the Next.js proxy (which
   * forwards cookies to the backend), exactly like the legacy client.
   */
  baseUrl?: string;
  /** Injectable fetch for tests; defaults to the global fetch. */
  fetch?: (request: Request) => Promise<Response>;
}

export function createApiClient(options: CreateApiClientOptions = {}) {
  const baseUrl = options.baseUrl ?? '';
  const fetchFn =
    options.fetch ?? ((request: Request) => globalThis.fetch(request));

  /**
   * Pristine clones of each in-flight request, keyed by openapi-fetch's
   * per-request id. Cloned BEFORE the first send so the body is replayable
   * on the post-refresh retry; entries are deleted on every response/error
   * path, so the map never outlives a request.
   *
   * CAVEAT: if another middleware is ever registered BEFORE this one and
   * short-circuits by returning a Response from onRequest, openapi-fetch
   * skips onResponse/onError entirely and the clone for that id would leak.
   * Keep this middleware first (or revisit the cleanup) if more middleware
   * is added.
   */
  const pristine = new Map<string, Request>();

  /** POST /api/v1/auth/refresh — true when the backend renewed the cookie. */
  const refreshAccessToken = async (): Promise<boolean> => {
    try {
      const response = await fetchFn(
        new Request(`${baseUrl}/api/v1/auth/refresh`, {
          method: 'POST',
          credentials: 'include',
          headers: { 'X-Requested-With': 'XMLHttpRequest' },
        })
      );
      return response.ok;
    } catch {
      return false;
    }
  };

  const authMiddleware: Middleware = {
    async onRequest({ request, id }) {
      if (!request.headers.has('X-Requested-With')) {
        request.headers.set('X-Requested-With', 'XMLHttpRequest');
      }
      pristine.set(id, request.clone());
      return request;
    },
    async onResponse({ response, id }) {
      const original = pristine.get(id);
      pristine.delete(id);

      if (response.status !== 401 || original === undefined) {
        return response;
      }

      if (!(await refreshAccessToken())) {
        return response;
      }

      // Replay the pristine original directly through fetch — NOT through the
      // client — so this middleware never sees it and cannot refresh twice.
      return fetchFn(original);
    },
    async onError({ id }) {
      pristine.delete(id);
    },
  };

  const client = createClient<paths>({
    baseUrl,
    credentials: 'include',
    fetch: fetchFn,
  });
  client.use(authMiddleware);

  return client;
}

export type ApiClient = ReturnType<typeof createApiClient>;

/** The app-wide typed client. Import this from screens and hooks. */
export const api = createApiClient();
