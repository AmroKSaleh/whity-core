<?php

declare(strict_types=1);

namespace Tests\Http;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Core\Tenant\TenantResolutionException;
use Whity\Http\HttpKernel;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\RbacMiddleware;

/**
 * WC-159 single-decode contract: the JWT is decoded exactly once per request.
 *
 * EnforceTenantIsolation (the first pipeline middleware) parses the token once
 * and stashes the decoded claims (array|null) on the Request as the
 * {@see Request::ATTR_JWT_CLAIMS} attribute. TenantContext::resolve() and
 * RbacMiddleware read the stashed claims instead of re-parsing; both fall back
 * to parsing only when the attribute is absent (standalone usage), so behavior
 * is unchanged outside the kernel pipeline.
 *
 * Uses a real JwtParser (counting subclass) rather than a mock so the assertion
 * covers genuine firebase/php-jwt decode work, not mock bookkeeping.
 */
class JwtSingleDecodeTest extends TestCase
{
    /** ≥32-char HS256 secret per the platform security guardrail. */
    private const SECRET = 'jwt-single-decode-test-secret-0123456789abcdef';

    protected function setUp(): void
    {
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    /**
     * A real JwtParser that counts how many times parse() is invoked.
     */
    private function countingParser(): JwtParser
    {
        return new class (self::SECRET) extends JwtParser {
            public int $parseCalls = 0;

            /**
             * @return array<string, mixed>|null
             */
            public function parse(string $token): ?array
            {
                $this->parseCalls++;
                return parent::parse($token);
            }
        };
    }

    /**
     * The Request attribute bag stores, reads back, and reports values —
     * including an explicitly stashed null (absent ≠ stashed-null).
     */
    public function testRequestAttributeBagStoresAndReadsValues(): void
    {
        $request = new Request('GET', '/api/resource');

        $this->assertFalse($request->hasAttribute('jwt.claims'));
        $this->assertNull($request->getAttribute('jwt.claims'));
        $this->assertSame('fallback', $request->getAttribute('jwt.claims', 'fallback'));

        $request->setAttribute('jwt.claims', ['user_id' => 7]);
        $this->assertTrue($request->hasAttribute('jwt.claims'));
        $this->assertSame(['user_id' => 7], $request->getAttribute('jwt.claims'));

        // A stashed null is "present": consumers must not re-parse it away.
        $request->setAttribute('jwt.claims', null);
        $this->assertTrue($request->hasAttribute('jwt.claims'));
        $this->assertNull($request->getAttribute('jwt.claims', 'fallback'));
    }

    /**
     * Core contract: across the production pipeline (EnforceTenantIsolation ->
     * RbacMiddleware -> handler) the JWT is decoded exactly once, and both
     * former parse sites still resolve the claims they need.
     */
    public function testJwtDecodedExactlyOncePerRequestAcrossPipeline(): void
    {
        $parser = $this->countingParser();
        $token = $parser->create(['user_id' => 7, 'tenant_id' => 1], 3600, 'access');
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')->with(7, 'users:read', 1)->willReturn(true);

        $tenantMiddleware = new EnforceTenantIsolation($parser);
        $rbacMiddleware = new RbacMiddleware($parser, $roleChecker);

        $handler = fn(Request $req): Response => new Response(200, 'ok');
        $response = $tenantMiddleware->handle(
            $request,
            fn(Request $req): Response => $rbacMiddleware->handle($req, $handler, null, 'users:read')
        );

        $this->assertSame(200, $response->getStatusCode());
        // Both former call sites still resolve claims:
        $this->assertSame(1, TenantContext::getTenantId());
        $this->assertNotNull($request->user);
        $this->assertSame(7, $request->user->user_id);
        // ... from a single decode.
        $this->assertSame(1, $parser->parseCalls, 'JWT must be decoded exactly once per request');
    }

    /**
     * The decoded claims are shared on the Request as an attribute so any
     * downstream consumer can read them without re-parsing.
     */
    public function testClaimsAreStashedAsRequestAttribute(): void
    {
        $parser = $this->countingParser();
        $token = $parser->create(['user_id' => 7, 'tenant_id' => 1], 3600, 'access');
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $middleware = new EnforceTenantIsolation($parser);
        $middleware->handle($request, fn(Request $req): Response => new Response(200, 'ok'));

        $this->assertTrue($request->hasAttribute(Request::ATTR_JWT_CLAIMS));
        $claims = $request->getAttribute(Request::ATTR_JWT_CLAIMS);
        $this->assertIsArray($claims);
        $this->assertSame(7, $claims['user_id']);
        $this->assertSame(1, $claims['tenant_id']);
    }

    /**
     * RbacMiddleware reuses stashed claims without invoking the parser.
     */
    public function testRbacMiddlewareReusesStashedClaimsWithoutReparsing(): void
    {
        TenantContext::setTenantId(1);

        $parser = $this->countingParser();
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer already.validated.token']);
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, ['user_id' => 7, 'tenant_id' => 1]);

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')->with(7, 'users:read', 1)->willReturn(true);

        $middleware = new RbacMiddleware($parser, $roleChecker);
        $response = $middleware->handle(
            $request,
            fn(Request $req): Response => new Response(200, 'ok'),
            null,
            'users:read'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(0, $parser->parseCalls, 'Stashed claims must be reused, not re-parsed');
    }

    /**
     * Standalone RbacMiddleware (no upstream stash) still parses the token
     * itself — the attribute fallback preserves the pre-WC-159 behavior.
     */
    public function testRbacMiddlewareParsesWhenAttributeAbsent(): void
    {
        TenantContext::setTenantId(1);

        $parser = $this->countingParser();
        $token = $parser->create(['user_id' => 7, 'tenant_id' => 1], 3600, 'access');
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')->with(7, 'users:read', 1)->willReturn(true);

        $middleware = new RbacMiddleware($parser, $roleChecker);
        $response = $middleware->handle(
            $request,
            fn(Request $req): Response => new Response(200, 'ok'),
            null,
            'users:read'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $parser->parseCalls);
    }

    /**
     * TenantContext::resolve() reuses stashed claims without invoking the parser.
     */
    public function testTenantContextResolveReusesStashedClaims(): void
    {
        $parser = $this->countingParser();
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer already.validated.token']);
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, ['user_id' => 7, 'tenant_id' => 5]);

        $tenantId = TenantContext::resolve($request, $parser);

        $this->assertSame(5, $tenantId);
        $this->assertSame(5, TenantContext::getTenantId());
        $this->assertSame(0, $parser->parseCalls, 'Stashed claims must be reused, not re-parsed');
    }

