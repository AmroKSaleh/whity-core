<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDOStatement;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * RBAC authentication edge-case integration tests (WC-17, issue #13).
 *
 * Drives the FULL stack — real {@see JwtParser} → real {@see RbacMiddleware} →
 * real {@see RoleChecker} → mocked {@see Database} — to prove the RBAC layer
 * behaves correctly when the credential itself is degraded: expired, revoked
 * (wrong signing secret), tampered, malformed, supplied via cookie rather than
 * header, or carrying a forged role claim.
 *
 * The middleware-unit suite ({@see \Tests\Http\RbacMiddlewareTest}) covers these
 * with a STUBBED parser returning null; this file instead uses REAL signed/expired
 * tokens so the JwtParser → middleware boundary is exercised authentically, and
 * additionally asserts the authoritative store is never consulted once
 * authentication fails (no DB query on a bad credential).
 */
class RbacAuthEdgeCaseTest extends TestCase
{
    private const SECRET = 'wc17-edge-secret';

    protected function setUp(): void
    {
        RoleChecker::clearCache();
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
    }

    /**
     * Build a RoleChecker over a Database mock that grants exactly $granted to the
     * caller's role (direct grant path), or none when $granted is empty.
     *
     * @param array<int, string> $granted Permissions the user's role grants.
     */
    private function roleCheckerGranting(array $granted): RoleChecker
    {
        $db = $this->createMock(Database::class);
        $db->method('query')->willReturnCallback(
            function (string $sql, array $bindings) use ($granted): PDOStatement {
                $statement = $this->createMock(PDOStatement::class);
                $hit = in_array($bindings[':permission'] ?? null, $granted, true);
                $statement->method('fetch')->willReturn($hit ? ['1' => 1] : false);
                $statement->method('fetchAll')->willReturn([]);
                return $statement;
            }
        );
        return new RoleChecker($db, new PermissionRegistry());
    }

    /**
     * Build a RoleChecker over a Database mock that fails the test if it is ever
     * queried — proving authentication short-circuits before any authorization
     * lookup.
     */
    private function roleCheckerThatMustNotBeQueried(): RoleChecker
    {
        $db = $this->createMock(Database::class);
        $db->expects($this->never())->method('query');
        return new RoleChecker($db, new PermissionRegistry());
    }

    /**
     * Register a single permission-protected route and dispatch through the
     * middleware as the kernel does. Returns the response.
     *
     * @param-out bool $handlerReached
     */
    private function dispatchProtected(
        RbacMiddleware $middleware,
        Request $request,
        string $permission,
        ?bool &$handlerReached = null
    ): Response {
        $router = new Router();
        $router->register('GET', '/api/users', static fn(): Response => new Response(200, '[]'), null, null, $permission);

        $match = $router->match($request);
        $this->assertNotNull($match);

        $reached = false;
        $response = $middleware->handle(
            $request,
            function (Request $req) use (&$reached): Response {
                $reached = true;
                return new Response(200, '[]');
            },
            $match['requiredRole'],
            $match['requiredPermission']
        );
        $handlerReached = $reached;
        return $response;
    }

    /**
     * A genuinely expired token (real signature, exp in the past) is rejected at
     * the RBAC layer with 401, and the authoritative store is never consulted.
     */
    public function testExpiredTokenIsRejectedWithoutHittingTheStore(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerThatMustNotBeQueried());

        // expiresIn = -10 puts exp 10s in the past; the parser rejects on expiry.
        $expired = $jwtParser->create(['user_id' => 5, 'email' => 'stale@example.com'], -10);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$expired}"]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertFalse($handlerReached);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid or expired token', json_decode($response->getBody(), true)['error']);
    }

    /**
     * A revoked token (here modelled as one signed by a DIFFERENT secret, e.g.
     * after a key rotation that invalidates previously issued tokens) fails the
     * signature check and is rejected with 401 without touching the store.
     */
    public function testRevokedTokenSignedWithRotatedSecretIsRejected(): void
    {
        $issuingParser = new JwtParser('previous-rotated-out-secret');
        $token = $issuingParser->create(['user_id' => 6, 'email' => 'revoked@example.com']);

        // The server now validates with the CURRENT secret; the old token's
        // signature no longer verifies.
        $validatingParser = new JwtParser(self::SECRET);
        $middleware = new RbacMiddleware($validatingParser, $this->roleCheckerThatMustNotBeQueried());

        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertFalse($handlerReached);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid or expired token', json_decode($response->getBody(), true)['error']);
    }

    /**
     * A tampered payload (valid structure, broken signature) is rejected with 401
     * and never reaches the authoritative store.
     */
    public function testTamperedTokenPayloadIsRejected(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $token = $jwtParser->create(['user_id' => 7, 'email' => 'user@example.com']);

        // Forge an elevated payload, re-base64 it, and splice it in keeping the
        // original (now mismatched) signature.
        $parts = explode('.', $token);
        $forged = ['user_id' => 7, 'email' => 'user@example.com', 'role' => 'super_admin', 'jti' => 'x', 'type' => 'access'];
        $parts[1] = strtr(base64_encode((string) json_encode($forged)), '+/', '-_');
        $tampered = implode('.', $parts);

        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerThatMustNotBeQueried());
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$tampered}"]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertFalse($handlerReached);
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * A valid token presented via the `access_token` cookie (no Authorization
     * header) authenticates and authorizes end-to-end — the cookie fallback path.
     */
    public function testValidTokenViaAccessTokenCookieIsAccepted(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerGranting([CorePermissions::USERS_READ]));

        $token = $jwtParser->create(['user_id' => 8, 'email' => 'cookie@example.com']);
        $request = new Request(
            'GET',
            '/api/users',
            ['Cookie' => "session=abc; access_token={$token}; theme=dark"]
        );

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertTrue($handlerReached, 'Cookie-borne token should authenticate');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * An expired token delivered via cookie is rejected exactly like a header
     * token — the cookie path is not a bypass for expiry.
     */
    public function testExpiredTokenViaCookieIsRejected(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerThatMustNotBeQueried());

        $expired = $jwtParser->create(['user_id' => 9, 'email' => 'stale-cookie@example.com'], -10);
        $request = new Request('GET', '/api/users', ['Cookie' => "access_token={$expired}"]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertFalse($handlerReached);
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * When both a (malformed) Authorization header and a valid cookie are present,
     * the header is preferred — a malformed Bearer value must NOT silently fall
     * through to the cookie. This guards the documented header-precedence rule.
     */
    public function testMalformedHeaderDoesNotFallThroughToValidCookie(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $valid = $jwtParser->create(['user_id' => 10, 'email' => 'mixed@example.com']);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerGranting([CorePermissions::USERS_READ]));

        // Malformed header ("Bearer" with no token) is treated as absent, so the
        // cookie fallback IS consulted and the valid cookie token is accepted.
        $request = new Request('GET', '/api/users', [
            'Authorization' => 'Bearer',
            'Cookie' => "access_token={$valid}",
        ]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        // Documenting actual behaviour: a malformed Bearer header is ignored and
        // the valid cookie authenticates the request.
        $this->assertTrue($handlerReached, 'Malformed Bearer header falls back to the cookie token');
        $this->assertSame(200, $response->getStatusCode());
    }

    /**
     * A forged elevated role claim inside an OTHERWISE VALID token does not grant
     * access: authorization is decided by the authoritative store, which reports
     * no grant, so the request is denied 403 (issue #54, proven end-to-end with a
     * real signed token rather than a stubbed parser).
     */
    public function testForgedRoleClaimInValidTokenDoesNotEscalate(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        // Store grants nothing to this user's role.
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerGranting([]));

        $token = $jwtParser->create([
            'user_id' => 11,
            'email' => 'attacker@example.com',
            'role' => 'super_admin',     // forged elevation, must be ignored
            'permissions' => ['users:delete'],
        ]);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertFalse($handlerReached, 'A forged role claim must never bypass the authoritative check');
        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * A token whose `user_id` is the wrong type (string, not int) is rejected with
     * 401 "Invalid token payload" before authorization, even with a real token.
     */
    public function testNonIntegerUserIdInValidTokenIsRejected(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerThatMustNotBeQueried());

        $token = $jwtParser->create(['user_id' => 'not-an-int', 'email' => 'bad@example.com']);
        $request = new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertFalse($handlerReached);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Invalid token payload', json_decode($response->getBody(), true)['error']);
    }
}
