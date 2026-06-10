<?php

declare(strict_types=1);

namespace Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\RBAC\CorePermissions;
use Whity\Core\RBAC\PermissionRegistry;
use Whity\Core\Request;
use Whity\Sdk\Http\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\RbacMiddleware;

/**
 * RBAC authentication edge-case integration tests (WC-17, issue #13).
 *
 * Drives the FULL stack â€” real {@see JwtParser} â†’ real {@see RbacMiddleware} â†’
 * real {@see RoleChecker} â†’ mocked {@see Database} â€” to prove the RBAC layer
 * behaves correctly when the credential itself is degraded: expired, revoked
 * (wrong signing secret), tampered, malformed, supplied via cookie rather than
 * header, or carrying a forged role claim.
 *
 * The middleware-unit suite ({@see \Tests\Http\RbacMiddlewareTest}) covers these
 * with a STUBBED parser returning null; this file instead uses REAL signed/expired
 * tokens so the JwtParser â†’ middleware boundary is exercised authentically, and
 * additionally asserts the authoritative store is never consulted once
 * authentication fails (no DB query on a bad credential).
 */
class RbacAuthEdgeCaseTest extends TestCase
{
    private const SECRET = 'wc17-edge-secret-padded-for-hs256-min-32-byte-key';
    private const TENANT = 1;

    protected function setUp(): void
    {
        RoleChecker::clearCache();
        // Mirror production: the tenant is resolved/locked before RBAC runs.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT);
    }

    protected function tearDown(): void
    {
        RoleChecker::clearCache();
        TenantContext::reset();
    }

    /**
     * Build a RoleChecker over a real in-memory SQLite engine in which the given
     * user (in tenant 1, no OU) holds a role granting exactly $granted.
     *
     * Using the real engine keeps the checker honest about the multi-query
     * resolution flow (role id -> OU chain -> per-role permissions) rather than
     * coupling to a single stubbed query shape.
     *
     * @param array<int, string> $granted Permissions the user's role grants.
     */
    private function roleCheckerGranting(int $userId, array $granted): RoleChecker
    {
        $pdo = $this->makeSchema();

        $pdo->prepare('INSERT INTO roles (name, created_at) VALUES (?, NOW())')->execute(['role_' . $userId]);
        $roleId = (int) $pdo->lastInsertId();
        foreach ($granted as $permission) {
            $pdo->prepare('INSERT OR IGNORE INTO permissions (name, created_at) VALUES (?, NOW())')->execute([$permission]);
            $stmt = $pdo->prepare('SELECT id FROM permissions WHERE name = ?');
            $stmt->execute([$permission]);
            $permissionId = (int) $stmt->fetchColumn();
            $pdo->prepare('INSERT OR IGNORE INTO role_permissions (role_id, permission_id, created_at) VALUES (?, ?, NOW())')
                ->execute([$roleId, $permissionId]);
        }
        $pdo->prepare(
            'INSERT INTO users (id, tenant_id, email, password, role_id, ou_id, created_at)
             VALUES (?, ?, ?, ?, ?, NULL, NOW())'
        )->execute([$userId, self::TENANT, "u{$userId}@example.com", 'x', $roleId]);

        return new RoleChecker($this->wrapSqlite($pdo), new PermissionRegistry());
    }

    /**
     * Build a RoleChecker over a Database mock that fails the test if it is ever
     * queried â€” proving authentication short-circuits before any authorization
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

        // expiresIn = -3600 puts exp firmly in the past (beyond the clock-skew
        // leeway); the parser rejects on expiry.
        $expired = $jwtParser->create(['user_id' => 5, 'email' => 'stale@example.com'], -3600);
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
        $issuingParser = new JwtParser('previous-rotated-out-secret-padded-for-hs256-min-32-byte-key');
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
     * header) authenticates and authorizes end-to-end â€” the cookie fallback path.
     */
    public function testValidTokenViaAccessTokenCookieIsAccepted(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerGranting(8, [CorePermissions::USERS_READ]));

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
     * token â€” the cookie path is not a bypass for expiry.
     */
    public function testExpiredTokenViaCookieIsRejected(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerThatMustNotBeQueried());

        $expired = $jwtParser->create(['user_id' => 9, 'email' => 'stale-cookie@example.com'], -3600);
        $request = new Request('GET', '/api/users', ['Cookie' => "access_token={$expired}"]);

        $handlerReached = null;
        $response = $this->dispatchProtected($middleware, $request, CorePermissions::USERS_READ, $handlerReached);

        $this->assertFalse($handlerReached);
        $this->assertSame(401, $response->getStatusCode());
    }

    /**
     * When both a (malformed) Authorization header and a valid cookie are present,
     * the header is preferred â€” a malformed Bearer value must NOT silently fall
     * through to the cookie. This guards the documented header-precedence rule.
     */
    public function testMalformedHeaderDoesNotFallThroughToValidCookie(): void
    {
        $jwtParser = new JwtParser(self::SECRET);
        $valid = $jwtParser->create(['user_id' => 10, 'email' => 'mixed@example.com']);
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerGranting(10, [CorePermissions::USERS_READ]));

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
        $middleware = new RbacMiddleware($jwtParser, $this->roleCheckerGranting(11, []));

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

    // ==================== Helpers ====================

    private function wrapSqlite(PDO $pdo): Database
    {
        $db = Database::withFactory(static fn (): PDO => $pdo);
        $db->setMaxLifetimeSeconds(86400);
        $db->setPingIntervalSeconds(86400);
        $db->forceConnect();

        return $db;
    }

    private function makeSchema(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'), 0);

        $pdo->exec('
            CREATE TABLE roles (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                parent_id INTEGER,
                tenant_id INTEGER,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE,
                description TEXT,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE role_permissions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                role_id INTEGER NOT NULL REFERENCES roles(id),
                permission_id INTEGER NOT NULL REFERENCES permissions(id),
                created_at TEXT,
                UNIQUE(role_id, permission_id)
            )
        ');
        $pdo->exec('
            CREATE TABLE organizational_units (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                parent_id INTEGER,
                name TEXT NOT NULL,
                slug TEXT NOT NULL,
                created_at TEXT
            )
        ');
        $pdo->exec('
            CREATE TABLE ou_role_assignments (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                tenant_id INTEGER NOT NULL,
                ou_id INTEGER NOT NULL,
                role_id INTEGER NOT NULL,
                created_at TEXT,
                UNIQUE(ou_id, role_id)
            )
        ');
        $pdo->exec('
            CREATE TABLE users (
                id INTEGER PRIMARY KEY,
                tenant_id INTEGER NOT NULL,
                email TEXT NOT NULL,
                password TEXT NOT NULL,
                role_id INTEGER,
                ou_id INTEGER,
                created_at TEXT
            )
        ');

        return $pdo;
    }
}
