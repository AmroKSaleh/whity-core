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
        $this->router = new Router();
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
}