    /**
     * A stashed null (token present but invalid upstream) is authoritative:
     * RbacMiddleware rejects with the same 401 instead of re-parsing.
     */
    public function testRbacMiddlewareRejectsStashedNullClaimsWithout401Drift(): void
    {
        TenantContext::setTenantId(1);

        $parser = $this->countingParser();
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer tampered.token.value']);
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, null);

        $roleChecker = $this->createMock(RoleChecker::class);

        $middleware = new RbacMiddleware($parser, $roleChecker);
        $response = $middleware->handle(
            $request,
            fn(Request $req): Response => new Response(200, 'ok'),
            null,
            'users:read'
        );

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid or expired token', $responseData['error']);
        $this->assertSame(0, $parser->parseCalls, 'A stashed null must not trigger a re-parse');
    }

    /**
     * The PRODUCTION wiring decodes once too: a real HttpKernel with
     * EnforceTenantIsolation registered ahead of the built-in RBAC stage must
     * not fall back to per-middleware parsing.
     */
    public function testKernelPipelineDecodesExactlyOnce(): void
    {
        $parser = $this->countingParser();
        $token = $parser->create(['user_id' => 7, 'tenant_id' => 1], 3600, 'access');
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')->with(7, 'users:read', 1)->willReturn(true);

        $router = new Router();
        $router->register(
            'GET',
            '/api/users',
            fn(Request $req): Response => new Response(200, 'ok'),
            null,
            null,
            'users:read'
        );

        $kernel = new HttpKernel($router, new RbacMiddleware($parser, $roleChecker));
        $kernel->use(new EnforceTenantIsolation($parser));

        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $parser->parseCalls, 'Kernel pipeline must decode the JWT exactly once');
    }

    /**
     * Bearer extraction is uniform across stasher and consumers: a header with
     * extra whitespace ("Bearer  <token>") yields the same token everywhere,
     * including standalone RbacMiddleware use (parse fallback).
     */
    public function testRbacMiddlewareExtractsBearerTokenWithExtraWhitespace(): void
    {
        TenantContext::setTenantId(1);

        $parser = $this->countingParser();
        $token = $parser->create(['user_id' => 7, 'tenant_id' => 1], 3600, 'access');
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer  {$token}"]);

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermission')->with(7, 'users:read', 1)->willReturn(true);

        $middleware = new RbacMiddleware($parser, $roleChecker);
        $response = $middleware->handle(
            $request,
            fn(Request $req): Response => new Response(200, 'ok'),
            null,
            'users:read'
        );

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(1, $parser->parseCalls);
    }

    /**
     * Defense-in-depth: a non-array stashed value (a buggy or hostile writer)
     * must fail CLOSED as an invalid token in both consumers — never bubble a
     * TypeError into a 500.
     */
    public function testNonArrayStashedClaimsFailClosed(): void
    {
        $parser = $this->countingParser();

        // TenantContext::resolve(): garbage stash -> TenantResolutionException.
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer some.token.value']);
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, 'garbage-not-claims');

        try {
            TenantContext::resolve($request, $parser);
            $this->fail('Expected TenantResolutionException for a non-array stash');
        } catch (TenantResolutionException $e) {
            $this->assertSame(0, $parser->parseCalls);
        }

        // RbacMiddleware: garbage stash -> same generic 401 as an invalid token.
        TenantContext::reset();
        TenantContext::setTenantId(1);
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer some.token.value']);
        $request->setAttribute(Request::ATTR_JWT_CLAIMS, 'garbage-not-claims');

        $middleware = new RbacMiddleware($parser, $this->createMock(RoleChecker::class));
        $response = $middleware->handle(
            $request,
            fn(Request $req): Response => new Response(200, 'ok'),
            null,
            'users:read'
        );

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Invalid or expired token', $responseData['error']);
    }

    /**
     * Behavior parity: an invalid/tampered token is still rejected with the
     * same generic 401 by the pipeline, decoding at most once.
     */
    public function testInvalidTokenStillRejectedWith401(): void
    {
        $parser = $this->countingParser();
        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer not.a.realtoken']);

        $middleware = new EnforceTenantIsolation($parser);
        $response = $middleware->handle($request, fn(Request $req): Response => new Response(200, 'ok'));

        $this->assertSame(401, $response->getStatusCode());
        $responseData = json_decode($response->getBody(), true);
        $this->assertSame('Authentication required', $responseData['error']);
        $this->assertSame(1, $parser->parseCalls, 'Invalid token must be decoded (and rejected) exactly once');
    }
}
