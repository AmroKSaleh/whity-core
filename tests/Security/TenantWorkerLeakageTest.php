<?php

declare(strict_types=1);

namespace Tests\Security;

use PDO;
use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Database\ScopesToTenant;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Database\Database;
use Whity\Http\HttpKernel;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\RbacMiddleware;

/**
 * FrankenPHP worker-reuse tenant-leakage proof (WC-22, issue #10).
 *
 * The platform runs persistent FrankenPHP workers: one PHP process serves many
 * requests in sequence, so {@see TenantContext}'s request-scoped *static* state
 * survives in memory between requests unless it is explicitly cleared. The
 * {@see HttpKernel} owns that lifecycle and calls {@see TenantContext::reset()}
 * in a `finally` block after every request (HttpKernel::resetRequestState()).
 *
 * What is already covered elsewhere (NOT repeated here):
 *  - {@see RequestIsolationTest::testStaticPropertiesAreReset} proves the kernel
 *    resets TenantContext between requests, but it sets the tenant id by calling
 *    {@see TenantContext::setTenantId()} directly inside a fake handler and never
 *    touches per-tenant *data*.
 *  - {@see \Tests\Integration\TenantIsolationTest} runs two sequential requests
 *    but calls {@see TenantContext::reset()} manually between them and filters an
 *    in-memory array — so it never proves the *kernel's* auto-reset nor a real
 *    query.
 *
 * This file closes that gap: two sequential requests (Tenant A then Tenant B) are
 * driven through the REAL {@see HttpKernel} + REAL {@see EnforceTenantIsolation}
 * middleware + REAL signed JWTs, with each request's handler reading a shared
 * SQLite table through the REAL {@see ScopesToTenant} trait. There is NO manual
 * reset between requests — only the kernel's own lifecycle runs — proving that a
 * reused worker serves each tenant only its own rows.
 */
class TenantWorkerLeakageTest extends TestCase
{
    private const SECRET = 'worker-leakage-test-secret';

    private const TENANT_A = 1;
    private const TENANT_B = 2;
    private const TENANT_A_USERS = 10;
    private const TENANT_B_USERS = 5;

    protected function setUp(): void
    {
        // Start every test from a clean worker-memory state.
        TenantContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        TenantContext::setLogger(null);
    }

    /**
     * AC2: a single reused worker serves Tenant A then Tenant B and each response
     * contains ONLY that tenant's data — proving the kernel's per-request
     * TenantContext::reset() prevents worker-level state leakage.
     *
     * Critically, no manual reset is performed between the two kernel->handle()
     * calls: the only thing clearing the static tenant state is the kernel's own
     * finally block. If that reset were absent, request B would either see
     * Tenant A's locked context (leak) or fail to resolve (locked-context error).
     */
    public function testSequentialRequestsOnSameWorkerDoNotLeakTenantData(): void
    {
        $kernel = $this->buildKernel($this->seededDatabase());

        // ---- Request 1: Tenant A ----
        $responseA = $kernel->handle($this->signedRequest(self::TENANT_A, 101));
        $this->assertSame(200, $responseA->getStatusCode());
        $namesA = json_decode($responseA->getBody(), true)['names'];

        $this->assertCount(self::TENANT_A_USERS, $namesA, 'Request A must return exactly Tenant A\'s 10 users');
        $this->assertContains('a-user-1', $namesA);
        foreach ($namesA as $name) {
            $this->assertStringStartsWith('a-user-', $name, 'No Tenant B row may appear in Request A');
        }

        // The kernel must have cleared the context after request A; the worker is
        // now "idle" with no tenant in memory.
        $this->assertNull(
            TenantContext::getTenantId(),
            'Kernel must reset TenantContext after request A so nothing leaks to the next request'
        );

        // ---- Request 2: Tenant B on the SAME kernel/worker, no manual reset ----
        $responseB = $kernel->handle($this->signedRequest(self::TENANT_B, 202));
        $this->assertSame(
            200,
            $responseB->getStatusCode(),
            'Request B must resolve cleanly; a stale locked context from A would break this'
        );
        $namesB = json_decode($responseB->getBody(), true)['names'];

        $this->assertCount(self::TENANT_B_USERS, $namesB, 'Request B must return exactly Tenant B\'s 5 users');
        $this->assertContains('b-user-1', $namesB);
        foreach ($namesB as $name) {
            $this->assertStringStartsWith('b-user-', $name, 'No Tenant A row may leak into Request B');
        }

        // Explicit no-leak cross-check: zero overlap between the two responses.
        $this->assertSame([], array_intersect($namesA, $namesB), 'Tenant A and B result sets must be disjoint');
    }

