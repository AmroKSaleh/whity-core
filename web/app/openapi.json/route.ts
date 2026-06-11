/**
 * Same-origin proxy for the backend's public OpenAPI document (WC-169).
 *
 * The Next catch-all proxy (`app/api/[...path]/route.ts`) only forwards
 * `/api/*`, but the spec is served at the backend ROOT (`/openapi.json`).
 * Fetching it cross-origin from the browser would require backend CORS, so
 * this tiny route handler exposes it on the Next origin instead. The document
 * is public — no cookies or auth are forwarded.
 */
export async function GET(): Promise<Response> {
  const backendUrl = process.env.NEXT_PUBLIC_API_URL || 'http://localhost:8000';

  try {
    const upstream = await fetch(`${backendUrl}/openapi.json`);
    const body = await upstream.text();

    return new Response(body, {
      status: upstream.status,
      headers: {
        'Content-Type':
          upstream.headers.get('content-type') ?? 'application/json',
      },
    });
  } catch (error) {
    return Response.json(
      { error: `Failed to reach backend OpenAPI document: ${String(error)}` },
      { status: 502 }
    );
  }
}
