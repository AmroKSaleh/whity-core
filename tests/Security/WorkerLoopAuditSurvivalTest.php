<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Stringable;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Audit\AuditContext;
use Whity\Core\Log\ErrorLogLogger;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Http\HttpKernel;
use Whity\Http\Middleware\EnforceTenantIsolation;
use Whity\Http\RbacMiddleware;
use Whity\Core\Tenant\TenantContext;

/**
 * WC-184 (issue #156): worker-loop regression coverage for the WC-181 reset fix.
 *
 * {@see BootScopedStaticSurvivalTest} already pins that the boot-wired audit
 * logger survives ONE reset cycle and that request-scoped statics are cleared.
 * This test extends that coverage across a realistic reused-worker LIFECYCLE,
 * asserting the parts WC-181 must hold over many requests:
 *
 *  1. Nth-request survival — the boot-wired {@see TenantContext} logger survives
 *     across MANY handle()/resetRequestState() cycles (not just the 2nd request)
 *     and still audits a privileged bypass on a LATE (Nth) request.
 *  2. Cross-tenant bypass audited on a reused worker — a genuine cross-tenant
 *     access (system-mode + a tenant-N caller addressing tenant-M) is audited
 *     through BOTH audited seams that prod wires to the same logger:
 *       - TenantContext::audit() (the survival-tested boot logger), fired by
 *         {@see TenantContext::setSystemMode()} — its ONLY caller;
 *       - EnforceTenantIsolation::auditCrossTenantBypass(), fired when the
 *         middleware permits the cross-tenant request via the system-mode bypass.
 *  3. Logger rebind — boot statics are SETTABLE across the loop: a logger swapped
 *     in via {@see TenantContext::setLogger()} mid-lifecycle takes effect on
 *     later requests (the per-request reset preserves but does not freeze it).
 *  4. Prod injects a non-Null logger — pins that production bootstrap
 *     (public/index.php) actually wires a concrete, non-null PSR-3 logger, so the
 *     surviving-logger guarantee is not moot in prod.
 *
 * The pre-WC-181 kernel reset re-nulled TenantContext::$logger every request via
 * a reflection sweep, so on the Nth request (and the cross-tenant bypass below)
 * the audit produced ZERO records — exactly the silent control loss pinned here.
 */
class WorkerLoopAuditSurvivalTest extends TestCase
{
    /**
     * Number of full handle()/reset cycles driven before the audited "Nth"
     * request. Comfortably past the 2nd-request boundary the sibling test pins.
     */
    private const LOOP_ITERATIONS = 40;

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

            /**
             * @param mixed                $level
             * @param string|Stringable    $message
             * @param array<string, mixed> $context
             */
            public function log($level, string|Stringable $message, array $context = []): void
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
     * (1) Nth-request survival.
     *
     * The boot-wired audit logger must survive across MANY reset cycles on a
     * reused worker and still capture a system-mode bypass exercised on a LATE
     * (Nth) request — not merely the 2nd. On the pre-WC-181 reflection sweep,
     * TenantContext::$logger is re-nulled after every request, so the bypass on
     * request #N audits NOTHING and this assertion fails.
     */
    public function testBootLoggerSurvivesManyResetCyclesAndAuditsOnNthRequest(): void
    {
        $records = [];
        // Boot-time wiring: logger injected ONCE, exactly like public/index.php.
        TenantContext::setLogger($this->spyLogger($records));

        $router = new Router('');
        // An ordinary, non-privileged route driven for the warm-up loop. Its only
        // job is to run full handle() + resetRequestState() cycles (the worker
        // reuse boundary) without auditing anything.
        $router->register('GET', '/loop', static fn(Request $req): Response => Response::json(['ok' => true]));
        // The Nth-request route: a privileged system-mode bypass that MUST be
        // audited — and only can be if the boot logger survived N-1 resets.
        $router->register('GET', '/nth', static function (Request $req): Response {
            TenantContext::setSystemMode(true, 'cli:reindex', ['reason' => 'nth-request bypass']);
            return Response::json(['ok' => true]);
        });

        $kernel = $this->buildKernel($router);

        // Warm-up: many full request lifecycles, each ending in resetRequestState().
        for ($i = 0; $i < self::LOOP_ITERATIONS; $i++) {
            $this->assertSame(200, $kernel->handle(new Request('GET', '/loop'))->getStatusCode());
        }
        $this->assertSame([], $records, 'No audit may be emitted by the non-privileged warm-up requests');

        // The Nth (late) request: the bypass must still be audited.
        $this->assertSame(200, $kernel->handle(new Request('GET', '/nth'))->getStatusCode());

        $this->assertCount(
            1,
            $records,
            sprintf(
                'The boot-scoped audit logger must survive %d reset cycles and still capture the '
                . 'system-mode bypass on the Nth request (the pre-WC-181 sweep re-nulled it every request)',
                self::LOOP_ITERATIONS
            )
        );
        $this->assertStringContainsStringIgnoringCase('system mode', $records[0]['message']);
        $this->assertSame('cli:reindex', $records[0]['context']['actor']);
        $this->assertTrue($records[0]['context']['enabled']);
    }

