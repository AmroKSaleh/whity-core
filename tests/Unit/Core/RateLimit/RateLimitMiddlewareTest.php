<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\RateLimit\RateLimitMiddleware;
use Whity\Core\RateLimit\RateLimitRule;
use Whity\Core\RateLimit\SharedStoreRateLimitStore;
use Whity\Core\Store\ArraySharedStore;
use Whity\Sdk\Http\Request;
use Whity\Sdk\Http\Response;

/**
 * WC-c0fb3700: kernel rate-limit middleware.
 *
 * The middleware turns each configured {@see RateLimitRule} into a counter key,
 * runs it through the shared {@see SharedStoreRateLimitStore}, and short-circuits
 * with HTTP 429 + standard headers the moment any dimension exceeds its budget.
 * Rules whose resolver returns null (e.g. a per-tenant rule on an unauthenticated
 * request) are skipped, so the same class serves the pre-auth (per-IP) and
 * post-auth (per-tenant / per-principal) positions in the pipeline.
 */
final class RateLimitMiddlewareTest extends TestCase
{
    /** @var callable(Request):Response */
    private $next;
    private int $nextCalls = 0;

    protected function setUp(): void
    {
        $this->nextCalls = 0;
        $this->next = function (Request $req): Response {
            $this->nextCalls++;
            return new Response(200, 'OK');
        };
    }

    private function limiter(int $limit): SharedStoreRateLimitStore
    {
        return new SharedStoreRateLimitStore(new ArraySharedStore());
    }

    /** A rule that always resolves to the same fixed value. */
    private function fixedRule(string $name, string $value, int $limit, int $window = 60): RateLimitRule
    {
        return new RateLimitRule($name, static fn (Request $r): string => $value, $limit, $window);
    }

    public function testAllowsRequestsUnderTheLimit(): void
    {
        $mw = new RateLimitMiddleware($this->limiter(2), [$this->fixedRule('ip', '1.2.3.4', 2)]);

        $r1 = $mw->handle(new Request('GET', '/api/x'), $this->next);
        $r2 = $mw->handle(new Request('GET', '/api/x'), $this->next);

        self::assertSame(200, $r1->getStatusCode());
        self::assertSame(200, $r2->getStatusCode());
        self::assertSame(2, $this->nextCalls);
    }

    public function testBlocksRequestsOverTheLimit(): void
    {
        $mw = new RateLimitMiddleware($this->limiter(2), [$this->fixedRule('ip', '1.2.3.4', 2)]);

        $mw->handle(new Request('GET', '/api/x'), $this->next);
        $mw->handle(new Request('GET', '/api/x'), $this->next);
        $blocked = $mw->handle(new Request('GET', '/api/x'), $this->next); // 3rd of 2

        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame(2, $this->nextCalls, 'a blocked request must NOT reach the handler');
    }

    public function testBlockedResponseCarriesStandardHeaders(): void
    {
        $mw = new RateLimitMiddleware($this->limiter(1), [$this->fixedRule('ip', '1.2.3.4', 1, 60)]);

        $mw->handle(new Request('GET', '/api/x'), $this->next);
        $blocked = $mw->handle(new Request('GET', '/api/x'), $this->next);
        $headers = $blocked->getHeaders();

        // Headers are normalised to lowercase by the Response layer.
        self::assertArrayHasKey('retry-after', $headers);
        self::assertGreaterThanOrEqual(1, (int) $headers['retry-after']);
        self::assertLessThanOrEqual(60, (int) $headers['retry-after']);
        self::assertSame('1', $headers['x-ratelimit-limit'] ?? null);
        self::assertSame('0', $headers['x-ratelimit-remaining'] ?? null);
    }

