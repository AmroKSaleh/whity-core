/**
 * `@amroksaleh/api-client` — the typed client for the Whity API.
 *
 * The types are generated from the backend's OpenAPI spec (`public/openapi.json`)
 * via `npm run generate`. Build a client with {@link createApiClient} and branch
 * on `{ data, error, response }` — responses are never thrown; the shapes are
 * derived from the spec.
 *
 * Auth behaviour (same as the Whity web app): every request carries
 * `credentials: 'include'` (httpOnly cookies) and the `X-Requested-With` CSRF
 * header; on a 401 the client POSTs `/api/v1/auth/refresh` once and replays the
 * original request once (the replay bypasses the middleware, so refresh loops
 * are structurally impossible).
 *
 * The default `baseUrl` is `''` — same-origin `/api/*`, e.g. behind a reverse
 * proxy. A standalone frontend sets `baseUrl` to the backend's origin.
 */
import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from './schema';

export type { paths, components, operations, webhooks } from './schema';

export interface CreateApiClientOptions {
  /**
   * Base URL prepended to the spec paths. Default `''` keeps `/api/*` requests
   * same-origin (relative) so they can flow through a proxy that forwards
   * cookies. A standalone frontend points this at the backend origin.
   */
  baseUrl?: string;
  /** Injectable fetch (for tests / custom transports); defaults to global fetch. */
  fetch?: (request: Request) => Promise<Response>;
}

export function createApiClient(options: CreateApiClientOptions = {}) {
  const baseUrl = options.baseUrl ?? '';
  const fetchFn =
    options.fetch ?? ((request: Request) => globalThis.fetch(request));

  /**
   * Pristine clones of each in-flight request, keyed by openapi-fetch's
   * per-request id — cloned BEFORE the first send so the body is replayable on
   * the post-refresh retry; deleted on every response/error path so the map
   * never outlives a request. Keep this middleware FIRST: if another middleware
   * short-circuits onRequest before it, the clone for that id would leak.
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
      // Replay the pristine original directly through fetch — NOT the client —
      // so this middleware never sees it and cannot refresh twice.
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
