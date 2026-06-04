import { cookies } from 'next/headers';

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
    const backendUrl = `http://localhost:8000/api/${pathWithoutApi}${url.search}`;

    const cookieStore = await cookies();
    const cookieHeader = cookieStore
      .getAll()
      .map(c => `${c.name}=${c.value}`)
      .join('; ');

    const headers = new Headers(request.headers);
    headers.delete('host');
    headers.delete('connection');

    if (cookieHeader) {
      headers.set('Cookie', cookieHeader);
    }

    const options: RequestInit & { duplex?: string } = {
      method,
      headers,
    };

    if (method !== 'GET' && method !== 'HEAD' && method !== 'OPTIONS') {
      if (request.body) {
        const bodyText = await request.text();
        if (bodyText) {
          options.body = bodyText;
          options.duplex = 'half';
        }
      }
    }

    const response = await fetch(backendUrl, options);

    const responseHeaders = new Headers(response.headers);
    responseHeaders.delete('transfer-encoding');
    responseHeaders.delete('content-encoding'); // Remove since we've already decompressed via response.text()
    responseHeaders.delete('content-length'); // Recalculate based on decompressed text length

    // A null-body status (e.g. 204 No Content from an OU/role delete) must be
    // forwarded WITHOUT a body, or `new Response(...)` throws and the proxy
    // surfaces a 500. Read/forward the body only for statuses that allow one.
    const isNullBody = NULL_BODY_STATUSES.has(response.status);
    const responseBody = isNullBody ? null : await response.text();

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
