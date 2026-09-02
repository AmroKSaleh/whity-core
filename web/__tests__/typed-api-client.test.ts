/**
 * @jest-environment node
 *
 * Characterization tests for the typed API client (WC-168).
 *
 * These pin the auth behavior of the legacy `lib/api-client.ts` wrapper —
 * `credentials: 'include'`, the X-Requested-With CSRF header (WC-160), and the
 * 401 → silent-refresh → single-retry flow — onto the openapi-fetch based
 * typed client, so the migration cannot regress auth or introduce refresh
 * loops. They run in the node environment because openapi-fetch builds real
 * `Request` objects (jsdom provides none).
 */

import { createApiClient, SESSION_EXPIRED_EVENT } from '@/lib/api/client';

/** Build a JSON Response the way the backend shapes it. */
function jsonResponse(status: number, body: unknown): Response {
  return new Response(JSON.stringify(body), {
    status,
    headers: { 'Content-Type': 'application/json' },
  });
}

/** A fetch mock that replays a scripted sequence of responses. */
function scriptedFetch(...responses: Response[]) {
  const calls: Request[] = [];
  const fn = jest.fn(async (request: Request): Promise<Response> => {
    calls.push(request.clone());
    const next = responses.shift();
    if (next === undefined) {
      throw new Error('scriptedFetch: no response scripted for this call');
    }
    return next;
  });
  return { fn, calls };
}

const BASE = 'http://backend.test';

function client(fetchImpl: (request: Request) => Promise<Response>) {
  return createApiClient({ baseUrl: BASE, fetch: fetchImpl });
}

describe('typed api client — request shaping', () => {
  it('sends credentials:include and the CSRF header on every request', async () => {
    const { fn, calls } = scriptedFetch(jsonResponse(200, { data: [] }));

    const result = await client(fn).GET('/api/v1/users');

    expect(fn).toHaveBeenCalledTimes(1);
    expect(calls[0].url).toBe(`${BASE}/api/v1/users`);
    expect(calls[0].method).toBe('GET');
    expect(calls[0].credentials).toBe('include');
    expect(calls[0].headers.get('X-Requested-With')).toBe('XMLHttpRequest');
    expect(result.data).toEqual({ data: [] });
  });

  it('lets caller-supplied headers win on clash (legacy behavior)', async () => {
    const { fn, calls } = scriptedFetch(jsonResponse(200, { data: [] }));

    await client(fn).GET('/api/v1/users', {
      headers: { 'X-Requested-With': 'CustomValue' },
    });

    expect(calls[0].headers.get('X-Requested-With')).toBe('CustomValue');
  });
});

describe('typed api client — 401 silent refresh', () => {
  it('refreshes once on 401 and retries the original request', async () => {
    const { fn, calls } = scriptedFetch(
      jsonResponse(401, { error: 'Authentication required' }),
      jsonResponse(200, { message: 'refreshed' }),
      jsonResponse(200, { data: [] })
    );

    const result = await client(fn).GET('/api/v1/users');

    expect(fn).toHaveBeenCalledTimes(3);

    // Second call is the refresh: POST /api/v1/auth/refresh with credentials
    // and the CSRF header (the backend rejects auth POSTs without it).
    expect(calls[1].url).toBe(`${BASE}/api/v1/auth/refresh`);
    expect(calls[1].method).toBe('POST');
    expect(calls[1].credentials).toBe('include');
    expect(calls[1].headers.get('X-Requested-With')).toBe('XMLHttpRequest');

    // Third call replays the original request exactly.
    expect(calls[2].url).toBe(`${BASE}/api/v1/users`);
    expect(calls[2].method).toBe('GET');
    expect(calls[2].credentials).toBe('include');
    expect(calls[2].headers.get('X-Requested-With')).toBe('XMLHttpRequest');

    // The caller sees the retry's payload, not the 401.
    expect(result.response.status).toBe(200);
    expect(result.data).toEqual({ data: [] });
  });

  it('returns the original 401 when the refresh fails — no retry', async () => {
    const { fn } = scriptedFetch(
      jsonResponse(401, { error: 'Authentication required' }),
      jsonResponse(401, { error: 'refresh denied' })
    );

    const result = await client(fn).GET('/api/v1/users');

    expect(fn).toHaveBeenCalledTimes(2);
    expect(result.response.status).toBe(401);
    expect(result.error).toEqual({ error: 'Authentication required' });
  });

  it('returns the original 401 when the refresh request throws', async () => {
    let call = 0;
    const fn = jest.fn(async (): Promise<Response> => {
      call += 1;
      if (call === 1) {
        return jsonResponse(401, { error: 'Authentication required' });
      }
      throw new Error('network down');
    });

    const result = await client(fn).GET('/api/v1/users');

    expect(fn).toHaveBeenCalledTimes(2);
    expect(result.response.status).toBe(401);
  });

  it('does not refresh again when the retry also returns 401 (no loop)', async () => {
    const { fn } = scriptedFetch(
      jsonResponse(401, { error: 'Authentication required' }),
      jsonResponse(200, { message: 'refreshed' }),
      jsonResponse(401, { error: 'still unauthorized' })
    );

    const result = await client(fn).GET('/api/v1/users');

    // Exactly three calls: original, refresh, retry. A loop would make more.
    expect(fn).toHaveBeenCalledTimes(3);
    expect(result.response.status).toBe(401);
  });

  it('preserves the method, body and content type on the retried request', async () => {
    const { fn, calls } = scriptedFetch(
      jsonResponse(401, { error: 'Authentication required' }),
      jsonResponse(200, { message: 'refreshed' }),
      jsonResponse(201, {
        data: { ids: [7], count: 1 },
      })
    );

    const body = {
      granteeType: 'role' as const,
      granteeId: 3,
      permissions: ['ous:read'],
      ouId: null,
    };
    const result = await client(fn).POST('/api/v1/delegations', { body });

    expect(fn).toHaveBeenCalledTimes(3);
    expect(calls[2].method).toBe('POST');
    expect(calls[2].headers.get('Content-Type')).toContain('application/json');
    expect(JSON.parse(await calls[2].text())).toEqual(body);
    expect(result.response.status).toBe(201);
    expect(result.data?.data.count).toBe(1);
  });

  it('does not attempt a refresh on non-401 errors', async () => {
    const { fn } = scriptedFetch(
      jsonResponse(403, { error: 'Insufficient permissions' })
    );

    const result = await client(fn).GET('/api/v1/delegations');

    expect(fn).toHaveBeenCalledTimes(1);
    expect(result.response.status).toBe(403);
    expect(result.error).toEqual({ error: 'Insufficient permissions' });
  });
});

