<?php

declare(strict_types=1);

namespace Tests\Api;

use PHPUnit\Framework\TestCase;
use Whity\Api\PermittedActionsApiHandler;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\RbacMiddleware;

/**
 * #868: POST /api/v1/me/permitted-actions — the server half of the `inbox`
 * block type.
 *
 * The identity under test is the one the block's whole justification rests on:
 * `allowed: true` implies {@see RbacMiddleware} would admit that exact request.
 * {@see self::testAgreesWithRbacMiddlewareOnEveryCombination()} asserts it
 * directly by driving BOTH the handler and the real middleware with the SAME
 * mocked {@see RoleChecker} over the SAME router, rather than restating the
 * handler's logic in the test — a test that agrees with the implementation by
 * construction would pass through exactly the drift it exists to catch.
 */
final class PermittedActionsApiHandlerTest extends TestCase
{
    private const TENANT = 1;
    private const PROFILE = 42;

    protected function setUp(): void
    {
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
    }

    // ==================== the parity identity ====================

    /**
     * For every (route, held-permission) combination, the handler's `allowed`
     * must equal "RbacMiddleware admits" — never true when the middleware
     * refuses (that ships a button that 403s) and never false when it admits
     * (that hides work the user can do).
     */
    public function testAgreesWithRbacMiddlewareOnEveryCombination(): void
    {
        $cases = [
            // [path, requiredRole, requiredPermission, heldRoles, heldPermissions]
            ['/api/v1/tasks/1/approve', null, 'tasks:approve', [], ['tasks:approve']],
            ['/api/v1/tasks/1/approve', null, 'tasks:approve', [], []],
            ['/api/v1/tasks/1/approve', null, 'tasks:approve', [], ['tasks:read']],
            ['/api/v1/tasks/1/reject', 'manager', null, ['manager'], []],
            ['/api/v1/tasks/1/reject', 'manager', null, [], []],
            ['/api/v1/tasks/1/reject', 'manager', 'tasks:reject', ['manager'], ['tasks:reject']],
            ['/api/v1/tasks/1/reject', 'manager', 'tasks:reject', ['manager'], []],
            ['/api/v1/tasks/1/reject', 'manager', 'tasks:reject', [], ['tasks:reject']],
            // An unprotected route: the middleware is fail-OPEN, and so is the resolver.
            ['/api/v1/tasks/1/note', null, null, [], []],
        ];

        foreach ($cases as [$path, $role, $permission, $heldRoles, $heldPermissions]) {
            $router = new Router('');
            $router->register('POST', $path, static fn (): Response => Response::json([]), $role, null, $permission);
            $roleChecker = $this->roleChecker($heldRoles, $heldPermissions);

            $resolverSaidAllowed = $this->firstResult(
                (new PermittedActionsApiHandler($roleChecker, $router))
                    ->resolve($this->batch([['ref' => 'a', 'method' => 'POST', 'path' => $path]]))
            )['allowed'];

            $middlewareAdmitted = $this->middlewareAdmits($roleChecker, $role, $permission);

            $label = sprintf(
                '%s (role=%s, permission=%s, held=%s/%s)',
                $path,
                $role ?? 'none',
                $permission ?? 'none',
                implode(',', $heldRoles) ?: 'none',
                implode(',', $heldPermissions) ?: 'none'
            );
            $this->assertSame(
                $middlewareAdmitted,
                $resolverSaidAllowed,
                "The resolver and RbacMiddleware must agree for {$label}"
            );
        }
    }

