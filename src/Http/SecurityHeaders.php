<?php

declare(strict_types=1);

namespace Whity\Http;

/**
 * Centralized security response-header policy (WC-187).
 *
 * Mirrors {@see Cors}: a single source of truth for the hardening headers that
 * are merged into EVERY API response in public/index.php (the worker loop, the
 * single-request fallback, the OPTIONS/204 preflight path and the 500 error
 * path), so a client can never receive a response without them.
 *
 * Header rationale — each is chosen for a JSON API surface:
 *
 *  - X-Content-Type-Options: nosniff
 *      Stops a browser from MIME-sniffing a response away from its declared
 *      Content-Type, which neutralizes "JSON served as HTML/JS" sniffing
 *      attacks.
 *
 *  - X-Frame-Options: DENY
 *      Legacy clickjacking defense for older browsers that do not honor the
 *      CSP frame-ancestors directive. Paired with the CSP below. Also
 *      handler-overridable in the one case where a plugin genuinely wants its
 *      own response framed (WC-246, `screen: 'embed'`) — see
 *      {@see self::respectingHandlerCsp()}.
 *
 *  - Content-Security-Policy: default-src 'none'; frame-ancestors 'none'
 *      The modern clickjacking defense (frame-ancestors). Because this policy
 *      is calibrated for a JSON API response — never a rendered HTML document
 *      that loads scripts/styles/images — the strictest possible base of
 *      default-src 'none' is safe and adds defense in depth: even if a response
 *      were ever mis-rendered as a document it could load no subresources. The
 *      separate, app-aware frontend CSP lives in web/next.config.ts and is NOT
 *      this strict (see that file). A plugin CAN serve an actual HTML document
 *      (e.g. the `screen: 'custom'` fallback) that needs a different policy —
 *      see {@see self::respectingHandlerCsp()}, which lets a handler-set CSP
 *      survive the merge instead of being silently overwritten by this default.
 *
 *  - Referrer-Policy: no-referrer
 *      An API response carries no navigational context worth leaking; sending
 *      no Referer at all is the most conservative choice and prevents URL-borne
 *      tokens/identifiers in an API path from leaking cross-origin.
 *
 *  - Strict-Transport-Security: max-age=31536000; includeSubDomains
 *      Forces HTTPS for a year including subdomains. This is the ONLY
 *      environment-sensitive header: it is deliberately withheld in
 *      development, because a browser that sees HSTS once over local plaintext
 *      HTTP would then refuse future http:// connections to the host. Every
 *      non-development environment (staging, production, and — fail-secure —
 *      any unrecognized value) receives it.
 */
final class SecurityHeaders
{
    private const CSP_HEADER_NAME = 'Content-Security-Policy';
    private const CSP_HEADER_KEY = 'content-security-policy';
    private const FRAME_OPTIONS_HEADER_NAME = 'X-Frame-Options';

    private const NOSNIFF = 'nosniff';
    private const FRAME_OPTIONS = 'DENY';
    // JSON-only surface: lock everything down. frame-ancestors is the
    // clickjacking control; default-src 'none' is safe because no API response
    // is a document that needs to load subresources.
    private const CSP = "default-src 'none'; frame-ancestors 'none'";
    private const REFERRER_POLICY = 'no-referrer';
    // 1 year, applied to subdomains too. No 'preload' token: opting a host into
    // the browser preload list is an operational decision made out of band, not
    // something the app should assert unilaterally.
    private const HSTS = 'max-age=31536000; includeSubDomains';

    /**
     * Build the security headers to merge into a response for the given app env.
     *
     * The clickjacking/sniffing/referrer defenses are always present. HSTS is
     * added for every environment EXCEPT development (see class docblock).
     *
     * @param  string $appEnv The resolved APP_ENV (e.g. 'production', 'development').
     * @return array<string, string> Security headers to merge into the response.
     */
    public static function headers(string $appEnv): array
    {
        $headers = [
            'X-Content-Type-Options' => self::NOSNIFF,
            'X-Frame-Options' => self::FRAME_OPTIONS,
            'Content-Security-Policy' => self::CSP,
            'Referrer-Policy' => self::REFERRER_POLICY,
        ];

        // HSTS only outside development. Treat ONLY the explicit 'development'
        // value as dev — any other value (including an empty/misconfigured one)
        // is treated as a deployed environment and gets HSTS (fail-secure).
        if ($appEnv !== 'development') {
            $headers['Strict-Transport-Security'] = self::HSTS;
        }

        return $headers;
    }

    /**
     * Merge the hardening headers into a response's headers, but let a
     * HANDLER-SET Content-Security-Policy survive (WC-531).
     *
     * The strict `default-src 'none'` default above is calibrated for a JSON
     * API surface. A plugin can also serve a self-contained HTML document
     * (e.g. the sanctioned `screen: 'custom'` fallback) with its own inline
     * `<script>`/`<style>` — that response needs a policy that actually
     * permits its own content, which `default-src 'none'` would otherwise
     * silently overwrite: `Response::withHeaders()` lets the LATER array
     * (these hardening headers) win over anything already on the response.
     *
     * Every OTHER hardening header (nosniff, referrer-policy, HSTS) is still
     * enforced unconditionally regardless of what a handler sets — only CSP
     * (and, conditionally, X-Frame-Options — see below) are handler-
     * overridable, and only when the handler actually set a CSP of its own. A
     * response with no CSP of its own still gets the strict default (secure
     * by default).
     *
     * X-Frame-Options (WC-246): a plugin can also want ITS OWN response
     * embedded in an iframe inside the admin shell (a `screen: 'embed'`
     * feature) — the still-unconditional `X-Frame-Options: DENY` would block
     * that even after the CSP override above. Modern browsers already let
     * CSP's `frame-ancestors` supersede the legacy `X-Frame-Options` when
     * both are present, so X-Frame-Options is ALSO dropped, but ONLY when the
     * handler's own CSP declares an EXPLICIT `frame-ancestors` directive that
     * isn't `'none'` — `frame-ancestors` does not fall back to `default-src`
     * per the CSP spec, so a handler CSP with no `frame-ancestors` directive
     * at all leaves X-Frame-Options as the only clickjacking defense in
     * force, and dropping it there would be a real regression, not a feature.
     *
     * @param array<string, string> $securityHeaders The output of {@see self::headers()}.
     * @param array<string, string> $responseHeaders The response's CURRENT
     *        headers (already normalized to lowercase-hyphenated keys by
     *        {@see \Whity\Sdk\Http\Response::getHeaders()}).
     * @return array<string, string> The hardening headers to merge, with CSP
     *         (and, conditionally, X-Frame-Options) dropped as described above.
     */
    public static function respectingHandlerCsp(array $securityHeaders, array $responseHeaders): array
    {
        $handlerCsp = $responseHeaders[self::CSP_HEADER_KEY] ?? null;
        if (!is_string($handlerCsp)) {
            return $securityHeaders;
        }

        unset($securityHeaders[self::CSP_HEADER_NAME]);

        if (self::cspAllowsFraming($handlerCsp)) {
            unset($securityHeaders[self::FRAME_OPTIONS_HEADER_NAME]);
        }

        return $securityHeaders;
    }

    /**
     * True when a CSP string declares an explicit `frame-ancestors` directive
     * that permits at least some framing (anything other than `'none'`).
     *
     * @param string $csp A Content-Security-Policy header value.
     */
    private static function cspAllowsFraming(string $csp): bool
    {
        if (preg_match('/frame-ancestors\s+([^;]+)/i', $csp, $matches) !== 1) {
            return false;
        }

        return trim($matches[1]) !== "'none'";
    }
}
