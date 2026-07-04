<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * CSRF defense-in-depth for cookie-authenticated state changes (WC-160).
 *
 * The auth model is httpOnly-cookie based, and both RBAC and the 2FA
 * management endpoints fall back to the access_token cookie — an AMBIENT
 * credential a cross-site attacker can ride. SameSite=Lax already blocks
 * cookie-bearing cross-site POSTs in modern browsers; this guard adds an
 * explicit, browser-enforced second layer:
 *
 * A state-changing request (POST/PUT/PATCH/DELETE) must carry
 * `X-Requested-With: XMLHttpRequest` when it either (a) targets one of the
 * always-protected auth POSTs — login CSRF needs no cookies at all — or
 * (b) authenticates ambiently via an auth cookie without an Authorization
 * header. A cross-site HTML form cannot set custom headers, and a
 * cross-origin fetch/XHR that sets one triggers a CORS preflight, which the
 * strict origin allowlist ({@see \Whity\Http\Cors}) refuses for foreign
 * origins. Same-origin callers and allowlisted CORS-credentialed frontends
 * simply send the header (see web/lib/api-client.ts).
 *
 * Exemptions, deliberately: reads (GET/HEAD/OPTIONS) have nothing to forge;
 * Authorization-header (bearer) clients carry no ambient credential — an
 * attacker cannot attach that header cross-site — so API scripts keep working
 * unchanged; unauthenticated mutations to non-auth paths fail at their own
 * auth layer. Rejections return a generic 403 without internal detail.
 */
final class CsrfGuard
{
    /**
     * Auth endpoints requiring the custom header on POST even without any
     * cookie — they CREATE the session (login CSRF) or revoke/rotate it.
     *
     * @var list<string>
     */
    // WC-206: auth surface moved to /api/v1/; these paths must match the
    // versioned request path that reaches the middleware.
    //
    // WC-ddcd16ad: select-tenant and switch-tenant are listed EXPLICITLY. They
    // complete/re-mint the session, so the cookie (browser) path must actively
    // require X-Requested-With, and the token-mode exemption (X-Auth-Mode: token
    // with no auth cookie) must run for them deterministically — not merely pass
    // vacuously because no cookie happens to be present.
    private const PROTECTED_POSTS = [
        '/api/v1/login',
        '/api/v1/login/2fa',
        '/api/v1/auth/refresh',
        '/api/v1/auth/logout',
        '/api/v1/auth/select-tenant',
        '/api/v1/auth/switch-tenant',
    ];

    /**
     * HTTP methods that change state and therefore need the check.
     *
     * @var list<string>
     */
    private const STATE_CHANGING_METHODS = ['POST', 'PUT', 'PATCH', 'DELETE'];

    /**
     * Cookies that act as ambient credentials for this platform.
     *
     * @var list<string>
     */
    private const AUTH_COOKIES = ['access_token', 'refresh_token', 'temp_auth_token'];

    /** The custom header cross-site forms cannot set. */
    private const HEADER_NAME = 'X-Requested-With';

    /** Required header value (compared case-insensitively). */
    private const REQUIRED_VALUE = 'XMLHttpRequest';

    /**
     * Enforce the custom-header CSRF check on forgeable state changes.
     *
     * @param Request  $request The incoming HTTP request.
     * @param callable $next    The next middleware/handler in the pipeline.
     * @return Response HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        $method = $request->getMethod();
        if (!in_array($method, self::STATE_CHANGING_METHODS, true)) {
            return $next($request);
        }

        $alwaysProtected = $method === 'POST'
            && in_array($this->pathWithoutQuery($request->getPath()), self::PROTECTED_POSTS, true);

        if (!$alwaysProtected && !$this->usesAmbientCookieAuth($request)) {
            return $next($request);
        }

        // Token-mode exemption (WC-ddcd16ad): an always-protected POST (login,
        // refresh, etc.) that carries X-Auth-Mode: token and NO auth cookie is an
        // explicit native-client request, not a browser form.  A cross-site
        // attacker cannot forge X-Auth-Mode (custom headers trigger a CORS
        // preflight that our strict origin allowlist refuses), and without a
        // cookie there is no ambient credential to steal.  Exempting it keeps
        // non-browser clients working without requiring X-Requested-With.
        if ($alwaysProtected && !$this->usesAmbientCookieAuth($request)) {
            $authModeHeader = $request->getHeader('X-Auth-Mode');
            if ($authModeHeader !== null && strtolower(trim($authModeHeader)) === 'token') {
                return $next($request);
            }
        }

        $value = $request->getHeader(self::HEADER_NAME);
        if ($value !== null && strcasecmp(trim($value), self::REQUIRED_VALUE) === 0) {
            return $next($request);
        }

        return Response::error('Cross-site request rejected', 403);
    }

    /**
     * Whether the request authenticates ambiently via an auth cookie.
     *
     * CSRF attacks exploit ambient credentials — cookies that a browser attaches
     * automatically to cross-site requests. A well-formed `Authorization: Bearer`
     * header cannot be set by a cross-site form or an unpreflighted fetch, so a
     * request that carries a Bearer header AND NO auth cookie is not forgeable
     * cross-site and is exempt from the custom-header requirement.
     *
     * However: when BOTH a Bearer header and an auth cookie are present, the
     * cookie is ambient — the backend (WC-ddcd16ad) prefers the cookie in that
     * case — so the request is treated as ambient and the custom header is still
     * required. This prevents a scenario where an attacker-forged cookie-bearing
     * request bypasses the guard by also injecting (or spoofing) a Bearer header.
     *
     * Precedence rule (WC-ddcd16ad): Bearer-only → not ambient (exempt).
     *                                Cookie present (with or without Bearer) → ambient (check required).
     *
     * @param Request $request The incoming HTTP request.
     * @return bool True when an auth cookie is present (ambient credential in play).
     */
    private function usesAmbientCookieAuth(Request $request): bool
    {
        // Check for auth cookies FIRST: if ANY auth cookie is present the request
        // is ambient regardless of whether a Bearer header is also present.
        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader !== null) {
            foreach (explode(';', $cookieHeader) as $cookie) {
                $parts = explode('=', trim($cookie), 2);
                if (
                    count($parts) === 2
                    && $parts[1] !== ''
                    && in_array($parts[0], self::AUTH_COOKIES, true)
                ) {
                    return true;
                }
            }
        }

        // No auth cookie. Only a WELL-FORMED bearer header counts as a non-ambient
        // explicit credential (exempts from CSRF). A missing or malformed one means
        // the request is unauthenticated — the auth layer will reject it, but the
        // CSRF guard returns false (no ambient cookie = nothing to protect here).
        $authHeader = $request->getHeader('Authorization');
        if ($authHeader !== null && preg_match('/^Bearer\s+\S+$/', $authHeader) === 1) {
            return false;
        }

        return false;
    }

    /**
     * Strip the query string from a request path.
     *
     * @param string $rawPath The raw request path.
     * @return string The path component only.
     */
    private function pathWithoutQuery(string $rawPath): string
    {
        $path = parse_url($rawPath, PHP_URL_PATH);

        return is_string($path) ? $path : $rawPath;
    }
}
