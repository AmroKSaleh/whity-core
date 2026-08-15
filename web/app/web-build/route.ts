import { buildInfo } from '@/lib/build-info';

/**
 * `GET /web-build` — the web tier's own build identity (WHIT-587).
 *
 * WHY THIS LIVES ON THE WEB SERVICE, NOT IN `/api/health`
 *
 * Only the process that loaded a bundle knows which bundle it loaded. The
 * backend cannot answer this honestly:
 *  - in an image deployment the two tiers are separate images and the backend
 *    container has no `.next` directory to read at all;
 *  - in a source/bind-mount deployment it could read the checkout's
 *    `.next/BUILD_ID`, but that file is what is on DISK, not what the running
 *    Next process loaded at boot — and a checkout that changed the file under
 *    a still-running server is EXACTLY the incident this endpoint exists to
 *    surface. A backend-sourced field would be confidently wrong precisely
 *    when it mattered.
 *
 * So the mismatch check is two cheap unauthenticated probes against the same
 * public origin — `/api/health` for the API's `version`, `/web-build` for the
 * bundle's `core_version` — and they disagree loudly when the UI is stale.
 * Unauthenticated for the same reason `/api/health` is: monitoring has to be
 * able to see it from outside the box, and it discloses no more than the core
 * version `/api/health` already publishes.
 *
 * Dynamic and `no-store`: a cached response would report the PREVIOUS build's
 * identity, which is the very lie being hunted.
 */
export const dynamic = 'force-dynamic';

export async function GET(): Promise<Response> {
  return Response.json(buildInfo(), {
    headers: { 'Cache-Control': 'no-store' },
  });
}