    /**
     * The resource arguments are deliberately NOT passed to the ROUTE gate.
     *
     * Resource-scoped grants are additive (SDK 1.17/1.22: the scoped answer is a
     * superset of the unscoped one) while the middleware asks the tenant-wide
     * question — so scoping the route gate would answer "allowed" for a request
     * the middleware refuses. This pins the call shape, because the bug it
     * prevents is invisible in any test whose caller happens to hold the
     * permission tenant-wide anyway.
     */
    public function testTheRouteGateIsResolvedUnscoped(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/tasks/1/approve', static fn (): Response => Response::json([]), null, null, 'tasks:approve');

        $calls = [];
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(function (
                int $profileId,
                string $permission,
                int $tenantId,
                ?string $resourceType = null,
                ?int $resourceId = null
            ) use (&$calls): bool {
                $calls[] = [$permission, $resourceType, $resourceId];
                return true;
            });

        (new PermittedActionsApiHandler($roleChecker, $router))->resolve($this->batch([[
            'ref' => 'a',
            'method' => 'POST',
            'path' => '/api/v1/tasks/1/approve',
            'resourceType' => 'task',
            'resourceId' => 1,
        ]]));

        $this->assertSame(
            [['tasks:approve', null, null]],
            $calls,
            "The route's own permission must be resolved tenant-wide, exactly as RbacMiddleware resolves it"
        );
    }

    // ==================== the per-record narrowing ====================

    public function testScopedPermissionCanNarrowAnOtherwisePermittedAction(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/tasks/1/approve', static fn (): Response => Response::json([]), null, null, 'tasks:read');

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static function (
                int $profileId,
                string $permission,
                int $tenantId,
                ?string $resourceType = null,
                ?int $resourceId = null
            ): bool {
                // Everyone reads; nobody approves THIS record.
                if ($permission === 'tasks:read') {
                    return true;
                }
                return false;
            });

        $result = $this->firstResult(
            (new PermittedActionsApiHandler($roleChecker, $router))->resolve($this->batch([[
                'ref' => 'a',
                'method' => 'POST',
                'path' => '/api/v1/tasks/1/approve',
                'resourceType' => 'task',
                'resourceId' => 1,
                'scopedPermission' => 'tasks:approve',
            ]]))
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('tasks:approve', $result['required']);
    }

    public function testScopedPermissionIsResolvedAtTheItemsResource(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/tasks/7/approve', static fn (): Response => Response::json([]), null, null, 'tasks:read');

        $scopedCall = null;
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(function (
                int $profileId,
                string $permission,
                int $tenantId,
                ?string $resourceType = null,
                ?int $resourceId = null
            ) use (&$scopedCall): bool {
                if ($permission === 'tasks:approve') {
                    $scopedCall = [$profileId, $tenantId, $resourceType, $resourceId];
                }
                return true;
            });

        (new PermittedActionsApiHandler($roleChecker, $router))->resolve($this->batch([[
            'ref' => 'a',
            'method' => 'POST',
            'path' => '/api/v1/tasks/7/approve',
            'resourceType' => 'task',
            // The renderer sends the item id as its display string; both forms resolve.
            'resourceId' => '7',
            'scopedPermission' => 'tasks:approve',
        ]]));

        $this->assertSame([self::PROFILE, self::TENANT, 'task', 7], $scopedCall);
    }

    /**
     * A `scopedPermission` can NEVER admit a request the route gate refuses —
     * it is an additional conjunct, evaluated only after the gate passed.
     */
    public function testScopedPermissionCannotWidenPastTheRouteGate(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/tasks/1/approve', static fn (): Response => Response::json([]), null, null, 'tasks:approve');

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(static fn (
                int $profileId,
                string $permission,
                int $tenantId,
                ?string $resourceType = null,
                ?int $resourceId = null
            ): bool =>
                // The route's own gate refuses tenant-wide; a resource grant
                // would say yes. The answer must still be no.
                $resourceType !== null);

        $result = $this->firstResult(
            (new PermittedActionsApiHandler($roleChecker, $router))->resolve($this->batch([[
                'ref' => 'a',
                'method' => 'POST',
                'path' => '/api/v1/tasks/1/approve',
                'resourceType' => 'task',
                'resourceId' => 1,
                'scopedPermission' => 'tasks:approve',
            ]]))
        );

        $this->assertFalse($result['allowed']);
        $this->assertSame('tasks:approve', $result['required']);
    }

