<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Core\Tenant\TenantContext;
use Whity\Http\HttpKernel;
use Whity\Http\RbacMiddleware;

/**
 * WC-181 (issue #179) regression: HttpKernel's per-request reset must NOT wipe
 * BOOT-scoped core statics — only REQUEST-scoped ones.
 *
 * The pre-fix kernel reset every static property under src/Core/** to its
 * declared default via a reflection sweep. That re-nulled boot-wired
 * infrastructure such as {@see TenantContext}'s PSR-3 audit logger (set ONCE at
 * bootstrap in public/index.php). From request #2 onward the logger was null, so
 * {@see TenantContext::audit()} (guarded by `if ($logger !== null)`) silently
 * no-op'd and the privileged-operation / cross-tenant audit trail vanished —
 * exactly the kind of silent security-control loss this test pins.
 *
 * The fix replaces the blanket sweep with an explicit registry of request-scoped
 * resets. This test proves both halves of correctness:
 *  - BOOT-scoped logger SURVIVES across reset cycles and still audits a
 *    system-mode bypass exercised on a SUBSEQUENT (2nd/Nth) request.
 *  - REQUEST-scoped statics (tenant id + audit actor/IP) are CLEARED by the
 *    kernel reset, so nothing leaks into the next request on a reused worker.
 *
 * HttpKernel::resetRequestState() is private; it is driven here through the
 * public handle() path, the same seam the existing isolation tests use.
 */
class BootScopedStaticSurvivalTest extends TestCase
{
    protected function setUp(): void
    {
        // Start from clean worker memory; detach any logger a prior test left.
        TenantContext::reset();
        TenantContext::setLogger(null);
        AuditContext::reset();
    }

    protected function tearDown(): void
    {
        TenantContext::reset();
        TenantContext::setLogger(null);
        AuditContext::reset();
    }

    /**
     * A spy PSR-3 logger that records every structured record it receives.
     *
     * @param list<array{level:mixed,message:string,context:array<string,mixed>}> $records
     */
    private function spyLogger(array &$records): AbstractLogger
    {
        return new class ($records) extends AbstractLogger {
            /** @param list<array{level:mixed,message:string,context:array<string,mixed>}> $records */
            public function __construct(private array &$records)
            {
            }

            public function log($level, $message, array $context = []): void
            {
                $this->records[] = [
                    'level' => $level,
                    'message' => (string) $message,
                    'context' => $context,
                ];
            }
        };
    }

    private function buildKernel(Router $router): HttpKernel
    {
        $jwtParser = $this->createMock(JwtParser::class);
        $roleChecker = $this->createMock(RoleChecker::class);
        $rbac = new RbacMiddleware($jwtParser, $roleChecker);

        return new HttpKernel($router, $rbac);
    }

    /**
     * RED before the fix, GREEN after: the boot-wired audit logger must survive
     * the kernel's per-request reset and still capture a system-mode bypass that
     * is exercised on the SECOND request served by the same (reused) worker.
     *
     * On the pre-fix reflection sweep, TenantContext::$logger is re-nulled after
     * request #1, so the bypass audited inside request #2 produces ZERO records
     * and this assertion fails — proving the audit-trail loss.
     */
    public function testBootScopedLoggerSurvivesResetAndStillAuditsOnSecondRequest(): void
    {
        $records = [];
        // Boot-time wiring: logger injected ONCE, exactly like public/index.php.
        TenantContext::setLogger($this->spyLogger($records));

        $router = new Router('');

        // Request #1: an ordinary request. Its only job is to make the kernel run
        // a full handle() + resetRequestState() cycle (worker reuse boundary).
        $router->register('GET', '/r1', static fn(Request $req): Response => Response::json(['ok' => true]));

        // Request #2: exercises a privileged system-mode bypass that MUST be
        // audited. The audit only fires if the boot logger survived request #1's
        // reset.
        $router->register('GET', '/r2', static function (Request $req): Response {
            TenantContext::setSystemMode(true, 'cli:reindex', ['reason' => 'nth-request bypass']);
            return Response::json(['ok' => true]);
        });

        $kernel = $this->buildKernel($router);

        // ---- Request #1 ----
        $this->assertSame(200, $kernel->handle(new Request('GET', '/r1'))->getStatusCode());
        $this->assertSame([], $records, 'No audit expected from the first, non-privileged request');

        // ---- Request #2 (the bypass must still be audited) ----
        $this->assertSame(200, $kernel->handle(new Request('GET', '/r2'))->getStatusCode());

        $this->assertCount(
            1,
            $records,
            'The boot-scoped audit logger must survive the per-request reset and capture '
            . 'the system-mode bypass on the second request (it was re-nulled by the old sweep)'
        );
        $this->assertStringContainsStringIgnoringCase('system mode', $records[0]['message']);
        $this->assertSame('cli:reindex', $records[0]['context']['actor']);
        $this->assertTrue($records[0]['context']['enabled']);
    }

    /**
     * The flip side of correctness: REQUEST-scoped statics MUST still be cleared
     * by the kernel reset so no tenant/actor state leaks across worker reuse.
     *
     * Covers TenantContext (tenant id / lock / system mode) AND AuditContext
     * (actor user id / client IP) — the latter was previously cleared ONLY by the
     * reflection sweep inside the kernel, so an allowlist that forgot it would
     * silently reintroduce an actor-identity leak.
     */
    public function testRequestScopedStaticsAreClearedAcrossReset(): void
    {
        $router = new Router('');

        // Request #1 dirties every request-scoped static.
        $router->register('GET', '/dirty', static function (Request $req): Response {
            TenantContext::setTenantId(7);
            TenantContext::setSystemMode(true, 'test');
            AuditContext::set(99, '203.0.113.7');
            return Response::json(['ok' => true]);
        });

        $kernel = $this->buildKernel($router);
        $this->assertSame(200, $kernel->handle(new Request('GET', '/dirty'))->getStatusCode());

        // After the kernel's finally-block reset, nothing must survive.
        $this->assertNull(TenantContext::getTenantId(), 'tenant id must be cleared between requests');
        $this->assertFalse(TenantContext::hasTenant(), 'tenant lock must be cleared between requests');
        $this->assertFalse(TenantContext::isSystemMode(), 'system mode must be cleared between requests');
        $this->assertNull(AuditContext::getActorUserId(), 'audit actor must be cleared between requests');
        $this->assertNull(AuditContext::getIpAddress(), 'audit client IP must be cleared between requests');
    }
}
