<?php

declare(strict_types=1);

namespace Tests\Unit\Core\RateLimit;

use PHPUnit\Framework\TestCase;
use Whity\Core\RateLimit\RateLimitDecision;
use Whity\Core\RateLimit\SharedStoreRateLimitStore;
use Whity\Core\Store\ArraySharedStore;

/**
 * WC-dc791066: behavioural tests for the default rate-limit store.
 *
 * SharedStoreRateLimitStore composes the existing SharedStoreInterface (atomic
 * fixed-window counter) into a rate-limit DECISION — {allowed, limit, remaining,
 * retryAfter, count} — adding the seconds-until-reset the bare counter never
 * exposed, so callers can emit an accurate Retry-After header.
 *
 * Runs against ArraySharedStore (real, in-memory); the same store is exercised
 * over a real SQL engine by SharedStoreRateLimitStoreRealEngineTest.
 */
final class SharedStoreRateLimitStoreTest extends TestCase
{
    private SharedStoreRateLimitStore $limiter;

    protected function setUp(): void
    {
        $this->limiter = new SharedStoreRateLimitStore(new ArraySharedStore());
    }

    public function testFirstHitIsAllowed(): void
    {
        $d = $this->limiter->hit('ip:1', 3, 60);

        self::assertInstanceOf(RateLimitDecision::class, $d);
        self::assertTrue($d->allowed);
        self::assertSame(3, $d->limit);
        self::assertSame(1, $d->count);
        self::assertSame(2, $d->remaining);
        self::assertSame(0, $d->retryAfter, 'an allowed request needs no Retry-After');
    }

    public function testHitsUpToTheLimitAreAllowed(): void
    {
        $this->limiter->hit('ip:1', 3, 60);
        $this->limiter->hit('ip:1', 3, 60);
        $d = $this->limiter->hit('ip:1', 3, 60); // 3rd of 3

        self::assertTrue($d->allowed);
        self::assertSame(0, $d->remaining);
    }

    public function testHitOverTheLimitIsBlocked(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->limiter->hit('ip:1', 3, 60);
        }
        $d = $this->limiter->hit('ip:1', 3, 60); // 4th of 3

        self::assertFalse($d->allowed);
        self::assertSame(0, $d->remaining);
        self::assertSame(4, $d->count);
    }

    public function testBlockedDecisionCarriesRetryAfterWithinTheWindow(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $d = $this->limiter->hit('ip:1', 2, 60);
        }

        self::assertFalse($d->allowed);
        self::assertGreaterThanOrEqual(1, $d->retryAfter, 'a blocked client must be told to wait at least 1s');
        self::assertLessThanOrEqual(60, $d->retryAfter, 'Retry-After must not exceed the window');
    }

    public function testRemainingCountsDown(): void
    {
        self::assertSame(4, $this->limiter->hit('ip:1', 5, 60)->remaining);
        self::assertSame(3, $this->limiter->hit('ip:1', 5, 60)->remaining);
        self::assertSame(2, $this->limiter->hit('ip:1', 5, 60)->remaining);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $this->limiter->hit('a', 1, 60);
        $a = $this->limiter->hit('a', 1, 60); // over for 'a'
        $b = $this->limiter->hit('b', 1, 60); // first for 'b'

        self::assertFalse($a->allowed);
        self::assertTrue($b->allowed);
    }

    public function testResetClearsTheWindow(): void
    {
        $this->limiter->hit('ip:1', 1, 60);
        $this->limiter->hit('ip:1', 1, 60); // now over the limit

        $this->limiter->reset('ip:1');

        $d = $this->limiter->hit('ip:1', 1, 60);
        self::assertTrue($d->allowed, 'after reset the window starts fresh');
        self::assertSame(1, $d->count);
    }

    public function testExpiredWindowResetsOnNextHit(): void
    {
        $this->limiter->hit('ip:1', 1, -1); // window already elapsed
        $d = $this->limiter->hit('ip:1', 1, 60);

        self::assertTrue($d->allowed, 'a hit after the window elapsed starts a new window');
        self::assertSame(1, $d->count);
    }
}
