import type { NextConfig } from "next";
import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";
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

/**
 * Build identity (WHIT-587).
 *
 * A restart after a checkout used to silently re-serve the previous bundle,
 * because nothing about a build recorded WHICH checkout it came from. These
 * three facts, resolved once here and frozen into the build by `env` below,
 * make that observable: `scripts/start-web.sh` compares the built commit to
 * the checked-out one before deciding to rebuild, and `GET /web-build` serves
 * them so the staleness is visible from outside the container.
 *
 * All of it degrades to null rather than failing a build: a source tarball
 * with no `.git`, or an image build with no git binary, is a legitimate way to
 * build this app.
 */
const buildCommit = resolveBuildCommit();

/**
 * The commit HEAD points at, or null when this is not a readable git checkout.
 */
function resolveBuildCommit(): string | null {
  if (process.env.WHITY_BUILD_COMMIT) {
    // Image builds pass it in: the build context is a copied tree with no .git.
    return process.env.WHITY_BUILD_COMMIT;
  }

  try {
    return execFileSync("git", ["rev-parse", "HEAD"], {
      cwd: __dirname,
      encoding: "utf8",
      stdio: ["ignore", "pipe", "ignore"],
    }).trim() || null;
  } catch {
    return null;
  }
}

/**
 * `CoreVersion::VERSION` — the platform's single source of truth for its own
 * version, read straight from the file that declares it.
 *
 * Parsed rather than duplicated into package.json: a copy is a copy that can
 * drift, and this number's whole job is to be comparable with the `version`
 * the backend reports on `/api/health`. The web app is built from the same
 * checkout as the core it serves, which is exactly the invariant being pinned.
 */
function resolveCoreVersion(): string | null {
  try {
    const source = readFileSync(
      path.join(__dirname, "..", "src", "Core", "CoreVersion.php"),
      "utf8",
    );

    return /const VERSION = '([^']+)'/.exec(source)?.[1] ?? null;
  } catch {
    return null;
  }
}

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
  // Extend Turbopack's filesystem boundary to the workspace root so that the
  // hoisted root node_modules and the ../packages/ui workspace are in scope.
  outputFileTracingRoot: path.join(__dirname, ".."),
  // `@tanstack/react-table` v9 is ESM-ONLY — its package.json is
  // `"type": "module"` with no CJS build, unlike v8 which shipped both.
  // Listing it here is what puts it on the transform path for BOTH Next
  // and Jest: next/jest derives its `transformIgnorePatterns` allowlist
  // from this array, so a hand-written override in jest.config.mjs is
  // silently useless (next/jest's own pattern ORs first and wins).
  // `table-core` is listed because that is where the row models and
  // features actually live; react-table only re-exports them.
  transpilePackages: [
    "@amroksaleh/ui",
    "@tanstack/react-table",
    "@tanstack/table-core",
  ],
  /**
   * BUILD_ID *is* the commit (WHIT-587). Next's default is a random id, which
   * makes a build output impossible to trace back to a source revision —
   * `scripts/start-web.sh` can then only ask "does a build exist?", which is
   * how a restart came to serve a bundle 268 commits stale. Pinning it to the
   * commit turns that into "is the build the one this checkout describes?".
   *
   * Returning null hands the decision back to Next (its random default), which
   * is the honest answer when there is no commit to name.
   */
  generateBuildId: async () => buildCommit,
  /**
   * Statically replaced at BUILD time — deliberately not runtime env. See
   * lib/build-info.ts for why the distinction is the whole feature.
   */
  env: {
    WHITY_BUILD_ID: buildCommit ?? "",
    WHITY_BUILD_COMMIT: buildCommit ?? "",
    WHITY_BUILD_CORE_VERSION: resolveCoreVersion() ?? "",
    WHITY_BUILT_AT: new Date().toISOString(),
  },
  // Peer-dep singletons (react, radix, react-hook-form, …) are guaranteed by
  // npm workspaces hoisting them to the root node_modules, so the previous
  // turbopack.resolveAlias pins are no longer needed.
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