    public function testAHalfSpecifiedResourceLeavesTheAnswerUnnarrowed(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/tasks/1/approve', static fn (): Response => Response::json([]), null, null, 'tasks:read');

        $roleChecker = $this->roleChecker([], ['tasks:read']);

        // scopedPermission with no resourceType/resourceId: the route gate's
        // answer stands rather than degrading into a tenant-wide check that
        // reads as per-record.
        $result = $this->firstResult(
            (new PermittedActionsApiHandler($roleChecker, $router))->resolve($this->batch([[
                'ref' => 'a',
                'method' => 'POST',
                'path' => '/api/v1/tasks/1/approve',
                'scopedPermission' => 'tasks:approve',
            ]]))
        );

        $this->assertTrue($result['allowed']);
    }

    // ==================== route matching ====================

    public function testAPathMatchingNoRouteIsDenied(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/tasks/{id}/approve', static fn (): Response => Response::json([]), null, null, null);

        $result = $this->firstResult(
            (new PermittedActionsApiHandler($this->roleChecker([], []), $router))->resolve($this->batch([
                ['ref' => 'typo', 'method' => 'POST', 'path' => '/api/v1/tasks/1/aprove'],
            ]))
        );

        $this->assertFalse($result['allowed']);
        $this->assertNull($result['required']);
    }

    /**
     * A path on the pre-auth public list never has a tenant resolved for it, so
     * RbacMiddleware refuses a gated route on one with `401 Unresolved tenant
     * context` BEFORE consulting RBAC. Answering the narrower RBAC question
     * would be right about the wrong layer — the request still fails, and the
     * block would render a button that 401s.
     *
     * Found on a booted stack, not in a unit test: `POST /api/v1/languages`
     * shares its path with a public GET, resolved as allowed, and 401'd for real.
     */
    public function testAPathThatBypassesTenantResolutionIsDenied(): void
    {
        $router = new Router('');
        // Registered, gated, and satisfied — the ONLY thing denying it is that
        // the path skips tenant resolution.
        $router->register('POST', '/api/v1/languages', static fn (): Response => Response::json([]), null, null, 'languages:manage');

        $result = $this->firstResult(
            (new PermittedActionsApiHandler($this->roleChecker([], ['languages:manage']), $router))
                ->resolve($this->batch([
                    ['ref' => 'a', 'method' => 'POST', 'path' => '/api/v1/languages'],
                ]))
        );

        $this->assertTrue(
            EnforceTenantIsolation::pathBypassesTenantResolution('/api/v1/languages'),
            'Precondition: the path under test must actually be one that bypasses tenant resolution'
        );
        $this->assertFalse($result['allowed']);
    }

    public function testTheMethodIsPartOfTheMatch(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/tasks/{id}', static fn (): Response => Response::json([]), null, null, null);

        // DELETE on the same path is a different (unregistered) route.
        $result = $this->firstResult(
            (new PermittedActionsApiHandler($this->roleChecker([], []), $router))->resolve($this->batch([
                ['ref' => 'a', 'method' => 'DELETE', 'path' => '/api/v1/tasks/1'],
            ]))
        );

        $this->assertFalse($result['allowed']);
    }

    /**
     * GET is answered like any other verb (#909).
     *
     * It used to be refused outright, because the only caller was `inbox` and an
     * inbox action carries a write verb. #909's `accessGate` asks a READ
     * question — "may I see this region at all?" — to decide whether a region
     * exists, and without an answer to that the hidden state has no authority to
     * consult and an author has to fake it by gating a read-only panel on a
     * write request, which answers a different question and answers it wrong for
     * every caller who may look but not touch.
     *
     * The endpoint promises no less than before: its identity is "allowed
     * implies the middleware would admit exactly this request", and that is the
     * same route lookup, the same tenant guard and the same two RoleChecker
     * calls whatever the verb.
     */
    public function testAReadVerbIsAnsweredLikeAnyOther(): void
    {
        $router = new Router('');
        $router->register('GET', '/api/v1/tasks', static fn (): Response => Response::json([]), null, null, 'tasks:read');

        $refused = $this->firstResult(
            (new PermittedActionsApiHandler($this->roleChecker([], []), $router))->resolve($this->batch([
                ['ref' => 'a', 'method' => 'GET', 'path' => '/api/v1/tasks'],
            ]))
        );

        $this->assertFalse($refused['allowed'], 'a caller without tasks:read may not read');
        $this->assertSame('tasks:read', $refused['required']);

        $allowed = $this->firstResult(
            (new PermittedActionsApiHandler($this->roleChecker([], ['tasks:read']), $router))->resolve($this->batch([
                ['ref' => 'a', 'method' => 'GET', 'path' => '/api/v1/tasks'],
            ]))
        );

        $this->assertTrue($allowed['allowed']);
        $this->assertNull($allowed['required']);
    }

