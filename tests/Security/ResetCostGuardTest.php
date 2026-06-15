<?php

declare(strict_types=1);

namespace Tests\Security;

use PHPUnit\Framework\TestCase;
use Whity\Auth\JwtParser;
use Whity\Auth\RoleChecker;
use Whity\Core\Request;
use Whity\Core\Response;
use Whity\Core\Router;
use Whity\Http\HttpKernel;
use Whity\Http\RbacMiddleware;

/**
 * WC-183 (issue #148) cost guard: the per-request reset MUST NOT do an
 * avoidable per-request reflection walk or directory scan of src/Core.
 *
 * WC-181 already removed the old machinery: HttpKernel::resetRequestState()
 * used to (a) build a list of every class under src/Core via a
 * RecursiveDirectoryIterator (initCoreClasses(), cached in $coreClasses on the
 * first request) and (b) reflect over each one every request
 * (new ReflectionClass(...) + getDefaultProperties() + getProperties()) to
 * re-null its statics. That reflection sweep is gone, replaced by a fixed
 * $requestScopedResetters registry built ONCE in the constructor and invoked as
 * a plain foreach (see HttpKernel.php: property declared ~line 56, built in the
 * constructor ~line 73, invoked in resetRequestState() ~line 126).
 *
 * This test PINS that de-cost so a future change cannot silently reintroduce a
 * per-request scan. It is deliberately about COST (no per-request reflection /
 * directory walk), NOT about audit correctness — the logger-survives /
 * audit-on-Nth-request behaviour is pinned separately by
 * {@see BootScopedStaticSurvivalTest} (issue #156 follow-up). The two stay
 * distinct on purpose.
 *
 * Three independent layers, any of which fails on reintroduction:
 *  1. Structural: the scan machinery (initCoreClasses() / $coreClasses) stays
 *     deleted.
 *  2. Source-level: the reset path's own source carries no reflection /
 *     directory-scan token. This is the layer with teeth against someone
 *     pasting `new \ReflectionClass(...)` back into resetRequestState().
 *  3. Behavioural: the request-scoped resetter registry is a fixed, bounded
 *     list built ONCE — its identity (count + class-name shape) does not change
 *     across many handle()/reset cycles, i.e. it is not rebuilt per request.
 */
class ResetCostGuardTest extends TestCase
{
    /**
     * Reflection / filesystem-scan tokens that must never appear in the
     * per-request reset path. A reintroduced sweep would use at least one.
     *
     * @var list<string>
     */
    private const FORBIDDEN_RESET_TOKENS = [
        'ReflectionClass',
        'ReflectionObject',
        'getProperties(',
        'getDefaultProperties(',
        'getReflectionConstants(',
        'RecursiveDirectoryIterator',
        'RecursiveIteratorIterator',
        'FilesystemIterator',
        'scandir(',
        'glob(',
        'get_declared_classes(',
    ];

    private function buildKernel(): HttpKernel
    {
        $jwtParser = $this->createMock(JwtParser::class);
        $roleChecker = $this->createMock(RoleChecker::class);
        $rbac = new RbacMiddleware($jwtParser, $roleChecker);

        return new HttpKernel(new Router(''), $rbac);
    }

    /**
     * Layer 1 — Structural: the scan machinery removed by WC-181 stays gone.
     *
     * The old cost lived in HttpKernel::initCoreClasses() (the directory walk)
     * caching into the $coreClasses property (the per-request reflection
     * target). If either reappears, the cheapest way to "use" it is to scan
     * again — so their continued absence is a direct guard on the de-cost.
     */
    public function testScanMachineryRemainsRemoved(): void
    {
        $kernel = new \ReflectionClass(HttpKernel::class);

        $this->assertFalse(
            $kernel->hasMethod('initCoreClasses'),
            'HttpKernel::initCoreClasses() (the src/Core directory walk) was removed in '
            . 'WC-181 and must not return — it is the entry point of the per-request scan.'
        );
        $this->assertFalse(
            $kernel->hasProperty('coreClasses'),
            'HttpKernel::$coreClasses (the cached reflection target list) was removed in '
            . 'WC-181 and must not return — re-adding it reintroduces the per-request sweep.'
        );
    }

