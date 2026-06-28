import type { NextConfig } from "next";

/**
 * Security response headers for the Next.js FRONTEND (WC-187).
 *
 * Division of responsibility with the backend:
 *  - The PHP backend (src/Http/SecurityHeaders.php) emits the hardening headers
 *    on every /api response. Those responses are proxied to the browser
 *    verbatim by app/api/[...path]/route.ts (it copies upstream headers), so
 *    /api/* is ALREADY hardened by the backend.
 *  - These Next headers therefore cover the FRONTEND HTML/asset routes only and
 *    deliberately EXCLUDE /api/:path* (see the negative-lookahead source below)
 *    so the two layers never double-set or conflict on the same response.
 *
 * CSP scope (frontend): only `frame-ancestors 'none'` — the clickjacking
 * control. A restrictive script-src/style-src/default-src is intentionally
 * OMITTED: Next.js 16 ships an inline runtime/bootstrap and (in dev) inline
 * styles, so a strict policy would break the app. frame-ancestors is safe for
 * an HTML document and adds nothing the app depends on. The omitted directives
 * can be layered in later behind a nonce-based strategy without touching this
 * division of responsibility.
 *
 * HSTS is gated the same way as the backend: emitted only outside development
 * (NODE_ENV !== 'development'), so a browser never pins HSTS over local
 * plaintext HTTP.
 */
const isDevelopment = process.env.NODE_ENV === "development";

const securityHeaders = [
  { key: "X-Content-Type-Options", value: "nosniff" },
  { key: "X-Frame-Options", value: "DENY" },
  // Frontend CSP: clickjacking control only (see file docblock for why the
  // resource directives are deliberately omitted for the Next app).
  { key: "Content-Security-Policy", value: "frame-ancestors 'none'" },
  { key: "Referrer-Policy", value: "no-referrer" },
  // HSTS only outside development, matching the backend's gating.
  ...(isDevelopment
    ? []
    : [
        {
          key: "Strict-Transport-Security",
          value: "max-age=31536000; includeSubDomains",
        },
      ]),
];

const nextConfig: NextConfig = {
  transpilePackages: ["@whity/ui"],
  async headers() {
    return [
      {
        // Apply to every route EXCEPT the /api/* proxy, which is hardened by
        // the backend and forwarded verbatim — the negative lookahead prevents
        // duplicating/conflicting headers on those responses.
        source: "/((?!api/).*)",
        headers: securityHeaders,
      },
    ];
  },
};

export default nextConfig;
