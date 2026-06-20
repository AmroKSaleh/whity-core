import { cookies } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';

/**
 * HTTP statuses that, per the Fetch spec, MUST NOT carry a response body.
 * Constructing `new Response(body, { status })` with a non-null body for any of
 * these throws ("Invalid response status code"), so we forward a null body for
 * them while still passing through the upstream headers.
 * See https://fetch.spec.whatwg.org/#null-body-status
 */
const NULL_BODY_STATUSES = new Set([101, 204, 205, 304]);

export async function GET(request: Request) {
  return proxyRequest(request, 'GET');
}

export async function POST(request: Request) {
  return proxyRequest(request, 'POST');
}

export async function PATCH(request: Request) {
  return proxyRequest(request, 'PATCH');
}

export async function PUT(request: Request) {
  return proxyRequest(request, 'PUT');
}

export async function DELETE(request: Request) {
  return proxyRequest(request, 'DELETE');
}

export async function OPTIONS(request: Request) {
  return proxyRequest(request, 'OPTIONS');
}

async function proxyRequest(request: Request, method: string): Promise<Response> {
  try {
    const url = new URL(request.url);
    const pathWithoutApi = url.pathname.replace('/api/', '');
    // Runtime-resolved per deployment (WC-171): one web build, many hosts.
    const upstreamUrl = `${backendUrl()}/api/${pathWithoutApi}${url.search}`;

    const cookieStore = await cookies();
    const cookieHeader = cookieStore
      .getAll()
      .map(c => `${c.name}=${c.value}`)
      .join('; ');

    const headers = new Headers(request.headers);
    headers.delete('host');
    headers.delete('connection');
    // Force an identity (uncompressed) response on the proxy->backend leg.
    // The browser's Accept-Encoding (br/zstd/gzip) is forwarded by the line
    // above, and Caddy will negotiate zstd — but Node's undici fetch only
    // auto-decodes gzip/deflate, so `response.text()` would hand back raw
    // compressed bytes and JSON parsing would explode. The proxy sits on the
    // same host as the backend, so compressing this hop buys nothing; asking
    // for identity sidesteps the entire compressed-garbage-body class of bug.
    headers.set('Accept-Encoding', 'identity');

    if (cookieHeader) {
      headers.set('Cookie', cookieHeader);
    }

    const options: RequestInit & { duplex?: string } = {
      method,
      headers,
    };

    if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
      if (request.body) {
        // Forward the body as RAW BYTES, never as a decoded string (WC-221).
        // `request.text()` decodes the body as UTF-8; for a BINARY body — e.g. a
        // `multipart/form-data` plugin upload whose part is a `.zip` — the zip's
        // non-UTF-8 bytes (local-file-header magic, CRC-32, sizes) are replaced
        // with U+FFFD on decode and re-encoded at a DIFFERENT byte length. The
        // multipart framing then no longer matches its boundary/declared sizes,
        // so the FrankenPHP backend keeps waiting for body bytes that never come
        // and the request hangs until the proxy/socket idle timeout (~45s) — the
        // plugin-upload e2e timeout. (A direct `curl` sends the bytes intact, so
        // it never reproduced this.) `arrayBuffer()` is byte-exact, so JSON and
        // binary bodies alike are forwarded verbatim.
        const bodyBytes = await request.arrayBuffer();
        if (bodyBytes.byteLength > 0) {
          options.body = bodyBytes;
          options.duplex = 'half';
        }
      }
    }

    const response = await fetch(upstreamUrl, options);

    const responseHeaders = new Headers(response.headers);
    responseHeaders.delete('transfer-encoding');
    // We requested identity encoding from the backend, so no decompression is
    // needed. Drop content-encoding anyway to avoid any client confusion.
    responseHeaders.delete('content-encoding');
    // content-length is forwarded as-is; arrayBuffer() reads the exact bytes
    // the backend sent, so the length remains accurate. If the backend omits it
    // (chunked), it stays absent — which is fine.

    // A null-body status (e.g. 204 No Content from an OU/role delete) must be
    // forwarded WITHOUT a body, or `new Response(...)` throws and the proxy
    // surfaces a 500. Read/forward the body only for statuses that allow one.
    const isNullBody = NULL_BODY_STATUSES.has(response.status);
    // Use arrayBuffer() so binary responses (PNG/WEBP/ICO/SVG) are forwarded
    // byte-exact. text() would UTF-8-decode then re-encode, corrupting any
    // non-UTF-8 bytes. arrayBuffer() is also lossless for JSON/text bodies.
    const responseBody = isNullBody ? null : await response.arrayBuffer();

    const result = new Response(responseBody, {
      status: response.status,
      statusText: response.statusText,
      headers: responseHeaders,
    });

    // Forward Set-Cookie headers
    response.headers.getSetCookie().forEach(cookie => {
      result.headers.append('Set-Cookie', cookie);
    });

    return result;
  } catch (error) {
    console.error('Proxy error:', error);
    return new Response(
      JSON.stringify({ error: String(error) }),
      { status: 500, headers: { 'Content-Type': 'application/json' } }
    );
  }
}
