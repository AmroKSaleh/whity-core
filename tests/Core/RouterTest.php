<?php

namespace Tests\Core;

use PHPUnit\Framework\TestCase;
use Whity\Core\Router;
use Whity\Core\Request;

/**
 * Tests for Router class
 */
class RouterTest extends TestCase
{
    private Router $router;

    protected function setUp(): void
    {
        $this->router = new Router('');
    }

    /**
     * Test matching a simple path without parameters
     */
    public function testMatchesSimplePath(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/users', $handler);

        $request = new Request('GET', '/users');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertSame($handler, $match['handler']);
        $this->assertEmpty($match['params']);
        $this->assertNull($match['requiredRole']);
    }

    /**
     * Test that different HTTP methods do not match
     */
    public function testDoesNotMatchDifferentMethod(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/users', $handler);

        $request = new Request('POST', '/users');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    /**
     * Test matching a path with parameters
     */
    public function testMatchesPathWithParam(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/users/{id}', $handler);

        $request = new Request('GET', '/users/42');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertSame($handler, $match['handler']);
        $this->assertSame(['id' => '42'], $match['params']);
        $this->assertNull($match['requiredRole']);
    }

    /**
     * Test matching multiple parameters in a path
     */
    public function testMatchesPathWithMultipleParams(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/users/{userId}/posts/{postId}', $handler);

        $request = new Request('GET', '/users/42/posts/100');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertSame(['userId' => '42', 'postId' => '100'], $match['params']);
    }

    /**
     * Test that similar paths do not match incorrectly
     */
    public function testDoesNotMatchSimilarPath(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/users', $handler);

        $request = new Request('GET', '/users/');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    /**
     * Test registering route with required role
     */
    public function testRegisterRouteWithRequiredRole(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('POST', '/admin/users', $handler, 'admin');

        $request = new Request('POST', '/admin/users');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertSame('admin', $match['requiredRole']);
    }

    /**
     * Test that routes default to no required permission
     */
    public function testRouteDefaultsToNoRequiredPermission(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/api/users', $handler);

        $match = $this->router->match(new Request('GET', '/api/users'));

        $this->assertNotNull($match);
        $this->assertNull($match['requiredPermission']);
    }

    /**
     * Test registering a route with a required permission (resource:action)
     */
    public function testRegisterRouteWithRequiredPermission(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/api/users', $handler, null, null, 'users:read');

        $match = $this->router->match(new Request('GET', '/api/users'));

        $this->assertNotNull($match);
        $this->assertSame('users:read', $match['requiredPermission']);
        $this->assertNull($match['requiredRole']);
    }

    /**
     * Test that role and permission metadata coexist on a route
     */
    public function testRouteCarriesBothRoleAndPermission(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('POST', '/api/users', $handler, 'admin', null, 'users:write');

        $match = $this->router->match(new Request('POST', '/api/users'));

        $this->assertNotNull($match);
        $this->assertSame('admin', $match['requiredRole']);
        $this->assertSame('users:write', $match['requiredPermission']);
    }

    /**
     * Test middleware addition
     */
    public function testAddMiddleware(): void
    {
        $middleware1 = static fn() => null;
        $middleware2 = static fn() => null;

        $this->router->addMiddleware($middleware1);
        $this->router->addMiddleware($middleware2);

        $middlewares = $this->router->getMiddleware();
        $this->assertCount(2, $middlewares);
        $this->assertSame($middleware1, $middlewares[0]);
        $this->assertSame($middleware2, $middlewares[1]);
    }

    /**
     * Test case-insensitive HTTP method matching
     */
    public function testCaseInsensitiveMethodMatching(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('get', '/users', $handler);

        $request = new Request('GET', '/users');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
    }

    /**
     * Test that path parameters don't match forward slashes
     */
    public function testParameterDoesNotMatchSlash(): void
    {
        $handler = static fn() => 'response';
        $this->router->register('GET', '/users/{id}', $handler);

        $request = new Request('GET', '/users/42/extra');
        $match = $this->router->match($request);

        $this->assertNull($match);
    }

    /**
     * Test matching multiple routes and returning the correct one
     */
    public function testMatchesCorrectRouteFromMultiple(): void
    {
        $handler1 = static fn() => 'handler1';
        $handler2 = static fn() => 'handler2';

        $this->router->register('GET', '/users', $handler1);
        $this->router->register('GET', '/posts', $handler2);

        $request = new Request('GET', '/posts');
        $match = $this->router->match($request);

        $this->assertNotNull($match);
        $this->assertSame($handler2, $match['handler']);
    }

    /**
     * Test that routes can be removed by their plugin namespace prefix
     */
    public function testUnregisterByNamespace(): void
    {
        $coreHandler = static fn() => 'core';
        $pluginHandler = static fn() => 'plugin';

        $this->router->register('GET', '/api/core', $coreHandler);
        $this->router->register('GET', '/api/plugin', $pluginHandler, null, 'MyPlugin');

        // Both routes match initially
        $this->assertNotNull($this->router->match(new Request('GET', '/api/core')));
        $this->assertNotNull($this->router->match(new Request('GET', '/api/plugin')));

        // Removing the plugin namespace drops only its route
        $removed = $this->router->unregisterByNamespace('MyPlugin');
        $this->assertSame(1, $removed);

        $this->assertNotNull($this->router->match(new Request('GET', '/api/core')));
        $this->assertNull($this->router->match(new Request('GET', '/api/plugin')));
    }

    /**
     * Test that unregistering an unknown namespace removes nothing
     */
    public function testUnregisterByUnknownNamespaceRemovesNothing(): void
    {
        $this->router->register('GET', '/api/core', static fn() => 'core');

        $removed = $this->router->unregisterByNamespace('DoesNotExist');
        $this->assertSame(0, $removed);
        $this->assertNotNull($this->router->match(new Request('GET', '/api/core')));
    }

    /**
     * WC-160: a {param:regex} constraint restricts what the segment matches.
     */
    public function testMatchesPathParamWithRegexConstraint(): void
    {
        $this->router->register('GET', '/api/users/{id:\d+}', static fn() => 'response');

        $match = $this->router->match(new Request('GET', '/api/users/123'));

        $this->assertNotNull($match);
        $this->assertSame('123', $match['params']['id']);
    }

    /**
     * WC-160: a segment violating the {id:\d+} constraint does NOT match.
     */
    public function testRejectsPathParamViolatingRegexConstraint(): void
    {
        $this->router->register('GET', '/api/users/{id:\d+}', static fn() => 'response');

        $this->assertNull($this->router->match(new Request('GET', '/api/users/abc')));
        $this->assertNull($this->router->match(new Request('GET', '/api/users/12abc')));
    }

    /**
     * WC-160: unconstrained {param} placeholders keep their permissive
     * single-segment behavior alongside constrained ones.
     */
    public function testMixedConstrainedAndUnconstrainedParams(): void
    {
        $this->router->register('GET', '/api/tenants/{tenant:\d+}/users/{name}', static fn() => 'r');

        $match = $this->router->match(new Request('GET', '/api/tenants/7/users/jane'));

        $this->assertNotNull($match);
        $this->assertSame('7', $match['params']['tenant']);
        $this->assertSame('jane', $match['params']['name']);

        $this->assertNull($this->router->match(new Request('GET', '/api/tenants/acme/users/jane')));
    }

    /**
     * WC-160: allowedMethods() reports which methods are registered for a path
     * so the kernel can answer 405 (with Allow) instead of 404.
     */
    public function testAllowedMethodsForKnownPath(): void
    {
        $this->router->register('GET', '/api/users', static fn() => 'list');
        $this->router->register('POST', '/api/users', static fn() => 'create');
        $this->router->register('DELETE', '/api/users/{id:\d+}', static fn() => 'delete');

        $this->assertSame(['GET', 'POST'], $this->router->allowedMethods('/api/users'));
        $this->assertSame(['DELETE'], $this->router->allowedMethods('/api/users/42'));
    }

    /**
     * WC-160: an unknown path has no allowed methods (kernel keeps 404).
     */
    public function testAllowedMethodsForUnknownPathIsEmpty(): void
    {
        $this->router->register('GET', '/api/users', static fn() => 'list');

        $this->assertSame([], $this->router->allowedMethods('/api/nothing'));
        // A constraint-violating path is "unknown", not method-mismatched.
        $this->router->register('DELETE', '/api/users/{id:\d+}', static fn() => 'delete');
        $this->assertSame([], $this->router->allowedMethods('/api/users/abc'));
    }

    /**
     * WC-160: constraints containing characters that would corrupt the compiled
     * pattern (parentheses, '#' — the pattern delimiter) are rejected loudly at
     * registration time instead of producing per-request preg warnings.
     */
    public function testRegisterRejectsUnsupportedConstraintCharacters(): void
    {
        foreach (['/api/x/{id:(\d+)}', '/api/x/{tag:[a-z#]+}'] as $path) {
            try {
                $this->router->register('GET', $path, static fn() => 'r');
                $this->fail("Registering {$path} should throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('constraint', $e->getMessage());
            }
        }
    }

    /**
     * WC-569: a constraint may itself contain a `{n}` / `{n,m}` quantifier —
     * e.g. exactly 10 hex characters — and it must actually be enforced, not
     * silently fall through to a literal-text (never-matching) requirement.
     */
    public function testMatchesPathParamWithBraceQuantifierConstraint(): void
    {
        $this->router->register('GET', '/api/documents/{code:[a-f0-9]{10}}', static fn() => 'r');

        $match = $this->router->match(new Request('GET', '/api/documents/03d01101ef'));
        $this->assertNotNull($match);
        $this->assertSame('03d01101ef', $match['params']['code']);

        // Too short / too long / non-hex must NOT match.
        $this->assertNull($this->router->match(new Request('GET', '/api/documents/03d0110')));
        $this->assertNull($this->router->match(new Request('GET', '/api/documents/03d01101ef00')));
        $this->assertNull($this->router->match(new Request('GET', '/api/documents/zzzzzzzzzz')));
    }

    /**
     * WC-569: a `{n,m}` range quantifier inside a constraint works the same way.
     */
    public function testMatchesPathParamWithRangeQuantifierConstraint(): void
    {
        $this->router->register('GET', '/api/x/{code:\d{2,4}}', static fn() => 'r');

        $this->assertNotNull($this->router->match(new Request('GET', '/api/x/12')));
        $this->assertNotNull($this->router->match(new Request('GET', '/api/x/1234')));
        $this->assertNull($this->router->match(new Request('GET', '/api/x/1')));
        $this->assertNull($this->router->match(new Request('GET', '/api/x/12345')));
    }

    /**
     * WC-569: a constraint with brace usage that ISN'T a simple {n}/{n,m}
     * quantifier (so the placeholder can't be fully parsed) must fail loudly
     * at registration — never silently become an unmatchable literal route.
     */
    public function testRegisterRejectsUnparsableBraceUsageInConstraint(): void
    {
        foreach (['/api/x/{id:[0-9]{}', '/api/x/{id:[0-9]{abc}}'] as $path) {
            try {
                $this->router->register('GET', $path, static fn() => 'r');
                $this->fail("Registering {$path} should throw");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString("'{'", $e->getMessage());
            }
        }
    }

    // ===== WC-206: URL-prefix versioning =====

    /**
     * WC-206: register() prepends the version prefix into the path so callers
     * write '/api/users' and the router stores '/api/v1/users'.
     */
    public function testVersionPrefixIsAppliedToRegisteredPath(): void
    {
        $router = new Router('/v1');
        $handler = static fn() => 'ok';
        $router->register('GET', '/api/users', $handler);

        // Must match at the prefixed path …
        $this->assertNotNull($router->match(new Request('GET', '/api/v1/users')));
        // … and NOT at the bare path.
        $this->assertNull($router->match(new Request('GET', '/api/users')));
    }

    /**
     * WC-206: registerUnversioned() stores the path exactly as given, skipping
     * the version prefix — used for /api/health, /api/version, etc.
     */
    public function testRegisterUnversionedStoresExactPath(): void
    {
        $router = new Router('/v1');
        $handler = static fn() => 'probe';
        $router->registerUnversioned('GET', '/api/health', $handler);

        $this->assertNotNull($router->match(new Request('GET', '/api/health')));
        $this->assertNull($router->match(new Request('GET', '/api/v1/health')));
    }

    /**
     * WC-206: with an empty version prefix register() behaves as before — no
     * prefix injection — so existing test suites can use new Router('') safely.
     */
    public function testEmptyVersionPrefixDisablesInjection(): void
    {
        $router = new Router('');
        $handler = static fn() => 'ok';
        $router->register('GET', '/api/users', $handler);

        $this->assertNotNull($router->match(new Request('GET', '/api/users')));
        $this->assertNull($router->match(new Request('GET', '/api/v1/users')));
    }

    /**
     * WC-206: getVersionPrefix() returns the string that was supplied at
     * construction — useful for generating the /api/version payload.
     */
    public function testGetVersionPrefixReturnsConfiguredValue(): void
    {
        $this->assertSame('', $this->router->getVersionPrefix());
        $this->assertSame('/v1', (new Router('/v1'))->getVersionPrefix());
    }

    /**
     * WC-206: first-registration-wins still applies after prefixing — re-
     * registering the same bare path on the same versioned router is refused.
     */
    public function testVersionedDuplicateRegistrationReturnsFalse(): void
    {
        $router = new Router('/v1');
        $first  = static fn() => 'first';
        $second = static fn() => 'second';

        $this->assertTrue($router->register('GET', '/api/users', $first));
        $this->assertFalse($router->register('GET', '/api/users', $second));

        // The first handler is the one that matches.
        $match = $router->match(new Request('GET', '/api/v1/users'));
        $this->assertNotNull($match);
        $this->assertSame($first, $match['handler']);
    }

    /**
     * WC-206: unversioned and versioned routes can coexist under the same base
     * path (e.g. /api/health is unversioned; /api/v1/health could be versioned)
     * without colliding.
     */
    public function testUnversionedAndVersionedCoexist(): void
    {
        $router      = new Router('/v1');
        $unversioned = static fn() => 'probe';
        $versioned   = static fn() => 'v1';

        $router->registerUnversioned('GET', '/api/health', $unversioned);
        // A separate versioned route at the same base path is fine.
        $router->register('GET', '/api/health', $versioned);

        $matchUnversioned = $router->match(new Request('GET', '/api/health'));
        $matchVersioned   = $router->match(new Request('GET', '/api/v1/health'));

        $this->assertNotNull($matchUnversioned);
        $this->assertSame($unversioned, $matchUnversioned['handler']);

        $this->assertNotNull($matchVersioned);
        $this->assertSame($versioned, $matchVersioned['handler']);
    }
}
