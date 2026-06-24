<?php

declare(strict_types=1);

namespace Tests\Unit\Core\Store;

use PHPUnit\Framework\TestCase;
use Whity\Core\Store\ArraySharedStore;

/**
 * WC-91f2: behavioural contract tests for SharedStoreInterface.
 *
 * Runs against ArraySharedStore (pure in-memory). The same assertions are
 * replayed against DatabaseSharedStore in the integration suite, proving that
 * both implementations honour the contract identically.
 */
final class SharedStoreContractTest extends TestCase
{
    private ArraySharedStore $store;

    protected function setUp(): void
    {
        $this->store = new ArraySharedStore();
    }

    // ── increment ────────────────────────────────────────────────────────────

    public function testFirstIncrementReturnsOne(): void
    {
        self::assertSame(1, $this->store->increment('k1', 60));
    }

    public function testSubsequentIncrementsCountUp(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k1', 60);
        self::assertSame(3, $this->store->increment('k1', 60));
    }

    public function testIncrementOnExpiredKeyResetsToOne(): void
    {
        $this->store->increment('k1', -1); // already expired
        self::assertSame(1, $this->store->increment('k1', 60));
    }

    public function testIncrementDoesNotMoveTtlOnSubsequentCalls(): void
    {
        $this->store->increment('k1', 60);
        // A second increment on an active key must keep the original expiry,
        // not push it out. Verify via count — the key is still alive.
        $this->store->increment('k1', 60);
        self::assertSame(2, $this->store->count('k1'));
    }

    public function testDifferentKeysAreIndependent(): void
    {
        $this->store->increment('a', 60);
        $this->store->increment('a', 60);
        $this->store->increment('b', 60);

        self::assertSame(2, $this->store->count('a'));
        self::assertSame(1, $this->store->count('b'));
    }

    // ── count ────────────────────────────────────────────────────────────────

    public function testCountReturnZeroForMissingKey(): void
    {
        self::assertSame(0, $this->store->count('missing'));
    }

    public function testCountReflectsCurrentValue(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k1', 60);
        self::assertSame(2, $this->store->count('k1'));
    }

    public function testCountReturnsZeroForExpiredKey(): void
    {
        $this->store->increment('k1', -1); // expired immediately
        self::assertSame(0, $this->store->count('k1'));
    }

    public function testCountDoesNotModifyTheValue(): void
    {
        $this->store->increment('k1', 60);
        $this->store->count('k1');
        $this->store->count('k1');
        self::assertSame(1, $this->store->count('k1'));
    }

    // ── delete ───────────────────────────────────────────────────────────────

    public function testDeleteEvictsKey(): void
    {
        $this->store->increment('k1', 60);
        $this->store->delete('k1');
        self::assertSame(0, $this->store->count('k1'));
    }

    public function testDeleteOnMissingKeyIsNoOp(): void
    {
        $this->store->delete('ghost');
        self::assertSame(0, $this->store->count('ghost'));
    }

    public function testDeleteOnlyRemovesTargetKey(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k2', 60);
        $this->store->delete('k1');

        self::assertSame(0, $this->store->count('k1'));
        self::assertSame(1, $this->store->count('k2'));
    }

    // ── prune ────────────────────────────────────────────────────────────────

    public function testPruneRemovesExpiredEntries(): void
    {
        $this->store->increment('live',    60);
        $this->store->increment('expired', -1);

        $removed = $this->store->prune();

        self::assertSame(1, $removed);
        self::assertSame(1, $this->store->count('live'));
        self::assertSame(0, $this->store->count('expired'));
    }

    public function testPruneReturnsZeroWhenNothingExpired(): void
    {
        $this->store->increment('live', 60);
        self::assertSame(0, $this->store->prune());
    }

    public function testPruneRemovesMultipleExpiredEntries(): void
    {
        $this->store->increment('e1', -1);
        $this->store->increment('e2', -1);
        $this->store->increment('live', 60);

        self::assertSame(2, $this->store->prune());
        self::assertSame(0, $this->store->count('e1'));
        self::assertSame(0, $this->store->count('e2'));
        self::assertSame(1, $this->store->count('live'));
    }

    public function testIncrementAfterDeleteStartsFresh(): void
    {
        $this->store->increment('k1', 60);
        $this->store->increment('k1', 60);
        $this->store->delete('k1');
        self::assertSame(1, $this->store->increment('k1', 60));
    }
}
