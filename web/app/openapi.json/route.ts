/**
 * Same-origin proxy for the backend's LIVE OpenAPI document (WC-169, WC-209).
 *
 * The Next catch-all proxy (`app/api/[...path]/route.ts`) only forwards
 * `/api/*`, so this tiny route handler exposes the spec on the Next origin
 * instead (fetching it cross-origin from the browser would require backend
 * CORS). The document is public — no cookies or auth are forwarded.
 *
 * WC-209: this now targets the backend's DYNAMIC endpoint (`/api/openapi.json`,
 * regenerated from the live router per request) rather than the committed,
 * core-only static file at the backend root. That keeps the schema-driven
 * plugin CRUD UI in sync with plugins installed/uninstalled/reloaded after the
 * last manual `generate:openapi`, which the static file never reflected.
 */
import { backendUrl } from '@/lib/backend-url';

export async function GET(): Promise<Response> {
  try {
    // Force identity (uncompressed) on this loopback hop: undici only
    // auto-decodes gzip/deflate, so if Caddy negotiates zstd/br the
    // `upstream.text()` below would return raw compressed bytes. The backend
    // is same-host, so compressing this leg is pointless anyway.
    const upstream = await fetch(`${backendUrl()}/api/openapi.json`, {
      headers: { 'Accept-Encoding': 'identity' },
    });
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
