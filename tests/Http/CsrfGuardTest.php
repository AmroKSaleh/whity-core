<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Http\Middleware\CsrfGuard;

/**
 * WC-160 CSRF defense-in-depth for the state-changing auth endpoints.
 *
 * The guard requires the custom `X-Requested-With: XMLHttpRequest` header on
 * POSTs to /api/login, /api/login/2fa, /api/auth/refresh and /api/auth/logout.
 * Cross-site HTML forms cannot set custom headers, and a cross-origin
 * fetch/XHR that sets one triggers a CORS preflight that the origin allowlist
 * refuses for foreign origins — so a forged cross-site POST can never carry it.
 * Legitimate same-origin and allowlisted CORS-credentialed flows just send the
 * header.
 */
class CsrfGuardTest extends TestCase
{
    private CsrfGuard $guard;

    protected function setUp(): void
    {
        $this->guard = new CsrfGuard();
    }

    /**
     * @return callable(Request): Response
     */
    private function nextHandler(bool &$reached): callable
    {
        return static function (Request $req) use (&$reached): Response {
            $reached = true;
            return new Response(200, 'ok');
        };
    }

    /**
     * @return list<array{string}>
     */
    public static function protectedAuthPosts(): array
    {
        return [
            ['/api/login'],
            ['/api/login/2fa'],
            ['/api/auth/refresh'],
            ['/api/auth/logout'],
        ];
    }

    /**
     * A forged cross-site POST (no custom header — exactly what an attacker's
     * auto-submitting form produces) is refused with 403 before the handler.
     *
     * @dataProvider protectedAuthPosts
     */
    public function testRejectsAuthPostWithoutCsrfHeader(string $path): void
    {
        $request = new Request('POST', $path, ['Content-Type' => 'application/json'], '{}');

        $reached = false;
        $response = $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached, "Forged POST {$path} must not reach the handler");
        $this->assertSame(403, $response->getStatusCode());
        $data = json_decode($response->getBody(), true);
        $this->assertSame('Cross-site request rejected', $data['error']);
    }

    /**
     * The same POSTs WITH the custom header pass through untouched.
     *
     * @dataProvider protectedAuthPosts
     */
    public function testAllowsAuthPostWithCsrfHeader(string $path): void
    {
        $request = new Request(
            'POST',
            $path,
            ['Content-Type' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest'],
            '{}'
        );

        $reached = false;
        $response = $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached, "Legitimate POST {$path} must reach the handler");
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * The header value must be XMLHttpRequest; other values do not satisfy the
     * guard (case-insensitive comparison, mirroring browser normalization).
     */
    public function testRejectsWrongHeaderValue(): void
    {
        $request = new Request('POST', '/api/login', ['X-Requested-With' => 'fetch']);

        $reached = false;
        $response = $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * Case-insensitive value comparison: `xmlhttprequest` passes.
     */
    public function testAcceptsCaseInsensitiveHeaderValue(): void
    {
        $request = new Request('POST', '/api/login', ['X-Requested-With' => 'xmlhttprequest']);

        $reached = false;
        $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
    }

    /**
     * A query string on a protected path does not bypass the guard.
     */
    public function testProtectedPathWithQueryStringStillGuarded(): void
    {
        $request = new Request('POST', '/api/login?redirect=/dashboard');

        $reached = false;
        $response = $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertFalse($reached);
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * An UNAUTHENTICATED state-changing request to a non-auth path carries no
     * ambient credential an attacker could ride, so the guard stays out of the
     * way (the route's own auth will 401 it).
     */
    public function testUnauthenticatedNonAuthPostPassesWithoutHeader(): void
    {
        $request = new Request('POST', '/api/users');

        $reached = false;
        $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
    }

    /**
     * A COOKIE-authenticated state-changing request (the CSRF-able shape:
     * ambient credential, no custom header) is rejected on ANY path — RBAC and
     * the 2FA management endpoints fall back to the access_token cookie, so
     * admin mutations and 2FA disable are forgeable targets without this.
     */
    public function testCookieAuthenticatedMutationWithoutHeaderRejected(): void
    {
        foreach (
            [
                ['POST', '/api/users'],
                ['PATCH', '/api/me'],
                ['POST', '/api/auth/2fa/disable'],
                ['DELETE', '/api/roles/7'],
            ] as [$method, $path]
        ) {
            $request = new Request($method, $path, ['Cookie' => 'access_token=some.jwt.value']);

            $reached = false;
            $response = $this->guard->handle($request, $this->nextHandler($reached));

            $this->assertFalse($reached, "Cookie-authed {$method} {$path} without header must be rejected");
            $this->assertSame(403, $response->getStatusCode());
        }
    }

    /**
     * The same cookie-authenticated mutations WITH the header pass through.
     */
    public function testCookieAuthenticatedMutationWithHeaderPasses(): void
    {
        $request = new Request('POST', '/api/auth/2fa/disable', [
            'Cookie' => 'access_token=some.jwt.value',
            'X-Requested-With' => 'XMLHttpRequest',
        ]);

        $reached = false;
        $response = $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * Bearer-authenticated requests (Authorization header, e.g. API scripts)
     * are exempt: a cross-site attacker cannot attach an Authorization header
     * without triggering a CORS preflight, so there is no forgeable ambient
     * credential and non-browser clients keep working unchanged.
     */
    public function testBearerAuthenticatedMutationPassesWithoutHeader(): void
    {
        $request = new Request('POST', '/api/users', ['Authorization' => 'Bearer some.jwt.value']);

        $reached = false;
        $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
    }

    /**
     * Cookie-authenticated GETs are never blocked (nothing to forge).
     */
    public function testCookieAuthenticatedGetPasses(): void
    {
        $request = new Request('GET', '/api/users', ['Cookie' => 'access_token=some.jwt.value']);

        $reached = false;
        $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
    }

    /**
     * Non-state-changing methods on protected paths pass (nothing to forge).
     */
    public function testGetOnProtectedPathPasses(): void
    {
        $request = new Request('GET', '/api/login');

        $reached = false;
        $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
    }

    /**
     * OPTIONS (CORS preflight) always passes — the worker answers preflights
     * before the kernel, but the guard must stay permissive defensively.
     */
    public function testOptionsAlwaysPasses(): void
    {
        $request = new Request('OPTIONS', '/api/login');

        $reached = false;
        $this->guard->handle($request, $this->nextHandler($reached));

        $this->assertTrue($reached);
    }
}
