import { backendUrl } from '@/lib/backend-url';

/**
 * Forward an OAuth Protected Resource Metadata request to the backend (WHIT-709).
 *
 * When the bundled admin serves the public origin, an MCP client that connects
 * to `APP_URL/mcp` looks for this document on the same origin, before it holds
 * any credential. It is a public, static document, so nothing from the
 * browser is forwarded: no cookies, no authorization.
 */
export async function forwardOAuthMetadata(path: string): Promise<Response> {
  try {
    const upstream = await fetch(`${backendUrl()}${path}`, {
      method: 'GET',
      headers: { Accept: 'application/json' },
      redirect: 'manual',
    });
    const headers = new Headers();
    for (const name of ['content-type', 'cache-control']) {
      const value = upstream.headers.get(name);
      if (value !== null) headers.set(name, value);
    }
    return new Response(await upstream.text(), { status: upstream.status, headers });
  } catch (error) {
    console.error('OAuth metadata proxy error:', error);
    return new Response(JSON.stringify({ error: 'Upstream request failed' }), {
      status: 502,
      headers: { 'Content-Type': 'application/json' },
    });
  }
}
