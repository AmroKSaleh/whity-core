/**
 * @jest-environment node
 *
 * The web tier forwards OAuth Protected Resource Metadata to the backend
 * (WHIT-709), so an MCP client connecting to APP_URL/mcp finds where to sign in
 * when the bundled admin serves that origin.
 */

const BACKEND = 'http://backend.test';

jest.mock('@/lib/backend-url', () => ({
  backendUrl: () => BACKEND,
}));

import { GET as rootGet } from '@/app/.well-known/oauth-protected-resource/route';
import { GET as mcpGet } from '@/app/.well-known/oauth-protected-resource/mcp/route';

const originalFetch = global.fetch;
afterEach(() => {
  global.fetch = originalFetch;
});

function backend(response: Response) {
  const urls: string[] = [];
  global.fetch = jest.fn(async (url: string | URL | Request) => {
    urls.push(String(url));
    return response;
  }) as typeof fetch;
  return urls;
}

const METADATA = '{"resource":"https://app.test/mcp","authorization_servers":["https://auth.test"]}';

describe('OAuth protected-resource metadata proxy', () => {
  it('serves the path-suffixed document from the backend', async () => {
    const urls = backend(new Response(METADATA, { status: 200, headers: { 'content-type': 'application/json', 'cache-control': 'public, max-age=3600', 'set-cookie': 'x=1' } }));

    const response = await mcpGet();

    expect(urls).toEqual([`${BACKEND}/.well-known/oauth-protected-resource/mcp`]);
    expect(response.status).toBe(200);
    expect(await response.text()).toBe(METADATA);
    expect(response.headers.get('cache-control')).toBe('public, max-age=3600');
    expect(response.headers.get('set-cookie')).toBeNull();
  });

  it('passes a 404 through when OAuth is not configured', async () => {
    const urls = backend(new Response('{"error":"Not found"}', { status: 404 }));

    const response = await rootGet();

    expect(urls).toEqual([`${BACKEND}/.well-known/oauth-protected-resource`]);
    expect(response.status).toBe(404);
  });

  it('answers 502 when the backend is unreachable', async () => {
    global.fetch = jest.fn(async () => {
      throw new Error('down');
    }) as typeof fetch;
    jest.spyOn(console, 'error').mockImplementation(() => {});

    expect((await mcpGet()).status).toBe(502);
  });
});
