/**
 * @jest-environment node
 *
 * Regression guard: the server-side proxies MUST request an identity
 * (uncompressed) response on their proxy->backend hop.
 *
 * Caddy negotiates zstd/br, but Node's undici fetch only auto-decodes
 * gzip/deflate, so forwarding the browser's Accept-Encoding caused
 * `response.text()` to return raw compressed bytes and every JSON parse to
 * blow up (observed only against the freshly-built CI backend image). These
 * tests pin `Accept-Encoding: identity` on the outbound request so the fix
 * cannot silently regress. They run in the node environment because the route
 * handlers build real `Request`/`Response` objects (jsdom provides none).
 */

const BACKEND = 'http://backend.test';

// Mock the runtime backend-origin resolver to a fixed value so the outbound
// URL is deterministic and no real env is consulted.
jest.mock('@/lib/backend-url', () => ({
  backendUrl: () => BACKEND,
}));

// Mock next/headers cookies(): the route only calls `.getAll().map(...)`, so
// returning an empty list is enough and keeps the proxy off any auth path.
jest.mock('next/headers', () => ({
  cookies: jest.fn(async () => ({
    getAll: () => [] as { name: string; value: string }[],
  })),
}));

import { GET as catchAllGET } from '@/app/api/[...path]/route';
import { GET as openapiGET } from '@/app/openapi.json/route';

/** Capture the (url, init) every fetch was called with. */
function captureFetch() {
  const calls: { url: string; init: RequestInit | undefined }[] = [];
  const fn = jest.fn(async (url: string, init?: RequestInit): Promise<Response> => {
    calls.push({ url, init });
    return new Response('{}', {
      status: 200,
      headers: { 'content-type': 'application/json' },
    });
  });
  return { fn, calls };
}

/** Read the Accept-Encoding off a fetch init regardless of headers shape. */
function acceptEncoding(init: RequestInit | undefined): string | null {
  const h = init?.headers;
  if (h instanceof Headers) {
    return h.get('Accept-Encoding');
  }
  if (Array.isArray(h)) {
    const found = h.find(([k]) => k.toLowerCase() === 'accept-encoding');
    return found ? found[1] : null;
  }
  if (h && typeof h === 'object') {
    const key = Object.keys(h).find(k => k.toLowerCase() === 'accept-encoding');
    return key ? (h as Record<string, string>)[key] : null;
  }
  return null;
}

describe('proxy outbound Accept-Encoding', () => {
  const realFetch = global.fetch;

  afterEach(() => {
    global.fetch = realFetch;
    jest.clearAllMocks();
  });

  it('catch-all proxy forces identity even when the browser asks for br/zstd', async () => {
    const { fn, calls } = captureFetch();
    global.fetch = fn as unknown as typeof fetch;

    // The incoming browser request advertises compressed encodings.
    const request = new Request('http://localhost:3000/api/navigation', {
      method: 'GET',
      headers: { 'Accept-Encoding': 'br, zstd, gzip, deflate' },
    });

    await catchAllGET(request);

    expect(fn).toHaveBeenCalledTimes(1);
    expect(calls[0].url).toBe(`${BACKEND}/api/navigation`);
    expect(acceptEncoding(calls[0].init)).toBe('identity');
  });

  it('openapi.json proxy requests identity on its bare GET', async () => {
    const { fn, calls } = captureFetch();
    global.fetch = fn as unknown as typeof fetch;

    await openapiGET();

    expect(fn).toHaveBeenCalledTimes(1);
    expect(calls[0].url).toBe(`${BACKEND}/openapi.json`);
    expect(acceptEncoding(calls[0].init)).toBe('identity');
  });
});
