import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createApiClient, createMemoryTokenStore, type AuthTokens } from '../src/index.ts';

const BASE = 'https://api.example.test';

interface Seen {
  path: string;
  method: string;
  credentials: RequestCredentials;
  headers: Headers;
  body: string;
}

type Handler = (request: Request, seen: Seen) => Response | Promise<Response>;

/** A fake backend: routes by path, records every request it receives. */
function fakeBackend(routes: Record<string, Handler>) {
  const seen: Seen[] = [];
  const fetch = async (request: Request): Promise<Response> => {
    const entry: Seen = {
      path: new URL(request.url).pathname,
      method: request.method,
      credentials: request.credentials,
      headers: new Headers(request.headers),
      body: request.body === null ? '' : await request.clone().text(),
    };
    seen.push(entry);
    const handler = routes[entry.path];
    if (handler === undefined) return new Response('not found', { status: 404 });
    return handler(request, entry);
  };
  return { fetch, seen, calls: (path: string) => seen.filter((s) => s.path === path) };
}

const json = (body: unknown, status = 200) =>
  new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

const tokenBody = (n: number) => ({
  access_token: `access-${n}`,
  refresh_token: `refresh-${n}`,
  token_type: 'Bearer',
  expires_in: 900,
});

const pair = (n: number): AuthTokens => ({ accessToken: `access-${n}`, refreshToken: `refresh-${n}` });

/** /me answers 200 only for the given bearer, 401 otherwise. */
const meAcceptingOnly = (token: string): Handler => (_request, seen) =>
  seen.headers.get('Authorization') === `Bearer ${token}` ? json({ id: 1 }) : json({ error: 'expired' }, 401);

// ── cookie mode (default) ───────────────────────────────────────────────────

test('cookie mode sends cookies and the CSRF header, no token headers', async () => {
  const backend = fakeBackend({ '/api/v1/me': () => json({ id: 1 }) });
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch });

  const { response } = await api.GET('/api/v1/me');

  assert.equal(response.status, 200);
  const [me] = backend.calls('/api/v1/me');
  assert.equal(me.credentials, 'include');
  assert.equal(me.headers.get('X-Requested-With'), 'XMLHttpRequest');
  assert.equal(me.headers.get('X-Auth-Mode'), null);
  assert.equal(me.headers.get('Authorization'), null);
});

test('cookie mode refreshes once on 401 and replays the request once', async () => {
  let refreshed = false;
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => {
      refreshed = true;
      return json({ user: {} });
    },
    '/api/v1/me': () => (refreshed ? json({ id: 1 }) : json({ error: 'expired' }, 401)),
  });
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch });

  const { response } = await api.GET('/api/v1/me');

  assert.equal(response.status, 200);
  assert.equal(backend.calls('/api/v1/auth/refresh').length, 1);
  assert.equal(backend.calls('/api/v1/me').length, 2);
});

test('cookie mode returns a second 401 as-is instead of looping', async () => {
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => json({ user: {} }),
    '/api/v1/me': () => json({ error: 'nope' }, 401),
  });
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch });

  const { response } = await api.GET('/api/v1/me');

  assert.equal(response.status, 401);
  assert.equal(backend.calls('/api/v1/auth/refresh').length, 1);
  assert.equal(backend.calls('/api/v1/me').length, 2);
});

test('cookie mode shares one refresh between concurrent 401s', async () => {
  let refreshed = false;
  const backend = fakeBackend({
    '/api/v1/auth/refresh': async () => {
      await new Promise((resolve) => setTimeout(resolve, 10));
      refreshed = true;
      return json({ user: {} });
    },
    '/api/v1/me': () => (refreshed ? json({ id: 1 }) : json({ error: 'expired' }, 401)),
  });
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch });

  const results = await Promise.all([api.GET('/api/v1/me'), api.GET('/api/v1/me'), api.GET('/api/v1/me')]);

  assert.deepEqual(results.map((r) => r.response.status), [200, 200, 200]);
  assert.equal(backend.calls('/api/v1/auth/refresh').length, 1);
});

test('a failed sign-in is not treated as an expired session', async () => {
  const backend = fakeBackend({
    '/api/v1/login': () => json({ error: 'Invalid credentials' }, 401),
    '/api/v1/auth/refresh': () => json({ user: {} }),
  });
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch });

  const { response } = await api.POST('/api/v1/login', { body: { email: 'a@b.c', password: 'x' } as never });

  assert.equal(response.status, 401);
  assert.equal(backend.calls('/api/v1/auth/refresh').length, 0);
});

// ── token mode ──────────────────────────────────────────────────────────────

test('token mode captures the tokens login returns and sends them as a bearer', async () => {
  const backend = fakeBackend({
    '/api/v1/login': () => json({ ...tokenBody(1), user: {} }),
    '/api/v1/me': meAcceptingOnly('access-1'),
  });
  const store = createMemoryTokenStore();
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  await api.POST('/api/v1/login', { body: { email: 'a@b.c', password: 'x' } as never });
  const { response } = await api.GET('/api/v1/me');

  assert.deepEqual(await store.get(), pair(1));
  assert.equal(response.status, 200);
  const [login] = backend.calls('/api/v1/login');
  assert.equal(login.headers.get('X-Auth-Mode'), 'token');
  assert.equal(login.headers.get('Authorization'), null);
  const [me] = backend.calls('/api/v1/me');
  assert.equal(me.credentials, 'omit');
  assert.equal(me.headers.get('X-Auth-Mode'), 'token');
});

