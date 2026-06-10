<?php

declare(strict_types=1);

namespace Whity\Http\Middleware;

use Whity\Core\Request;
use Whity\Core\Response;

/**
 * CSRF defense-in-depth for the state-changing auth endpoints (WC-160).
 *
 * The auth model is httpOnly-cookie based, so the auth POSTs are the routes a
 * cross-site attacker could try to forge: login CSRF (logging the victim into
 * an attacker account) and forced refresh/logout. SameSite=Lax already blocks
 * cookie-bearing cross-site POSTs in modern browsers; this guard adds an
 * explicit, browser-enforced second layer:
 *
 * POSTs to the protected paths must carry `X-Requested-With: XMLHttpRequest`.
 * A cross-site HTML form cannot set custom headers at all, and a cross-origin
 * fetch/XHR that sets one triggers a CORS preflight, which the strict origin
 * allowlist ({@see \Whity\Http\Cors}) refuses for foreign origins. Same-origin
 * callers and allowlisted CORS-credentialed frontends simply send the header
 * (see web/lib/api-client.ts).
 *
 * The guard is method- and path-scoped: non-POST requests and unprotected
 * paths pass through untouched, and OPTIONS preflights are never blocked.
 * Rejections return a generic 403 without internal detail.
 */
final class CsrfGuard
{
    /**
     * State-changing auth endpoints requiring the custom header on POST.
     *
     * @var list<string>
     */
    private const PROTECTED_POSTS = [
        '/api/login',
        '/api/login/2fa',
        '/api/auth/refresh',
        '/api/auth/logout',
    ];

    /** The custom header cross-site forms cannot set. */
    private const HEADER_NAME = 'X-Requested-With';

    /** Required header value (compared case-insensitively). */
    private const REQUIRED_VALUE = 'XMLHttpRequest';

    /**
     * Enforce the custom-header CSRF check on protected auth POSTs.
     *
     * @param Request  $request The incoming HTTP request.
     * @param callable $next    The next middleware/handler in the pipeline.
     * @return Response HTTP response.
     */
    public function handle(Request $request, callable $next): Response
    {
        if ($request->getMethod() !== 'POST') {
            return $next($request);
        }

        if (!in_array($this->pathWithoutQuery($request->getPath()), self::PROTECTED_POSTS, true)) {
            return $next($request);
        }

        $value = $request->getHeader(self::HEADER_NAME);
        if ($value !== null && strcasecmp(trim($value), self::REQUIRED_VALUE) === 0) {
            return $next($request);
        }

        return Response::error('Cross-site request rejected', 403);
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