    /**
     * A verb outside the accepted set is still refused. The set widened by
     * exactly one entry; it did not become "anything the caller types".
     */
    public function testAnUnknownVerbIsStillRefusedOutright(): void
    {
        $router = new Router('');
        $router->register('GET', '/api/v1/tasks', static fn (): Response => Response::json([]), null, null, null);

        foreach (['HEAD', 'OPTIONS', 'TRACE', 'CONNECT'] as $method) {
            $result = $this->firstResult(
                (new PermittedActionsApiHandler($this->roleChecker([], []), $router))->resolve($this->batch([
                    ['ref' => 'a', 'method' => $method, 'path' => '/api/v1/tasks'],
                ]))
            );

            $this->assertFalse($result['allowed'], "{$method} must not be resolvable");
        }
    }

    // ==================== batch shape ====================

    public function testAnswersEveryCheckInRequestOrder(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/a', static fn (): Response => Response::json([]), null, null, 'x:write');
        $router->register('POST', '/api/v1/b', static fn (): Response => Response::json([]), null, null, 'y:write');

        $response = (new PermittedActionsApiHandler($this->roleChecker([], ['y:write']), $router))
            ->resolve($this->batch([
                ['ref' => 'first', 'method' => 'POST', 'path' => '/api/v1/a'],
                ['ref' => 'second', 'method' => 'POST', 'path' => '/api/v1/b'],
            ]));

        $this->assertSame(200, $response->getStatusCode());
        $data = $this->data($response);
        $this->assertSame(['first', 'second'], array_column($data, 'ref'));
        $this->assertSame([false, true], array_column($data, 'allowed'));
        $this->assertSame(['x:write', null], array_column($data, 'required'));
    }

    public function testAMalformedCheckIsDeniedRatherThanSkipped(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/a', static fn (): Response => Response::json([]), null, null, null);

        $response = (new PermittedActionsApiHandler($this->roleChecker([], []), $router))
            ->resolve($this->batch([
                ['ref' => 'ok', 'method' => 'POST', 'path' => '/api/v1/a'],
                ['ref' => 'no-path', 'method' => 'POST'],
                'not-an-object',
            ]));

        $data = $this->data($response);
        $this->assertCount(3, $data, 'Every check must get an answer, so the caller can align them by position');
        $this->assertSame([true, false, false], array_column($data, 'allowed'));
    }

    public function testAMissingChecksListIs422(): void
    {
        $router = new Router('');
        $response = (new PermittedActionsApiHandler($this->roleChecker([], []), $router))
            ->resolve($this->request(['nope' => []]));

        $this->assertSame(422, $response->getStatusCode());
    }

    public function testAnOversizedBatchIsRefused(): void
    {
        $router = new Router('');
        $checks = array_fill(
            0,
            PermittedActionsApiHandler::MAX_CHECKS + 1,
            ['ref' => 'a', 'method' => 'POST', 'path' => '/api/v1/a']
        );

        $response = (new PermittedActionsApiHandler($this->roleChecker([], []), $router))->resolve($this->batch($checks));

        $this->assertSame(422, $response->getStatusCode());
    }

    // ==================== fail-closed ====================

