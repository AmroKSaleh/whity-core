/**
 * @jest-environment node
 *
 * `/mcp` on the web tier forwards to the backend (WHIT-697), so the address the
 * connect wizard hands out works when the bundled admin serves APP_URL. It must
 * forward the bearer and the body, and must NOT forward the browser's cookies.
 */

const BACKEND = 'http://backend.test';

jest.mock('@/lib/backend-url', () => ({
  backendUrl: () => BACKEND,
}));

import { POST } from '@/app/mcp/route';

const originalFetch = global.fetch;
afterEach(() => {
  global.fetch = originalFetch;
});

function capture(response: Response) {
  const calls: { url: string; init: RequestInit }[] = [];
  global.fetch = jest.fn(async (url: string | URL | Request, init?: RequestInit) => {
    calls.push({ url: String(url), init: init ?? {} });
    return response;
  }) as typeof fetch;
  return calls;
}

const PING = '{"jsonrpc":"2.0","method":"ping","id":1}';

describe('/mcp proxy', () => {
  it('forwards the bearer and the body to the backend /mcp', async () => {
    const calls = capture(new Response('{"jsonrpc":"2.0","id":1,"result":{}}', { status: 200, headers: { 'content-type': 'application/json' } }));

    const response = await POST(
      new Request('http://web.test/mcp', {
        method: 'POST',
        headers: { Authorization: 'Bearer tok', 'Content-Type': 'application/json', Cookie: 'access_token=session' },
        body: PING,
      })
    );

    expect(calls).toHaveLength(1);
    expect(calls[0].url).toBe(`${BACKEND}/mcp`);
    const headers = new Headers(calls[0].init.headers);
    expect(headers.get('authorization')).toBe('Bearer tok');
    expect(headers.get('content-type')).toBe('application/json');
    expect(new TextDecoder().decode(calls[0].init.body as ArrayBuffer)).toBe(PING);
    expect(response.status).toBe(200);
    expect(await response.text()).toBe('{"jsonrpc":"2.0","id":1,"result":{}}');
  });

  it('forwards the MCP request headers a 2026-07-28 client must send, and the query', async () => {
    const calls = capture(new Response('{}', { status: 200 }));

    await POST(
      new Request('http://web.test/mcp?tools=discover', {
        method: 'POST',
        headers: { 'MCP-Protocol-Version': '2026-07-28', 'Mcp-Method': 'tools/call', 'Mcp-Name': 'get_api_users' },
        body: PING,
      })
    );

    // The backend refuses a 2026 request whose headers it cannot see (HeaderMismatch).
    const headers = new Headers(calls[0].init.headers);
    expect(headers.get('mcp-protocol-version')).toBe('2026-07-28');
    expect(headers.get('mcp-method')).toBe('tools/call');
    expect(headers.get('mcp-name')).toBe('get_api_users');
    expect(calls[0].url).toBe(`${BACKEND}/mcp?tools=discover`);
  });

  it('never forwards the browser session cookie', async () => {
    const calls = capture(new Response('{}', { status: 200 }));

    await POST(new Request('http://web.test/mcp', { method: 'POST', headers: { Cookie: 'access_token=session' }, body: PING }));

    expect(new Headers(calls[0].init.headers).get('cookie')).toBeNull();
  });

  it('does not pass a backend Set-Cookie through', async () => {
    capture(new Response('{}', { status: 200, headers: { 'set-cookie': 'x=y' } }));

    const response = await POST(new Request('http://web.test/mcp', { method: 'POST', body: PING }));

    expect(response.headers.get('set-cookie')).toBeNull();
  });

  it('answers a JSON-RPC error, not a stack trace, when the backend is down', async () => {
    global.fetch = jest.fn(async () => {
      throw new Error('connect ECONNREFUSED 10.0.0.5:80');
    }) as typeof fetch;
    const spy = jest.spyOn(console, 'error').mockImplementation(() => {});

    const response = await POST(new Request('http://web.test/mcp', { method: 'POST', body: PING }));
    const text = await response.text();

    expect(response.status).toBe(502);
    expect(JSON.parse(text).error.message).toBe('Upstream request failed');
    expect(text).not.toContain('10.0.0.5');
    spy.mockRestore();
  });
});