describe('typed api client — module surface', () => {
  it('exports a ready-to-use singleton for app code', async () => {
    const mod = await import('@/lib/api/client');
    expect(typeof mod.api.GET).toBe('function');
    expect(typeof mod.api.POST).toBe('function');
    expect(typeof mod.api.PATCH).toBe('function');
    expect(typeof mod.api.DELETE).toBe('function');
  });
});

/**
 * Saying, once, that the session is over.
 *
 * `AuthGate` redirects on a null user but evaluates only at MOUNT, so a token
 * that expired mid-visit left a fully-rendered shell where every request 401'd
 * — and the sidebar, which turns its own failed fetch into an empty list,
 * showed no navigation at all. The product looked featureless rather than
 * signed out, which is exactly how it was reported.
 *
 * The second test is the one that matters more. Signing somebody out because
 * their laptop lost Wi-Fi for a moment would be a worse bug than the one being
 * fixed, and it is the kind that only shows up on bad networks — so the
 * distinction between "the server REFUSED the refresh" and "the refresh never
 * arrived" is pinned rather than trusted.
 */
describe('typed api client — a refused refresh ends the session', () => {
  // This suite runs in the NODE environment (see the file docblock: openapi-fetch
  // needs real `Request` objects, which jsdom does not provide), so there is no
  // `window` to dispatch on. An `EventTarget` is exactly the contract
  // `announceSessionExpired` uses — addEventListener / dispatchEvent — so
  // standing one up here exercises the real path rather than a stub of it.
  //
  // It also means the production guard is under test: without the window this
  // installs, `typeof window === 'undefined'` must make the announcement a
  // no-op instead of a crash, which is what keeps this module importable during
  // SSR.
  beforeAll(() => {
    (globalThis as unknown as { window: EventTarget }).window = new EventTarget();
  });

  afterAll(() => {
    delete (globalThis as unknown as { window?: EventTarget }).window;
  });

  const listen = () => {
    const seen = jest.fn();
    window.addEventListener(SESSION_EXPIRED_EVENT, seen);
    return {
      seen,
      stop: () => window.removeEventListener(SESSION_EXPIRED_EVENT, seen),
    };
  };

  it('announces the expiry when the refresh is refused', async () => {
    const { seen, stop } = listen();
    const { fn } = scriptedFetch(
      jsonResponse(401, { error: 'Authentication required' }),
      jsonResponse(401, { error: 'refresh denied' })
    );

    await client(fn).GET('/api/v1/users');

    expect(seen).toHaveBeenCalledTimes(1);
    stop();
  });

  it('stays silent when the refresh never reaches the server', async () => {
    // A dropped connection says nothing about whether the session is valid.
    const { seen, stop } = listen();
    let call = 0;
    const fn = jest.fn(async (): Promise<Response> => {
      call += 1;
      if (call === 1) {
        return jsonResponse(401, { error: 'Authentication required' });
      }
      throw new Error('network down');
    });

    await client(fn).GET('/api/v1/users');

    expect(seen).not.toHaveBeenCalled();
    stop();
  });

  it('stays silent when the refresh succeeds', async () => {
    // The ordinary path: a 15-minute token lapses, the refresh renews it, the
    // original request replays. Nobody should be signed out for that.
    const { seen, stop } = listen();
    const { fn } = scriptedFetch(
      jsonResponse(401, { error: 'Authentication required' }),
      jsonResponse(200, { ok: true }),
      jsonResponse(200, { data: [] })
    );

    await client(fn).GET('/api/v1/users');

    expect(seen).not.toHaveBeenCalled();
    stop();
  });

  it('says nothing on a non-401, where no refresh is attempted at all', async () => {
    const { seen, stop } = listen();
    const { fn } = scriptedFetch(jsonResponse(500, { error: 'boom' }));

    await client(fn).GET('/api/v1/users');

    expect(seen).not.toHaveBeenCalled();
    stop();
  });
});