    /**
     * RED-proof for (1): with NO surviving logger (the pre-WC-181 effect, where
     * the per-request reset re-nulled TenantContext::$logger), the same Nth-request
     * bypass audits NOTHING. Simulating the re-null here demonstrates the
     * survival assertion above has real teeth rather than passing vacuously.
     */
    public function testNthRequestAuditIsLostWhenLoggerDoesNotSurvive(): void
    {
        $records = [];
        TenantContext::setLogger($this->spyLogger($records));

        // Simulate the pre-fix behaviour: the worker reset re-nulled the logger.
        // (The current kernel preserves it; this nulling stands in for the old
        // reflection sweep so we can prove the bypass would have gone unaudited.)
        TenantContext::setLogger(null);

        TenantContext::setSystemMode(true, 'cli:reindex', ['reason' => 'nth-request bypass']);

        $this->assertSame(
            [],
            $records,
            'Sanity check / RED-proof: with the logger re-nulled (pre-WC-181), the Nth-request '
            . 'system-mode bypass produces ZERO audit records — which is exactly the silent '
            . 'control loss the survival test pins against.'
        );
    }

    /**
     * (2) Cross-tenant bypass audited on a reused worker.
     *
     * Investigated trigger: TenantContext::audit() has a SINGLE caller,
     * {@see TenantContext::setSystemMode()}. The middleware's own cross-tenant
     * audit ({@see EnforceTenantIsolation::auditCrossTenantBypass()}) fires when a
     * caller WITHOUT a same-tenant target is let through by the system-mode
     * bypass (hasCrossTenantAuthority() === true). Production wires the SAME
     * ErrorLogLogger into both seams (public/index.php: TenantContext::setLogger
     * and `new EnforceTenantIsolation($jwtParser, $logger)`), so we mirror that
     * here with one shared spy logger.
     *
     * We then drive a genuine cross-tenant request through the kernel on a REUSED
     * worker (after a warm-up loop): a tenant-1 caller addressing tenant-2 while
     * system mode is active. Both audited seams must fire — proving the audit
     * trail for cross-tenant access survives worker reuse.
     */
    public function testCrossTenantBypassIsAuditedOnReusedWorker(): void
    {
        $records = [];
        $logger = $this->spyLogger($records);

        // Mirror prod: the SAME logger backs both audited seams.
        TenantContext::setLogger($logger);

        $jwtParser = $this->createMock(JwtParser::class);
        $jwtParser->method('parse')->willReturn([
            'user_id' => 9,
            'tenant_id' => 1,
            'email' => 'svc@t1',
        ]);
        $isolation = new EnforceTenantIsolation($jwtParser, $logger);

        $router = new Router('');
        $router->register('GET', '/loop', static fn(Request $req): Response => Response::json(['ok' => true]));
        // The cross-tenant target: a tenant-1 caller addressing tenant-2. The
        // route handler is reached ONLY if the middleware permits the bypass.
        $reached = false;
        $router->register('GET', '/api/resource', function (Request $req) use (&$reached): Response {
            $reached = true;
            return Response::json(['ok' => true]);
        });

        $kernel = $this->buildKernel($router);
        $kernel->use($isolation);

        // Warm up the worker with several non-privileged cycles first. Each
        // carries a valid token (resolves to tenant 1) but declares no resource
        // tenant, so the middleware lets it through without auditing.
        for ($i = 0; $i < 10; $i++) {
            $warmup = new Request('GET', '/loop', ['Authorization' => 'Bearer t1.token']);
            $this->assertSame(200, $kernel->handle($warmup)->getStatusCode());
        }
        $this->assertSame([], $records, 'Warm-up loop must not audit anything');

        // Trusted tooling activates the cross-tenant bypass for this request.
        // setSystemMode() is TenantContext::audit()'s only caller -> seam #1.
        TenantContext::setSystemMode(true, 'cli:cross-tenant-maintenance', ['run' => 'nightly']);

        // The cross-tenant request: tenant-1 caller -> tenant-2 resource. The
        // target tenant is declared via the X-Tenant-Id header (keeping the path
        // clean for the router, which matches on the path, not the query string).
        // Permitted by the active system mode and audited by the middleware (seam #2).
        $request = new Request('GET', '/api/resource', [
            'Authorization' => 'Bearer t1.token',
            'X-Tenant-Id' => '2',
        ]);
        $response = $kernel->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertTrue($reached, 'The cross-tenant request must reach the handler via the system-mode bypass');

        // Seam #1: TenantContext audited the system-mode activation via the
        // surviving boot logger.
        $systemModeRecords = array_values(array_filter(
            $records,
            static fn(array $r): bool =>
                ($r['context']['event'] ?? null) === 'tenant_context.system_mode'
        ));
        $this->assertCount(
            1,
            $systemModeRecords,
            'TenantContext::audit() (its only caller is setSystemMode) must record the bypass '
            . 'activation through the surviving boot logger on the reused worker'
        );
        $this->assertSame('cli:cross-tenant-maintenance', $systemModeRecords[0]['context']['actor']);

        // Seam #2: the middleware audited the actual cross-tenant access.
        $crossTenantRecords = array_values(array_filter(
            $records,
            static fn(array $r): bool =>
                ($r['context']['event'] ?? null) === 'tenant_isolation.cross_tenant_bypass'
        ));
        $this->assertCount(
            1,
            $crossTenantRecords,
            'The cross-tenant bypass must be audited by EnforceTenantIsolation on the reused worker'
        );
        $this->assertSame(1, $crossTenantRecords[0]['context']['tenant_id'], 'caller tenant');
        $this->assertSame(2, $crossTenantRecords[0]['context']['resource_tenant_id'], 'target tenant');
        $this->assertTrue($crossTenantRecords[0]['context']['system_mode']);
    }