    /**
     * Layer 2 — Source-level: the reset path performs no reflection / scan.
     *
     * We read the exact source span of resetRequestState() (and the constructor
     * that builds the registry it drives) straight off disk via the method's
     * own file/line metadata, then assert none of the forbidden tokens appear.
     * Reading the real source — not a behavioural proxy — is what gives this
     * teeth: pasting `new \ReflectionClass(...)` back into the reset turns the
     * assertion RED immediately, regardless of whether that reflection happens
     * to change observable behaviour.
     */
    public function testResetPathSourceContainsNoReflectionOrScan(): void
    {
        $resetSource = $this->methodSource(HttpKernel::class, 'resetRequestState');

        foreach (self::FORBIDDEN_RESET_TOKENS as $token) {
            $this->assertStringNotContainsString(
                $token,
                $resetSource,
                sprintf(
                    'resetRequestState() must not perform a per-request reflection/directory '
                    . 'scan, but its source contains the forbidden token "%s". The WC-181 '
                    . 'de-cost replaced that sweep with a fixed resetter registry; do not '
                    . 'reintroduce a per-request scan here.',
                    $token
                )
            );
        }

        // The registry is built in the constructor, so guard that span too: a
        // scan moved "once into the constructor" is cheaper than per-request but
        // still an avoidable directory walk we deliberately deleted.
        $ctorSource = $this->methodSource(HttpKernel::class, '__construct');
        foreach (['RecursiveDirectoryIterator', 'RecursiveIteratorIterator', 'FilesystemIterator', 'scandir(', 'glob('] as $token) {
            $this->assertStringNotContainsString(
                $token,
                $ctorSource,
                sprintf('HttpKernel::__construct() must not scan the filesystem to build the resetter registry (found "%s").', $token)
            );
        }
    }

    /**
     * Layer 3 — Behavioural: the resetter registry is a fixed, bounded list
     * built ONCE, not rebuilt per request.
     *
     * If a future change reverted to discovering reset targets dynamically
     * (e.g. scanning src/Core each request), the registry's size or contents
     * would track that discovery rather than staying constant. We snapshot it,
     * drive many full handle()/reset cycles, and assert it is byte-for-byte the
     * same fixed, small allowlist afterwards.
     */
    public function testResetterRegistryIsFixedAndNotRebuiltPerRequest(): void
    {
        $kernel = $this->buildKernel();

        $before = $this->resetterFingerprint($kernel);

        // A bounded allowlist — not a discovered set. Today it holds exactly the
        // AuditContext reset; this pins it as O(1) explicit entries, not a sweep.
        $this->assertGreaterThanOrEqual(1, $before['count']);
        $this->assertLessThanOrEqual(
            8,
            $before['count'],
            'The request-scoped resetter registry must stay a small explicit allowlist, '
            . 'not a discovered list of every static-bearing class under src/Core.'
        );

        // Drive many full request lifecycles (each runs resetRequestState() in
        // its finally block on the same kernel instance — the worker-reuse seam).
        $router = $this->routerWithNoopRoute();
        $reflRouter = new \ReflectionProperty(HttpKernel::class, 'router');
        $reflRouter->setValue($kernel, $router);

        for ($i = 0; $i < 25; $i++) {
            $kernel->handle(new Request('GET', '/noop'));
        }

        $after = $this->resetterFingerprint($kernel);

        $this->assertSame(
            $before,
            $after,
            'The resetter registry changed across request cycles, implying it is being '
            . '(re)built per request. It must be assembled ONCE in the constructor.'
        );
    }

    /**
     * Read the on-disk source of a method by its own file/line metadata.
     */
    private function methodSource(string $class, string $method): string
    {
        $refMethod = new \ReflectionMethod($class, $method);
        $file = $refMethod->getFileName();
        $this->assertIsString($file, "Could not resolve source file for {$class}::{$method}()");

        $lines = file($file);
        $this->assertIsArray($lines, "Could not read source file {$file}");

        $start = $refMethod->getStartLine() - 1;
        $length = $refMethod->getEndLine() - $refMethod->getStartLine() + 1;

        return implode('', array_slice($lines, $start, $length));
    }

    /**
     * A stable fingerprint of the private $requestScopedResetters registry:
     * its size plus the declaring class/method of each callable. This is what a
     * per-request rebuild would perturb.
     *
     * @return array{count:int,shape:list<string>}
     */
    private function resetterFingerprint(HttpKernel $kernel): array
    {
        $prop = new \ReflectionProperty(HttpKernel::class, 'requestScopedResetters');
        /** @var list<callable> $resetters */
        $resetters = $prop->getValue($kernel);

        $shape = [];
        foreach ($resetters as $resetter) {
            $closure = \Closure::fromCallable($resetter);
            $refFn = new \ReflectionFunction($closure);
            $scope = $refFn->getClosureScopeClass();
            $shape[] = ($scope !== null ? $scope->getName() : '?') . '::' . $refFn->getName();
        }
        sort($shape);

        return [
            'count' => count($resetters),
            'shape' => $shape,
        ];
    }

    private function routerWithNoopRoute(): Router
    {
        $router = new Router('');
        $router->register('GET', '/noop', static fn(Request $req): Response => Response::json(['ok' => true]));

        return $router;
    }
}