    /**
     * AC2 (interleaved at scale): alternating A/B/A/B requests on one worker
     * always reflect the tenant of the *current* request, never a carried-over
     * one. This stresses the reset across many reuse cycles, as a long-lived
     * FrankenPHP worker would experience.
     */
    public function testInterleavedTenantRequestsAlwaysReflectCurrentTenant(): void
    {
        $kernel = $this->buildKernel($this->seededDatabase());

        $sequence = [self::TENANT_A, self::TENANT_B, self::TENANT_A, self::TENANT_B, self::TENANT_A];

        foreach ($sequence as $i => $tenantId) {
            $expectedCount = $tenantId === self::TENANT_A ? self::TENANT_A_USERS : self::TENANT_B_USERS;
            $expectedPrefix = $tenantId === self::TENANT_A ? 'a-user-' : 'b-user-';

            $response = $kernel->handle($this->signedRequest($tenantId, 300 + $i));
            $this->assertSame(200, $response->getStatusCode(), "Request #{$i} (tenant {$tenantId}) must succeed");

            $names = json_decode($response->getBody(), true)['names'];
            $this->assertCount($expectedCount, $names, "Request #{$i} returned the wrong tenant's row count");
            foreach ($names as $name) {
                $this->assertStringStartsWith(
                    $expectedPrefix,
                    $name,
                    "Request #{$i} leaked a foreign tenant's row"
                );
            }

            $this->assertNull(TenantContext::getTenantId(), "Worker context must be clear after request #{$i}");
        }
    }

    /**
     * Proves the kernel reset is load-bearing, not incidental. If the worker
     * carried Tenant A's locked context into a subsequent request WITHOUT the
     * kernel reset, the middleware's resolve() would hit a locked context and the
     * second tenant could never be established. We simulate the "missing reset"
     * by locking the context to Tenant A first and then attempting to resolve
     * Tenant B through the middleware directly (bypassing the kernel's finally).
     *
     * This documents WHY HttpKernel::resetRequestState() exists: without it,
     * worker reuse is unsafe.
     */
    public function testStaleLockedContextWouldBlockNextTenantWithoutReset(): void
    {
        $jwt = $this->createMock(JwtParser::class);
        $jwt->method('parse')->willReturn(['user_id' => 202, 'tenant_id' => self::TENANT_B, 'email' => 'b@t']);
        $middleware = new EnforceTenantIsolation($jwt);

        // Simulate a worker that DID NOT reset after a Tenant A request.
        TenantContext::setTenantId(self::TENANT_A);

        $request = new Request('GET', '/api/users', ['Authorization' => 'Bearer b.token']);

        // resolve() inside the middleware tries to lock Tenant B onto an already
        // locked context, which throws RuntimeException('...locked...'). The
        // middleware does not catch RuntimeException, so it surfaces here.
        $threw = false;
        try {
            $middleware->handle($request, static fn(Request $r): Response => new Response(200, 'ok'));
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertStringContainsString('locked', $e->getMessage());
        }

        $this->assertTrue(
            $threw,
            'A reused worker without reset cannot establish the next tenant — this is the leak the kernel reset prevents'
        );
        // The stale Tenant A context is still in place: a clear leakage hazard.
        $this->assertSame(self::TENANT_A, TenantContext::getTenantId());
    }

