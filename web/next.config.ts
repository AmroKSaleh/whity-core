import type { NextConfig } from "next";
import path from "path";

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
  // Extend Turbopack's filesystem boundary to the monorepo root so that
  // symlinks in node_modules that point to ../packages/ui are allowed.
  outputFileTracingRoot: path.join(__dirname, ".."),
  transpilePackages: ["@whity/ui"],
  // Turbopack follows symlinks to real disk paths, so packages/ui/src/* imports
  // are resolved starting from packages/ui/ — outside web/node_modules. Pin the
  // eight peer-deps that Turbopack can't find back to web/node_modules.
  turbopack: {
    resolveAlias: {
      "radix-ui": path.resolve(__dirname, "node_modules/radix-ui"),
      "@radix-ui/react-label": path.resolve(__dirname, "node_modules/@radix-ui/react-label"),
      "@radix-ui/react-slot": path.resolve(__dirname, "node_modules/@radix-ui/react-slot"),
      "@tabler/icons-react": path.resolve(__dirname, "node_modules/@tabler/icons-react"),
      "class-variance-authority": path.resolve(__dirname, "node_modules/class-variance-authority"),
      "clsx": path.resolve(__dirname, "node_modules/clsx"),
      "tailwind-merge": path.resolve(__dirname, "node_modules/tailwind-merge"),
      "react-hook-form": path.resolve(__dirname, "node_modules/react-hook-form"),
    },
  },
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