    public function testUnresolvedTenantContextFailsClosed(): void
    {
        TenantContext::reset();

        $response = (new PermittedActionsApiHandler($this->roleChecker([], []), new Router('')))
            ->resolve($this->batch([['ref' => 'a', 'method' => 'POST', 'path' => '/api/v1/a']]));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testMissingAuthenticatedUserFailsClosed(): void
    {
        $request = new Request('POST', '/api/v1/me/permitted-actions', [], json_encode(['checks' => []]) ?: '');

        $response = (new PermittedActionsApiHandler($this->roleChecker([], []), new Router('')))->resolve($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testInternalFailureReturnsAGeneric500WithoutLeakingDetails(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/a', static fn (): Response => Response::json([]), null, null, 'x:write');

        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willThrowException(new \RuntimeException('secret internal detail'));

        $response = (new PermittedActionsApiHandler($roleChecker, $router))
            ->resolve($this->batch([['ref' => 'a', 'method' => 'POST', 'path' => '/api/v1/a']]));

        $this->assertSame(500, $response->getStatusCode());
        $this->assertStringNotContainsString('secret internal detail', $response->getBody());
    }

    /**
     * The subject of every check is the CALLER, never the body: a client cannot
     * ask "what may someone else do?".
     */
    public function testTheSubjectIsAlwaysTheAuthenticatedCallerAndResolvedTenant(): void
    {
        $router = new Router('');
        $router->register('POST', '/api/v1/a', static fn (): Response => Response::json([]), null, null, 'x:write');

        $seen = null;
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(function (int $profileId, string $permission, int $tenantId) use (&$seen): bool {
                $seen = [$profileId, $tenantId];
                return true;
            });

        $request = $this->request([
            'checks' => [['ref' => 'a', 'method' => 'POST', 'path' => '/api/v1/a']],
            // Ignored: neither is a value this endpoint reads.
            'profile_id' => 999,
            'tenant_id' => 999,
        ]);

        (new PermittedActionsApiHandler($roleChecker, $router))->resolve($request);

        $this->assertSame([self::PROFILE, self::TENANT], $seen);
    }

    // ==================== helpers ====================

    /**
     * Whether the real RbacMiddleware admits a request to a route with the given
     * requirements, for the same caller and RoleChecker.
     */
    private function middlewareAdmits(RoleChecker $roleChecker, ?string $role, ?string $permission): bool
    {
        $jwtParser = $this->createMock(JwtParser::class);
        $jwtParser->method('parse')->willReturn(['profile_id' => self::PROFILE]);

        $request = new Request('POST', '/api/v1/tasks/1/approve', ['Authorization' => 'Bearer token']);
        $response = (new RbacMiddleware($jwtParser, $roleChecker))->handle(
            $request,
            static fn (Request $r): Response => new Response(200, 'ok'),
            $role,
            $permission
        );

        return $response->getStatusCode() === 200;
    }

    /**
     * A RoleChecker stub holding exactly the given roles and permissions,
     * tenant-wide and at every resource.
     *
     * @param list<string> $roles
     * @param list<string> $permissions
     */
    private function roleChecker(array $roles, array $permissions): RoleChecker
    {
        $roleChecker = $this->createMock(RoleChecker::class);
        $roleChecker->method('hasRoleForProfile')
            ->willReturnCallback(static fn (int $p, string $role, int $t): bool => in_array($role, $roles, true));
        $roleChecker->method('hasPermissionForProfile')
            ->willReturnCallback(
                static fn (int $p, string $permission, int $t): bool => in_array($permission, $permissions, true)
            );

        return $roleChecker;
    }

    /**
     * @param list<mixed> $checks
     */
    private function batch(array $checks): Request
    {
        return $this->request(['checks' => $checks]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function request(array $body): Request
    {
        $request = new Request(
            'POST',
            '/api/v1/me/permitted-actions',
            ['Content-Type' => 'application/json'],
            json_encode($body) ?: ''
        );
        $request->user = (object) ['profile_id' => self::PROFILE];

        return $request;
    }

    /**
     * @return list<array{ref: string|null, allowed: bool, required: string|null}>
     */
    private function data(Response $response): array
    {
        /** @var array{data: list<array{ref: string|null, allowed: bool, required: string|null}>} $decoded */
        $decoded = json_decode($response->getBody(), true);

        return $decoded['data'];
    }

    /**
     * @return array{ref: string|null, allowed: bool, required: string|null}
     */
    private function firstResult(Response $response): array
    {
        $this->assertSame(200, $response->getStatusCode(), $response->getBody());

        return $this->data($response)[0];
    }
}
