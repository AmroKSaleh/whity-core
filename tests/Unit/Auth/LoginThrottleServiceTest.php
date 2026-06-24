<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use PHPUnit\Framework\TestCase;
use Whity\Auth\LoginThrottleService;
use Whity\Core\Store\ArraySharedStore;

/**
 * WC-0abcc29f: contract tests for LoginThrottleService.
 *
 * Uses ArraySharedStore as the SharedStoreInterface implementation so tests
 * are deterministic and require no database.
 */
final class LoginThrottleServiceTest extends TestCase
{
    private ArraySharedStore $store;
    private LoginThrottleService $throttle;

    protected function setUp(): void
    {
        $this->store    = new ArraySharedStore();
        $this->throttle = new LoginThrottleService($this->store);
    }

    // ── isThrottled: fresh state ─────────────────────────────────────────────

    public function testNotThrottledWithNoFailures(): void
    {
        self::assertFalse($this->throttle->isThrottled(1, '1.2.3.4'));
    }

    public function testNotThrottledWithNullUserIdAndIp(): void
    {
        self::assertFalse($this->throttle->isThrottled(null, null));
    }

    // ── per-user throttle ────────────────────────────────────────────────────

    public function testNotThrottledBelowUserThreshold(): void
    {
        for ($i = 0; $i < 9; $i++) {
            $this->throttle->recordFailure(42, null);
        }
        self::assertFalse($this->throttle->isThrottled(42, null));
    }

    public function testThrottledAtUserThreshold(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->recordFailure(42, null);
        }
        self::assertTrue($this->throttle->isThrottled(42, null));
    }

    public function testUserThrottleDoesNotAffectOtherUsers(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->recordFailure(42, null);
        }
        self::assertFalse($this->throttle->isThrottled(99, null));
    }

    // ── per-IP throttle ──────────────────────────────────────────────────────

    public function testNotThrottledBelowIpThreshold(): void
    {
        for ($i = 0; $i < 19; $i++) {
            $this->throttle->recordFailure(null, '1.2.3.4');
        }
        self::assertFalse($this->throttle->isThrottled(null, '1.2.3.4'));
    }

    public function testThrottledAtIpThreshold(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->recordFailure(null, '1.2.3.4');
        }
        self::assertTrue($this->throttle->isThrottled(null, '1.2.3.4'));
    }

    public function testIpThrottleDoesNotAffectOtherIps(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->recordFailure(null, '1.2.3.4');
        }
        self::assertFalse($this->throttle->isThrottled(null, '5.6.7.8'));
    }

    // ── combined user + IP check ─────────────────────────────────────────────

    public function testThrottledWhenUserOverThresholdEvenIfIpIsNot(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->recordFailure(1, '1.2.3.4');
        }
        // User 1 is over threshold, but IP only has 10 failures (below IP threshold of 20)
        self::assertTrue($this->throttle->isThrottled(1, '1.2.3.4'));
    }

    public function testThrottledWhenIpOverThresholdEvenIfUserIsNot(): void
    {
        // Different user IDs, all from same IP — fills IP counter without hitting user threshold
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->recordFailure($i + 100, '9.9.9.9');
        }
        // User 100 only has 1 failure; but IP 9.9.9.9 has 20
        self::assertTrue($this->throttle->isThrottled(100, '9.9.9.9'));
    }

    // ── clearUser ────────────────────────────────────────────────────────────

    public function testClearUserResetsUserCounter(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->throttle->recordFailure(7, null);
        }
        self::assertTrue($this->throttle->isThrottled(7, null));

        $this->throttle->clearUser(7);

        self::assertFalse($this->throttle->isThrottled(7, null));
    }

    public function testClearUserDoesNotResetIpCounter(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->throttle->recordFailure(7, '1.2.3.4');
        }
        $this->throttle->clearUser(7);

        // User counter cleared, but IP should still be throttled
        self::assertFalse($this->throttle->isThrottled(7, null));
        self::assertTrue($this->throttle->isThrottled(null, '1.2.3.4'));
    }

    public function testClearUserOnCleanStateIsNoOp(): void
    {
        $this->throttle->clearUser(999);
        self::assertFalse($this->throttle->isThrottled(999, null));
    }

    // ── recordFailure with both args ──────────────────────────────────────────

    public function testRecordFailureIncrementsUserAndIpCounters(): void
    {
        $this->throttle->recordFailure(5, '2.2.2.2');

        // Verify underlying store incremented both keys
        self::assertSame(1, $this->store->count('login:fail:user:5'));
        self::assertSame(1, $this->store->count('login:fail:ip:2.2.2.2'));
    }

    public function testRecordFailureWithNullUserIdSkipsUserCounter(): void
    {
        $this->throttle->recordFailure(null, '3.3.3.3');

        self::assertSame(0, $this->store->count('login:fail:user:'));
        self::assertSame(1, $this->store->count('login:fail:ip:3.3.3.3'));
    }

    public function testRecordFailureWithNullIpSkipsIpCounter(): void
    {
        $this->throttle->recordFailure(8, null);

        self::assertSame(1, $this->store->count('login:fail:user:8'));
        self::assertSame(0, $this->store->count('login:fail:ip:'));
    }
}