    public function testRuleResolvingToNullIsSkipped(): void
    {
        // A per-tenant style rule on an unauthenticated request resolves to null.
        $nullRule = new RateLimitRule('tenant', static fn (Request $r): ?string => null, 1, 60);
        $mw = new RateLimitMiddleware($this->limiter(1), [$nullRule]);

        $r1 = $mw->handle(new Request('GET', '/api/x'), $this->next);
        $r2 = $mw->handle(new Request('GET', '/api/x'), $this->next);

        self::assertSame(200, $r1->getStatusCode());
        self::assertSame(200, $r2->getStatusCode(), 'a null-resolving rule never limits');
        self::assertSame(2, $this->nextCalls);
    }

    public function testAnyExceededRuleBlocks(): void
    {
        // IP budget generous, tenant budget tight — the tenant rule trips first.
        $mw = new RateLimitMiddleware($this->limiter(100), [
            $this->fixedRule('ip', '1.2.3.4', 100),
            $this->fixedRule('tenant', '42', 1),
        ]);

        $mw->handle(new Request('GET', '/api/x'), $this->next);
        $blocked = $mw->handle(new Request('GET', '/api/x'), $this->next);

        self::assertSame(429, $blocked->getStatusCode());
        self::assertSame('1', $blocked->getHeaders()['x-ratelimit-limit'] ?? null, 'the tripped rule defines the headers');
    }

    public function testDistinctResolvedValuesAreCountedSeparately(): void
    {
        $value = '1.1.1.1';
        $rule = new RateLimitRule('ip', static fn (Request $r): ?string => $r->getHeader('X-Test-Ip'), 1, 60);
        $mw = new RateLimitMiddleware($this->limiter(1), [$rule]);

        $a = $mw->handle(new Request('GET', '/api/x', ['X-Test-Ip' => '1.1.1.1']), $this->next);
        $b = $mw->handle(new Request('GET', '/api/x', ['X-Test-Ip' => '2.2.2.2']), $this->next);
        $aAgain = $mw->handle(new Request('GET', '/api/x', ['X-Test-Ip' => '1.1.1.1']), $this->next);

        self::assertSame(200, $a->getStatusCode());
        self::assertSame(200, $b->getStatusCode(), 'a different IP has its own budget');
        self::assertSame(429, $aAgain->getStatusCode(), 'the first IP is now over budget');
    }

    public function testExemptPathBypassesLimiting(): void
    {
        $mw = new RateLimitMiddleware(
            $this->limiter(1),
            [$this->fixedRule('ip', '1.2.3.4', 1)],
            enabled: true,
            exemptPaths: ['/api/health'],
        );

        $mw->handle(new Request('GET', '/api/health'), $this->next);
        $r = $mw->handle(new Request('GET', '/api/health'), $this->next); // would be over limit

        self::assertSame(200, $r->getStatusCode(), 'exempt paths are never limited');
        self::assertSame(2, $this->nextCalls);
    }

    public function testExemptPathIgnoresQueryString(): void
    {
        $mw = new RateLimitMiddleware(
            $this->limiter(1),
            [$this->fixedRule('ip', '1.2.3.4', 1)],
            enabled: true,
            exemptPaths: ['/api/health'],
        );

        $mw->handle(new Request('GET', '/api/health?probe=1'), $this->next);
        $r = $mw->handle(new Request('GET', '/api/health?probe=2'), $this->next);

        self::assertSame(200, $r->getStatusCode());
    }

    public function testDisabledMiddlewarePassesThrough(): void
    {
        $mw = new RateLimitMiddleware(
            $this->limiter(1),
            [$this->fixedRule('ip', '1.2.3.4', 1)],
            enabled: false,
        );

        $mw->handle(new Request('GET', '/api/x'), $this->next);
        $r = $mw->handle(new Request('GET', '/api/x'), $this->next);

        self::assertSame(200, $r->getStatusCode(), 'a disabled limiter never blocks');
        self::assertSame(2, $this->nextCalls);
    }

    public function testNoRulesNeverBlocks(): void
    {
        $mw = new RateLimitMiddleware($this->limiter(1), []);

        $r = $mw->handle(new Request('GET', '/api/x'), $this->next);

        self::assertSame(200, $r->getStatusCode());
    }
}