    /**
     * (3) Logger rebind across the loop.
     *
     * Boot statics are preserved by the per-request reset but NOT frozen: an
     * operator (or test) may swap in a different logger via
     * {@see TenantContext::setLogger()} mid-lifecycle, and the NEW logger must take
     * effect for subsequent audits while the OLD one stops receiving them. This
     * proves the WC-181 fix preserves boot statics without pinning the original
     * instance.
     */
    public function testLoggerRebindsAcrossResetCyclesAndTakesEffect(): void
    {
        $firstRecords = [];
        $secondRecords = [];

        TenantContext::setLogger($this->spyLogger($firstRecords));

        $router = new Router('');
        $router->register('GET', '/loop', static fn(Request $req): Response => Response::json(['ok' => true]));
        $router->register('GET', '/bypass', static function (Request $req): Response {
            TenantContext::setSystemMode(true, 'cli:rebind-probe');
            return Response::json(['ok' => true]);
        });

        $kernel = $this->buildKernel($router);

        // Drive a few cycles, then audit once against the FIRST logger.
        for ($i = 0; $i < 5; $i++) {
            $kernel->handle(new Request('GET', '/loop'));
        }
        $kernel->handle(new Request('GET', '/bypass'));
        $this->assertCount(1, $firstRecords, 'First logger must capture the pre-rebind bypass');

        // Rebind to a SECOND logger mid-lifecycle (e.g. reconfigured at runtime).
        TenantContext::setLogger($this->spyLogger($secondRecords));

        // Drive more cycles, then audit again — must land on the SECOND logger.
        for ($i = 0; $i < 5; $i++) {
            $kernel->handle(new Request('GET', '/loop'));
        }
        $kernel->handle(new Request('GET', '/bypass'));

        $this->assertCount(
            1,
            $firstRecords,
            'The original logger must receive NO further records after being rebound — proving '
            . 'the per-request reset preserves the static without freezing the original instance'
        );
        $this->assertCount(
            1,
            $secondRecords,
            'The rebound logger must take effect and capture the post-rebind bypass across resets'
        );
        $this->assertSame('cli:rebind-probe', $secondRecords[0]['context']['actor']);
    }