    /**
     * Defence-in-depth at the query layer: even if the HTTP layer were bypassed,
     * a repository sharing the worker's connection scopes strictly to whatever
     * tenant is currently resolved. Switching the resolved tenant between two
     * reads on the SAME repository/connection yields disjoint result sets.
     */
    public function testSameConnectionReturnsDisjointResultsAcrossTenantSwitch(): void
    {
        $repo = new WorkerScopedUserRepository($this->seededDatabase());

        TenantContext::setTenantId(self::TENANT_A);
        $namesA = $repo->listNames();

        // Worker reuse: clear and switch tenant on the SAME repo + connection.
        TenantContext::reset();
        TenantContext::setTenantId(self::TENANT_B);
        $namesB = $repo->listNames();

        $this->assertCount(self::TENANT_A_USERS, $namesA);
        $this->assertCount(self::TENANT_B_USERS, $namesB);
        $this->assertSame([], array_intersect($namesA, $namesB));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Build a real HttpKernel wired with the real EnforceTenantIsolation
     * middleware and a single GET /api/users route whose handler reads the shared
     * SQLite table through the real ScopesToTenant trait.
     */
    private function buildKernel(Database $db): HttpKernel
    {
        $jwtParser = new JwtParser(self::SECRET);
        $router = new Router();

        // No required role/permission => RBAC is fail-open; the only enforcement
        // exercised here is tenant resolution + query scoping.
        $router->register('GET', '/api/users', function (Request $request) use ($db): Response {
            $repo = new WorkerScopedUserRepository($db);
            return Response::json(['names' => $repo->listNames()]);
        });

        // RbacMiddleware is required by the kernel constructor but, with no
        // protected routes, never gates these requests.
        $rbac = new RbacMiddleware($jwtParser, $this->createMock(RoleChecker::class));

        $kernel = new HttpKernel($router, $rbac);
        $kernel->use(new EnforceTenantIsolation($jwtParser));

        return $kernel;
    }

    /**
     * A GET /api/users request bearing a genuine, signed JWT for the given tenant.
     */
    private function signedRequest(int $tenantId, int $userId): Request
    {
        $token = (new JwtParser(self::SECRET))->create([
            'user_id' => $userId,
            'tenant_id' => $tenantId,
            'email' => "user{$userId}@example.com",
        ]);

        return new Request('GET', '/api/users', ['Authorization' => "Bearer {$token}"]);
    }

    /**
     * Seeded in-memory SQLite: 10 Tenant A users and 5 Tenant B users sharing one
     * `users` table on a single worker-scoped connection.
     */
    private function seededDatabase(): Database
    {
        $factory = static function (): PDO {
            $pdo = new PDO('sqlite::memory:');
            $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, tenant_id INTEGER)');

            for ($i = 1; $i <= self::TENANT_A_USERS; $i++) {
                $pdo->exec("INSERT INTO users (name, tenant_id) VALUES ('a-user-{$i}', 1)");
            }
            for ($i = 1; $i <= self::TENANT_B_USERS; $i++) {
                $pdo->exec("INSERT INTO users (name, tenant_id) VALUES ('b-user-{$i}', 2)");
            }

            return $pdo;
        };

        // Never recycle/ping the seeded in-memory connection during a test.
        return Database::withFactory($factory, 86400, 86400);
    }
}

/**
 * Repository sharing the worker's connection, scoping reads to the current tenant
 * via {@see ScopesToTenant}. Defined in this uniquely-named file to avoid any
 * collision with shared support fixtures a concurrent agent might touch.
 */
class WorkerScopedUserRepository
{
    use ScopesToTenant;

    public ?int $tenant_id = null;

    public function __construct(private Database $db)
    {
    }

    /**
     * @return list<string>
     */
    public function listNames(): array
    {
        /** @var list<string> $names */
        $names = $this->tenantScopedQuery($this->db, 'SELECT name FROM users ORDER BY id')
            ->fetchAll(PDO::FETCH_COLUMN);

        return $names;
    }
}
