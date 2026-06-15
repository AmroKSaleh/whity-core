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
 *      CSP frame-ancestors directive. Paired with the CSP below.
 *
 *  - Content-Security-Policy: default-src 'none'; frame-ancestors 'none'
 *      The modern clickjacking defense (frame-ancestors). Because this policy
 *      only ever guards JSON API responses — never a rendered HTML document
 *      that loads scripts/styles/images — the strictest possible base of
 *      default-src 'none' is safe and adds defense in depth: even if a response
 *      were ever mis-rendered as a document it could load no subresources. The
 *      separate, app-aware frontend CSP lives in web/next.config.ts and is NOT
 *      this strict (see that file).
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
}
