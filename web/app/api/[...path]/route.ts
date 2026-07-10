import { cookies } from 'next/headers';
import { backendUrl } from '@/lib/backend-url';
import { CLIENT_IP_HEADER, stripClientIpHeaders, trustedClientIp } from '@/lib/trusted-client-ip';

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

    // Trusted client-IP propagation (WC-b19ff21a). This proxy is the single
    // trusted front door: derive the real client IP from the incoming
    // X-Forwarded-For (honouring TRUSTED_PROXY_HOPS), then STRIP every
    // client-suppliable IP header (raw forwarding headers, any inbound copy of
    // the internal header, and underscore-variant smuggling headers) before
    // setting the internal header from the trusted value. The backend trusts
    // ONLY the internal header, so a browser can neither spoof its IP nor preset
    // the internal header.
    const clientIp = trustedClientIp(request);
    stripClientIpHeaders(headers);
    if (clientIp) {
      headers.set(CLIENT_IP_HEADER, clientIp);
    }

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

    // A reverse proxy must RELAY upstream redirects, not follow them. The SSO
    // hosted-login routes 302 the browser to the provider (…/start) and, on
    // return, to /dashboard or /login?sso_error=… (…/callback) — and they set the
    // flow-state / session cookie ON THAT 302. If we followed it here (undici's
    // default), the proxy would fetch the provider's page (or /dashboard) and
    // hand the browser a 200 with the Set-Cookie swallowed, so the flow can never
    // complete (the user gets stuck on …/start showing the provider's markup).
    // 'manual' surfaces the 3xx so its Location + Set-Cookie are forwarded and
    // the browser navigates itself. Scoped to the SSO routes to leave every other
    // proxied call's behaviour unchanged.
    const isSsoRedirectRoute = /(^|\/)auth\/sso\//.test(pathWithoutApi);
    const options: RequestInit & { duplex?: string } = {
      method,
      headers,
      redirect: isSsoRedirectRoute ? 'manual' : 'follow',
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
    // A relayed redirect (from redirect:'manual' above) carries only Location +
    // Set-Cookie; forward it with a null body so `new Response` never chokes on a
    // redirect-with-body and the browser just follows the Location.
    const isRedirect = response.status >= 300 && response.status < 400;
    // Use arrayBuffer() so binary responses (PNG/WEBP/ICO/SVG) are forwarded
    // byte-exact. text() would UTF-8-decode then re-encode, corrupting any
    // non-UTF-8 bytes. arrayBuffer() is also lossless for JSON/text bodies.
    const responseBody = isNullBody || isRedirect ? null : await response.arrayBuffer();

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
    // Log the full detail server-side only; never return the error (which may
    // carry a stack trace or internal host/URL) to the client (CodeQL: info
    // exposure through a stack trace).
    console.error('Proxy error:', error);
    return new Response(
      JSON.stringify({ error: 'Upstream request failed' }),
      { status: 500, headers: { 'Content-Type': 'application/json' } }
    );
  }
}