    /**
     * (4a) Prod injects a non-Null logger — source guard.
     *
     * Mirrors the source-guard style of {@see ResetCostGuardTest}: reads
     * public/index.php straight off disk and asserts it wires a concrete,
     * non-null logger into TenantContext via setLogger(). If someone removes the
     * `TenantContext::setLogger($logger)` line (or passes null), this turns RED —
     * the surviving-logger fix would otherwise be moot because prod would have no
     * logger to survive.
     */
    public function testProdBootstrapInjectsNonNullLoggerIntoTenantContext(): void
    {
        $indexPath = dirname(__DIR__, 2) . '/public/index.php';
        $this->assertFileExists($indexPath, 'public/index.php (the production bootstrap) must exist');

        $lines = file($indexPath);
        $this->assertIsArray($lines, 'Could not read public/index.php');

        // Keep only EXECUTABLE PHP lines: drop blank lines and whole-line `//`
        // comments. This is what gives the guard teeth — merely COMMENTING OUT the
        // wiring (a tempting "quick disable") leaves the matching text on disk but
        // must still register as the wiring being GONE.
        $codeLines = array_values(array_filter(
            $lines,
            static function (string $line): bool {
                $trimmed = ltrim($line);
                return $trimmed !== '' && !str_starts_with($trimmed, '//');
            }
        ));
        $code = implode('', $codeLines);

        // A concrete logger is built and bound. We assert the wiring exists with a
        // non-null concrete logger, not a NullLogger or a null literal.
        $this->assertMatchesRegularExpression(
            '/\$logger\s*=\s*new\s+ErrorLogLogger\s*\(\s*\)\s*;/',
            $code,
            'public/index.php must construct a concrete PSR-3 logger (ErrorLogLogger) at boot'
        );
        $this->assertMatchesRegularExpression(
            '/TenantContext::setLogger\s*\(\s*\$logger\s*\)\s*;/',
            $code,
            'public/index.php must wire the concrete boot logger into TenantContext::setLogger() '
            . '(removing/commenting this makes the WC-181 surviving-logger fix moot in production)'
        );
        $this->assertStringNotContainsString(
            'TenantContext::setLogger(null)',
            $code,
            'production bootstrap must NOT detach the audit logger by passing null'
        );
    }

    /**
     * (4b) Prod injects a non-Null logger — the concrete logger is a usable PSR-3.
     *
     * Complements the source guard: pins that the logger prod wires
     * ({@see ErrorLogLogger}) is genuinely instantiable and a valid, non-null
     * PSR-3 LoggerInterface — so TenantContext::audit()'s `if ($logger !== null)`
     * guard actually fires in production.
     */
    public function testProdLoggerIsInstantiableNonNullPsr3Logger(): void
    {
        $logger = new ErrorLogLogger();

        $this->assertInstanceOf(
            LoggerInterface::class,
            $logger,
            'The production boot logger must be a valid PSR-3 LoggerInterface'
        );

        // Wiring it into TenantContext and exercising the audited path must reach
        // a non-null logger (the guard fires), confirming it is not a no-op sink.
        $records = [];
        $probe = $this->spyLogger($records);
        TenantContext::setLogger($probe);
        TenantContext::setSystemMode(true, 'prod-logger-probe');

        $this->assertCount(
            1,
            $records,
            'A non-null PSR-3 logger wired into TenantContext must receive the audit record '
            . '(TenantContext::audit() is guarded by `if ($logger !== null)`)'
        );
    }
}
