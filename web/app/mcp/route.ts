import { backendUrl } from '@/lib/backend-url';
import { CLIENT_IP_HEADER, stripClientIpHeaders, trustedClientIp } from '@/lib/trusted-client-ip';

/**
 * Forward `/mcp` to the backend (WHIT-697).
 *
 * When the bundled admin serves the public origin, `APP_URL` is this server, and
 * the connect wizard hands AI apps `APP_URL + /mcp`. Without this route that
 * address 404s here, because only `/api/*` was forwarded — so every app set up
 * through the wizard on such a deployment would fail to connect. A headless
 * deployment, whose `APP_URL` is the backend itself, never reaches this file.
 *
 * Narrower than the `/api` proxy on purpose. MCP authenticates with a bearer
 * token only, so the browser's cookies are NOT forwarded: a page cannot ride the
 * signed-in user's session into the agent surface. The request headers are
 * reduced to the ones the transport reads.
 */

const FORWARDED_REQUEST_HEADERS = [
  'authorization',
  'content-type',
  'accept',
  'mcp-protocol-version',
  'mcp-session-id',
  'last-event-id',
];

export async function POST(request: Request) {
  return forward(request);
}

export async function GET(request: Request) {
  return forward(request);
}

export async function DELETE(request: Request) {
  return forward(request);
}

async function forward(request: Request): Promise<Response> {
  try {
    const url = new URL(request.url);
    const headers = new Headers();
    for (const name of FORWARDED_REQUEST_HEADERS) {
      const value = request.headers.get(name);
      if (value !== null) headers.set(name, value);
    }
    // Same trusted client-IP rule as the /api proxy: derive it, then set only
    // the internal header the backend trusts.
    stripClientIpHeaders(headers);
    const clientIp = trustedClientIp(request);
    if (clientIp) headers.set(CLIENT_IP_HEADER, clientIp);
    headers.set('Accept-Encoding', 'identity');

    const init: RequestInit & { duplex?: string } = { method: request.method, headers, redirect: 'manual' };
    if (request.method === 'POST') {
      const body = await request.arrayBuffer();
      if (body.byteLength > 0) {
        init.body = body;
        init.duplex = 'half';
      }
    }

    const upstream = await fetch(`${backendUrl()}/mcp${url.search}`, init);
    const responseHeaders = new Headers(upstream.headers);
    responseHeaders.delete('transfer-encoding');
    responseHeaders.delete('content-encoding');
    responseHeaders.delete('set-cookie');

    // Streamed through rather than buffered: MCP responses may be an event
    // stream, and buffering one would hold every event until the last.
    const hasBody = upstream.status !== 204 && upstream.status !== 304;
    return new Response(hasBody ? upstream.body : null, {
      status: upstream.status,
      statusText: upstream.statusText,
      headers: responseHeaders,
    });
  } catch (error) {
    console.error('MCP proxy error:', error);
    return new Response(JSON.stringify({ jsonrpc: '2.0', id: null, error: { code: -32603, message: 'Upstream request failed' } }), {
      status: 502,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}
