import { cookies } from 'next/headers';

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
        options.body = request.body;
        options.duplex = 'half';
      }
    }

    const response = await fetch(backendUrl, options);
    const responseText = await response.text();

    const responseHeaders = new Headers(response.headers);
    responseHeaders.delete('transfer-encoding');
    responseHeaders.delete('content-encoding'); // Remove since we've already decompressed via response.text()
    responseHeaders.delete('content-length'); // Recalculate based on decompressed text length

    const result = new Response(responseText, {
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