test('token mode does not store anything from a 2FA challenge', async () => {
  const backend = fakeBackend({
    '/api/v1/login': () => json({ requires_2fa: true, temp_token: 'temp' }, 202),
  });
  const store = createMemoryTokenStore();
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  const { data } = await api.POST('/api/v1/login', { body: { email: 'a@b.c', password: 'x' } as never });

  assert.equal((data as { temp_token?: string }).temp_token, 'temp');
  assert.equal(await store.get(), null);
});

test('token mode refreshes with the stored refresh token and replays with the new bearer', async () => {
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => json(tokenBody(2)),
    '/api/v1/me': meAcceptingOnly('access-2'),
  });
  const store = createMemoryTokenStore(pair(1));
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  const { response } = await api.GET('/api/v1/me');

  assert.equal(response.status, 200);
  const [refresh] = backend.calls('/api/v1/auth/refresh');
  assert.deepEqual(JSON.parse(refresh.body), { refresh_token: 'refresh-1' });
  assert.equal(refresh.headers.get('X-Auth-Mode'), 'token');
  assert.equal(refresh.credentials, 'omit');
  assert.deepEqual(await store.get(), pair(2));
  assert.deepEqual(
    backend.calls('/api/v1/me').map((c) => c.headers.get('Authorization')),
    ['Bearer access-1', 'Bearer access-2']
  );
});

test('token mode shares one refresh between concurrent 401s', async () => {
  const backend = fakeBackend({
    '/api/v1/auth/refresh': async () => {
      await new Promise((resolve) => setTimeout(resolve, 10));
      return json(tokenBody(2));
    },
    '/api/v1/me': meAcceptingOnly('access-2'),
  });
  const store = createMemoryTokenStore(pair(1));
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  const results = await Promise.all([api.GET('/api/v1/me'), api.GET('/api/v1/me'), api.GET('/api/v1/me')]);

  assert.deepEqual(results.map((r) => r.response.status), [200, 200, 200]);
  assert.equal(backend.calls('/api/v1/auth/refresh').length, 1);
});

test('token mode replays a request that went out with a token already refreshed away', async () => {
  const store = createMemoryTokenStore(pair(1));
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => json(tokenBody(3)),
    '/api/v1/me': async (_request, seen) => {
      if (seen.headers.get('Authorization') === 'Bearer access-1') {
        // Someone else refreshed while this request was in flight.
        await store.set(pair(2));
        return json({ error: 'expired' }, 401);
      }
      return meAcceptingOnly('access-2')(_request, seen);
    },
  });
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  const { response } = await api.GET('/api/v1/me');

  assert.equal(response.status, 200);
  assert.equal(backend.calls('/api/v1/auth/refresh').length, 0);
  assert.deepEqual(await store.get(), pair(2));
});

test('token mode signs out when the server refuses the refresh', async () => {
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => json({ error: 'revoked' }, 401),
    '/api/v1/me': () => json({ error: 'expired' }, 401),
  });
  const store = createMemoryTokenStore(pair(1));
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  const { response } = await api.GET('/api/v1/me');

  assert.equal(response.status, 401);
  assert.equal(await store.get(), null);
  assert.equal(backend.calls('/api/v1/me').length, 1);
});

test('token mode keeps the session when the refresh fails on the network', async () => {
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => {
      throw new TypeError('network down');
    },
    '/api/v1/me': () => json({ error: 'expired' }, 401),
  });
  const store = createMemoryTokenStore(pair(1));
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  const { response } = await api.GET('/api/v1/me');

  assert.equal(response.status, 401);
  assert.deepEqual(await store.get(), pair(1));
});

test('token mode picks up the pair PATCH /me re-issues, and GET /me still refreshes', async () => {
  let refreshed = false;
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => {
      refreshed = true;
      return json(tokenBody(3));
    },
    '/api/v1/me': (request, seen) => {
      if (request.method === 'PATCH') return json({ user: {}, ...tokenBody(2) });
      return meAcceptingOnly('access-3')(request, seen);
    },
  });
  const store = createMemoryTokenStore(pair(1));
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  await api.PATCH('/api/v1/me', { body: { current_password: 'x', password: 'new-password' } });
  assert.deepEqual(await store.get(), pair(2));

  const { response } = await api.GET('/api/v1/me');
  assert.equal(response.status, 200);
  assert.ok(refreshed);
  assert.deepEqual(JSON.parse(backend.calls('/api/v1/auth/refresh')[0].body), { refresh_token: 'refresh-2' });
});

test('token mode logout revokes the refresh token too and clears the store', async () => {
  const backend = fakeBackend({ '/api/v1/auth/logout': () => json({ message: 'ok' }) });
  const store = createMemoryTokenStore(pair(1));
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  await api.POST('/api/v1/auth/logout');

  const [logout] = backend.calls('/api/v1/auth/logout');
  assert.equal(logout.headers.get('Authorization'), 'Bearer access-1');
  assert.deepEqual(JSON.parse(logout.body), { refresh_token: 'refresh-1' });
  assert.equal(await store.get(), null);
});

test("token mode leaves a caller's own Authorization alone and does not refresh for it", async () => {
  const backend = fakeBackend({
    '/api/v1/auth/refresh': () => json(tokenBody(2)),
    '/api/v1/me': () => json({ error: 'nope' }, 401),
  });
  const store = createMemoryTokenStore(pair(1));
  const api = createApiClient({ baseUrl: BASE, fetch: backend.fetch, auth: 'token', tokenStore: store });

  const { response } = await api.GET('/api/v1/me', { headers: { Authorization: 'Bearer my-own' } });

  assert.equal(response.status, 401);
  assert.equal(backend.calls('/api/v1/me')[0].headers.get('Authorization'), 'Bearer my-own');
  assert.equal(backend.calls('/api/v1/auth/refresh').length, 0);
});
