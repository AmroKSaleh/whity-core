/**
 * `@whity-org/api-client` — the typed client for the Whity API.
 *
 * The types are generated from the backend's OpenAPI spec (`public/openapi.json`)
 * via `npm run generate`. Build a client with {@link createApiClient} and branch
 * on `{ data, error, response }` — responses are never thrown; the shapes are
 * derived from the spec.
 *
 * Two auth modes:
 *
 * - `'cookie'` (default, same as the Whity web app): every request carries
 *   `credentials: 'include'` (httpOnly cookies) and the `X-Requested-With` CSRF
 *   header. Needs the frontend and the API on the same site.
 * - `'token'` (headless frontends on another site, native apps, servers): every
 *   request carries `X-Auth-Mode: token` and `Authorization: Bearer <access>`,
 *   never cookies. Tokens returned by the sign-in endpoints (and by `PATCH
 *   /me`, which re-issues them) are captured into a {@link TokenStore}
 *   automatically; logout clears it.
 *
 * In both modes a 401 triggers one POST to `/api/v1/auth/refresh` — shared by
 * every request that hit the 401 at the same time — and the original request is
 * replayed once (the replay bypasses the middleware, so refresh loops are
 * structurally impossible).
 *
 * The default `baseUrl` is `''` — same-origin `/api/*`, e.g. behind a reverse
 * proxy. A standalone frontend sets `baseUrl` to the backend's origin.
 */
import createClient, { type Middleware } from 'openapi-fetch';
import type { paths } from './schema';

export type { paths, components, operations, webhooks } from './schema';

/** The access + refresh pair a token-mode sign-in returns. */
export interface AuthTokens {
  accessToken: string;
  refreshToken: string;
}

/**
 * Where token mode keeps the session. Implement it over the platform keychain
 * on mobile, or a server-side session for a web frontend — refresh tokens last
 * days, so never over `localStorage`.
 */
export interface TokenStore {
  get(): AuthTokens | null | Promise<AuthTokens | null>;
  /** `null` signs out (logout, or a refresh the server refused). */
  set(tokens: AuthTokens | null): void | Promise<void>;
}

/** In-memory {@link TokenStore}: the session ends with the page or process. */
export function createMemoryTokenStore(initial: AuthTokens | null = null): TokenStore {
  let tokens = initial;
  return {
    get: () => tokens,
    set: (next) => {
      tokens = next;
    },
  };
}

export interface CreateApiClientOptions {
  /**
   * Base URL prepended to the spec paths. Default `''` keeps `/api/*` requests
   * same-origin (relative) so they can flow through a proxy that forwards
   * cookies. A standalone frontend points this at the backend origin.
   */
  baseUrl?: string;
  /** Injectable fetch (for tests / custom transports); defaults to global fetch. */
  fetch?: (request: Request) => Promise<Response>;
  /** `'cookie'` (default) or `'token'`; see the module docs. */
  auth?: 'cookie' | 'token';
  /** Token mode only: where tokens live. Defaults to {@link createMemoryTokenStore}. */
  tokenStore?: TokenStore;
}

/** Endpoints that start or renew a session without an existing bearer. */
const SIGN_IN_PATHS = [
  '/api/v1/login',
  '/api/v1/login/2fa',
  '/api/v1/auth/select-tenant',
  '/api/v1/auth/refresh',
];

/**
 * Endpoints whose token-mode success body can carry a fresh token pair.
 * `PATCH /me` is one: changing the email or password bumps the token epoch,
 * so the pair the caller held stops working the moment it succeeds.
 */
const ISSUING_PATHS = [...SIGN_IN_PATHS, '/api/v1/auth/switch-tenant', '/api/v1/me'];

const LOGOUT_PATH = '/api/v1/auth/logout';

/**
 * Endpoints whose 401 means "wrong credentials", not "expired session" — a
 * refresh cannot fix them, and refreshing on a refresh's own 401 would loop.
 */
const NO_REFRESH_PATHS = [...SIGN_IN_PATHS, LOGOUT_PATH];

const pathOf = (request: Request): string => new URL(request.url, 'http://x').pathname;
const endsWithAny = (path: string, candidates: string[]): boolean =>
  candidates.some((candidate) => path.endsWith(candidate));

/** The token pair in a token-mode response body, or null when it has none. */
async function readTokens(response: Response): Promise<AuthTokens | null> {
  try {
    const body: unknown = await response.clone().json();
    if (typeof body !== 'object' || body === null) return null;
    const { access_token: accessToken, refresh_token: refreshToken } = body as Record<string, unknown>;
    return typeof accessToken === 'string' && typeof refreshToken === 'string'
      ? { accessToken, refreshToken }
      : null;
  } catch {
    return null;
  }
}

