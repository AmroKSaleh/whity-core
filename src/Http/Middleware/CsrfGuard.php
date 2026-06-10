<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Core\Request;
use Whity\Core\Response;

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
    private const PROTECTED_POSTS = [
        '/api/login',
        '/api/login/2fa',
        '/api/auth/refresh',
        '/api/auth/logout',
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

        $value = $request->getHeader(self::HEADER_NAME);
        if ($value !== null && strcasecmp(trim($value), self::REQUIRED_VALUE) === 0) {
            return $next($request);
        }

        return Response::error('Cross-site request rejected', 403);
    }

    /**
     * Whether the request authenticates ambiently via an auth cookie.
     *
     * Requests carrying an Authorization header are NOT ambient: a cross-site
     * attacker cannot set that header without triggering a CORS preflight, so
     * CSRF does not apply regardless of which credential the backend prefers.
     *
     * @param Request $request The incoming HTTP request.
     * @return bool True when an auth cookie is the only credential present.
     */
    private function usesAmbientCookieAuth(Request $request): bool
    {
        if ($request->getHeader('Authorization') !== null) {
            return false;
        }

        $cookieHeader = $request->getHeader('Cookie');
        if ($cookieHeader === null) {
            return false;
        }

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
