<?php

declare(strict_types=1);

namespace Tests\Integration\RateLimit;

use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\SchemaFromMigrations;
use Whity\Core\RateLimit\SharedStoreRateLimitStore;
use Whity\Core\Store\DatabaseSharedStore;

/**
 * WC-dc791066: SharedStoreRateLimitStore over a real SQL engine.
 *
 * Replays the rate-limit decision behaviour against DatabaseSharedStore on a
 * from-migrations SQLite engine (the postgres-integration CI job runs the same
 * assertions on real PostgreSQL), proving the limiter behaves identically on the
 * production storage path — including the seconds-until-reset that drives
 * Retry-After, which is computed from the persisted expires_at column.
 */
final class SharedStoreRateLimitStoreRealEngineTest extends TestCase
{
    private PDO $pdo;
    private SharedStoreRateLimitStore $limiter;

    protected function setUp(): void
    {
        $this->pdo     = SchemaFromMigrations::make(true);
        $this->limiter = new SharedStoreRateLimitStore(new DatabaseSharedStore($this->pdo));
    }

    public function testAllowsUpToTheLimitThenBlocks(): void
    {
        self::assertTrue($this->limiter->hit('ip:1', 2, 60)->allowed);
        self::assertTrue($this->limiter->hit('ip:1', 2, 60)->allowed);

        $blocked = $this->limiter->hit('ip:1', 2, 60);
        self::assertFalse($blocked->allowed);
        self::assertSame(0, $blocked->remaining);
        self::assertSame(3, $blocked->count);
    }

    public function testBlockedDecisionCarriesRetryAfterFromPersistedExpiry(): void
    {
        $this->limiter->hit('ip:1', 1, 60);
        $blocked = $this->limiter->hit('ip:1', 1, 60);

        self::assertFalse($blocked->allowed);
        self::assertGreaterThanOrEqual(1, $blocked->retryAfter);
        self::assertLessThanOrEqual(60, $blocked->retryAfter);
    }

    public function testRemainingCountsDown(): void
    {
        self::assertSame(2, $this->limiter->hit('ip:1', 3, 60)->remaining);
        self::assertSame(1, $this->limiter->hit('ip:1', 3, 60)->remaining);
        self::assertSame(0, $this->limiter->hit('ip:1', 3, 60)->remaining);
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $this->limiter->hit('a', 1, 60);
        self::assertFalse($this->limiter->hit('a', 1, 60)->allowed);
        self::assertTrue($this->limiter->hit('b', 1, 60)->allowed);
    }

    public function testResetClearsTheWindow(): void
    {
        $this->limiter->hit('ip:1', 1, 60);
        self::assertFalse($this->limiter->hit('ip:1', 1, 60)->allowed);

        $this->limiter->reset('ip:1');

        self::assertTrue($this->limiter->hit('ip:1', 1, 60)->allowed);
    }

    public function testExpiredWindowResetsOnNextHit(): void
    {
        $this->limiter->hit('ip:1', 1, -1); // window already elapsed
        $d = $this->limiter->hit('ip:1', 1, 60);

        self::assertTrue($d->allowed);
        self::assertSame(1, $d->count);
    }
}