export function createApiClient(options: CreateApiClientOptions = {}) {
  const baseUrl = options.baseUrl ?? '';
  const fetchFn =
    options.fetch ?? ((request: Request) => globalThis.fetch(request));
  const tokenMode = options.auth === 'token';
  const store = options.tokenStore ?? createMemoryTokenStore();

  /**
   * Pristine clones of each in-flight request, keyed by openapi-fetch's
   * per-request id — cloned BEFORE the first send so the body is replayable on
   * the post-refresh retry; deleted on every response/error path so the map
   * never outlives a request. Keep this middleware FIRST: if another middleware
   * short-circuits onRequest before it, the clone for that id would leak.
   * `sentToken` is the bearer the request went out with (token mode, and only
   * when this client attached it) — `null` means the caller owns auth.
   */
  const pristine = new Map<string, { request: Request; sentToken: string | null }>();

  /** The one refresh currently running, shared by every 401 that wants it. */
  let refreshing: Promise<boolean> | null = null;

  /** POST /api/v1/auth/refresh — true when the backend renewed the session. */
  const refreshOnce = async (): Promise<boolean> => {
    if (!tokenMode) {
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
    }

    const current = await store.get();
    if (current === null) return false;
    let response: Response;
    try {
      response = await fetchFn(
        new Request(`${baseUrl}/api/v1/auth/refresh`, {
          method: 'POST',
          credentials: 'omit',
          headers: { 'Content-Type': 'application/json', 'X-Auth-Mode': 'token' },
          body: JSON.stringify({ refresh_token: current.refreshToken }),
        })
      );
    } catch {
      // A network failure says nothing about the refresh token; keep it.
      return false;
    }
    const renewed = response.ok ? await readTokens(response) : null;
    if (renewed === null) {
      // Refused (expired, revoked, rotated away): the session is over.
      if (response.status === 401 || response.status === 403) await store.set(null);
      return false;
    }
    await store.set(renewed);
    return true;
  };

  const refresh = (): Promise<boolean> => {
    refreshing ??= refreshOnce().finally(() => {
      refreshing = null;
    });
    return refreshing;
  };

  const authMiddleware: Middleware = {
    async onRequest({ request, id }) {
      if (!request.headers.has('X-Requested-With')) {
        request.headers.set('X-Requested-With', 'XMLHttpRequest');
      }

      let sentToken: string | null = null;
      if (tokenMode) {
        request.headers.set('X-Auth-Mode', 'token');
        const tokens = await store.get();
        if (!request.headers.has('Authorization') && tokens !== null) {
          request.headers.set('Authorization', `Bearer ${tokens.accessToken}`);
          sentToken = tokens.accessToken;
        }
        // Logout revokes only what it is shown. Without the refresh token in
        // the body the bearer session ends but the refresh token stays usable
        // for days, so hand it over unless the caller sent a body of their own.
        if (
          tokens !== null &&
          request.method === 'POST' &&
          request.body === null &&
          pathOf(request).endsWith(LOGOUT_PATH)
        ) {
          const headers = new Headers(request.headers);
          headers.set('Content-Type', 'application/json');
          request = new Request(request, {
            headers,
            body: JSON.stringify({ refresh_token: tokens.refreshToken }),
          });
        }
      }

      pristine.set(id, { request: request.clone(), sentToken });
      return request;
    },
    async onResponse({ request, response, id }) {
      const entry = pristine.get(id);
      pristine.delete(id);

      const path = pathOf(request);
      if (tokenMode) {
        if (path.endsWith(LOGOUT_PATH)) {
          await store.set(null);
          return response;
        }
        if (response.ok && endsWithAny(path, ISSUING_PATHS)) {
          const issued = await readTokens(response);
          if (issued !== null) await store.set(issued);
          return response;
        }
      }

      if (response.status !== 401 || entry === undefined || endsWithAny(path, NO_REFRESH_PATHS)) {
        return response;
      }
      if (tokenMode && entry.sentToken === null) {
        // The caller supplied its own Authorization (or there was no session):
        // a refresh of ours would not change what this request carries.
        return response;
      }

      if (tokenMode) {
        // Another request may have refreshed while this one was in flight;
        // then the token it went out with is simply stale — replay, don't
        // rotate the refresh token a second time.
        const current = await store.get();
        if (current === null || current.accessToken === entry.sentToken) {
          if (!(await refresh())) return response;
        }
      } else if (!(await refresh())) {
        return response;
      }

      // Replay the pristine original directly through fetch — NOT the client —
      // so this middleware never sees it and cannot refresh twice.
      const replay = entry.request;
      if (tokenMode) {
        const current = await store.get();
        if (current === null) return response;
        replay.headers.set('Authorization', `Bearer ${current.accessToken}`);
      }
      return fetchFn(replay);
    },
    async onError({ id }) {
      pristine.delete(id);
    },
  };

  const client = createClient<paths>({
    baseUrl,
    credentials: tokenMode ? 'omit' : 'include',
    fetch: fetchFn,
  });
  client.use(authMiddleware);

  return client;
}

export type ApiClient = ReturnType<typeof createApiClient>;
