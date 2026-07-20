'use client';

import type { PluginFeature } from '@/lib/plugin-features';
import { AdminHeader } from '@/components/admin/admin-header';

/**
 * Generic "embed" screen (WC-246): iframes the plugin's own declared GET
 * route (`feature.embed.path`) into the admin shell — the sanctioned way for
 * a backend-only, deploy-copied plugin to ship a bespoke UI with ZERO edits
 * to this checkout (unlike `screen: 'custom'`, which requires registering a
 * component here).
 *
 * `feature.embed.path` is already the host-rewritten, versioned, same-origin
 * API path (e.g. `/api/v1/bom/tool`) — the browser requests it through the
 * SAME Next `/api/[...path]` proxy every other plugin call already goes
 * through, so auth cookies attach normally with zero new plumbing. The
 * framed response can be any self-contained HTML document (inline
 * `<script>`/`<style>`, its own CSP) — see
 * `Whity\Http\SecurityHeaders::respectingHandlerCsp()` on the backend, which
 * lets a handler-set CSP (and, when its `frame-ancestors` explicitly allows
 * it, the legacy `X-Frame-Options`) survive instead of being silently
 * overwritten by the strict JSON-API default.
 *
 * Sandboxed as a same-origin, authenticated plugin route (not third-party
 * content): scripts/forms/same-origin storage are allowed, top-level
 * navigation is not (a plugin screen can't navigate the admin shell away).
 */
export function EmbedScreen({ feature }: { feature: PluginFeature }) {
  if (feature.embed === null) {
    return null;
  }

  return (
    <div className="space-y-8">
      <AdminHeader
        title={feature.label}
        description={`Provided by the ${feature.plugin} plugin.`}
      />
      <iframe
        src={feature.embed.path}
        title={feature.label}
        className="h-[70vh] w-full rounded-lg border border-border"
        sandbox="allow-scripts allow-same-origin allow-forms"
      />
    </div>
  );
}
